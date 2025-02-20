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
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

use function Env\env;

/**
 * Class Mailer
 *
 * This class is responsible for configuring and handling email sending through SMTP with MailHog,
 * including setting PHPMailer options and logging debugging information on failed mail events.
 */
class Mailer
{
    /**
     * Mailer constructor.
     * Initializes the Mailer class and prepares it for use.
     */
    public function __construct() {}

    /**
     * Adds necessary actions for MailHog email testing.
     * Registers the `wp_mail_failed` and `phpmailer_init` actions for handling failed emails and setting up PHPMailer.
     *
     * @return void
     */
    public function add_mailhog_actions() {
        add_action('wp_mail_failed', [$this, 'action_wp_mail_failed'], 10, 1);
        add_action('phpmailer_init', [$this, 'set_php_mailer'], 10, 1);
    }

    /**
     * Logs failed email attempts.
     *
     * This method is triggered when the `wp_mail_failed` action is fired and logs the error information
     * about the failure to the error log.
     *
     * @param  WP_Error $wp_error The error returned by the failed email attempt.
     * @return void
     */
    public function action_wp_mail_failed(WP_Error $wp_error) {
        error_log('[Mail] Failed: ' . print_r($wp_error, true));
    }

    /**
     * Configures PHPMailer to send emails via MailHog's SMTP server.
     *
     * This method is triggered on the `phpmailer_init` action and sets up PHPMailer to use SMTP for sending
     * emails, providing the necessary SMTP configuration, and appending debugging information to the email body.
     *
     * @param  PHPMailer $phpmailer The PHPMailer instance used to send the email.
     * @return void
     *
     * @phpcs:disable WordPress.NamingConventions.ValidVariableName
     *   Ignore the PHPMailer subvariables since we cannot change them.
     */
    public function set_php_mailer(PHPMailer $phpmailer) {
        $phpmailer->isSMTP();
        // host details
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPSecure = '';
        $phpmailer->SMTPAutoTLS = false;
        $phpmailer->Host = 'mailhog';
        $phpmailer->Port = Config::get('MAILHOG_SMTP');
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

        error_log("[Rewriter]: PHPMailer configured for SMTP via MailHog on port " . Config::get('MAILHOG_SMTP') . ". Email sent to: " . implode(', ', (array) $phpmailer->getToAddresses()));
    }
}
