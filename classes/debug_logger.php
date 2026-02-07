<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Comprehensive debug logger for the AI Quiz Generator plugin.
 *
 * Provides detailed logging to both database and file for debugging purposes.
 * Logs are accessible via the debug_logs.php page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Debug logger class for comprehensive error and event logging.
 */
class debug_logger {
    /** @var string Debug log level. */
    const LEVEL_DEBUG = 'DEBUG';
    /** @var string Info log level. */
    const LEVEL_INFO = 'INFO';
    /** @var string Warning log level. */
    const LEVEL_WARNING = 'WARNING';
    /** @var string Error log level. */
    const LEVEL_ERROR = 'ERROR';
    /** @var string Critical log level. */
    const LEVEL_CRITICAL = 'CRITICAL';

    /** @var string Log file name */
    const LOG_FILE = 'local_hlai_quizgen_debug.log';

    /** @var bool Whether logging is enabled */
    private static $enabled = null;

    /** @var string Log file path */
    private static $logfile = null;

    /**
     * Initialize the logger.
     */
    private static function init(): void {
        global $CFG;

        if (self::$enabled === null) {
            // Always enable logging for now (can be made configurable later).
            self::$enabled = true;

            // Set up log file path in moodledata.
            $logdir = $CFG->dataroot . '/local_hlai_quizgen_logs';
            if (!is_dir($logdir)) {
                @mkdir($logdir, 0777, true);
            }
            self::$logfile = $logdir . '/' . self::LOG_FILE;
        }
    }

    /**
     * Log a debug message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @param int|null $requestid Optional request ID
     */
    public static function debug(string $message, array $context = [], ?int $requestid = null): void {
        self::log(self::LEVEL_DEBUG, $message, $context, $requestid);
    }

    /**
     * Log an info message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @param int|null $requestid Optional request ID
     */
    public static function info(string $message, array $context = [], ?int $requestid = null): void {
        self::log(self::LEVEL_INFO, $message, $context, $requestid);
    }

    /**
     * Log a warning message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @param int|null $requestid Optional request ID
     */
    public static function warning(string $message, array $context = [], ?int $requestid = null): void {
        self::log(self::LEVEL_WARNING, $message, $context, $requestid);
    }

    /**
     * Log an error message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @param int|null $requestid Optional request ID
     */
    public static function error(string $message, array $context = [], ?int $requestid = null): void {
        self::log(self::LEVEL_ERROR, $message, $context, $requestid);
    }

    /**
     * Log a critical message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @param int|null $requestid Optional request ID
     */
    public static function critical(string $message, array $context = [], ?int $requestid = null): void {
        self::log(self::LEVEL_CRITICAL, $message, $context, $requestid);
    }

    /**
     * Log an exception with full stack trace.
     *
     * @param \Throwable $exception The exception to log
     * @param string $component Component where exception occurred
     * @param int|null $requestid Optional request ID
     */
    public static function exception(\Throwable $exception, string $component = 'unknown', ?int $requestid = null): void {
        $context = [
            'component' => $component,
            'exception_class' => get_class($exception),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => self::formattrace($exception->getTraceAsString()),
        ];

        // Check for previous exception.
        if ($exception->getPrevious()) {
            $context['previous_exception'] = $exception->getPrevious()->getMessage();
        }

        self::log(self::LEVEL_ERROR, 'EXCEPTION: ' . $exception->getMessage(), $context, $requestid);
    }

    /**
     * Log AI provider request/response for debugging.
     *
     * @param string $operation Operation type
     * @param string $prompt Prompt sent (truncated)
     * @param mixed $response Response received
     * @param float $duration Request duration in seconds
     * @param int|null $requestid Optional request ID
     */
    public static function ai_request(
        string $operation,
        string $prompt,
        $response,
        float $duration,
        ?int $requestid = null
    ): void {
        $context = [
            'operation' => $operation,
            'prompt_length' => strlen($prompt),
            'prompt_preview' => substr($prompt, 0, 500) . (strlen($prompt) > 500 ? '...' : ''),
            'duration_seconds' => round($duration, 2),
            'response_type' => gettype($response),
        ];

        if (is_object($response)) {
            $context['has_content'] = !empty($response->content);
            $context['content_length'] = strlen($response->content ?? '');
            $context['provider'] = $response->provider ?? 'unknown';
            if (isset($response->tokens)) {
                $context['tokens'] = [
                    'prompt' => $response->tokens->prompt ?? 0,
                    'completion' => $response->tokens->completion ?? 0,
                    'total' => $response->tokens->total ?? 0,
                ];
            }
        } else if ($response === null) {
            $context['response'] = 'NULL';
        }

        self::log(self::LEVEL_INFO, "AI Request: {$operation}", $context, $requestid);
    }

    /**
     * Log AI provider error.
     *
     * @param string $operation Operation type
     * @param string $error Error message
     * @param array $details Additional details
     * @param int|null $requestid Optional request ID
     */
    public static function ai_error(
        string $operation,
        string $error,
        array $details = [],
        ?int $requestid = null
    ): void {
        $context = array_merge([
            'operation' => $operation,
        ], $details);

        self::log(self::LEVEL_ERROR, "AI Error [{$operation}]: {$error}", $context, $requestid);
    }

    /**
     * Log question generation event.
     *
     * @param int $requestid Request ID
     * @param int $topicid Topic ID
     * @param int $questionsgenerated Number of questions generated
     * @param int $questionsrequested Number of questions requested
     * @param array $types Question types
     */
    public static function question_generation(
        int $requestid,
        int $topicid,
        int $questionsgenerated,
        int $questionsrequested,
        array $types
    ): void {
        $context = [
            'topic_id' => $topicid,
            'questions_generated' => $questionsgenerated,
            'questions_requested' => $questionsrequested,
            'question_types' => $types,
            'success' => $questionsgenerated > 0,
        ];

        $level = $questionsgenerated > 0 ? self::LEVEL_INFO : self::LEVEL_WARNING;
        $message = $questionsgenerated > 0
            ? "Generated {$questionsgenerated}/{$questionsrequested} questions for topic {$topicid}"
            : "FAILED to generate questions for topic {$topicid} (0/{$questionsrequested})";

        self::log($level, $message, $context, $requestid);
    }

    /**
     * Log wizard step transition.
     *
     * @param int $step Step number
     * @param string $action Action performed
     * @param int|null $requestid Request ID
     * @param array $data Additional data
     */
    public static function wizard_step(int $step, string $action, ?int $requestid = null, array $data = []): void {
        $context = array_merge([
            'step' => $step,
            'action' => $action,
        ], $data);

        self::log(self::LEVEL_INFO, "Wizard Step {$step}: {$action}", $context, $requestid);
    }

    /**
     * Main logging method.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     * @param int|null $requestid Optional request ID
     */
    public static function log(string $level, string $message, array $context = [], ?int $requestid = null): void {
        self::init();

        if (!self::$enabled) {
            return;
        }

        global $USER, $DB;

        $timestamp = date('Y-m-d H:i:s');
        $userid = $USER->id ?? 0;

        // Add standard context.
        $context['user_id'] = $userid;
        $context['request_id'] = $requestid;
        $context['memory_usage'] = self::formatbytes(memory_get_usage(true));
        $context['peak_memory'] = self::formatbytes(memory_get_peak_usage(true));

        // Format log entry for file.
        $logentry = sprintf(
            "[%s] [%s] [User:%d] [Request:%s] %s\n",
            $timestamp,
            str_pad($level, 8),
            $userid,
            $requestid ?? 'N/A',
            $message
        );

        // Add context if not empty.
        if (!empty($context)) {
            $contextstr = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $logentry .= "    Context: " . str_replace("\n", "\n    ", $contextstr) . "\n";
        }

        $logentry .= str_repeat('-', 80) . "\n";

        // Write to file.
        self::writetofile($logentry);

        // Write to database for errors and above.
        if (in_array($level, [self::LEVEL_ERROR, self::LEVEL_CRITICAL, self::LEVEL_WARNING])) {
            self::writetodatabase($level, $message, $context, $requestid, $userid);
        }

        // Also write to PHP error log for critical errors.
        if ($level === self::LEVEL_CRITICAL) {
            debugging("HLAI QuizGen CRITICAL: {$message}");
        }
    }

    /**
     * Write log entry to file.
     *
     * @param string $entry Log entry
     */
    private static function writetofile(string $entry): void {
        if (self::$logfile) {
            // Rotate log if too large (> 10MB).
            if (file_exists(self::$logfile) && filesize(self::$logfile) > 10 * 1024 * 1024) {
                $rotated = self::$logfile . '.' . date('Y-m-d-His') . '.old';
                @rename(self::$logfile, $rotated);
            }

            @file_put_contents(self::$logfile, $entry, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Write log entry to database.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Context data
     * @param int|null $requestid Request ID
     * @param int $userid User ID
     */
    private static function writetodatabase(
        string $level,
        string $message,
        array $context,
        ?int $requestid,
        int $userid
    ): void {
        global $DB;

        try {
            $record = new \stdClass();
            $record->requestid = $requestid;
            $record->userid = $userid;
            $record->action = 'debug_log';
            $record->component = $context['component'] ?? 'debug_logger';
            $record->details = json_encode($context);
            $record->status = strtolower($level);
            $record->error_message = substr($message, 0, 1000);
            $record->timecreated = time();

            $DB->insert_record('local_hlai_quizgen_logs', $record);
        } catch (\Exception $e) {
            // Silently fail to prevent cascading errors.
            debugging("HLAI QuizGen: Failed to write to log database: " . $e->getMessage());
        }
    }

    /**
     * Get recent log entries from file.
     *
     * @param int $lines Number of lines to retrieve
     * @return array Log entries
     */
    public static function getrecentfilelogs(int $lines = 100): array {
        self::init();

        if (!self::$logfile || !file_exists(self::$logfile)) {
            return [];
        }

        $content = file_get_contents(self::$logfile);
        if (empty($content)) {
            return [];
        }

        // Split by separator and get recent entries.
        $entries = explode(str_repeat('-', 80), $content);
        $entries = array_filter($entries, function ($e) {
            return !empty(trim($e));
        });

        return array_slice($entries, -$lines);
    }

    /**
     * Get recent log entries from database.
     *
     * @param int $limit Number of records to retrieve
     * @param int|null $requestid Optional filter by request ID
     * @param string|null $level Optional filter by level
     * @return array Log records
     */
    public static function getrecentdatabaselogs(int $limit = 100, ?int $requestid = null, ?string $level = null): array {
        global $DB;

        $conditions = [];
        $params = [];

        if ($requestid !== null) {
            $conditions[] = 'requestid = :requestid';
            $params['requestid'] = $requestid;
        }

        if ($level !== null) {
            $conditions[] = 'status = :status';
            $params['status'] = strtolower($level);
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sql = "SELECT * FROM {local_hlai_quizgen_logs} {$where} ORDER BY timecreated DESC, id DESC";

        try {
            return $DB->get_records_sql($sql, $params, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get log file path.
     *
     * @return string|null Log file path
     */
    public static function getlogfilepath(): ?string {
        self::init();
        return self::$logfile;
    }

    /**
     * Clear log file.
     *
     * @return bool Success
     */
    public static function clearlogfile(): bool {
        self::init();

        if (self::$logfile && file_exists(self::$logfile)) {
            return @unlink(self::$logfile);
        }
        return true;
    }

    /**
     * Format bytes to human readable.
     *
     * @param int $bytes Bytes
     * @return string Formatted string
     */
    private static function formatbytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Format stack trace for readability.
     *
     * @param string $trace Stack trace
     * @return string Formatted trace
     */
    private static function formattrace(string $trace): string {
        // Limit trace length.
        if (strlen($trace) > 2000) {
            $trace = substr($trace, 0, 2000) . "\n... (truncated)";
        }
        return $trace;
    }

    /**
     * Log system/environment info (useful for debugging).
     *
     * @param int|null $requestid Request ID
     */
    public static function logsysteminfo(?int $requestid = null): void {
        global $CFG;

        $context = [
            'php_version' => PHP_VERSION,
            'moodle_version' => $CFG->version ?? 'unknown',
            'moodle_release' => $CFG->release ?? 'unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'loaded_extensions' => [
                'curl' => extension_loaded('curl'),
                'json' => extension_loaded('json'),
                'zip' => extension_loaded('zip'),
                'simplexml' => extension_loaded('simplexml'),
                'openssl' => extension_loaded('openssl'),
                'zlib' => extension_loaded('zlib'),
            ],
        ];

        // Check Gateway status.
        try {
            $context['gateway'] = [
                'url' => gateway_client::get_gateway_url(),
                'is_ready' => gateway_client::is_ready(),
            ];
        } catch (\Exception $e) {
            $context['gateway'] = 'Error: ' . $e->getMessage();
        }

        self::log(self::LEVEL_INFO, 'System Info Snapshot', $context, $requestid);
    }
}
