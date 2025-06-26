<?php

/**
 * This base configuration file is customized for the mattv8/bedrock-multisite-docker
 * environment. While it follows Roots/Bedrock conventions, there are key changes here
 * specifically for WordPress Multisite compatibility.
 *
 * Environment-specific overrides still go in their respective config/environments/{{WP_ENV}}.php
 * files, and the production configuration should deviate as little as possible from this file.
 */

use Roots\WPConfig\Config;

use function Env\env;

// USE_ENV_ARRAY + CONVERT_* + STRIP_QUOTES
Env\Env::$options = 31;

/**
 * Directory containing all of the site's files
 *
 * @var string
 */
$root_dir = dirname(__DIR__);

/**
 * Document Root
 *
 * @var string
 */
$webroot_dir = $root_dir . '/web';

/**
 * Use Dotenv to set required environment variables and load .env file in root
 * .env.local will override .env if it exists
 */
if (file_exists($root_dir . '/.env')) {
    $env_files = file_exists($root_dir . '/.env.local')
        ? ['.env', '.env.local']
        : ['.env'];

    $dotenv = Dotenv\Dotenv::createImmutable($root_dir, $env_files, false);

    $dotenv->load();

    $dotenv->required(['WP_HOME', 'WP_SITEURL']);
    if (!env('DATABASE_URL')) {
        $dotenv->required(['DB_NAME', 'DB_USER', 'DB_PASSWORD']);
    }
}

/**
 * Set up our global environment constant and load its config first
 * Default: production
 */
define('WP_ENV', env('WP_ENV') ?: 'production');

/**
 * Infer WP_ENVIRONMENT_TYPE based on WP_ENV
 */
if (!env('WP_ENVIRONMENT_TYPE') && in_array(WP_ENV, ['production', 'staging', 'development', 'local'])) {
    Config::define('WP_ENVIRONMENT_TYPE', WP_ENV);
}

/**
 * URLs and Primary Domain Configuration
 */
$nginx_port = env('NGINX_PORT') ? ':' . env('NGINX_PORT') : '';
$subdomain_suffix = env('SUBDOMAIN_SUFFIX');
$production_domain = env('WP_PRODUCTION_DOMAIN');

// Append the port only if it's a 'localhost' instance; otherwise, assume it's proxied and don't append the port.
$wp_home = (strpos(env('WP_HOME'), 'localhost') !== false) ? env('WP_HOME') . $nginx_port : env('WP_HOME');

// Default DOMAIN_CURRENT_SITE if unset, adjusted for environment
$domain_current_site = env('DOMAIN_CURRENT_SITE') ?: 'localhost';

// Add subdomain suffix dynamically in non-production environments
if (WP_ENV !== 'production' && $subdomain_suffix) {
    $domain_parts = explode('.', $domain_current_site, 2);
    if (count($domain_parts) === 2) {
        $domain_current_site = $domain_parts[0] . $subdomain_suffix . '.' . $domain_parts[1];
    }
}

Config::define('NGINX_PORT', env('NGINX_PORT') ?: '');
Config::define('WP_HOME', $wp_home);
Config::define('WP_SITEURL', $wp_home . '/wp');
Config::define('DOMAIN_CURRENT_SITE', $domain_current_site);
Config::define('WP_PRODUCTION_DOMAIN', $production_domain);
Config::define('SUBDOMAIN_SUFFIX', $subdomain_suffix);

// Validate and log errors for domain configuration
if (!filter_var($domain_current_site, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
    error_log("Invalid DOMAIN_CURRENT_SITE: $domain_current_site");
}

/**
 * Custom Content Directory
 */
Config::define('CONTENT_DIR', '/app');
Config::define('WP_CONTENT_DIR', $webroot_dir . Config::get('CONTENT_DIR'));
Config::define('WP_CONTENT_URL', Config::get('WP_HOME') . Config::get('CONTENT_DIR'));

/**
 * DB settings
 */
if (env('DB_SSL')) {
    Config::define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL);
}

Config::define('DB_NAME', env('DB_NAME'));
Config::define('DB_USER', env('DB_USER'));
Config::define('DB_PASSWORD', env('DB_PASSWORD'));
Config::define('DB_HOST', env('DB_HOST') ?: 'localhost');
Config::define('DB_CHARSET', 'utf8mb4');
Config::define('DB_COLLATE', '');
$table_prefix = env('DB_PREFIX') ?: 'wp_';

if (env('DATABASE_URL')) {
    $dsn = (object) parse_url(env('DATABASE_URL'));

    Config::define('DB_NAME', substr($dsn->path, 1));
    Config::define('DB_USER', $dsn->user);
    Config::define('DB_PASSWORD', isset($dsn->pass) ? $dsn->pass : null);
    Config::define('DB_HOST', isset($dsn->port) ? "{$dsn->host}:{$dsn->port}" : $dsn->host);
}

/**
 * Authentication Unique Keys and Salts
 */
Config::define('AUTH_KEY', env('AUTH_KEY'));
Config::define('SECURE_AUTH_KEY', env('SECURE_AUTH_KEY'));
Config::define('LOGGED_IN_KEY', env('LOGGED_IN_KEY'));
Config::define('NONCE_KEY', env('NONCE_KEY'));
Config::define('AUTH_SALT', env('AUTH_SALT'));
Config::define('SECURE_AUTH_SALT', env('SECURE_AUTH_SALT'));
Config::define('LOGGED_IN_SALT', env('LOGGED_IN_SALT'));
Config::define('NONCE_SALT', env('NONCE_SALT'));

/**
 * Custom Settings
 */
Config::define('AUTOMATIC_UPDATER_DISABLED', true);
Config::define('DISABLE_WP_CRON', env('DISABLE_WP_CRON') ?: false);

// Disable the plugin and theme file editor in the admin
Config::define('DISALLOW_FILE_EDIT', true);

// Disable plugin and theme updates and installation from the admin
Config::define('DISALLOW_FILE_MODS', true);

// Limit the number of post revisions
Config::define('WP_POST_REVISIONS', env('WP_POST_REVISIONS') ?? true);

/**
 * SMTP
 */
Config::define('MAILHOG_SMTP', env('MAILHOG_SMTP') ?? '1025');

/**
 * Debugging Settings
 */
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', false);
Config::define('SCRIPT_DEBUG', false);
Config::define('LOG_REWRITES', env('LOG_REWRITES') ?? false);

ini_set('display_errors', '0');

/**
 * Allow WordPress to detect HTTPS when used behind a reverse proxy or a load balancer
 * See https://codex.wordpress.org/Function_Reference/is_ssl#Notes
 */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

/**
 * Environment-specific Configurations
 */
$env_config = __DIR__ . '/environments/' . WP_ENV . '.php';
if (file_exists($env_config)) {
    include_once $env_config;
}

/**
 * Multisite Network
 */
Config::define('WP_ALLOW_MULTISITE', env('WP_ALLOW_MULTISITE') ?: false);
Config::define('MULTISITE', env('MULTISITE') ?: false);
Config::define('SUBDOMAIN_INSTALL', env('SUBDOMAIN_INSTALL') ?: false);
Config::define('PATH_CURRENT_SITE', env('PATH_CURRENT_SITE') ?: '/');
Config::define('SITE_ID_CURRENT_SITE', env('SITE_ID_CURRENT_SITE') ?: 1);
Config::define('BLOG_ID_CURRENT_SITE', env('BLOG_ID_CURRENT_SITE') ?: 1);
Config::define('SUNRISE', env('SUNRISE') ?: false);

// Offload uploads to minio server, if set
Config::define('MINIO_PORT', env('MINIO_PORT') ?: false);
$minio_url = env('MINIO_URL');
$minio_port = Config::get('MINIO_PORT');

if (strpos($minio_url, '${MINIO_PORT}') !== false) {
    $minio_url = str_replace('${MINIO_PORT}', $minio_port, $minio_url);
}
Config::define('MINIO_URL', $minio_url ?: false);
Config::define('MINIO_BUCKET', env('MINIO_BUCKET') ?: false);
Config::define('MINIO_KEY', env('MINIO_KEY') ?: false);
Config::define('MINIO_SECRET', env('MINIO_SECRET') ?: false);
Config::define('MINIO_CHECKSUMS', env('MINIO_CHECKSUMS') !== null ? env('MINIO_CHECKSUMS') : true);
Config::define('MINIO_PROXY', env('MINIO_PROXY') ?: false);

if (in_array(WP_ENV, ['development'])) {
    // Log the last loaded PHP file
    register_shutdown_function(function () {
        $last_error = error_get_last();

        // Initialize last file and stack trace variables
        $stack_trace = [];
        $base_path = '/var/www/';

        // Check if an error occurred
        if ($last_error) {
            $last_file = $last_error['file'] ?? false; // File from error
            if ($last_file) {
                $last_file = str_replace($base_path, '', $last_file); // Make relative
                $stack_trace[] = sprintf(
                    '%s:%d %s',
                    $last_file,
                    $last_error['line'] ?? 0,
                    '[shutdown error]'
                );
            }
        } else {
            // Use backtrace if no error
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            foreach ($backtrace as $trace) {
                $stack_trace[] = sprintf(
                    '%s:%d %s%s%s',
                    isset($trace['file']) ? str_replace($base_path, '', $trace['file']) : '[internal function]',
                    $trace['line'] ?? 0,
                    $trace['class'] ?? '',
                    $trace['type'] ?? '',
                    $trace['function'] ?? ''
                );
            }
        }

        $log_message = '';

        // Format the log message
        if ($last_error) {
            $log_message = sprintf(
                "[%s] PHP Error: [%d] %s in %s on line %d\n",
                date('d-M-Y H:i:s e'),
                $last_error['type'],
                $last_error['message'],
                $last_error['file'] ?? 'Unknown file',
                $last_error['line'] ?? 0
            );
        }

        if (!empty($stack_trace)) {
            $log_message .= "[Shutdown trace]\n" . implode("\n", $stack_trace) . "\n";
        }

        // Write the message to the debug log
        if (!empty($last_error) && !empty($stack_trace)) {
            error_log($log_message, 3, Config::get('WP_DEBUG_LOG'));
        } else {
            //error_log("[Done]\n", 3, Config::get('WP_DEBUG_LOG'));
        }
    });
}

// Parse BYPASS_URLS from .env into an array
$bypass_urls_string = env('BYPASS_URLS') ?: false;
$bypass_urls = [];
if (!empty($bypass_urls_string)) {
    // Split by comma and trim each entry
    $raw_urls = array_map('trim', explode(',', $bypass_urls_string));

    // Remove empty entries
    $bypass_urls = array_filter($raw_urls, function($url) {
        return !empty($url);
    });
}
Config::define('BYPASS_URLS', $bypass_urls);

/**
 * Apply Configuration
 */
Config::apply();

/**
 * Bootstrap WordPress
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $webroot_dir . '/wp/');
}
