<?php

/**
 * Class URLFixer
 * @package mattv8\URLFixer
 * @author mattv8
 * @link https://github.com/mattv8/multisite-url-fixer
 */

namespace URL;

use Roots\WPConfig\Config;
use function Env\env;

class Rewriter
{
    protected $minio_url;
    protected $minio_bucket;
    protected $uploads_baseurl;
    protected $uploads_path;
    protected $rewrite_cache = [];
    protected $subdomain_suffix;
    protected $wp_base_domain;
    protected $wp_default_site;
    protected $scheme;
    protected $rewrite_overrides = []; // Add a property for overrides
    protected $port;
    protected $wp_production_domain;

    public function __construct()
    {
        // Uploads Directory
        // Fetch uploads directory base URL once to avoid recursion issues
        $uploads_dir = wp_get_upload_dir();
        $this->uploads_baseurl = $uploads_dir['baseurl']; // e.g. http://localhost:81/app/uploads/sites/3
        $this->uploads_path = str_replace("/app/uploads/", "", parse_url($uploads_dir['url'])['path']); // e.g. sites/3/2024/11

        // MinIO or S3 Rewrites
        $this->minio_url = Config::get('MINIO_URL', '');
        $this->minio_bucket = Config::get('MINIO_BUCKET', '');

        // Domain Configuration
        // Fetch and parse domain-related settings
        $this->subdomain_suffix = Config::get('SUBDOMAIN_SUFFIX') ?: '';
        $this->port = Config::get('NGINX_PORT') ?: '';
        $wp_home = Config::get('WP_HOME', 'http://localhost');
        $this->wp_base_domain = get_base_domain($wp_home);
        $parsed_wp_home = parse_url($wp_home);
        $this->scheme = $parsed_wp_home['scheme'];

        // Production Domain
        // Load the production domain from the environment
        $this->wp_production_domain = Config::get('WP_PRODUCTION_DOMAIN', ''); // e.g. hammerton.com
        if (!$this->wp_production_domain) {
            error_log("WP_PRODUCTION_DOMAIN is not set. Please check your .env file.");
        }

        $this->wp_default_site = Config::get('DOMAIN_CURRENT_SITE', '');

        // Rewrite Overrides
        $this->rewrite_overrides = $this->loadOverrides();
    }

    private function loadOverrides(): array
    {
        $overrides_file = dirname(__DIR__, 1) . '/overrides.php';
        if (file_exists($overrides_file)) {
            return include $overrides_file;
        }
        return []; // Return an empty array if the file does not exist
    }

    /**
     * Add filters to rewrite URLs, including multisite domain filters.
     */
    public function addFilters()
    {
        if (is_multisite()) {
            add_filter('option_home', [$this, 'rewriteSiteURL']);
            add_filter('option_siteurl', [$this, 'rewriteSiteURL']);
            add_filter('network_site_url', [$this, 'rewriteSiteURL']);
            add_filter('network_admin_url', [$this, 'rewriteSiteURL']);
        }

        // Media-specific filters
        add_filter('wp_get_attachment_url', [$this, 'rewriteSiteURL']);
        add_filter('wp_calculate_image_srcset', [$this, 'rewriteSiteURL']);
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 2);

        // Uncomment below lines if filters for scripts, styles, and other items are needed
        add_filter('script_loader_src', [$this, 'rewriteSiteURL']);
        add_filter('style_loader_src', [$this, 'rewriteSiteURL']);

        add_filter('plugins_url', [$this, 'rewriteSiteURL']);
    }

    function login_redirect($redirect_to, $requested_redirect_to)
    {
        error_log("Redirecting to: $redirect_to");
        // Process normally if the URL does not match the production domain
        return $this->rewriteURL($redirect_to);
    }


    /**
     * Core function to rewrite URLs for media and site content.
     */
    protected function rewriteURL($url)
    {
        global $current_blog;

        // Fallback to default uploads directory if MinIO configuration is not defined
        if (empty($this->minio_url) || empty($this->minio_bucket)) {
            // If $url is an array (e.g., srcset), return as is
            if (is_array($url)) {
                return $url;
            }

            // Ensure the URL is valid and points to the uploads directory
            $parsed_url = parse_url($url);
            if (isset($parsed_url['path']) && strpos($parsed_url['path'], '/app/uploads/') !== false) {
                return $url;
            }

            // Return unaltered URL if no valid MinIO config and not an upload
            return $url;
        }

        // Check if $url is an array (for srcset), and apply rewriting to each entry.
        if (is_array($url)) {
            foreach ($url as $key => $single_url) {
                $url[$key] = $this->rewriteURL($single_url);  // Recursively rewrite each URL in the srcset array.
            }
            return $url;
        }

        // Check cache to prevent repeated rewrites
        if (isset($this->rewrite_cache[$url])) {
            return $this->rewrite_cache[$url];
        }

        // Check if valid URL
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['host']) || !isset($parsed_url['scheme'])) {
            return $url;
        }

        // Check overrides
        foreach ($this->rewrite_overrides as $override) {
            if ($this->matchOverride($override, $url)) {
                error_log("URL $url was excluded from rewrite (rule: $override)");
                $this->rewrite_cache[$url] = $url; // Cache the original URL
                return $url; // Return the original URL
            }
        }

        // Check for missing port
        if (
            strpos($parsed_url['host'], $this->wp_base_domain['without_port']) !== false &&
            (!isset($parsed_url['port']) || $parsed_url['host'] === $this->wp_base_domain['without_port'])
        ) {

            // Regex pattern to match the production domain and optional subdomain
            $pattern = "/(?:https:\/\/|http:\/\/)?(?:([a-zA-Z0-9_-]+)\.)?" . preg_quote($this->wp_base_domain['without_port'], '/') . "(?::[0-9]+)?(\/.*)?/";

            // Match the subdomain and path using preg_match
            if (preg_match($pattern, $url, $matches)) {
                $subdomain = $matches[1] ?? ''; // Capture the subdomain if present

                // If no subdomain is defined, default to DOMAIN_CURRENT_SITE's subdomain
                if (empty($subdomain)) {
                    $default_site_host = str_replace($this->wp_base_domain['without_port'], '', $this->wp_default_site);
                    $subdomain = trim($default_site_host, '.'); // Remove leading/trailing dots
                }

                // Construct the rewritten URL
                $rewrittenURL = $this->scheme . '://' . $subdomain . '.' . $this->wp_base_domain['with_port'] . ($matches[2] ?? '');

                // Cache the rewritten URL
                $this->rewrite_cache[$url] = $rewrittenURL . ($query_string ?? '');
                error_log("Rewrote URL with missing port from $url to $rewrittenURL");

                return $this->rewrite_cache[$url];
            }
        }

        $base_url = $parsed_url['host'] . (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
        $path = $parsed_url['path'] ?? '';
        $query_string = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';

        // Skip if already rewritten to MinIO
        if (strpos($base_url, $this->minio_url) !== false) {
            return $url;
        }

        // Rewrite if in the uploads directory
        if ($path && strpos($path, '/app/uploads/') !== false) {
            $uploads_path = strpos($path, '/app/uploads/sites/') !== false
                ? "/sites/{$current_blog->blog_id}"
                : '';

            $rewrittenURL = str_replace(
                $this->uploads_baseurl,
                "{$this->minio_url}/{$this->minio_bucket}$uploads_path",
                $url
            );

            // Cache the rewritten URL
            $this->rewrite_cache[$url] = $rewrittenURL . $query_string;
            error_log("Rewrite media URL from $url to $rewrittenURL");

            return $this->rewrite_cache[$url];
        } elseif (!strpos($url, $this->subdomain_suffix . "." . $this->wp_base_domain['with_port'])) {

            // Replace only URLs containing the production domain
            $pattern = "/(?:https:\/\/|http:\/\/)?(?:([a-zA-Z0-9_-]+)\.)?" . preg_quote($this->wp_production_domain, '/') . "(?::[0-9]+)?(\/.*)?/";
            $replacement = $this->scheme . '://${1}' . $this->subdomain_suffix . '.' . $this->wp_base_domain['with_port'] . '${2}';
            $rewrittenURL = preg_replace($pattern, $replacement, $url);
            $this->rewrite_cache[$url] = $rewrittenURL . $query_string;
            error_log("Rewrite specific URL from $url to $rewrittenURL");
            return $this->rewrite_cache[$url];
        }

        // If already pointing to localhost without MinIO
        $this->rewrite_cache[$url] = $url;
        return $url;
    }

    /**
     * Check if the given URL matches an override pattern.
     *
     * @param string $override
     * @param string $url
     * @return bool
     */
    private function matchOverride($override, $url): bool
    {
        // Ensure the URL has a scheme
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['scheme'])) {
            $url = 'http://' . ltrim($url, '/');
        }

        // Handle wildcard patterns
        if (strpos($override, '*') !== false) {
            // If override does not include a scheme, add a scheme to match dynamically
            if (!preg_match('/^https?:\/\//', $override)) {
                $override = 'https?://' . ltrim($override, '/');
            }
            $escaped_override = preg_quote($override, '/');

            // Restore the regex characters like `?` that were escaped
            $escaped_override = str_replace(['\?', '\*'], ['?', '.*'], $escaped_override);
            $pattern = '/^' . $escaped_override . '$/';
            return (bool) preg_match($pattern, $url);
        }

        // Handle regex patterns explicitly (starting with `/`)
        if (strpos($override, '/') === 0) {
            return (bool) preg_match($override, $url);
        }

        // Handle exact matches
        if (!preg_match('/^https?:\/\//', $override)) {
            // Add default scheme for exact match if missing
            $override = 'http://' . ltrim($override, '/');
        }
        return $override === $url;
    }

    /**
     * Rewrites the site URL, applying only necessary transformations.
     */
    public function rewriteSiteURL($url)
    {
        return $this->rewriteURL($url);
    }
}


/**
 * Extracts the base domain from a given URL, stripping subdomains if applicable,
 * and appends the port unless it is 80 (HTTP) or 443 (HTTPS).
 *
 * This function handles multi-level domains (e.g., example.co.uk) and preserves
 * non-standard ports if specified in the URL. If no valid host is found, it logs an error.
 *
 * @param string $url The URL from which to extract the base domain and port.
 * @return string|null The base domain with the port appended (if present and not implied), or null on failure.
 */
function get_base_domain($url)
{
    $parsed_url = parse_url($url);
    if (!isset($parsed_url['host'])) {
        error_log("Invalid URL: $url");
        return null;
    }

    $host = $parsed_url['host'];
    $port = isset($parsed_url['port']) && !in_array($parsed_url['port'], [80, 443])
        ? ':' . $parsed_url['port']
        : '';

    // Split the host into parts
    $host_parts = explode('.', $host);

    // Determine the base domain based on common patterns
    $num_parts = count($host_parts);
    if (strpos($host, 'localhost')) {
        $base_domain = 'localhost';
    } elseif ($num_parts > 2) {
        // Handle domains like example.co.uk
        $base_domain = implode('.', array_slice($host_parts, -2));
        if (in_array($host_parts[$num_parts - 2], ['co', 'gov', 'ac'])) {
            $base_domain = implode('.', array_slice($host_parts, -3));
        }
    } else {
        // For domains like example.com
        $base_domain = $host;
    }

    return [
        'with_port' => $base_domain . $port,
        'without_port' => $base_domain,
    ];
}
