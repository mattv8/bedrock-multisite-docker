<?php

/**
 * Plugin Name: URL Rewrite
 * Description: Rewrites URLs for local development, similar to Roots\Bedrock\URLFixer.
 */

// Load Bedrock's autoload to ensure environment variables are available
require_once(dirname(__DIR__, 4) . '/vendor/autoload.php');
require_once(dirname(__DIR__, 4) . '/config/application.php');
require_once(__DIR__ . '/Rewriter.php');

use URL\Rewriter;
(new Rewriter())->addFilters();
