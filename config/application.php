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
 * URLs and Domain Configuration
 */
$nginx_port = env('NGINX_PORT') ? ':' . env('NGINX_PORT') : '';
$subdomain_suffix = env('SUBDOMAIN_SUFFIX');

Config::define('NGINX_PORT', env('NGINX_PORT') ?: '');
// Configure production domain
$production_domain = env('WP_PRODUCTION_DOMAIN');

// Default DOMAIN_CURRENT_SITE if unset, adjusted for environment
$domain_current_site = env('DOMAIN_CURRENT_SITE') ?: 'localhost';
if (WP_ENV === 'production') {
    $domain_current_site = $production_domain ?: $domain_current_site;
}

// Add subdomain suffix dynamically in non-production environments
if (WP_ENV !== 'production' && $subdomain_suffix) {
    $domain_parts = explode('.', $domain_current_site, 2);
    if (count($domain_parts) === 2) {
        $domain_current_site = $domain_parts[0] . $subdomain_suffix . '.' . $domain_parts[1];
    }
}

Config::define('WP_HOME', env('WP_HOME') . $nginx_port);
Config::define('WP_SITEURL', env('WP_HOME') . $nginx_port . '/wp');
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
Config::define('MINIO_URL', env('MINIO_URL') ?: false);
Config::define('MINIO_BUCKET', env('MINIO_BUCKET') ?: false);

if (in_array(WP_ENV, ['development'])) {
    // Log the last loaded PHP file
    register_shutdown_function(function () {
        $lastError = error_get_last();

        // Initialize last file and stack trace variables
        $stackTrace = [];
        $basePath = '/var/www/';

        // Check if an error occurred
        if ($lastError) {
            $lastFile = $lastError['file'] ?? false; // File from error
            if ($lastFile) {
                $lastFile = str_replace($basePath, '', $lastFile); // Make relative
                $stackTrace[] = sprintf(
                    '%s:%d %s',
                    $lastFile,
                    $lastError['line'] ?? 0,
                    '[shutdown error]'
                );
            }
        } else {
            // Use backtrace if no error
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            foreach ($backtrace as $trace) {
                $stackTrace[] = sprintf(
                    '%s:%d %s%s%s',
                    isset($trace['file']) ? str_replace($basePath, '', $trace['file']) : '[internal function]',
                    $trace['line'] ?? 0,
                    $trace['class'] ?? '',
                    $trace['type'] ?? '',
                    $trace['function'] ?? ''
                );
            }
        }

        $logMessage = '';

        // Format the log message
        if ($lastError) {
            $logMessage = sprintf(
                "[%s] PHP Error: [%d] %s in %s on line %d\n",
                date('d-M-Y H:i:s e'),
                $lastError['type'],
                $lastError['message'],
                $lastError['file'] ?? 'Unknown file',
                $lastError['line'] ?? 0
            );
        }

        if (!empty($stackTrace)) {
            $logMessage .= "[Shutdown trace]\n" . implode("\n", $stackTrace) . "\n";
        }

        // Write the message to the debug log
        if (!empty($lastError) && !empty($stackTrace)) {
            error_log($logMessage, 3, Config::get('WP_DEBUG_LOG'));
        } else {
           //error_log("[Done]\n", 3, Config::get('WP_DEBUG_LOG'));
        }
    });
}

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
