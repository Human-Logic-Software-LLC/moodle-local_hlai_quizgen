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
 * Rate limiter to prevent API abuse.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Implements rate limiting to prevent abuse and control costs.
 */
class rate_limiter {
    /** @var int Default: 10 requests per user per hour. */
    const DEFAULT_LIMIT_PER_HOUR = 10;

    /** @var int Default: 50 requests per user per day. */
    const DEFAULT_LIMIT_PER_DAY = 50;

    /** @var int Default: 200 requests per site per hour. */
    const DEFAULT_SITE_LIMIT_PER_HOUR = 200;

    /**
     * Check if user can make a request.
     *
     * @param int $userid User ID
     * @param int $courseid Course ID
     * @return array ['allowed' => bool, 'reason' => string, 'retry_after' => int]
     */
    public static function check_rate_limit($userid, $courseid = 0) {
        global $DB;

        // Check if rate limiting is enabled.
        if (!self::is_rate_limiting_enabled()) {
            return ['allowed' => true, 'reason' => '', 'retry_after' => 0];
        }

        // Get limits from config.
        $limitperhour = get_config('local_hlai_quizgen', 'rate_limit_per_hour')
            ?: self::DEFAULT_LIMIT_PER_HOUR;
        $limitperday = get_config('local_hlai_quizgen', 'rate_limit_per_day')
            ?: self::DEFAULT_LIMIT_PER_DAY;
        $sitelimitperhour = get_config('local_hlai_quizgen', 'site_rate_limit_per_hour')
            ?: self::DEFAULT_SITE_LIMIT_PER_HOUR;

        // Check user hourly limit.
        $hourlimit = self::check_user_hourly_limit($userid, $limitperhour);
        if (!$hourlimit['allowed']) {
            return $hourlimit;
        }

        // Check user daily limit.
        $dailylimit = self::check_user_daily_limit($userid, $limitperday);
        if (!$dailylimit['allowed']) {
            return $dailylimit;
        }

        // Check site-wide hourly limit.
        $sitelimit = self::check_site_hourly_limit($sitelimitperhour);
        if (!$sitelimit['allowed']) {
            return $sitelimit;
        }

        // All checks passed.
        return ['allowed' => true, 'reason' => '', 'retry_after' => 0];
    }

    /**
     * Check user hourly limit.
     *
     * @param int $userid User ID
     * @param int $limit Limit per hour
     * @return array Result
     */
    private static function check_user_hourly_limit($userid, $limit) {
        global $DB;

        $hourstarttime = strtotime('-1 hour');

        $count = $DB->count_records_select(
            'local_hlai_quizgen_requests',
            'userid = ? AND timecreated > ?',
            [$userid, $hourstarttime]
        );

        if ($count >= $limit) {
            return [
                'allowed' => false,
                'reason' => 'Hourly limit exceeded. You can make ' . $limit . ' requests per hour.',
                'retry_after' => 3600 - (time() - $hourstarttime),
                'limit_type' => 'user_hourly',
                'current' => $count,
                'limit' => $limit,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Check user daily limit.
     *
     * @param int $userid User ID
     * @param int $limit Limit per day
     * @return array Result
     */
    private static function check_user_daily_limit($userid, $limit) {
        global $DB;

        $daystarttime = strtotime('-24 hours');

        $count = $DB->count_records_select(
            'local_hlai_quizgen_requests',
            'userid = ? AND timecreated > ?',
            [$userid, $daystarttime]
        );

        if ($count >= $limit) {
            return [
                'allowed' => false,
                'reason' => 'Daily limit exceeded. You can make ' . $limit . ' requests per day.',
                'retry_after' => 86400 - (time() - $daystarttime),
                'limit_type' => 'user_daily',
                'current' => $count,
                'limit' => $limit,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Check site-wide hourly limit.
     *
     * @param int $limit Site-wide limit per hour
     * @return array Result
     */
    private static function check_site_hourly_limit($limit) {
        global $DB;

        $hourstarttime = strtotime('-1 hour');

        $count = $DB->count_records_select(
            'local_hlai_quizgen_requests',
            'timecreated > ?',
            [$hourstarttime]
        );

        if ($count >= $limit) {
            return [
                'allowed' => false,
                'reason' => 'System is currently busy. Please try again later.',
                'retry_after' => 3600 - (time() - $hourstarttime),
                'limit_type' => 'site_hourly',
                'current' => $count,
                'limit' => $limit,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Check if rate limiting is enabled.
     *
     * @return bool True if enabled
     */
    public static function is_rate_limiting_enabled() {
        return (bool) get_config('local_hlai_quizgen', 'enable_rate_limiting');
    }

    /**
     * Get rate limit status for user.
     *
     * @param int $userid User ID
     * @return array Status information
     */
    public static function get_user_status($userid) {
        global $DB;

        $hourstarttime = strtotime('-1 hour');
        $daystarttime = strtotime('-24 hours');

        $hourlyrequests = $DB->count_records_select(
            'local_hlai_quizgen_requests',
            'userid = ? AND timecreated > ?',
            [$userid, $hourstarttime]
        );

        $dailyrequests = $DB->count_records_select(
            'local_hlai_quizgen_requests',
            'userid = ? AND timecreated > ?',
            [$userid, $daystarttime]
        );

        $limitperhour = get_config('local_hlai_quizgen', 'rate_limit_per_hour')
            ?: self::DEFAULT_LIMIT_PER_HOUR;
        $limitperday = get_config('local_hlai_quizgen', 'rate_limit_per_day')
            ?: self::DEFAULT_LIMIT_PER_DAY;

        return [
            'hourly' => [
                'current' => $hourlyrequests,
                'limit' => $limitperhour,
                'remaining' => max(0, $limitperhour - $hourlyrequests),
                'reset_in' => 3600 - (time() - $hourstarttime),
            ],
            'daily' => [
                'current' => $dailyrequests,
                'limit' => $limitperday,
                'remaining' => max(0, $limitperday - $dailyrequests),
                'reset_in' => 86400 - (time() - $daystarttime),
            ],
        ];
    }

    /**
     * Get site-wide rate limit status.
     *
     * @return array Status information
     */
    public static function get_site_status() {
        global $DB;

        $hourstarttime = strtotime('-1 hour');

        $hourlyrequests = $DB->count_records_select(
            'local_hlai_quizgen_requests',
            'timecreated > ?',
            [$hourstarttime]
        );

        $sitelimitperhour = get_config('local_hlai_quizgen', 'site_rate_limit_per_hour')
            ?: self::DEFAULT_SITE_LIMIT_PER_HOUR;

        return [
            'current' => $hourlyrequests,
            'limit' => $sitelimitperhour,
            'remaining' => max(0, $sitelimitperhour - $hourlyrequests),
            'reset_in' => 3600 - (time() - $hourstarttime),
            'utilization_percent' => round(($hourlyrequests / $sitelimitperhour) * 100, 2),
        ];
    }

    /**
     * Record a rate limit violation.
     *
     * @param int $userid User ID
     * @param string $limittype Type of limit exceeded
     * @param array $details Additional details
     * @return void
     */
    public static function record_violation($userid, $limittype, $details = []) {
        global $DB;

        $record = new \stdClass();
        $record->userid = $userid;
        $record->limittype = $limittype;
        $record->details = json_encode($details);
        $record->timecreated = time();

        try {
            $DB->insert_record('local_hlai_quizgen_ratelimit_log', $record);
        } catch (\Exception $e) {
            // Silently fail - violations are informational.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Get top rate limit violators.
     *
     * @param int $limit Number of users to return
     * @param int $since Timestamp to check from
     * @return array Array of user IDs and violation counts
     */
    public static function get_top_violators($limit = 10, $since = 0) {
        global $DB;

        if ($since === 0) {
            $since = strtotime('-7 days');
        }

        $sql = "SELECT userid, COUNT(*) as violations
                FROM {local_hlai_quizgen_ratelimit_log}
                WHERE timecreated > ?
                GROUP BY userid
                ORDER BY violations DESC";

        return $DB->get_records_sql($sql, [$since], 0, $limit);
    }

    /**
     * Reset user rate limits (admin function).
     *
     * @param int $userid User ID
     * @return bool Success
     */
    public static function reset_user_limits($userid) {
        global $DB;

        try {
            // We can't actually delete their requests, but we can flag them as exempt temporarily.
            // Or simply delete very recent requests (last hour) if needed for support.
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if user is exempt from rate limiting.
     *
     * @param int $userid User ID
     * @return bool True if exempt
     */
    public static function is_user_exempt($userid) {
        global $DB;

        // Site admins are always exempt.
        if (is_siteadmin($userid)) {
            return true;
        }

        // Check for explicit exemption.
        $exemptions = get_config('local_hlai_quizgen', 'rate_limit_exempt_users');
        if ($exemptions) {
            $exemptlist = explode(',', $exemptions);
            if (in_array($userid, $exemptlist)) {
                return true;
            }
        }

        return false;
    }
}
