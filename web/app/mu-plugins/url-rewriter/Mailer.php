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

class Mailer
{

    public function __construct() {}

    /**
     * Add filters to rewrite URLs, including multisite domain filters.
     */
    public function addMailhogActions() {
        add_action('wp_mail_failed', [$this, 'action_wp_mail_failed'], 10, 1);
        add_action('phpmailer_init', [$this, 'set_php_mailer'], 10, 1);
    }

    public function action_wp_mail_failed($wp_error) {
        error_log('[Mail] Failed: ' . print_r($wp_error, true));
    }

    // configure PHPMailer to send through SMTP
    public function set_php_mailer($phpmailer) {

        $phpmailer->isSMTP();
        // host details
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPSecure = '';
        $phpmailer->SMTPAutoTLS = false;
        $phpmailer->Host = 'mailhog';
        $phpmailer->Port = '1025';
        // login details
        $phpmailer->Username = null;
        $phpmailer->Password = null;

        // Debugging Data
        $debug_info = "<br>--- Debugging Information ---<br>";

        // // 1. POST Data
        // if (!empty($_POST)) {
        //     $debug_info .= "<br>--- POST Data ---<br>";
        //     foreach ($_POST as $key => $value) {
        //         $debug_info .= "{$key}: " . print_r($value, true) . "<br>";
        //     }
        // }

        // // 2. GET Parameters
        // if (!empty($_GET)) {
        //     $debug_info .= "<br>--- GET Parameters ---<br>";
        //     foreach ($_GET as $key => $value) {
        //         $debug_info .= "{$key}: " . print_r($value, true) . "<br>";
        //     }
        // }

        // // 3. Session Data
        // if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION)) {
        //     $debug_info .= "<br>--- Session Data ---<br>";
        //     foreach ($_SESSION as $key => $value) {
        //         $debug_info .= "{$key}: " . print_r($value, true) . "<br>";
        //     }
        // }

        // 4. Server Data (User-Agent, IP, Referrer)
        $debug_info .= "<br>--- Server Details ---<br>";
        $debug_info .= "Referrer: " . ($_SERVER['HTTP_REFERER'] ?? 'N/A') . "<br>";
        $debug_info .= "Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A') . "<br>";
        $debug_info .= "Current URL: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "<br>";

        // Append debugging info to email body
        $phpmailer->Body .= $debug_info;
    }
}
