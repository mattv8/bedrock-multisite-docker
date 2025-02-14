<?php

/**
 * Plugin Name: URL Rewrite
 * Description: Rewrites URLs for local development, similar to Roots\Bedrock\URLFixer.
 */

// Load Bedrock's autoload to ensure environment variables are available
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 4) . '/config/application.php';
require_once __DIR__ . '/Rewriter.php';
require_once __DIR__ . '/Mailer.php';

use URL\Rewriter;
use URL\Mailer;

(new Rewriter())->addFilters();

if (defined('WP_ENV') && in_array(WP_ENV, ['development', 'staging'], true)) {
    (new URL\Mailer())->addMailhogActions();
}
