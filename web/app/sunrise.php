<?php

use Roots\WPConfig\Config;

// Access environment and configuration values
$environment = defined('WP_ENV') ? WP_ENV : '';
$wp_home = Config::get('WP_HOME') ?: 'http://localhost';
$nginx_port = Config::get('NGINX_PORT') ? ':' . Config::get('NGINX_PORT') : '';
$subdomain_suffix = Config::get('SUBDOMAIN_SUFFIX') ?: '';

// Only proceed for development or staging environments
if ($environment === 'development' || $environment === 'staging') {
    global $current_site, $current_blog, $wpdb;

    // Parse the domain and subdomain from the request
    $domain = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $base_domain = parse_url($wp_home, PHP_URL_HOST);
    $base_domain_with_port = $base_domain . $nginx_port;
    $subdomain = null;

    // Extract the subdomain if it differs from the base domain
    if (strpos(explode('.', $domain)[0], $base_domain_with_port) === false) {
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
        'domain' => $base_domain_with_port,
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
            ? $subdomain . $subdomain_suffix . '.' . $base_domain_with_port
            : $base_domain_with_port,
        'path' => Config::get('PATH_CURRENT_SITE') ?: '/',
        'public' => 1,
        'archived' => 0,
        'mature' => 0,
        'spam' => 0,
        'deleted' => 0,
        'lang_id' => 0,
    ];

    // Set COOKIE_DOMAIN to handle subdomains dynamically, including suffix
    $cookie_domain = $subdomain
        ? $subdomain . $subdomain_suffix . '.' . $base_domain
        : $base_domain;
    define('COOKIE_DOMAIN', $cookie_domain);

    // Debugging log to confirm settings in development
    if ($environment === 'development') {
        error_log("SUNRISE: Detected subdomain '{$subdomain}', setting current_blog->domain to {$current_blog->domain}, blog_id to {$blog_id}, site_id to {$site_id}");
    }
}
