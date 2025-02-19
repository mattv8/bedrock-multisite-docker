<?php

use Roots\WPConfig\Config;

// Access environment and configuration values
$environment = defined('WP_ENV') ? WP_ENV : '';
$wp_home = Config::get('WP_HOME') ?: 'http://localhost';
$nginx_port = Config::get('NGINX_PORT') ? ':' . Config::get('NGINX_PORT') : '';
$subdomain_suffix = Config::get('SUBDOMAIN_SUFFIX') ?: '';
$default_site = Config::get('DOMAIN_CURRENT_SITE') ?: '';
$log_rewrites = Config::get('LOG_REWRITES');

// Only proceed for development or staging environments
if ($environment === 'development' || $environment === 'staging') {
    global $current_site, $current_blog, $wpdb;

    // Parse the domain and subdomain from the request
    $domain = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $wp_base_domain = get_base_domain($wp_home);

    // Extract the subdomain if it differs from the base domain
    $subdomain = null;
    if (strpos(explode('.', $domain)[0], $wp_base_domain['with_port']) === false) {
        $subdomain = explode('.', $domain)[0];
        if ($subdomain_suffix && str_ends_with($subdomain, $subdomain_suffix)) {
            $subdomain = substr($subdomain, 0, -strlen($subdomain_suffix));
        }
    }

    // If subdomain exists, fetch corresponding blog_id and site_id from the database
    $site_id = $blog_id = 1; // Default site ID for the main network
    if ($subdomain) {
        // Fetch blog ID for the subdomain
        $querystring = "SELECT blog_id, site_id FROM {$wpdb->blogs} WHERE domain LIKE %s AND path = '/'";
        $blog = $wpdb->get_row($wpdb->prepare($querystring, $subdomain . '%'));

        // Update blog_id and site_id if found
        if ($blog) {
            $blog_id = (int) $blog->blog_id;
            $site_id = (int) $blog->site_id;
        }
    }

    // Set $current_site and $current_blog based on environment variables and dynamic values
    $current_site = (object) [
        'id' => $site_id,
        'domain' => $subdomain
            ? $subdomain . $subdomain_suffix . '.' . $wp_base_domain['without_port']
            : $wp_base_domain['without_port'], // Use base domain if no subdomain
        'path' => Config::get('PATH_CURRENT_SITE') ?: '/',
        'blog_id' => $blog_id,
        'public' => 1,
        'archived' => 0,
        'mature' => 0,
        'spam' => 0,
        'deleted' => 0,
        'site_id' => $site_id,
    ];

    $current_blog = (object) [
        'blog_id' => $blog_id,
        'site_id' => $site_id,
        'domain' => $subdomain
            ? $subdomain . $subdomain_suffix . '.' . $wp_base_domain['without_port']
            : $wp_base_domain['without_port'],
        'path' => Config::get('PATH_CURRENT_SITE') ?: '/',
        'public' => 1,
        'archived' => 0,
        'mature' => 0,
        'spam' => 0,
        'deleted' => 0,
        'lang_id' => 0,
    ];

    // Debugging log to confirm settings in development
    if ($environment === 'development') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
        $final_url = "$scheme://$domain" . "$uri";
        if (Config::get('LOG_REWRITES')) {
            error_log("[SUNRISE]: blog_id=$blog_id, site_id=$site_id, Rewritten URL: $final_url");
        }
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
function get_base_domain($url) {
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
