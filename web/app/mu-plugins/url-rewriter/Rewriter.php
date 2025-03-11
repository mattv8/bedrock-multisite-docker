<?php

/**
 * Class URLFixer
 *
 * @package mattv8\URLFixer
 * @author  mattv8
 * @link    https://github.com/mattv8/multisite-url-fixer
 */

namespace URL;

use Roots\WPConfig\Config;

use function Env\env;

/**
 * Class Rewriter
 *
 * Handles URL rewriting for WordPress uploads and MinIO/S3 integration.
 * Ensures correct domain handling, subdomain support, and logging of rewrites.
 */
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
    protected $port;
    protected $wp_production_domain;
    protected $log_rewrites;

    /**
     * Rewriter constructor.
     *
     * Initializes domain settings, upload paths, and MinIO/S3 configuration.
     * Fetches base domain and port settings from configuration.
     */
    public function __construct()
    {
        // Uploads Directory
        // Fetch uploads directory base URL once to avoid recursion issues
        $uploads_dir = wp_get_upload_dir();
        $this->uploads_baseurl = $uploads_dir['baseurl']; // e.g. http://localhost:81/app/uploads/sites/3
        $this->uploads_path = str_replace("/app/uploads/", "", parse_url($uploads_dir['url'], PHP_URL_PATH)); // e.g. sites/3/2024/11

        // MinIO or S3 Rewrites
        $this->minio_url = Config::get('MINIO_URL', '');
        $this->minio_bucket = Config::get('MINIO_BUCKET', '');

        // Domain Configuration
        // Fetch and parse domain-related settings
        $this->subdomain_suffix = Config::get('SUBDOMAIN_SUFFIX') ?: '';
        $this->port = Config::get('NGINX_PORT') ?: '';
        $wp_home = Config::get('WP_HOME', 'http://localhost');
        $this->wp_base_domain = $this->get_base_domain($wp_home);
        $this->scheme = parse_url($wp_home, PHP_URL_SCHEME);

        // Production Domain
        // Load the production domain from the environment
        $this->wp_production_domain = Config::get('WP_PRODUCTION_DOMAIN', ''); // e.g. example.com
        if (!$this->wp_production_domain) {
            error_log("[Rewriter]: WP_PRODUCTION_DOMAIN is not set. Please check your .env file.");
        }

        // Cookies
        $this->set_cookie_domain();

        // Whether to log rewrite information
        $this->log_rewrites = Config::get('LOG_REWRITES') ?: false;
        $this->wp_default_site = Config::get('DOMAIN_CURRENT_SITE', '');
    }

    /**
     * Registers WordPress filters to rewrite URLs.
     *
     * This method hooks into various WordPress filters to modify URLs dynamically.
     * It ensures URLs are correctly rewritten for multisite setups, media uploads,
     * redirects, and asset loading (scripts, styles, plugins).
     *
     * @return void
     */
    public function add_filters()
    {
        // Primary Site URL Rewrites
        add_filter('option_home', [$this, 'rewrite_site_url']);
        add_filter('option_siteurl', [$this, 'rewrite_site_url']);

        // Multisite URL Rewrites
        if (is_multisite()) {
            add_filter('network_site_url', [$this, 'rewrite_site_url']);
            add_filter('network_admin_url', [$this, 'rewrite_site_url']);
        }

        // Redirect URL Rewrites
        add_filter('login_redirect', [$this, 'rewrite_site_url']);
        add_filter('wp_redirect', [$this, 'rewrite_site_url']);

        // Asset URL Rewrites
        add_filter('script_loader_src', [$this, 'rewrite_site_url']);
        add_filter('style_loader_src', [$this, 'rewrite_site_url']);
        add_filter('plugins_url', [$this, 'rewrite_site_url']);
    }

    /**
     * Rewrites the site URL by applying necessary transformations.
     *
     * This method acts as a wrapper to the `rewrite_url` method, passing the given URL through
     * to the core URL rewriting logic. It ensures that the necessary transformations, such as
     * path corrections, media URL rewrites, and domain adjustments, are applied to the provided URL.
     *
     * @param mixed $url The URL to be rewritten.
     *
     * @return string The rewritten URL after applying the transformations.
     */
    public function rewrite_site_url(mixed $url)
    {

        // If $url is a string, rewrite it directly
        if (is_string($url)) {
            return $this->rewrite_url($url);
        }

        // If $url is an array, such as in a scrset, recursively rewrite each URL in the array
        if (is_array($url)) {
            foreach ($url as $key => $single_url) {
                if (is_string($single_url)) {
                    $url[$key] = $this->rewrite_url($single_url);
                }
            }
            return $url;
        }

        // If $url is neither a string nor an array, return the original value
        return $url;
    }

    /**
     * Rewrites URLs for media and site content, handling various conditions such as path corrections,
     * media URL rewrites to MinIO, and domain and subdomain adjustments.
     *
     * This method processes a given URL (or an array of URLs), performs checks to ensure it's a valid
     * URL, and rewrites it based on conditions such as:
     * - Removal of the `/wp/` path segment if present.
     * - Rewriting media URLs to point to MinIO if applicable.
     * - Correcting missing or incorrect schemes and ports.
     * - Handling multisite installs by appending the appropriate `blog_id` to media URLs.
     *
     * The method checks if a URL has been rewritten before by looking up its cache, ensuring that the
     * same URL is not processed multiple times. The URL is rewritten only if certain conditions are met
     * based on the configured base domain, production domain, and subdomain suffix.
     *
     * @param string $url The URL(s) to be rewritten. Can be a single URL or an array of URLs (e.g., for srcset).
     *
     * @return string The rewritten URL(s), or the original URL if no rewriting was needed.
     */
    protected function rewrite_url(string $url)
    {
        global $current_blog;

        // First, check cache to prevent repeated rewrites
        if (isset($this->rewrite_cache[$url])) {
            return $this->rewrite_cache[$url];
        }

        $parsed_url = parse_url($url);

        // Gut-check if this is a valid URL before proceeding
        if (!isset($parsed_url['host']) || !isset($parsed_url['scheme'])) {
            $this->rewrite_cache[$url] = $url;
            return $url;
        }

        // Extract URL components to be used for later processing
        $host = $parsed_url['host'] . (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
        $path = $parsed_url['path'] ?? '';
        $query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';

        // Remove '/wp/' or a trailing '/wp' from the URL path
        $pattern = '#/wp(/|$)#';
        if ($path && preg_match($pattern, $path)) {
            $rewritten_url = preg_replace($pattern, '', $url, 1);

            if ($rewritten_url !== $url) {
                if ($this->log_rewrites) {
                    error_log("[Rewriter]: Fixed path from: $url to $rewritten_url");
                }
                $this->rewrite_cache[$url] = $this->rewrite_url($rewritten_url);
                return $this->rewrite_cache[$url];
            }
        }

        // Rewrite any media URL's to MinIO
        $pattern = '#(app|wp-content|wp-includes)/uploads(/|$)#';
        if ($path && preg_match($pattern, $path)) {
            // Check that minio parameters have been set before continuing
            if (empty($this->minio_url) || empty($this->minio_bucket) || strpos($host, $this->minio_url) !== false) {
                $this->rewrite_cache[$url] = $url;
                return $this->rewrite_cache[$url];
            }

            // Adjust MINIO_URL for local development based on WP_HOME from .env
            if ($this->wp_base_domain['without_port'] === 'localhost' && Config::get("MINIO_PORT")) {
                $this->minio_url = $this->scheme . '://' . $this->wp_base_domain['without_port'] . ':' . Config::get('MINIO_PORT');
            }

            // If this is a multi-site install, we need to include the blog_id
            $upload_prefix = is_multisite() ? 'uploads/sites/' . $current_blog->blog_id : 'uploads';

            // Rewrite the url
            $rewritten_url = str_replace(
                "{$this->uploads_baseurl}",
                "{$this->minio_url}/{$this->minio_bucket}/{$upload_prefix}",
                $url
            );

            // Re-append any query params and cache the $url
            $this->rewrite_cache[$url] = $rewritten_url . $query;
            if ($this->log_rewrites) {
                error_log("[Rewriter]: Rewrite media URL from $url to $rewritten_url");
            }

            return $this->rewrite_cache[$url];
        }

        // Basic check that the URL qualifies as being rewritable
        $base_domain = $this->get_base_domain($url)['with_port'];
        $base_domain_present = in_array($base_domain, [
            $this->wp_production_domain,
            $this->wp_base_domain['with_port'],
            $this->wp_base_domain['without_port']
        ], true);

        // Check if the base domain and subdomain suffix are present in the URL
        $subdomain = $this->get_subdomain($host);
        $suffix_present = $subdomain && strpos($subdomain, $this->subdomain_suffix) !== false;

        if (!$suffix_present && $base_domain_present) {
            // If the scheme is not as expected, correct it.
            $wrong_scheme = $parsed_url['scheme'] !== $this->scheme ? true : false;
            $url_with_scheme = $wrong_scheme ? $this->scheme . '://' . preg_replace('/^https?:\/\//', '', $url) : $url;

            // If the port is missing and needs to be added, correct it.
            $missing_port = !isset($parsed_url['port'])
                && isset($this->port)
                && !in_array($this->port, [80, 443], true)
                && $this->wp_base_domain['without_port'] === 'localhost'
                && strpos($url, $this->wp_base_domain['with_port']) === false;
            $url_with_port = ($missing_port) ? "{$this->scheme}://{$host}:{$this->port}{$path}" : $url_with_scheme;

            // If $url has a subdomain and the suffix is missing, prepend it before the base domain
            $suffix = (!$suffix_present && $subdomain) ? $this->subdomain_suffix . '.' : '';

            // Rewrite the URL by ensuring the correct subdomain and port formatting
            $pattern = "/(?:https:\/\/|http:\/\/)?(?:([a-zA-Z0-9_-]+)\.)?(?:";
            $pattern .= preg_quote($this->wp_production_domain, '/');
            $pattern .= "|";
            $pattern .= preg_quote($this->wp_base_domain['without_port'], '/');
            $pattern .=")(?::[0-9]+)?(\/.*)?/";

            // Final replacement
            $replacement = $this->scheme . '://${1}' . $suffix . $this->wp_base_domain['with_port'] . '${2}';

            // Apply the rewrite and cache the new URL
            $rewritten_url = preg_replace($pattern, $replacement, $url_with_port);
            $this->rewrite_cache[$url] = $rewritten_url;

            if ($this->log_rewrites) {
                error_log("[Rewriter]: Rewrite specific URL from $url to $rewritten_url");
            }

            return $this->rewrite_cache[$url];
        }

        // Fallback if no other conditions are met
        $this->rewrite_cache[$url] = $url;
        return $url;
    }

    /**
     * Sets the COOKIE_DOMAIN for WordPress authentication cookies.
     *
     * This method determines the appropriate cookie domain based on the current
     * HTTP host and configured base domain. If a subdomain is detected and
     * does not match the base domain, it is included in the cookie domain.
     *
     * Constants Defined:
     * - COOKIE_DOMAIN: The computed domain for setting authentication cookies.
     * - ADMIN_COOKIE_PATH: Ensures admin cookies are scoped correctly.
     *
     * Logs the computed COOKIE_DOMAIN if logging is enabled.
     *
     * @return void
     */
    protected function set_cookie_domain(): void
    {
        $domain = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $parts = explode('.', $domain);
        $subdomain = (strpos($parts[0], $this->wp_base_domain['with_port']) === false) ? $parts[0] : null;

        if ($subdomain && $this->subdomain_suffix && str_ends_with($subdomain, $this->subdomain_suffix)) {
            $subdomain = substr($subdomain, 0, -strlen($this->subdomain_suffix));
        }

        $cookie_domain = $subdomain
            ? "$subdomain{$this->subdomain_suffix}.{$this->wp_base_domain['without_port']}"
            : $this->wp_base_domain['without_port'];

        // Check COOKIE_DOMAIN
        if (defined('COOKIE_DOMAIN')) {
            if (COOKIE_DOMAIN !== $cookie_domain) {
                error_log("[Rewriter] COOKIE_DOMAIN mismatch: defined as " . COOKIE_DOMAIN . " but expected $cookie_domain");
            }
        } else {
            define('COOKIE_DOMAIN', $cookie_domain);
        }

        // Check ADMIN_COOKIE_PATH
        if (defined('ADMIN_COOKIE_PATH')) {
            if (ADMIN_COOKIE_PATH !== '/wp-admin') {
                error_log("[Rewriter] ADMIN_COOKIE_PATH mismatch: defined as " . ADMIN_COOKIE_PATH . " but expected /wp-admin");
            }
        } else {
            define('ADMIN_COOKIE_PATH', '/wp-admin');
        }

        if ($this->log_rewrites) {
            error_log("[Rewriter]: COOKIE_DOMAIN: $cookie_domain");
        }
    }


    /**
     * Extracts the base domain from a given URL, stripping subdomains if applicable,
     * and appends the port unless it is 80 (HTTP) or 443 (HTTPS).
     *
     * This function handles multi-level domains (e.g., example.co.uk) and preserves
     * non-standard ports if specified in the URL. If no valid host is found, it logs an error.
     *
     * @param  string $url The URL from which to extract the base domain and port.
     * @return string|null The base domain with the port appended (if present and not implied), or null on failure.
     */
    protected function get_base_domain(string $url)
    {
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['host'])) {
            error_log("[Rewriter]: Invalid URL: $url");
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
        if (strpos($host, 'localhost') !== false) {
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

    /**
     * Extracts the subdomain from a given URL.
     *
     * This function checks if the provided URL contains a subdomain. It returns `true` and
     * the subdomain part (with any trailing dot removed) if a subdomain exists. If no subdomain
     * is found, it returns `false` and an empty string.
     *
     * The URL can optionally include a scheme (http or https) and port number.
     *
     * Example:
     * getSubdomain("http://docs.example.com:8080") will return 'docs'.
     * getSubdomain("example.com") will return false.
     *
     * @param  string $host The $host to check for a subdomain, without a path (e.g. parse_url($url, PHP_URL_HOST)).
     * @return string|boolean Either a boolean indicating if a subdomain is present, or a string
     *                        containing the subdomain .
     */
    private function get_subdomain(string $host)
    {
        // Extract host from URL
        if (!$host || filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return false;
        }

        // Match subdomain and domain
        if (preg_match('/^((?:[a-z0-9\-]+\.)+)([a-z0-9\-]{2,}(?:\.[a-z]{2,6})?)(?::\d+)?$/i', $host, $matches)) {
            return rtrim($matches[1], '.'); // Return only the subdomain
        }
        return false; // No subdomain found
    }
}
