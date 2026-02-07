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
 * Centralized error handling for the AI Quiz Generator plugin.
 *
 * Provides standardized error handling, logging, and user-friendly error messages.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Error handler class with standardized error handling.
 */
class error_handler {
    /** @var string Debug severity level. */
    const SEVERITY_DEBUG = 'debug';
    /** @var string Info severity level. */
    const SEVERITY_INFO = 'info';
    /** @var string Warning severity level. */
    const SEVERITY_WARNING = 'warning';
    /** @var string Error severity level. */
    const SEVERITY_ERROR = 'error';
    /** @var string Critical severity level. */
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Handle an exception with logging and optional status update.
     *
     * @param \Exception $exception The exception to handle
     * @param int|null $requestid Optional request ID to update status
     * @param string $component Component where error occurred
     * @param string $severity Severity level
     * @return array Error details array
     */
    public static function handle_exception(
        \Exception $exception,
        ?int $requestid = null,
        string $component = 'unknown',
        string $severity = self::SEVERITY_ERROR
    ): array {
        global $USER;

        $errordetails = [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'component' => $component,
            'severity' => $severity,
            'timestamp' => time(),
        ];

        // Log to debug_logger for comprehensive logging (file + database).
        debug_logger::exception($exception, $component, $requestid);

        // Also log to original database method for backwards compatibility.
        self::log_error($errordetails, $requestid);

        // Update request status if applicable.
        if ($requestid && $severity === self::SEVERITY_ERROR || $severity === self::SEVERITY_CRITICAL) {
            try {
                api::update_request_status($requestid, 'failed', $exception->getMessage());
            } catch (\Exception $e) {
                // Prevent cascading failures.
                debug_logger::error('Failed to update request status', [
                    'original_error' => $exception->getMessage(),
                    'status_update_error' => $e->getMessage(),
                ], $requestid);
            }
        }

        return $errordetails;
    }

    /**
     * Log error to database.
     *
     * @param array $errordetails Error details
     * @param int|null $requestid Optional request ID
     */
    private static function log_error(array $errordetails, ?int $requestid = null): void {
        global $DB, $USER;

        try {
            $log = new \stdClass();
            $log->requestid = $requestid;
            $log->userid = $USER->id ?? 0;
            $log->action = 'error_occurred';
            $log->component = $errordetails['component'];
            $log->details = json_encode([
                'message' => $errordetails['message'],
                'code' => $errordetails['code'],
                'file' => $errordetails['file'],
                'line' => $errordetails['line'],
                'severity' => $errordetails['severity'],
            ]);
            $log->status = 'error';
            $log->error_message = substr($errordetails['message'], 0, 1000); // Truncate if too long.
            $log->timecreated = time();

            $DB->insert_record('local_hlai_quizgen_logs', $log);
        } catch (\Exception $e) {
            // Prevent cascading failures.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Wrap a callable in try-catch with standardized error handling.
     *
     * @param callable $callback Function to execute
     * @param int|null $requestid Optional request ID
     * @param string $component Component name
     * @param mixed $defaultreturn Value to return on error
     * @return mixed Result from callback or default value on error
     */
    public static function safe_execute(
        callable $callback,
        ?int $requestid = null,
        string $component = 'unknown',
        $defaultreturn = null
    ) {
        try {
            return $callback();
        } catch (\moodle_exception $e) {
            self::handle_exception($e, $requestid, $component, self::SEVERITY_ERROR);
            return $defaultreturn;
        } catch (\Exception $e) {
            self::handle_exception($e, $requestid, $component, self::SEVERITY_CRITICAL);
            return $defaultreturn;
        }
    }

    /**
     * Get user-friendly error message.
     *
     * @param string $errorkey Error string key
     * @param mixed $a Optional parameter for string
     * @return string User-friendly error message
     */
    public static function get_error_message(string $errorkey, $a = null): string {
        if (get_string_manager()->string_exists($errorkey, 'local_hlai_quizgen')) {
            return get_string($errorkey, 'local_hlai_quizgen', $a);
        }
        return get_string('error:unknown', 'local_hlai_quizgen');
    }

    /**
     * Validate request can proceed (not failed or completed).
     *
     * @param int $requestid Request ID
     * @param bool $allowcompleted Whether to allow completed requests.
     * @return bool True if can proceed
     * @throws \moodle_exception If request is in invalid state
     */
    public static function validate_request_state(int $requestid, bool $allowcompleted = false): bool {
        global $DB;

        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], 'status', MUST_EXIST);

        // FIX: Allow completed requests when regenerating questions.
        if ($request->status === 'completed' && !$allowcompleted) {
            throw new \moodle_exception('error:requestalreadycompleted', 'local_hlai_quizgen');
        }

        if ($request->status === 'failed') {
            throw new \moodle_exception('error:requestfailed', 'local_hlai_quizgen');
        }

        return true;
    }

    /**
     * Create standardized error response for AJAX calls.
     *
     * @param string $message Error message
     * @param string $code Error code
     * @param array $additionaldata Additional data
     * @return array Error response array
     */
    public static function create_error_response(
        string $message,
        string $code = 'error',
        array $additionaldata = []
    ): array {
        return array_merge([
            'success' => false,
            'error' => true,
            'message' => $message,
            'code' => $code,
            'timestamp' => time(),
        ], $additionaldata);
    }
}
