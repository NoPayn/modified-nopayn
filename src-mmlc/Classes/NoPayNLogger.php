<?php

namespace CostPlus\NoPayN;

/**
 * Logging utility for the NoPayN payment module.
 *
 * Writes to the shop's log directory with a NoPayn_ prefix.
 * Two modes: error logging (always on) and debug logging (configurable).
 */
class NoPayNLogger
{
    /** @var string Path to the log file */
    private string $logFile;

    /** @var bool Whether debug logging is enabled */
    private bool $debugEnabled;

    public function __construct()
    {
        // Determine log directory — use shop's log dir if available
        $logDir = '';
        if (defined('DIR_FS_LOG')) {
            $logDir = rtrim(DIR_FS_LOG, '/');
        } elseif (defined('DIR_FS_DOCUMENT_ROOT')) {
            $logDir = rtrim(DIR_FS_DOCUMENT_ROOT, '/') . '/log';
        } else {
            $logDir = dirname(__FILE__, 4) . '/log';
        }

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $this->logFile = $logDir . '/nopayn.log';

        // Check if debug logging is enabled via config
        $this->debugEnabled = defined('MODULE_PAYMENT_NOPAYN_DEBUG_LOGGING')
            && constant('MODULE_PAYMENT_NOPAYN_DEBUG_LOGGING') === 'True';
    }

    /**
     * Log an error message (always logged).
     *
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * Log a debug message (only when debug logging is enabled).
     *
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function debug(string $message, array $context = []): void
    {
        if (!$this->debugEnabled) {
            return;
        }
        $this->write('DEBUG', $message, $context);
    }

    /**
     * Log an API request (debug level).
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $body Request body
     */
    public function logApiRequest(string $method, string $endpoint, ?array $body = null): void
    {
        $this->debug('API Request: ' . $method . ' ' . $endpoint, $body !== null ? ['body' => $body] : []);
    }

    /**
     * Log an API response (debug level).
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param int $httpCode HTTP response code
     * @param array $response Decoded response body
     */
    public function logApiResponse(string $method, string $endpoint, int $httpCode, array $response): void
    {
        $this->debug('API Response: ' . $method . ' ' . $endpoint . ' [HTTP ' . $httpCode . ']', ['response' => $response]);
    }

    /**
     * Log an API error (always logged).
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param string $error Error message
     */
    public function logApiError(string $method, string $endpoint, string $error): void
    {
        $this->error('API Error: ' . $method . ' ' . $endpoint . ' - ' . $error);
    }

    /**
     * Log a webhook event (debug level).
     *
     * @param string $event Event description
     * @param array $context Additional context data
     */
    public function logWebhook(string $event, array $context = []): void
    {
        $this->debug('Webhook: ' . $event, $context);
    }

    /**
     * Log a webhook error (always logged).
     *
     * @param string $event Event description
     * @param array $context Additional context data
     */
    public function logWebhookError(string $event, array $context = []): void
    {
        $this->error('Webhook: ' . $event, $context);
    }

    /**
     * Write a log entry to the log file.
     *
     * @param string $level Log level (ERROR, DEBUG)
     * @param string $message Log message
     * @param array $context Additional context data
     */
    private function write(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $entry = '[' . $timestamp . '] NoPayn_' . $level . ': ' . $message;

        if (!empty($context)) {
            $entry .= ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $entry .= PHP_EOL;

        @file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
