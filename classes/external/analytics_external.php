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
 * External API for analytics functions.
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
 * External API class for analytics endpoints.
 *
 * Provides external functions for retrieving course analytics
 * and teacher confidence data.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analytics_external extends external_api {
    /**
     * Describes the parameters for get_course_analytics.
     *
     * @return external_function_parameters
     */
    public static function get_course_analytics_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course ID to get analytics for'),
        ]);
    }

    /**
     * Get analytics data for a specific course.
     *
     * Returns question statistics grouped by type and difficulty,
     * as well as a 30-day generation trend.
     *
     * @param int $courseid The course ID.
     * @return array The analytics data.
     */
    public static function get_course_analytics($courseid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_course_analytics_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context and check capability.
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/hlai_quizgen:viewreports', $context);

        // Questions by type in this course.
        $types = $DB->get_records_sql(
            "SELECT questiontype, COUNT(*) as count,
                    AVG(validation_score) as avg_quality,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    AVG(regeneration_count) as avg_regens
             FROM {local_hlai_quizgen_questions}
             WHERE courseid = ?
             GROUP BY questiontype",
            [$courseid]
        );

        // Questions by difficulty.
        $difficulties = $DB->get_records_sql(
            "SELECT difficulty, COUNT(*) as count,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
             FROM {local_hlai_quizgen_questions}
             WHERE courseid = ?
             GROUP BY difficulty",
            [$courseid]
        );

        // Recent generation trend (last 30 days).
        $recent = $DB->get_records_sql(
            "SELECT DATE(FROM_UNIXTIME(timecreated)) as date, COUNT(*) as count
             FROM {local_hlai_quizgen_questions}
             WHERE courseid = ? AND timecreated > ?
             GROUP BY DATE(FROM_UNIXTIME(timecreated))
             ORDER BY date",
            [$courseid, time() - (30 * 24 * 60 * 60)]
        );

        // Format by_type results.
        $bytype = [];
        foreach (array_values($types) as $row) {
            $bytype[] = [
                'questiontype' => $row->questiontype,
                'count' => (int) $row->count,
                'avg_quality' => (float) round($row->avg_quality, 2),
                'approved' => (int) $row->approved,
                'avg_regens' => (float) round($row->avg_regens, 2),
            ];
        }

        // Format by_difficulty results.
        $bydifficulty = [];
        foreach (array_values($difficulties) as $row) {
            $bydifficulty[] = [
                'difficulty' => $row->difficulty,
                'count' => (int) $row->count,
                'approved' => (int) $row->approved,
            ];
        }

        // Format trend results.
        $trend = [];
        foreach (array_values($recent) as $row) {
            $trend[] = [
                'date' => $row->date,
                'count' => (int) $row->count,
            ];
        }

        return [
            'by_type' => $bytype,
            'by_difficulty' => $bydifficulty,
            'trend' => $trend,
        ];
    }

    /**
     * Describes the return value for get_course_analytics.
     *
     * @return external_single_structure
     */
    public static function get_course_analytics_returns() {
        return new external_single_structure([
            'by_type' => new external_multiple_structure(
                new external_single_structure([
                    'questiontype' => new external_value(PARAM_TEXT, 'The question type'),
                    'count' => new external_value(PARAM_INT, 'Number of questions of this type'),
                    'avg_quality' => new external_value(PARAM_FLOAT, 'Average validation score'),
                    'approved' => new external_value(PARAM_INT, 'Number of approved questions'),
                    'avg_regens' => new external_value(PARAM_FLOAT, 'Average regeneration count'),
                ]),
                'Questions grouped by type'
            ),
            'by_difficulty' => new external_multiple_structure(
                new external_single_structure([
                    'difficulty' => new external_value(PARAM_TEXT, 'The difficulty level'),
                    'count' => new external_value(PARAM_INT, 'Number of questions at this difficulty'),
                    'approved' => new external_value(PARAM_INT, 'Number of approved questions'),
                ]),
                'Questions grouped by difficulty'
            ),
            'trend' => new external_multiple_structure(
                new external_single_structure([
                    'date' => new external_value(PARAM_TEXT, 'The date (YYYY-MM-DD)'),
                    'count' => new external_value(PARAM_INT, 'Number of questions generated on this date'),
                ]),
                'Daily generation trend for the last 30 days'
            ),
        ]);
    }

    /**
     * Describes the parameters for get_teacher_confidence.
     *
     * @return external_function_parameters
     */
    public static function get_teacher_confidence_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Get teacher confidence trend data.
     *
     * Retrieves confidence ratings from approval logs for the current user
     * and calculates rolling averages in groups of 10.
     *
     * @return array The confidence data.
     */
    public static function get_teacher_confidence() {
        global $DB, $USER;

        // Validate parameters (none required).
        self::validate_parameters(self::get_teacher_confidence_parameters(), []);

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        $userid = $USER->id;

        // Get confidence ratings from logs.
        $logs = $DB->get_records_sql(
            "SELECT l.id, l.details, l.timecreated
             FROM {local_hlai_quizgen_logs} l
             WHERE l.userid = ? AND l.action = 'question_approved'
             ORDER BY l.timecreated ASC
             LIMIT 100",
            [$userid]
        );

        $confidences = [];
        foreach ($logs as $log) {
            $details = json_decode($log->details, true);
            if (isset($details['confidence'])) {
                $confidences[] = (int) $details['confidence'];
            }
        }

        // Calculate rolling average (groups of 10).
        $averages = [];
        $chunksize = 10;
        $chunks = array_chunk($confidences, $chunksize);
        foreach ($chunks as $i => $chunk) {
            $averages[] = [
                'group' => get_string('ajax_group_label', 'local_hlai_quizgen', ($i + 1)),
                'avg' => round(array_sum($chunk) / count($chunk), 2),
            ];
        }

        $overallavg = count($confidences) > 0
            ? round(array_sum($confidences) / count($confidences), 2)
            : 0.0;

        return [
            'overall_average' => (float) $overallavg,
            'total_ratings' => count($confidences),
            'trend' => $averages,
        ];
    }

    /**
     * Describes the return value for get_teacher_confidence.
     *
     * @return external_single_structure
     */
    public static function get_teacher_confidence_returns() {
        return new external_single_structure([
            'overall_average' => new external_value(PARAM_FLOAT, 'Overall average confidence rating'),
            'total_ratings' => new external_value(PARAM_INT, 'Total number of confidence ratings'),
            'trend' => new external_multiple_structure(
                new external_single_structure([
                    'group' => new external_value(PARAM_TEXT, 'The group label'),
                    'avg' => new external_value(PARAM_FLOAT, 'Average confidence for this group'),
                ]),
                'Rolling average confidence in groups of 10'
            ),
        ]);
    }
}
