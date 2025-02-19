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

    public function __construct() {
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
        $this->wp_base_domain = $this->get_base_domain($wp_home);
        $parsed_wp_home = parse_url($wp_home);
        $this->scheme = $parsed_wp_home['scheme'];

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
     * Add filters to rewrite URLs, including multisite domain filters.
     */
    public function addFilters() {
        add_filter('option_home', [$this, 'rewriteSiteURL']);
        add_filter('option_siteurl', [$this, 'rewriteSiteURL']);

        if (is_multisite()) {
            add_filter('network_site_url', [$this, 'rewriteSiteURL']);
            add_filter('network_admin_url', [$this, 'rewriteSiteURL']);
        }

        // Media-specific filters
        add_filter('upload_dir', [$this, 'rewriteSiteURL']);
        add_filter('login_redirect', [$this, 'rewriteSiteURL']);
        add_filter('wp_redirect', [$this, 'rewriteSiteURL']);

        // Uncomment below lines if filters for scripts, styles, and other items are needed
        add_filter('script_loader_src', [$this, 'rewriteSiteURL']);
        add_filter('style_loader_src', [$this, 'rewriteSiteURL']);

        add_filter('plugins_url', [$this, 'rewriteSiteURL']);
    }

    /**
     * Core function to rewrite URLs for media and site content.
     */
    protected function rewriteURL($url) {
        global $current_blog;

        // Check if $url is an array (for srcset), and apply rewriting to each entry.
        if (is_array($url)) {
            foreach ($url as $key => $single_url) {
                $url[$key] = $this->rewriteURL($single_url); // Recursively rewrite each URL in the srcset array.
            }
            return $url;
        }

        // Check cache to prevent repeated rewrites
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
        $subdomain = $this->get_subdomain($url);

        // Remove '/wp/' or a trailing '/wp' from the URL path
        $pattern = '#/wp(/|$)#';
        if ($path && preg_match($pattern, $path)) {
            $rewrittenURL = preg_replace($pattern, '', $url, 1);

            if ($rewrittenURL !== $url) {
                if ($this->log_rewrites) {
                    error_log("[Rewriter]: Fixed path from: $url to $rewrittenURL");
                }
                $this->rewrite_cache[$url] = $this->rewriteURL($rewrittenURL);
                return $this->rewrite_cache[$url];
            }
        }

        // Rewrite any media URL's to MinIO
        if ($path && strpos($path, '/app/uploads') !== false) {
            if (empty($this->minio_url) || empty($this->minio_bucket) || strpos($host, $this->minio_url) !== false) {
                $this->rewrite_cache[$url] = $url;
                return $this->rewrite_cache[$url];
            }

            $uploads_path = strpos($path, '/app/uploads/sites/') !== false
                ? "/sites/{$current_blog->blog_id}"
                : '';

            $rewrittenURL = str_replace(
                $this->uploads_baseurl,
                "{$this->minio_url}/{$this->minio_bucket}$uploads_path",
                $url
            );

            $this->rewrite_cache[$url] = $rewrittenURL . $query;
            if ($this->log_rewrites) {
                error_log("[Rewriter]: Rewrite media URL from $url to $rewrittenURL");
            }

            return $this->rewrite_cache[$url];
        }

        // Check if the base domain and subdomain suffix are present in the URL
        $base_domain_present_in_url = strpos($url, $this->wp_base_domain['with_port']) !== false;
        $suffix_present_in_url = $subdomain && strpos((string)$subdomain, $this->subdomain_suffix) !== false;

        // Basic check that the URL qualifies as being rewritable
        $rewriteable_url = $this->get_base_domain($url)['with_port'] == $this->wp_production_domain ||
            // $this->get_base_domain($url)['with_port'] == $this->wp_base_domain['with_port'] ||
            $this->get_base_domain($url)['with_port'] == $this->wp_base_domain['without_port'];

        // Determine if the port is missing and needs to be added
        $missing_port = strpos($url, $this->wp_base_domain['with_port']) === false &&
            !isset($parsed_url['port']) &&
            isset($this->port) &&
            $this->wp_base_domain['without_port'] == 'localhost' &&
            ($this->port != 80 && $this->port != 443);

        if (!($base_domain_present_in_url && $suffix_present_in_url) && $rewriteable_url) {

            // If the scheme is not as expected, correct it.
            $wrong_scheme = parse_url($url, PHP_URL_SCHEME) !== $this->scheme ? true : false;
            $url_with_scheme = $wrong_scheme ? $this->scheme . '://' . preg_replace('/^https?:\/\//', '', $url) : $url;

            // If the port is missing, append it to the URL
            $url_with_port = ($missing_port) ? "{$this->scheme}://{$host}:{$this->port}{$path}" : $url_with_scheme;

            // If the suffix is missing, prepend it before the base domain
            $suffix = (!$suffix_present_in_url && $subdomain) ? $this->subdomain_suffix . '.' : '.';

            // Rewrite the URL by ensuring the correct subdomain and port formatting
            $pattern = "/(?:https:\/\/|http:\/\/)?(?:([a-zA-Z0-9_-]+)\.)?(?:";
            $pattern .= preg_quote($this->wp_production_domain, '/');
            $pattern .= "|";
            $pattern .= preg_quote($this->wp_base_domain['without_port'], '/');
            $pattern .=")(?::[0-9]+)?(\/.*)?/";

            $replacement = $this->scheme . '://${1}' . $suffix . $this->wp_base_domain['with_port'] . '${2}';

            // Apply the rewrite and cache the new URL
            $rewrittenURL = preg_replace($pattern, $replacement, $url_with_port);

            if ($this->log_rewrites) {
                error_log("[Rewriter]: Rewrite specific URL from $url to $rewrittenURL");
            }

            $this->rewrite_cache[$url] = $rewrittenURL;
            return $this->rewrite_cache[$url];
        }

        // Fallback if no other conditions are met
        $this->rewrite_cache[$url] = $url;
        return $url;
    }

    /**
     * Rewrites the site URL, applying only necessary transformations.
     */
    public function rewriteSiteURL($url) {
        return $this->rewriteURL($url);
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

        define('COOKIE_DOMAIN', $cookie_domain);
        define('ADMIN_COOKIE_PATH', '/wp-admin');

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
    protected function get_base_domain(string $url) {
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
     * getSubdomain("http://docs.example.com:8080") will return [true, 'docs'].
     * getSubdomain("example.com") will return [false, ''].
     *
     * @param  string $url The URL to check for a subdomain.
     * @return array An array where the first element is a boolean indicating if a subdomain is present,
     *               and the second element is the subdomain (if found), or an empty string if no subdomain exists.
     */
    private function get_subdomain(string $url) {
        // Extract host from URL
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return false;
        }

        // Match subdomain and domain
        if (preg_match('/^((?:[a-z0-9\-]+\.)+)([a-z0-9\-]{2,}(?:\.[a-z]{2,6})?)$/i', $host, $matches)) {
            return rtrim($matches[1], '.'); // Return only the subdomain
        }

        return false; // No subdomain found
    }

}
