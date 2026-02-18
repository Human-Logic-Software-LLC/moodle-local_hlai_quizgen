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

global $CFG;
require_once("$CFG->libdir/externallib.php");

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
class dashboard_external extends \external_api {
    // -----------------------------------------------------------------------
    // 1. get_dashboard_stats
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_dashboard_stats.
     *
     * @return \external_function_parameters
     */
    public static function get_dashboard_stats_parameters() {
        return new \external_function_parameters([]);
    }

    /**
     * Get quick stats for dashboard cards.
     *
     * @return array Dashboard statistics.
     */
    public static function get_dashboard_stats() {
        global $DB, $USER;

        // Validate parameters (none required).
        self::validate_parameters(self::get_dashboard_stats_parameters(), []);

        // Validate context.
        $context = \context_system::instance();
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
     * @return \external_single_structure
     */
    public static function get_dashboard_stats_returns() {
        return new \external_single_structure([
            'total_quizzes' => new \external_value(PARAM_INT, 'Total quizzes created by the user'),
            'total_questions' => new \external_value(PARAM_INT, 'Total questions generated'),
            'approved_questions' => new \external_value(PARAM_INT, 'Total questions approved'),
            'avg_quality' => new \external_value(PARAM_FLOAT, 'Average quality score'),
            'acceptance_rate' => new \external_value(PARAM_FLOAT, 'Acceptance rate percentage'),
            'ftar' => new \external_value(PARAM_FLOAT, 'First-time acceptance rate percentage'),
            'avg_regenerations' => new \external_value(PARAM_FLOAT, 'Average regeneration count per question'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 2. get_question_type_distribution
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_question_type_distribution.
     *
     * @return \external_function_parameters
     */
    public static function get_question_type_distribution_parameters() {
        return new \external_function_parameters([
            'filtercourseid' => new \external_value(PARAM_INT, 'Course ID to filter by (0 for all)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get question type distribution for charts.
     *
     * @param int $filtercourseid Course ID to filter by (0 for all).
     * @return array Labels and values arrays.
     */
    public static function get_question_type_distribution($filtercourseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_question_type_distribution_parameters(), [
            'filtercourseid' => $filtercourseid,
        ]);
        $filtercourseid = $params['filtercourseid'];

        // Validate context.
        $context = \context_system::instance();
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
     * @return \external_single_structure
     */
    public static function get_question_type_distribution_returns() {
        return new \external_single_structure([
            'labels' => new \external_multiple_structure(
                new \external_value(PARAM_TEXT, 'Question type label')
            ),
            'values' => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'Count for this question type')
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // 3. get_difficulty_distribution
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_difficulty_distribution.
     *
     * @return \external_function_parameters
     */
    public static function get_difficulty_distribution_parameters() {
        return new \external_function_parameters([]);
    }

    /**
     * Get difficulty distribution of generated questions.
     *
     * @return array Counts for easy, medium, hard.
     */
    public static function get_difficulty_distribution() {
        global $DB, $USER;

        // Validate parameters (none required).
        self::validate_parameters(self::get_difficulty_distribution_parameters(), []);

        // Validate context.
        $context = \context_system::instance();
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
     * @return \external_single_structure
     */
    public static function get_difficulty_distribution_returns() {
        return new \external_single_structure([
            'easy' => new \external_value(PARAM_INT, 'Count of easy questions'),
            'medium' => new \external_value(PARAM_INT, 'Count of medium questions'),
            'hard' => new \external_value(PARAM_INT, 'Count of hard questions'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 4. get_blooms_distribution
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_blooms_distribution.
     *
     * @return \external_function_parameters
     */
    public static function get_blooms_distribution_parameters() {
        return new \external_function_parameters([]);
    }

    /**
     * Get Bloom's taxonomy distribution of generated questions.
     *
     * @return array Counts for each Bloom's level.
     */
    public static function get_blooms_distribution() {
        global $DB, $USER;

        // Validate parameters (none required).
        self::validate_parameters(self::get_blooms_distribution_parameters(), []);

        // Validate context.
        $context = \context_system::instance();
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
     * @return \external_single_structure
     */
    public static function get_blooms_distribution_returns() {
        return new \external_single_structure([
            'remember' => new \external_value(PARAM_INT, 'Count of remember-level questions'),
            'understand' => new \external_value(PARAM_INT, 'Count of understand-level questions'),
            'apply' => new \external_value(PARAM_INT, 'Count of apply-level questions'),
            'analyze' => new \external_value(PARAM_INT, 'Count of analyze-level questions'),
            'evaluate' => new \external_value(PARAM_INT, 'Count of evaluate-level questions'),
            'create' => new \external_value(PARAM_INT, 'Count of create-level questions'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 5. get_acceptance_trend
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_acceptance_trend.
     *
     * @return \external_function_parameters
     */
    public static function get_acceptance_trend_parameters() {
        return new \external_function_parameters([
            'limit' => new \external_value(PARAM_INT, 'Number of recent generations to include', VALUE_DEFAULT, 10),
        ]);
    }

    /**
     * Get acceptance rate trend over last N quiz generations.
     *
     * @param int $limit Number of recent generations to include.
     * @return array Labels, acceptance rates, and FTAR rates.
     */
    public static function get_acceptance_trend($limit = 10) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_acceptance_trend_parameters(), [
            'limit' => $limit,
        ]);
        $limit = $params['limit'];

        // Validate context.
        $context = \context_system::instance();
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
     * @return \external_single_structure
     */
    public static function get_acceptance_trend_returns() {
        return new \external_single_structure([
            'labels' => new \external_multiple_structure(
                new \external_value(PARAM_TEXT, 'Generation label')
            ),
            'acceptance_rates' => new \external_multiple_structure(
                new \external_value(PARAM_FLOAT, 'Acceptance rate percentage')
            ),
            'ftar_rates' => new \external_multiple_structure(
                new \external_value(PARAM_FLOAT, 'First-time acceptance rate percentage')
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // 6. get_regeneration_by_type
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_regeneration_by_type.
     *
     * @return \external_function_parameters
     */
    public static function get_regeneration_by_type_parameters() {
        return new \external_function_parameters([]);
    }

    /**
     * Get regeneration statistics by question type.
     *
     * Returns data as a JSON string because the structure is dynamic
     * (keyed by question type), which Moodle's external API does not
     * support natively with typed structures.
     *
     * @return array Contains a 'data' key with JSON-encoded regeneration stats.
     */
    public static function get_regeneration_by_type() {
        global $DB, $USER;

        // Validate parameters (none required).
        self::validate_parameters(self::get_regeneration_by_type_parameters(), []);

        // Validate context.
        $context = \context_system::instance();
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
     * @return \external_single_structure
     */
    public static function get_regeneration_by_type_returns() {
        return new \external_single_structure([
            'data' => new \external_value(PARAM_RAW, 'JSON-encoded regeneration stats keyed by question type'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 7. get_quality_distribution
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_quality_distribution.
     *
     * @return \external_function_parameters
     */
    public static function get_quality_distribution_parameters() {
        return new \external_function_parameters([]);
    }

    /**
     * Get quality score distribution (histogram data).
     *
     * @return array Labels (score ranges) and values (counts).
     */
    public static function get_quality_distribution() {
        global $DB, $USER;

        // Validate parameters (none required).
        self::validate_parameters(self::get_quality_distribution_parameters(), []);

        // Validate context.
        $context = \context_system::instance();
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

        $labels = [];
        $values = [];
        foreach ($ranges as $label => $range) {
            $count = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
                 WHERE userid = ? AND validation_score >= ? AND validation_score <= ?",
                [$userid, $range[0], $range[1]]
            );
            $labels[] = $label;
            $values[] = (int) $count;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Describes the return value for get_quality_distribution.
     *
     * @return \external_single_structure
     */
    public static function get_quality_distribution_returns() {
        return new \external_single_structure([
            'labels' => new \external_multiple_structure(
                new \external_value(PARAM_TEXT, 'Score range label')
            ),
            'values' => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'Count of questions in this range')
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // 8. get_recent_requests
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for get_recent_requests.
     *
     * @return \external_function_parameters
     */
    public static function get_recent_requests_parameters() {
        return new \external_function_parameters([
            'limit' => new \external_value(PARAM_INT, 'Maximum number of requests to return', VALUE_DEFAULT, 5),
        ]);
    }

    /**
     * Get recent quiz generation requests for the dashboard.
     *
     * @param int $limit Maximum number of requests to return.
     * @return array Array containing a 'requests' key with the list of request items.
     */
    public static function get_recent_requests($limit = 5) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_recent_requests_parameters(), [
            'limit' => $limit,
        ]);
        $limit = $params['limit'];

        // Validate context.
        $context = \context_system::instance();
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
     * @return \external_single_structure
     */
    public static function get_recent_requests_returns() {
        return new \external_single_structure([
            'requests' => new \external_multiple_structure(
                new \external_single_structure([
                    'id' => new \external_value(PARAM_INT, 'Request ID'),
                    'courseid' => new \external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new \external_value(PARAM_TEXT, 'Course full name'),
                    'status' => new \external_value(PARAM_TEXT, 'Request status'),
                    'total' => new \external_value(PARAM_INT, 'Total questions requested'),
                    'generated' => new \external_value(PARAM_INT, 'Number of questions generated'),
                    'approved' => new \external_value(PARAM_INT, 'Number of questions approved'),
                    'timecreated' => new \external_value(PARAM_TEXT, 'Formatted creation date'),
                    'timeago' => new \external_value(PARAM_TEXT, 'Human-readable time since creation'),
                ])
            ),
        ]);
    }
}
