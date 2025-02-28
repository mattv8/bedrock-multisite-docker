<?php

/**
 * Class Uploader
 *
 * This class is responsible for handling media offload to MinIO (or an S3-compatible API).
 * It hooks into WordPress’s upload process, uploads files to MinIO, and updates file URLs.
 *
 * @package mattv8\URLFixer
 */

namespace URL;

use Roots\WPConfig\Config;
use WP_Error;
use Aws\S3\S3Client;

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

    /**
     * Uploader constructor.
     * Initializes the uploader and sets up the S3 client for MinIO.
     */
    public function __construct()
    {
        $this->minio_url    = Config::get('MINIO_URL');
        $this->minio_key    = Config::get('MINIO_KEY');
        $this->minio_secret = Config::get('MINIO_SECRET');
        $this->bucket       = Config::get('MINIO_BUCKET');

        if (!empty($this->minio_url) && !empty($this->minio_key) && !empty($this->minio_secret)) {
            // Setup S3 client using AWS SDK (MinIO is S3-compatible)
            $this->s3_client = new S3Client([
                'version'                 => 'latest',
                'region'                  => 'us-east-1', // Region is arbitrary for MinIO.
                'endpoint'                => $this->minio_url,
                'use_path_style_endpoint' => true,
                'credentials'             => [
                    'key'    => $this->minio_key,
                    'secret' => $this->minio_secret,
                ],
            ]);
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
    public function add_filters()
    {

        // Intercept the file upload handling to offload files to MinIO
        add_filter('wp_handle_upload', [$this, 'handle_upload_to_minio']);
        add_filter('wp_generate_attachment_metadata', [$this, 'offload_thumbnails_to_minio'], 10, 2);
        add_action('delete_attachment', [$this, 'delete_from_minio']);

        // Rewrite media URL's
        $rewriter = new \URL\Rewriter();
        add_filter('upload_dir', [$rewriter, 'rewrite_site_url']);
    }

    /**
     * Handles the file upload by offloading the media to MinIO.
     *
     * @param  array $upload The file upload information from wp_handle_upload.
     * @return array The modified upload array, with a new URL pointing to MinIO, or WP_Error on failure.
     */
    public function handle_upload_to_minio(array $upload)
    {
        if (empty($this->minio_url) || empty($this->minio_key) || empty($this->minio_secret)) {
            error_log('[ERROR] MINIO_URL, MINIO_KEY, and MINIO_SECRET must be set in your .env file. Defaulting to local uploads.');
            return $upload;
        }

        global $current_blog;

        if (!isset($upload['file']) || !isset($upload['url'])) {
            return $upload;
        }

        $file_path = $upload['file'];
        $file_name = basename($file_path);

        // Determine the upload path prefix based on multisite
        $upload_path_prefix = is_multisite() ? 'uploads/sites/' . $current_blog->blog_id . '/' : 'uploads/';

        // Build the object key using the proper prefix
        $object_key = $upload_path_prefix . date('Y/m') . '/' . $file_name;

        try {
            // Upload the main file to MinIO
            $this->s3_client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $object_key,
                'Body'   => fopen($file_path, 'r'),
                'ACL'    => 'public-read',
            ]);
            // Update the URL to point to MinIO
            $upload['url'] = $this->minio_url . '/' . $object_key;
        } catch (\Exception $e) {
            error_log('MinIO upload error: ' . $e->getMessage());
            return new WP_Error('upload_error', 'Error uploading file to MinIO: ' . $e->getMessage());
        }

        return $upload;
    }

    public function offload_thumbnails_to_minio($metadata, $attachment_id) {
        global $current_blog;
        $upload_path_prefix = is_multisite() ? 'uploads/sites/' . $current_blog->blog_id . '/' : 'uploads/';

        foreach ($metadata['sizes'] as $size => &$info) {
            $thumb_path = dirname(get_attached_file($attachment_id)) . '/' . $info['file'];
            $thumb_key  = $upload_path_prefix . date('Y/m') . '/' . $info['file'];

            if (file_exists($thumb_path)) {
                try {
                    $this->s3_client->putObject([
                        'Bucket' => $this->bucket,
                        'Key'    => $thumb_key,
                        'Body'   => fopen($thumb_path, 'r'),
                        'ACL'    => 'public-read',
                    ]);
                    // Optionally, update metadata with the MinIO URL for this size.
                    $info['url'] = $this->minio_url . '/' . $thumb_key;
                } catch (\Exception $e) {
                    error_log('MinIO thumbnail upload error: ' . $e->getMessage());
                }
            }
        }
        return $metadata;
    }

    /**
     * Deletes the media file and its associated thumbnails from MinIO when the attachment is deleted in WordPress.
     *
     * @param  integer $attachment_id The ID of the attachment being deleted.
     * @return void
     */
    public function delete_from_minio(int $attachment_id)
    {
        if (empty($this->minio_url) || empty($this->minio_key) || empty($this->minio_secret)) {
            error_log('[ERROR] MINIO_URL, MINIO_KEY, and MINIO_SECRET must be set in your .env file. Defaulting to local uploads.');
            return;
        }

        global $current_blog;
        $attachment = get_post($attachment_id);
        $file_url = $attachment->guid;

        // Extract the object key from the MinIO URL
        $object_key = ltrim(parse_url($file_url, PHP_URL_PATH), '/');

        try {
            // Attempt to delete the main media object from MinIO
            $this->s3_client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $object_key,
            ]);

            // Get attachment metadata to find thumbnails
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (!is_wp_error($metadata)) {
                $upload_path_prefix = is_multisite() ? 'uploads/sites/' . $current_blog->blog_id . '/' : 'uploads/';
                foreach ($metadata['sizes'] as $size => $info) {
                    $thumb_key = $upload_path_prefix . date('Y/m') . '/' . $info['file'];
                    try {
                        $this->s3_client->deleteObject([
                            'Bucket' => $this->bucket,
                            'Key'    => $thumb_key,
                        ]);
                    } catch (\Exception $e) {
                        error_log('Error deleting thumbnail from MinIO: ' . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('Error deleting main file from MinIO: ' . $e->getMessage());
        }
    }
}
