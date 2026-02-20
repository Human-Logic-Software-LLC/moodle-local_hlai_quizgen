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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External API for dashboard statistics.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\external;

defined('MOODLE_INTERNAL') || die();

// Backward compatibility for Moodle < 4.2 (before core_external namespace was introduced).
if (!class_exists('core_external\external_api')) {
    global $CFG;
    require_once($CFG->libdir . '/externallib.php');
    class_alias('external_api', 'core_external\external_api');
    class_alias('external_function_parameters', 'core_external\external_function_parameters');
    class_alias('external_value', 'core_external\external_value');
    class_alias('external_single_structure', 'core_external\external_single_structure');
    class_alias('external_multiple_structure', 'core_external\external_multiple_structure');
}

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * Dashboard external functions for the local_hlai_quizgen plugin.
 *
 * Provides 8 external service functions that return dashboard statistics
 * for quiz generation activity, question quality, and acceptance metrics.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_external extends external_api {
    // -----------------------------------------------------------------------
    // 1. get_dashboard_stats
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_dashboard_stats.
     *
     * @return external_function_parameters
     */
    public static function get_dashboard_stats_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID for context validation', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get quick stats for dashboard cards.
     *
     * @param int $courseid Course ID for context validation.
     * @return array Dashboard statistics.
     */
    public static function get_dashboard_stats($courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_dashboard_stats_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context - use course context when available for teacher access.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        $userid = $USER->id;

        // Total quizzes created by user.
        $totalquizzes = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT r.id)
             FROM {local_hlai_quizgen_requests} r
             WHERE r.userid = ? AND r.status = 'completed'",
            [$userid]
        );

        // Total questions generated.
        $totalquestions = $DB->count_records_sql(
            "SELECT COUNT(q.id)
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ?",
            [$userid]
        );

        // Questions approved.
        $approvedquestions = $DB->count_records_sql(
            "SELECT COUNT(q.id)
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ? AND q.status = 'approved'",
            [$userid]
        );

        // Average quality score.
        $avgquality = $DB->get_field_sql(
            "SELECT AVG(q.validation_score)
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ? AND q.validation_score IS NOT NULL",
            [$userid]
        );

        // Acceptance rate.
        $totalreviewed = $DB->count_records_sql(
            "SELECT COUNT(q.id)
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ? AND q.status IN ('approved', 'rejected')",
            [$userid]
        );
        $acceptancerate = $totalreviewed > 0 ? round(($approvedquestions / $totalreviewed) * 100, 1) : 0;

        // First-time acceptance rate.
        $firsttimeapproved = $DB->count_records_sql(
            "SELECT COUNT(q.id)
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ? AND q.status = 'approved' AND q.regeneration_count = 0",
            [$userid]
        );
        $ftar = $approvedquestions > 0 ? round(($firsttimeapproved / $approvedquestions) * 100, 1) : 0;

        // Average regeneration count.
        $avgregen = $DB->get_field_sql(
            "SELECT AVG(q.regeneration_count)
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ?",
            [$userid]
        );

        return [
            'total_quizzes' => (int) $totalquizzes,
            'total_questions' => (int) $totalquestions,
            'approved_questions' => (int) $approvedquestions,
            'avg_quality' => round((float) $avgquality, 1),
            'acceptance_rate' => $acceptancerate,
            'ftar' => $ftar,
            'avg_regenerations' => round((float) $avgregen, 2),
        ];
    }

    /**
     * Describes the return value for get_dashboard_stats.
     *
     * @return external_single_structure
     */
    public static function get_dashboard_stats_returns() {
        return new external_single_structure([
            'total_quizzes' => new external_value(PARAM_INT, 'Total quizzes created by the user'),
            'total_questions' => new external_value(PARAM_INT, 'Total questions generated'),
            'approved_questions' => new external_value(PARAM_INT, 'Total questions approved'),
            'avg_quality' => new external_value(PARAM_FLOAT, 'Average quality score'),
            'acceptance_rate' => new external_value(PARAM_FLOAT, 'Acceptance rate percentage'),
            'ftar' => new external_value(PARAM_FLOAT, 'First-time acceptance rate percentage'),
            'avg_regenerations' => new external_value(PARAM_FLOAT, 'Average regeneration count per question'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 2. get_question_type_distribution
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_question_type_distribution.
     *
     * @return external_function_parameters
     */
    public static function get_question_type_distribution_parameters() {
        return new external_function_parameters([
            'filtercourseid' => new external_value(PARAM_INT, 'Course ID to filter by (0 for all)', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Course ID for context validation', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get question type distribution for charts.
     *
     * @param int $filtercourseid Course ID to filter by (0 for all).
     * @param int $courseid Course ID for context validation.
     * @return array Labels and values arrays.
     */
    public static function get_question_type_distribution($filtercourseid = 0, $courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_question_type_distribution_parameters(), [
            'filtercourseid' => $filtercourseid,
            'courseid' => $courseid,
        ]);
        $filtercourseid = $params['filtercourseid'];
        $courseid = $params['courseid'];

        // Validate context - use course context when available for teacher access.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        $userid = $USER->id;

        $sqlparams = [$userid];
        $coursefilter = '';
        if ($filtercourseid > 0) {
            $coursefilter = ' AND q.courseid = ?';
            $sqlparams[] = $filtercourseid;
        }

        $types = $DB->get_records_sql(
            "SELECT q.questiontype, COUNT(q.id) as count
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ? $coursefilter
             GROUP BY q.questiontype
             ORDER BY count DESC",
            $sqlparams
        );

        $labels = [];
        $values = [];
        foreach ($types as $type) {
            $labels[] = ucfirst(str_replace('_', ' ', $type->questiontype));
            $values[] = (int) $type->count;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Describes the return value for get_question_type_distribution.
     *
     * @return external_single_structure
     */
    public static function get_question_type_distribution_returns() {
        return new external_single_structure([
            'labels' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Question type label')
            ),
            'values' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Count for this question type')
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // 3. get_difficulty_distribution
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_difficulty_distribution.
     *
     * @return external_function_parameters
     */
    public static function get_difficulty_distribution_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID for context validation', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get difficulty distribution of generated questions.
     *
     * @param int $courseid Course ID for context validation.
     * @return array Counts for easy, medium, hard.
     */
    public static function get_difficulty_distribution($courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_difficulty_distribution_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context - use course context when available for teacher access.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        $userid = $USER->id;

        $difficulties = $DB->get_records_sql(
            "SELECT q.difficulty, COUNT(q.id) as count
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ?
             GROUP BY q.difficulty",
            [$userid]
        );

        $dist = ['easy' => 0, 'medium' => 0, 'hard' => 0];
        foreach ($difficulties as $d) {
            if (isset($dist[$d->difficulty])) {
                $dist[$d->difficulty] = (int) $d->count;
            }
        }

        return [
            'easy' => $dist['easy'],
            'medium' => $dist['medium'],
            'hard' => $dist['hard'],
        ];
    }

    /**
     * Describes the return value for get_difficulty_distribution.
     *
     * @return external_single_structure
     */
    public static function get_difficulty_distribution_returns() {
        return new external_single_structure([
            'easy' => new external_value(PARAM_INT, 'Count of easy questions'),
            'medium' => new external_value(PARAM_INT, 'Count of medium questions'),
            'hard' => new external_value(PARAM_INT, 'Count of hard questions'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 4. get_blooms_distribution
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_blooms_distribution.
     *
     * @return external_function_parameters
     */
    public static function get_blooms_distribution_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID for context validation', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get Bloom's taxonomy distribution of generated questions.
     *
     * @param int $courseid Course ID for context validation.
     * @return array Counts for each Bloom's level.
     */
    public static function get_blooms_distribution($courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_blooms_distribution_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context - use course context when available for teacher access.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        $userid = $USER->id;

        $blooms = $DB->get_records_sql(
            "SELECT q.blooms_level, COUNT(q.id) as count
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ? AND q.blooms_level IS NOT NULL
             GROUP BY q.blooms_level",
            [$userid]
        );

        $dist = [
            'remember' => 0,
            'understand' => 0,
            'apply' => 0,
            'analyze' => 0,
            'evaluate' => 0,
            'create' => 0,
        ];
        foreach ($blooms as $b) {
            $level = strtolower($b->blooms_level);
            if (isset($dist[$level])) {
                $dist[$level] = (int) $b->count;
            }
        }

        return [
            'remember' => $dist['remember'],
            'understand' => $dist['understand'],
            'apply' => $dist['apply'],
            'analyze' => $dist['analyze'],
            'evaluate' => $dist['evaluate'],
            'create' => $dist['create'],
        ];
    }

    /**
     * Describes the return value for get_blooms_distribution.
     *
     * @return external_single_structure
     */
    public static function get_blooms_distribution_returns() {
        return new external_single_structure([
            'remember' => new external_value(PARAM_INT, 'Count of remember-level questions'),
            'understand' => new external_value(PARAM_INT, 'Count of understand-level questions'),
            'apply' => new external_value(PARAM_INT, 'Count of apply-level questions'),
            'analyze' => new external_value(PARAM_INT, 'Count of analyze-level questions'),
            'evaluate' => new external_value(PARAM_INT, 'Count of evaluate-level questions'),
            'create' => new external_value(PARAM_INT, 'Count of create-level questions'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 5. get_acceptance_trend
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_acceptance_trend.
     *
     * @return external_function_parameters
     */
    public static function get_acceptance_trend_parameters() {
        return new external_function_parameters([
            'limit' => new external_value(PARAM_INT, 'Number of recent generations to include', VALUE_DEFAULT, 10),
            'courseid' => new external_value(PARAM_INT, 'Course ID for context validation', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get acceptance rate trend over last N quiz generations.
     *
     * @param int $limit Number of recent generations to include.
     * @param int $courseid Course ID for context validation.
     * @return array Labels, acceptance rates, and FTAR rates.
     */
    public static function get_acceptance_trend($limit = 10, $courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_acceptance_trend_parameters(), [
            'limit' => $limit,
            'courseid' => $courseid,
        ]);
        $limit = $params['limit'];
        $courseid = $params['courseid'];

        // Validate context - use course context when available for teacher access.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        $userid = $USER->id;

        $requests = $DB->get_records_sql(
            "SELECT r.id, r.timecreated,
                    (SELECT COUNT(*) FROM {local_hlai_quizgen_questions} q
                     WHERE q.requestid = r.id AND q.status = 'approved') as approved,
                    (SELECT COUNT(*) FROM {local_hlai_quizgen_questions} q
                     WHERE q.requestid = r.id AND q.status IN ('approved', 'rejected')) as total
             FROM {local_hlai_quizgen_requests} r
             WHERE r.userid = ? AND r.status = 'completed'
             ORDER BY r.timecreated ASC",
            [$userid],
            0,
            $limit
        );

        $labels = [];
        $acceptancerates = [];
        $ftarrates = [];
        $i = 1;

        // Batch-fetch FTAR counts to avoid N+1 query pattern.
        $requestids = array_keys($requests);
        $ftarcounts = [];
        if (!empty($requestids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($requestids);
            $ftarrecords = $DB->get_records_sql(
                "SELECT requestid, COUNT(*) as cnt
                 FROM {local_hlai_quizgen_questions}
                 WHERE requestid $insql AND status = 'approved' AND regeneration_count = 0
                 GROUP BY requestid",
                $inparams
            );
            foreach ($ftarrecords as $rec) {
                $ftarcounts[$rec->requestid] = (int) $rec->cnt;
            }
        }

        foreach ($requests as $r) {
            $labels[] = get_string('ajax_gen_label', 'local_hlai_quizgen', $i);
            $rate = $r->total > 0 ? round(($r->approved / $r->total) * 100, 1) : 0;
            $acceptancerates[] = $rate;

            // Use pre-fetched FTAR count.
            $firsttime = $ftarcounts[$r->id] ?? 0;
            $ftar = $r->approved > 0 ? round(($firsttime / $r->approved) * 100, 1) : 0;
            $ftarrates[] = $ftar;
            $i++;
        }

        return [
            'labels' => $labels,
            'acceptance_rates' => $acceptancerates,
            'ftar_rates' => $ftarrates,
        ];
    }

    /**
     * Describes the return value for get_acceptance_trend.
     *
     * @return external_single_structure
     */
    public static function get_acceptance_trend_returns() {
        return new external_single_structure([
            'labels' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Generation label')
            ),
            'acceptance_rates' => new external_multiple_structure(
                new external_value(PARAM_FLOAT, 'Acceptance rate percentage')
            ),
            'ftar_rates' => new external_multiple_structure(
                new external_value(PARAM_FLOAT, 'First-time acceptance rate percentage')
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // 6. get_regeneration_by_type
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_regeneration_by_type.
     *
     * @return external_function_parameters
     */
    public static function get_regeneration_by_type_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID for context validation', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get regeneration statistics by question type.
     *
     * Returns data as a JSON string because the structure is dynamic
     * (keyed by question type), which Moodle's external API does not
     * support natively with typed structures.
     *
     * @param int $courseid Course ID for context validation.
     * @return array Contains a 'data' key with JSON-encoded regeneration stats.
     */
    public static function get_regeneration_by_type($courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_regeneration_by_type_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context - use course context when available for teacher access.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        $userid = $USER->id;

        $stats = $DB->get_records_sql(
            "SELECT q.questiontype,
                    COUNT(q.id) as total,
                    SUM(CASE WHEN q.regeneration_count > 0 THEN 1 ELSE 0 END) as regenerated,
                    AVG(q.regeneration_count) as avg_regens
             FROM {local_hlai_quizgen_questions} q
             WHERE q.userid = ?
             GROUP BY q.questiontype",
            [$userid]
        );

        // Build object keyed by question type for dashboard.js compatibility.
        $data = [];
        foreach ($stats as $s) {
            $data[$s->questiontype] = [
                'total' => (int) $s->total,
                'regenerated' => (int) $s->regenerated,
                'regen_rate' => $s->total > 0 ? round(($s->regenerated / $s->total) * 100, 1) : 0,
                'avg_regenerations' => round((float) $s->avg_regens, 2),
            ];
        }

        return [
            'data' => json_encode($data),
        ];
    }

    /**
     * Describes the return value for get_regeneration_by_type.
     *
     * @return external_single_structure
     */
    public static function get_regeneration_by_type_returns() {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'JSON-encoded regeneration stats keyed by question type'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 7. get_quality_distribution
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_quality_distribution.
     *
     * @return external_function_parameters
     */
    public static function get_quality_distribution_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID for context validation', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get quality score distribution (histogram data).
     *
     * @param int $courseid Course ID for context validation.
     * @return array Labels (score ranges) and values (counts).
     */
    public static function get_quality_distribution($courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_quality_distribution_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context - use course context when available for teacher access.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        $userid = $USER->id;

        $ranges = [
            '0-10' => [0, 10],
            '11-20' => [11, 20],
            '21-30' => [21, 30],
            '31-40' => [31, 40],
            '41-50' => [41, 50],
            '51-60' => [51, 60],
            '61-70' => [61, 70],
            '71-80' => [71, 80],
            '81-90' => [81, 90],
            '91-100' => [91, 100],
        ];

        // Single query with CASE WHEN instead of 10 separate COUNT queries.
        $sql = "SELECT
                    SUM(CASE WHEN validation_score >= 0 AND validation_score <= 10 THEN 1 ELSE 0 END) AS r0_10,
                    SUM(CASE WHEN validation_score >= 11 AND validation_score <= 20 THEN 1 ELSE 0 END) AS r11_20,
                    SUM(CASE WHEN validation_score >= 21 AND validation_score <= 30 THEN 1 ELSE 0 END) AS r21_30,
                    SUM(CASE WHEN validation_score >= 31 AND validation_score <= 40 THEN 1 ELSE 0 END) AS r31_40,
                    SUM(CASE WHEN validation_score >= 41 AND validation_score <= 50 THEN 1 ELSE 0 END) AS r41_50,
                    SUM(CASE WHEN validation_score >= 51 AND validation_score <= 60 THEN 1 ELSE 0 END) AS r51_60,
                    SUM(CASE WHEN validation_score >= 61 AND validation_score <= 70 THEN 1 ELSE 0 END) AS r61_70,
                    SUM(CASE WHEN validation_score >= 71 AND validation_score <= 80 THEN 1 ELSE 0 END) AS r71_80,
                    SUM(CASE WHEN validation_score >= 81 AND validation_score <= 90 THEN 1 ELSE 0 END) AS r81_90,
                    SUM(CASE WHEN validation_score >= 91 AND validation_score <= 100 THEN 1 ELSE 0 END) AS r91_100
                FROM {local_hlai_quizgen_questions}
                WHERE userid = ?";
        $row = $DB->get_record_sql($sql, [$userid]);

        $labels = [];
        $values = [];
        $fieldmap = [
            '0-10' => 'r0_10', '11-20' => 'r11_20', '21-30' => 'r21_30',
            '31-40' => 'r31_40', '41-50' => 'r41_50', '51-60' => 'r51_60',
            '61-70' => 'r61_70', '71-80' => 'r71_80', '81-90' => 'r81_90',
            '91-100' => 'r91_100',
        ];
        foreach ($ranges as $label => $range) {
            $labels[] = $label;
            $field = $fieldmap[$label];
            $values[] = (int) ($row->$field ?? 0);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Describes the return value for get_quality_distribution.
     *
     * @return external_single_structure
     */
    public static function get_quality_distribution_returns() {
        return new external_single_structure([
            'labels' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Score range label')
            ),
            'values' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Count of questions in this range')
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // 8. get_recent_requests
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_recent_requests.
     *
     * @return external_function_parameters
     */
    public static function get_recent_requests_parameters() {
        return new external_function_parameters([
            'limit' => new external_value(PARAM_INT, 'Maximum number of requests to return', VALUE_DEFAULT, 5),
            'courseid' => new external_value(PARAM_INT, 'Course ID for context validation', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get recent quiz generation requests for the dashboard.
     *
     * @param int $limit Maximum number of requests to return.
     * @param int $courseid Course ID for context validation.
     * @return array Array containing a 'requests' key with the list of request items.
     */
    public static function get_recent_requests($limit = 5, $courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_recent_requests_parameters(), [
            'limit' => $limit,
            'courseid' => $courseid,
        ]);
        $limit = $params['limit'];
        $courseid = $params['courseid'];

        // Validate context - use course context when available for teacher access.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        $userid = $USER->id;

        $requests = $DB->get_records_sql(
            "SELECT r.id, r.courseid, r.status, r.total_questions, r.questions_generated,
                    r.timecreated, r.timecompleted,
                    c.fullname as coursename
             FROM {local_hlai_quizgen_requests} r
             JOIN {course} c ON c.id = r.courseid
             WHERE r.userid = ?
             ORDER BY r.timecreated DESC",
            [$userid],
            0,
            $limit
        );

        // Batch-fetch approved counts to avoid N+1 query pattern.
        $requestids = array_keys($requests);
        $approvedcounts = [];
        if (!empty($requestids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($requestids);
            $approvedrecords = $DB->get_records_sql(
                "SELECT requestid, COUNT(*) as cnt
                 FROM {local_hlai_quizgen_questions}
                 WHERE requestid $insql AND status = 'approved'
                 GROUP BY requestid",
                $inparams
            );
            foreach ($approvedrecords as $rec) {
                $approvedcounts[$rec->requestid] = (int) $rec->cnt;
            }
        }

        $items = [];
        foreach ($requests as $r) {
            $approved = $approvedcounts[$r->id] ?? 0;

            $items[] = [
                'id' => (int) $r->id,
                'courseid' => (int) $r->courseid,
                'coursename' => $r->coursename,
                'status' => $r->status,
                'total' => (int) $r->total_questions,
                'generated' => (int) $r->questions_generated,
                'approved' => $approved,
                'timecreated' => userdate($r->timecreated, '%d %b %Y, %H:%M'),
                'timeago' => format_time(time() - $r->timecreated),
            ];
        }

        return [
            'requests' => $items,
        ];
    }

    /**
     * Describes the return value for get_recent_requests.
     *
     * @return external_single_structure
     */
    public static function get_recent_requests_returns() {
        return new external_single_structure([
            'requests' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Request ID'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new external_value(PARAM_TEXT, 'Course full name'),
                    'status' => new external_value(PARAM_TEXT, 'Request status'),
                    'total' => new external_value(PARAM_INT, 'Total questions requested'),
                    'generated' => new external_value(PARAM_INT, 'Number of questions generated'),
                    'approved' => new external_value(PARAM_INT, 'Number of questions approved'),
                    'timecreated' => new external_value(PARAM_TEXT, 'Formatted creation date'),
                    'timeago' => new external_value(PARAM_TEXT, 'Human-readable time since creation'),
                ])
            ),
        ]);
    }
}
