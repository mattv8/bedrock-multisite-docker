<?php

namespace WPDieHandler;

new CustomErrorHandler();

class CustomErrorHandler {
    protected $basePath;

    public function __construct($basePath = '/var/www/') {
        $this->basePath = rtrim($basePath, '/') . '/'; // Ensure trailing slash
        // Register the shutdown function
        register_shutdown_function([$this, 'handleShutdown']);

        // Hook into wp_die to log errors
        add_filter('wp_die_handler', [$this, 'customWpDieHandler']);
    }

    /**
     * Handle shutdown and log fatal errors
     */
    public function handleShutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $file = $this->makePathRelative($error['file']);
            error_log('Fatal Error: ' . $error['message'] . ' in ' . $file . ' on line ' . $error['line']);
        }
    }

    /**
     * Custom wp_die handler to log and handle wp_die calls
     */
    public function customWpDieHandler($function) {
        return [$this, 'handleWpDie'];
    }

    /**
     * Custom handler for wp_die with backtrace logging
     */
    public function handleWpDie($message, $title = '', $args = []) {
        // Get debug backtrace
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Extract and format the stack trace
        $stackTrace = [];
        foreach ($backtrace as $index => $trace) {
            $file = isset($trace['file']) ? $this->makePathRelative($trace['file']) : 'unknown file';
            $line = isset($trace['line']) ? $trace['line'] : 'unknown line';
            $function = isset($trace['function']) ? $trace['function'] : 'unknown function';
            $stackTrace[] = "#$index $function called at [$file:$line]";
        }

        // Convert stack trace to a string
        $stackTraceString = implode("\n", $stackTrace);

        // Log the wp_die call and stack trace
        $logMessage = is_wp_error($message) ? $message->get_error_message() : $message;
        error_log("wp_die called: $logMessage");
        error_log("Stack trace:\n$stackTraceString");

        // Call the default wp_die function to maintain WordPress behavior
        _default_wp_die_handler($message, $title, $args);
    }

    /**
     * Adjusts file paths to be relative to the base path
     */
    protected function makePathRelative($filePath) {
        if (strpos($filePath, $this->basePath) === 0) {
            return substr($filePath, strlen($this->basePath));
        }
        return $filePath;
    }
}

// Initialize the custom error handler with your base path
new CustomErrorHandler('/var/www/');
