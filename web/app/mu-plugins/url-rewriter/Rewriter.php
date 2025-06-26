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
    protected $minio_proxy;
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
    protected $bypass_urls = []; // New property to store bypass URLs

    /**
     * Rewriter constructor.
     *
     * Initializes domain settings, upload paths, and MinIO/S3 configuration.
     * Fetches base domain and port settings from configuration.
     */
    public function __construct() {
        // Uploads Directory
        // Fetch uploads directory base URL once to avoid recursion issues
        $uploads_dir = wp_get_upload_dir();
        $this->uploads_baseurl = $uploads_dir['baseurl']; // e.g. http://localhost:81/app/uploads/sites/3
        $this->uploads_path = str_replace("/app/uploads/", "", parse_url($uploads_dir['url'], PHP_URL_PATH)); // e.g. sites/3/2024/11

        // MinIO or S3 Rewrites
        $this->minio_url = Config::get('MINIO_URL', '');
        $this->minio_bucket = Config::get('MINIO_BUCKET', '');
        $this->minio_proxy = Config::get('MINIO_PROXY', '');

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

        // Load bypass URLs
        $this->bypass_urls = Config::get('BYPASS_URLS', []);

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
    public function add_filters() {
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
    public function rewrite_site_url(mixed $url) {

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
    protected function rewrite_url(string $url) {

        // First, check cache to prevent repeated rewrites
        if (isset($this->rewrite_cache[$url])) {
            return $this->rewrite_cache[$url];
        }

        // Check if the URL should be bypassed
        if ($this->should_bypass_url($url)) {
            if ($this->log_rewrites) {
                error_log("[Rewriter]: Bypassing URL rewrite for: $url");
            }
            $this->rewrite_cache[$url] = $url;
            return $url;
        }

        $parsed_url = parse_url($url);

        // Gut-check if this is a valid URL before proceeding
        if (!isset($parsed_url['host']) || !isset($parsed_url['scheme'])) {
            $this->rewrite_cache[$url] = $url;
            return $url;
        }

        // Remove '/wp/' or a trailing '/wp' from the URL path
        $fixed_wp = $this->fix_wp_path($url, $parsed_url);
        if ($fixed_wp !== $url) {
            $this->rewrite_cache[$url] = $this->rewrite_url($fixed_wp);
            return $this->rewrite_cache[$url];
        }

        // Fix the scheme if it doesn't match the configured scheme
        $fixed_scheme = $this->fix_scheme($url, $parsed_url);
        if ($fixed_scheme !== $url) {
            $this->rewrite_cache[$url] = $this->rewrite_url($fixed_scheme);
            return $this->rewrite_cache[$url];
        }

        // Rewrite media URLs
        $media_url = $this->rewrite_media_url($url, $parsed_url);
        if ($media_url !== false) {
            $this->rewrite_cache[$url] = $media_url;
            return $media_url;
        }

        // For development or staging environments
        if (defined('WP_ENV') && in_array(WP_ENV, ['development', 'staging'], true)) {
            $dev_url = $this->rewrite_dev_url($url, $parsed_url);
            if ($dev_url !== false) {
                $this->rewrite_cache[$url] = $dev_url;
                return $dev_url;
            }
        }

        $this->rewrite_cache[$url] = $url;
        return $url;
    }

    /**
     * Modifies the provided URL by removing '/wp/' or a trailing '/wp' from its path.
     *
     * This method examines the path component of the given URL to determine if it contains
     * '/wp/' or ends with '/wp'. If such a segment is found, it is removed from the URL.
     * If a rewrite occurs and logging is enabled, the method logs both the original and
     * modified URLs for debugging purposes.
     *
     * @param  string $url        The original URL to be processed.
     * @param  array  $parsed_url The parsed components of the URL, used to identify '/wp/'.
     * @return string The updated URL with '/wp/' removed, or the original URL if no changes were necessary.
     */
    private function fix_wp_path(string $url, array $parsed_url): string {
        // This pattern matches '/wp' (with an optional trailing slash) right after the scheme and host,
        // replacing it with a single slash.
        $pattern = '~/wp(?:/|$)~';
        $rewritten_url = preg_replace($pattern, '', $url, 1);
        if ($rewritten_url !== $url && $this->log_rewrites) {
            error_log("[Rewriter]: Fixed path from: $url to $rewritten_url");
        }
        return $rewritten_url;
    }

    /**
     * Ensures the URL has the correct scheme based on the configuration.
     *
     * This method checks if the scheme of the provided URL matches the expected scheme
     * and corrects it if necessary. It logs the change if logging is enabled.
     *
     * @param  string $url        The original URL to be processed.
     * @param  array  $parsed_url The parsed components of the URL.
     * @return string The URL with the corrected scheme.
     */
    private function fix_scheme(string $url, array $parsed_url): string {
        $wrong_scheme = $parsed_url['scheme'] !== $this->scheme;
        $url_with_scheme = $wrong_scheme ? $this->scheme . '://' . preg_replace('/^https?:\/\//', '', $url) : $url;

        if ($url !== $url_with_scheme && $this->log_rewrites) {
            error_log("[Rewriter]: Fixed scheme from: $url to $url_with_scheme");
        }

        return $url_with_scheme;
    }

    /**
     * Rewrites the media URL to point to the configured MINIO bucket if applicable.
     *
     * This method checks if the provided URL matches the pattern for media uploads
     * and rewrites it to use the MINIO bucket URL if the necessary configuration
     * parameters are set. It also handles adjustments for local development and
     * multi-site installations.
     *
     * @param string $url        The original media URL.
     * @param array  $parsed_url The parsed components of the URL.
     *
     * @return string|false The rewritten URL if applicable, or false if no rewrite occurred.
     */
    protected function rewrite_media_url(string $url, array $parsed_url): string|bool {
        $pattern = '#(app|wp-content|wp-includes)/uploads(/|$)#';
        if (!empty($parsed_url['path']) && preg_match($pattern, $parsed_url['path'])) {

            // Extract URL components to be used for later processing
            $host = $parsed_url['host'] . (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
            $query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';

            // If MINIO parameters are missing or the URL already contains MINIO, skip rewriting.
            if (empty($this->minio_url) || empty($this->minio_bucket) || strpos($host, $this->minio_url) !== false) {
                return $url;
            }

            // Adjust MINIO_URL for local development based on WP_HOME from .env
            if ($this->wp_base_domain['without_port'] === 'localhost' && Config::get("MINIO_PORT")) {
                $this->minio_url = $this->scheme . '://' . $this->wp_base_domain['without_port'] . ':' . Config::get('MINIO_PORT');
            }

            // If this is a multi-site install, we need to include the blog_id
            $uploader = new \URL\Uploader();
            $upload_prefix = $uploader->get_upload_path_prefix(false);

            // Construct the CDN endpoint URL depending on if proxied or not
            $cdn_endpoint = ($this->minio_proxy) ? "{$this->minio_proxy}" : "{$this->minio_url}/{$this->minio_bucket}";

            // Rewrite the url
            $rewritten_url = str_replace(
                "{$this->uploads_baseurl}",
                "{$cdn_endpoint}/{$upload_prefix}",
                $url
            );

            // Only log if the URL actually changed
            if ($rewritten_url !== $url && $this->log_rewrites) {
                error_log("[Rewriter]: Rewrite media URL from $url to {$rewritten_url}{$query}");
            }
            return $rewritten_url . $query;
        }
        return false;
    }

    /**
     * Rewrites a given URL to include the appropriate subdomain suffix and port formatting
     * based on the provided host and internal configuration.
     *
     * This method ensures that URLs are correctly formatted for development environments
     * by checking the base domain, subdomain suffix, and specific paths. If the URL matches
     * certain criteria, it is rewritten to include the correct subdomain and port.
     *
     * @param  string $url        The URL to be rewritten.
     * @param  array  $parsed_url The parsed components of the URL.
     * @return string|false The rewritten URL if applicable, or false if no rewrite is performed.
     */
    protected function rewrite_dev_url(string $url, array $parsed_url, ): string|bool {

        // Extract URL components to be used for later processing
        $host = $parsed_url['host'] . (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
        $path = $parsed_url['path'] ?? '';
        $base_domain_present = $this->is_base_domain_present($url);

        // Only rewrite URLs that contain the base domain
        if ($base_domain_present) {

            // If the port is missing and needs to be added, correct it.
            $missing_port = !isset($parsed_url['port'])
                && isset($this->port)
                && !in_array($this->port, [80, 443], true)
                && $this->wp_base_domain['without_port'] === 'localhost'
                && strpos($url, $this->wp_base_domain['with_port']) === false;
            $url_with_port = ($missing_port) ? "{$this->scheme}://{$host}:{$this->port}{$path}" : $url;
            if ($url !== $url_with_port && $this->log_rewrites){
                error_log("[Rewriter]: Fixed missing port from: $url to $url_with_port");
            }

            // Check if the base domain and subdomain suffix are present in the URL
            $subdomain = $this->get_subdomain($host);
            $suffix_present = $subdomain && strpos($subdomain, $this->subdomain_suffix) !== false;

            if (!$suffix_present) {
                // If $url has a subdomain and the suffix is missing, prepend it before the base domain
                $suffix = ($subdomain && !$suffix_present) ? $this->subdomain_suffix . '.' : '';

                // Rewrite the URL by ensuring the correct subdomain and port formatting
                $pattern = "/(?:https:\/\/|http:\/\/)?(?:([a-zA-Z0-9_-]+)\.)?(?:";
                $pattern .= preg_quote($this->wp_production_domain, '/');
                $pattern .= "|";
                $pattern .= preg_quote($this->wp_base_domain['without_port'], '/');
                $pattern .=")(?::[0-9]+)?(\/.*)?/";

                // Final replacement
                $replacement = $this->scheme . '://${1}' . $suffix . $this->wp_base_domain['with_port'] . '${2}';
                $rewritten_url = preg_replace($pattern, $replacement, $url_with_port);

                if ($url !== $rewritten_url && $this->log_rewrites) {
                    error_log("[Rewriter]: Rewrite " . (defined('WP_ENV') ? WP_ENV : '') . " URL from $url to $rewritten_url");
                }
                return $rewritten_url;
            }
            return $url_with_port;
        }
        return false;
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
    protected function set_cookie_domain(): void {
        $domain = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $parts = explode('.', $domain);
        $subdomain = (strpos($parts[0], $this->wp_base_domain['with_port']) === false) ? $parts[0] : null;

        if ($subdomain && $this->subdomain_suffix && str_ends_with($subdomain, $this->subdomain_suffix)) {
            $subdomain = substr($subdomain, 0, -strlen($this->subdomain_suffix));
        }

        $cookie_domain = $subdomain
            ? "$subdomain{$this->subdomain_suffix}.{$this->wp_base_domain['without_port']}"
            : $this->wp_base_domain['without_port'];

        $this->ensure_constant_defined('COOKIE_DOMAIN', $cookie_domain);
        $this->ensure_constant_defined('ADMIN_COOKIE_PATH', '/wp-admin');

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
     * @return array|null The base domain with the port appended (if present and not implied), or null on failure.
     */
    protected function get_base_domain(string $url): array|null {
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
    private function get_subdomain(string $host): string|bool {
        // Extract host from URL
        if (!$host || filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return false;
        }

        // Match subdomain and domain
        if (preg_match('/^((?:[a-z0-9\-]+\.){1,1})([a-z0-9\-]{2,}(?:\.[a-z]{2,6})?)(?::\d+)?$/i', $host, $matches)) {
            return rtrim($matches[1], '.'); // Return only the subdomain
        }
        return false; // No subdomain found
    }

    /**
     * Checks if a constant is defined and matches the expected value; defines it if not.
     *
     * @param  string $constant The name of the constant to check or define.
     * @param  mixed  $expected The expected value of the constant.
     * @return void
     */
    private function ensure_constant_defined(string $constant, mixed $expected): void {
        if (defined($constant)) {
            if (constant($constant) !== $expected) {
                error_log("[Rewriter] {$constant} mismatch: defined as " . constant($constant) . " but expected {$expected}");
            }
        } else {
            define($constant, $expected);
        }
    }

    /**
     * Checks if the base domain is present in the given URL.
     *
     * This method extracts the base domain from the provided URL and verifies
     * if it matches the configured production domain or base domain (with or without port).
     *
     * @param  string $url The URL to check for the presence of the base domain.
     * @return boolean True if the base domain is present in the URL, false otherwise.
     */
    private function is_base_domain_present(string $url): bool {
        $base_domain = $this->get_base_domain($url)['with_port'];
        return in_array($base_domain, [
            $this->wp_production_domain,
            $this->wp_base_domain['with_port'],
            $this->wp_base_domain['without_port']
        ], true);
    }

    /**
     * Determines if a URL should bypass the rewriting process based on the bypass rules.
     *
     * This method checks the provided URL against various bypass patterns including:
     * - Exact matches: The URL exactly matches a bypass entry
     * - Scheme-agnostic matches: The URL matches regardless of the scheme (http/https)
     * - Wildcard matches: The URL matches a pattern ending with /* (wildcard)
     * - Regex matches: The URL matches a regex pattern (enclosed in / /)
     *
     * @param  string $url The URL to check against bypass rules.
     * @return boolean True if the URL should bypass rewriting, false otherwise.
     */
    protected function should_bypass_url(string $url): bool {

        // Short-circuit if there are no bypass URLs configured
        if (empty($this->bypass_urls)) {
            return false;
        }

        // Normalize the URL by removing trailing slashes
        $normalized_url = rtrim($url, '/');

        // Create scheme-agnostic version of the URL (without http:// or https://)
        $scheme_agnostic_url = preg_replace('/^https?:\/\//', '', $normalized_url);

        foreach ($this->bypass_urls as $bypass_pattern) {
            // Check for exact match
            if ($bypass_pattern === $normalized_url) {
                return true;
            }

            // Create scheme-agnostic version of the bypass pattern
            $pattern_without_scheme = preg_replace('/^(https?:\/\/|\/\/)/', '', $bypass_pattern);

            // Check for scheme-agnostic match
            if (!preg_match('/^(https?:\/\/|\/)/', $bypass_pattern) ||
                strpos($bypass_pattern, '//') === 0) {
                // For patterns without scheme or with // prefix, compare without schemes
                if ($pattern_without_scheme === $scheme_agnostic_url) {
                    return true;
                }
            }

            // Check for wildcard match (if pattern ends with /*)
            if (substr($bypass_pattern, -2) === '/*') {
                $wildcard_base = substr($bypass_pattern, 0, -1); // Remove the *

                // For patterns with explicit scheme
                if (preg_match('/^https?:\/\//', $wildcard_base)) {
                    if (strpos($normalized_url, $wildcard_base) === 0) {
                        return true;
                    }
                }
                // For patterns without scheme or with // prefix
                else {
                    $wildcard_without_scheme = preg_replace('/^(https?:\/\/|\/\/)/', '', $wildcard_base);
                    if (strpos($scheme_agnostic_url, $wildcard_without_scheme) === 0) {
                        return true;
                    }
                }
            }

            // Check for regex match (if pattern is enclosed in / /)
            if (strlen($bypass_pattern) > 2 && $bypass_pattern[0] === '/' && substr($bypass_pattern, -1) === '/') {
                if (preg_match($bypass_pattern, $normalized_url)) {
                    return true;
                }
            }
        }

        return false;
    }

}
