<?php

/**
 * Class Uploader
 *
 * This class is responsible for handling media offload to MinIO (or an S3-compatible API).
 * It hooks into WordPress's upload process, uploads files to MinIO, and updates file URLs.
 *
 * @package mattv8\URLFixer
 */

namespace URL;

use Roots\WPConfig\Config;
use WP_Error;
use WP_Image_Editor;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Psr\Http\Message\RequestInterface;
use Aws\CommandInterface;

/**
 * Class Uploader
 *
 * Handles URL rewriting for WordPress uploads and MinIO/S3 integration.
 * Ensures correct domain handling, subdomain support, and logging of rewrites.
 */
class Uploader
{
    protected $s3_client;
    protected $bucket;
    protected $minio_url;
    protected $minio_key;
    protected $minio_secret;
    protected $checksums;

    /**
     * Uploader constructor.
     * Initializes the uploader and sets up the S3 client for MinIO.
     */
    public function __construct() {
        $this->minio_url    = rtrim(Config::get('MINIO_URL'), '/');
        $this->minio_key    = Config::get('MINIO_KEY');
        $this->minio_secret = Config::get('MINIO_SECRET');
        $this->bucket       = Config::get('MINIO_BUCKET');
        $this->checksums    = Config::get('MINIO_CHECKSUMS');

        if (!empty($this->minio_url) && !empty($this->minio_key) && !empty($this->minio_secret)) {
            $this->s3_client = new S3Client([
                'version'                 => 'latest',
                'region'                  => 'us-west-000',
                'endpoint'                => $this->minio_url,
                'use_path_style_endpoint' => true,
                'credentials'             => [
                    'key'    => $this->minio_key,
                    'secret' => $this->minio_secret,
                ],
            ]);

            // This is some Backblaze B2-specific nonsense.
            // Backblaze B2 does not support x-amz-checksum-crc32, so we need to remove the header and compute
            // the md5 manually. We use the XML bytestream to compute the checksum in a way B2 expects.
            if (!$this->checksums) {
                // Compute MD5 on-the-fly for DeleteObjects
                $md5_middleware = function (callable $handler) {
                    return function (CommandInterface $cmd, RequestInterface $req) use ($handler) {
                        if ($cmd->getName() === 'DeleteObjects') {
                            // Grab the exact XML bytes about to go on the wire
                            $body = (string) $req->getBody();
                            // Rewind so Guzzle can re-send it
                            $req->getBody()->rewind();
                            // Compute and inject the MD5 checksum
                            $md5 = base64_encode(md5($body, true));
                            $req = $req->withHeader('Content-MD5', $md5);
                        }
                        return $handler($cmd, $req);
                    };
                };

                $remove_checksum_middleware = function (callable $handler) {
                    return function ($command, $request) use ($handler) {
                        if ($request->hasHeader('x-amz-checksum-crc32')) {
                            $request = $request->withoutHeader('x-amz-checksum-crc32');
                        }
                        return $handler($command, $request);
                    };
                };

                $this->s3_client->getHandlerList()->appendBuild($remove_checksum_middleware, 'remove_checksum');
                $this->s3_client->getHandlerList()->appendSign($remove_checksum_middleware, 'remove_checksum');
                $this->s3_client->getHandlerList()->appendBuild($md5_middleware, 'inject_delete_md5');
            }

        }
    }

    /**
     * Adds necessary filters for the media offloader.
     *
     * This method hooks into WordPress upload processes (e.g., upload_dir, wp_handle_upload)
     * to offload media files to MinIO.
     *
     * @return void
     */
    public function add_filters() {

        // Intercept the file upload handling to offload files to MinIO
        add_filter('wp_handle_upload', [$this, 'handle_upload_to_minio']);
        add_filter('wp_generate_attachment_metadata', [$this, 'offload_metadata_to_minio'], 10, 3);
        add_action('delete_attachment', [$this, 'delete_from_minio']);

        // Rewrite media URL's
        $rewriter = new \URL\Rewriter();
        add_filter('upload_dir', [$rewriter, 'rewrite_site_url']);

        // Image Edits
        add_filter('wp_update_attachment_metadata', [ $this, 'update_metadata_minio' ], 10, 2);
        add_filter('wp_save_image_editor_file', [ $this, 'save_image_to_minio' ], 10, 5);
    }

    ###################################################################################
    # Public methods
    ###################################################################################

    /**
     * Handles the upload to MinIO and updates the upload array with the new URL.
     *
     * @param  array $upload The upload array containing file information.
     * @return array The updated upload array with the new URL.
     */
    public function handle_upload_to_minio(array $upload): array {
        if (!$this->ensure_minio_credentials()) {
            return $upload;
        }

        $local_path = $upload['file'];
        $s3_key = $this->get_s3_key($local_path);

        if ($result = $this->upload_to_minio($local_path, $s3_key)) {
            $upload['url'] = $result['url'];
            // @unlink($local_path);
        } else {
            error_log("[Uploader] Failed to offload {$local_path} via filter.");
        }

        return $upload;
    }

    /**
     * Capture the image save, write it via the editor, then offload to MinIO.
     *
     * @param null|string     $override   WP's current override; pass through.
     * @param string          $local_file Intended disk path (unused for write).
     * @param WP_Image_Editor $image      The image editor instance.
     * @param string          $mime_type  The image's MIME type.
     * @param integer         $post_id    Attachment post ID.
     *
     * @return string The path that WP should assume was written.
     */
    public function save_image_to_minio(
        ?string $override,
        string $local_file,
        WP_Image_Editor $image,
        string $mime_type,
        int $post_id
    ): string {
        if (!$this->ensure_minio_credentials()) {
            return $override;
        }

        // Write the image locally
        $result = $image->save($local_file, $mime_type);
        if (is_wp_error($result)) {
            error_log("[Uploader] Image save error: " . $result->get_error_message());
            return $override;
        }

        $s3_key = $this->get_s3_key($local_file);

        // Upload to MinIO and update the post's GUID
        if ($res = $this->upload_to_minio($local_file, $s3_key)) {
            wp_update_post([
                'ID'   => $post_id,
                'guid' => $res['url'],
            ]);
            @unlink($local_file);
        } else {
            error_log("[Uploader] Offload error for {$local_file}");
        }

        // Tell WP we handled writing
        return $local_file;
    }

    /**
     * Offloads metadata to MinIO for the given attachment.
     *
     * @param  array   $metadata      The metadata array for the attachment.
     * @param  integer $attachment_id The ID of the attachment.
     * @param  string  $context       The context in which the metadata is being processed.
     * @return array The updated metadata array with MinIO URLs.
     */
    public function offload_metadata_to_minio(array $metadata, int $attachment_id, string $context = ''): array {
        if (!$this->ensure_minio_credentials()) {
            return $metadata;
        }

        if ($context !== 'create') {
            error_log("[Uploader] Not a create context, skipping thumbnail offload. (context: {$context})");
            return $metadata;
        }

        // Get the base directory for this attachment
        $attachment_file = get_attached_file($attachment_id);
        $base_dir = dirname($attachment_file) . '/';

        if (!empty($metadata['sizes'])) {
            $this->process_sizes_with_dedup($metadata['sizes'], $base_dir);
        }

        return $metadata;
    }

    /**
     * Updates the metadata for the given attachment and offloads it to MinIO.
     *
     * @param  array   $metadata      The metadata array for the attachment.
     * @param  integer $attachment_id The ID of the attachment.
     * @return array|null The updated metadata array with MinIO URLs, or null on failure.
     */
    public function update_metadata_minio(array $metadata, int $attachment_id): array|null {
        if (!$this->ensure_minio_credentials()) {
            return $metadata;
        }

        if (empty($metadata['sizes'])) {
            return $this->offload_metadata_to_minio($metadata, $attachment_id);
        }

        // The filename will have a -e[hash] in it, so we can use that to determine if this is an edit.
        // From what I can tell, there is no other clean way to do this. See WP_Image_Editor::generate_filename
        $main_file = $metadata['file']; // e.g. 2025/05/test-upload-image.jpg
        $is_edit   = (bool) preg_match('/-e[0-9a-f]{10,15}(?:-\d|\.)/i', $main_file);
        if ( !$is_edit ) {
            // error_log('[Uploader] Not an edit, skipping updating of metadata.');
            return $metadata;
        }

        // Get the base directory for this attachment
        $base_dir = dirname(get_attached_file($attachment_id)) . '/';

        // Upload main image
        $main_local = $base_dir . basename($main_file);
        $main_key = $this->get_s3_key($main_local);

        if ($res = $this->upload_to_minio($main_local, $main_key)) {
            $metadata['url'] = $res['url'];
            unlink($main_local);
        }

        // Upload each size
        if (!empty($metadata['sizes'])) {
            $this->process_sizes_with_dedup($metadata['sizes'], $base_dir);
        }

        return $metadata;
    }

    /**
     * Deletes the media file and its associated thumbnails (and *all* their versions)
     * from MinIO/Backblaze when the attachment is deleted in WordPress.
     *
     * @param  integer $attachment_id The ID of the attachment to delete.
     * @return void
     */
    public function delete_from_minio(int $attachment_id): void {
        if (!$this->ensure_minio_credentials()) {
            return;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata['file'])) {
            error_log("[Uploader] No metadata found for attachment #{$attachment_id}");
            return;
        }

        $prefix = $this->get_upload_path_prefix(); // e.g. uploads/sites/3/
        $date_dir = dirname($metadata['file']) . '/'; // e.g. 2025/05/
        $base_path = $prefix . $date_dir;

        // Strip all -e<hash> segments:
        $strip_edit_hash = function(string $filename): string {
            return preg_replace('/-e[0-9a-f]+/', '', $filename);
        };

        // Purge the "main" file (and versions)
        $main_file = $metadata['file']; // e.g. my-image-eabc123.jpg
        $main_base = $strip_edit_hash($main_file); // my-image.jpg
        $main_prefix = $base_path . pathinfo($main_base, PATHINFO_FILENAME);
        $this->purge_key_versions($main_prefix);

        // Purge each thumbnail (and versions)
        $thumb_count = 0;
        foreach ($metadata['sizes'] as $info) {
            $thumb_file   = $info['file']; // e.g. my-image-eabc123-150x150.jpg
            $thumb_base   = $strip_edit_hash($thumb_file); // my-image-150x150.jpg
            $thumb_prefix = $base_path . pathinfo($thumb_base, PATHINFO_FILENAME);
            $this->purge_key_versions($thumb_prefix);
            $thumb_count++;
        }

        error_log("[Uploader] Purged all versions for {$metadata['file']} + {$thumb_count} thumbnails");
    }

    ###################################################################################
    # Private methods
    ###################################################################################

    /**
     * Uploads a file to MinIO and returns the URL.
     *
     * @param  string $local_path The local path of the file to upload.
     * @param  string $s3_key     The S3 key (path) where the file will be stored.
     * @return array|null       The URL and size of the uploaded file, or null on failure.
     */
    private function upload_to_minio(string $local_path, string $s3_key): ?array {
        if (! $this->wait_for_file($local_path)) {
            error_log("[Uploader] Timeout waiting for: {$local_path}");
            return null;
        }

        list($fp, $size) = $this->open_stream($local_path);

        try {
            $this->s3_client->putObject([
                'Bucket'        => $this->bucket,
                'Key'           => $s3_key,
                'Body'          => $fp,
                'ContentLength' => $size,
                'ACL'           => 'public-read',
            ]);

            $url = "{$this->minio_url}/{$this->bucket}/{$s3_key}";
            error_log(sprintf(
                "[Uploader] Successfully uploaded %s -> %s (%s)",
                $s3_key,
                $url,
                $this->human_readable_filesize($size)
            ));

            return ['url' => $url, 'size' => $size];
        } catch (\Exception $e) {
            error_log("[Uploader] Upload failed for {$local_path}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Purge every version and delete‐marker for all objects whose key begins with $key_prefix.
     *
     * @param  string $key_prefix Full object key or prefix (e.g. 'uploads/2025/05/my-image').
     * @return void
     */
    private function purge_key_versions(string $key_prefix): void {
        try {
            // List all versions and delete markers under this prefix
            $resp = $this->s3_client->listObjectVersions([
                'Bucket' => $this->bucket,
                'Prefix' => $key_prefix,
            ]);

            $to_delete = [];
            foreach (['Versions', 'DeleteMarkers'] as $list_key) {
                foreach ($resp[$list_key] ?? [] as $v) {
                    $to_delete[] = [
                    'Key'       => $v['Key'],
                    'VersionId' => $v['VersionId'],
                    ];
                }
            }

            // Batch‐delete them, if any
            if (!empty($to_delete)) {
                $this->s3_client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => [ 'Objects' => $to_delete ],
                ]);
            }
        } catch (\Exception $e) {
            error_log("[Uploader] Failed to purge key versions for prefix {$key_prefix}: " . $e->getMessage());
        }
    }

    /**
     * Check MinIO credentials availability.
     *
     * @return boolean True if credentials present; false otherwise.
     */
    private function ensure_minio_credentials(): bool {
        if (empty($this->minio_url) || empty($this->minio_key) || empty($this->minio_secret)) {
            error_log('[Uploader] MINIO credentials missing. Skipping offload.');
            return false;
        }
        return true;
    }

    /**
     * Gets the upload path prefix with configurable trailing slash
     *
     * @param  boolean $trailing_slash Whether to include trailing slash.
     * @return string Upload path prefix.
     */
    public function get_upload_path_prefix(bool $trailing_slash = true): string {
        global $current_blog;

        $base = (is_multisite() && (int)$current_blog->blog_id !== 1)
            ? 'uploads/sites/' . $current_blog->blog_id
            : 'uploads';

        return $trailing_slash ? $base . '/' : $base;
    }

    /**
     * Helper function to build S3 key from local file path
     *
     * @param  string $local_path The local file path.
     * @return string The S3 key to use for upload.
     */
    protected function get_s3_key(string $local_path): string {
        // Get the uploads base directory
        $upload = wp_upload_dir();
        $base_dir = trailingslashit($upload['basedir']);

        // Get the relative path from the WP uploads directory
        // This preserves the WordPress-determined date path
        $rel_path = ltrim(str_replace($base_dir, '', $local_path), '/');

        // Combine with the upload path prefix
        return $this->get_upload_path_prefix() . $rel_path;
    }

    /**
     * Converts bytes to a human-readable file size.
     *
     * @param  integer $bytes     The file size in bytes.
     * @param  integer $precision The number of decimal places to round to.
     * @return string The human-readable file size.
     */
    private function human_readable_filesize(int $bytes, int $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        $bytes = max($bytes, 0);
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$precision}f", $bytes / pow(1024, $factor)) . ' ' . @$units[$factor];
    }

    /**
     * Retry until a file becomes readable, or give up after $max_retries.
     *
     * @param  string  $path        Absolute path to check.
     * @param  integer $max_retries Number of attempts.
     * @param  float   $interval    Time to sleep between attempts in seconds.
     * @return boolean                 True if readable, false on timeout (or non-readable in non-blocking mode).
     */
    private function wait_for_file(
        string $path,
        int    $max_retries = 10,
        float  $interval   = 0.1
    ): bool {
        $attempt = 0;
        do {
            clearstatcache(true, $path);
            if ( is_readable($path) ) {
                return true;
            }
            usleep((int)($interval * 1000000));
            $attempt++;
        } while ( $attempt < $max_retries );

        return false;
    }

    /**
     * Open a file for reading and return its stream and size.
     *
     * @param  string $path Absolute path to the file.
     * @return array{resource,int}  [0] => file pointer resource, [1] => file size in bytes
     *
     * @throws \RuntimeException If the file cannot be opened.
     */
    private function open_stream(string $path): array {
        $fp = fopen($path, 'rb');
        if (! $fp) {
            throw new \RuntimeException("Unable to open file: {$path}");
        }
        rewind($fp);
        $size = filesize($path);

        return [$fp, $size];
    }

    /**
     * Process sizes array with duplicate checking to avoid re-uploading same files.
     *
     * @param  array  $sizes    The sizes array from metadata.
     * @param  string $base_dir The base directory for the files.
     * @return void
     */
    private function process_sizes_with_dedup(array &$sizes, string $base_dir): void {
        $processed_files = [];

        foreach ($sizes as &$info) {
            $filename = $info['file'];

            // Skip if we've already processed this file
            if (isset($processed_files[$filename])) {
                $info['url'] = $processed_files[$filename]['url'];
                continue;
            }

            $local = $base_dir . $filename;
            $s3_key = $this->get_s3_key($local);

            if ($result = $this->upload_to_minio($local, $s3_key)) {
                $info['url'] = $result['url'];
                $processed_files[$filename] = $result;
                unlink($local);
            }
        }
    }
}
