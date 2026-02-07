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
 * Analytics data helper class for the AI Quiz Generator.
 *
 * Provides methods for retrieving and calculating analytics data
 * for the dashboard and analytics pages.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

/**
 * Analytics helper class
 */
class analytics_helper {
    /** @var int User ID */
    protected $userid;

    /** @var int|null Course ID (null for all courses) */
    protected $courseid;

    /** @var int Time filter (0 for all time) */
    protected $timefilter;

    /**
     * Constructor
     *
     * @param int $userid User ID
     * @param int|null $courseid Course ID (null for all courses)
     * @param int $timefilter Unix timestamp for time filter (0 for all time)
     */
    public function __construct($userid, $courseid = null, $timefilter = 0) {
        $this->userid = $userid;
        $this->courseid = $courseid;
        $this->timefilter = $timefilter;
    }

    /**
     * Get dashboard summary statistics
     *
     * @return object Statistics object
     */
    public function get_dashboard_stats() {
        global $DB;

        $stats = new \stdClass();

        // Total quizzes created.
        $sql = "SELECT COUNT(DISTINCT id) FROM {local_hlai_quizgen_requests} WHERE userid = ?";
        $params = [$this->userid];
        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }
        $sql .= " AND status = 'completed'";
        $stats->total_quizzes = $DB->count_records_sql($sql, $params);

        // Total questions.
        $sql = "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ?";
        $params = [$this->userid];
        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }
        $stats->total_questions = $DB->count_records_sql($sql, $params);

        // Approved questions.
        $sql = "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND status = 'approved'";
        $params = [$this->userid];
        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }
        $stats->approved_questions = $DB->count_records_sql($sql, $params);

        // Pending questions.
        $sql = "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND status = 'pending'";
        $params = [$this->userid];
        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }
        $stats->pending_questions = $DB->count_records_sql($sql, $params);

        // Rejected questions.
        $sql = "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND status = 'rejected'";
        $params = [$this->userid];
        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }
        $stats->rejected_questions = $DB->count_records_sql($sql, $params);

        // Average quality score.
        $sql = "SELECT AVG(validation_score) FROM {local_hlai_quizgen_questions}
                WHERE userid = ? AND validation_score IS NOT NULL";
        $params = [$this->userid];
        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }
        $avg = $DB->get_field_sql($sql, $params);
        $stats->avg_quality = $avg ? round($avg, 1) : 0;

        // First-time acceptance rate.
        $sql = "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
                WHERE userid = ? AND status = 'approved' AND regeneration_count = 0";
        $params = [$this->userid];
        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }
        $firsttime = $DB->count_records_sql($sql, $params);
        $stats->first_time_approved = $firsttime;

        // Calculate rates.
        $reviewed = $stats->approved_questions + $stats->rejected_questions;
        $stats->acceptance_rate = $reviewed > 0 ? round(($stats->approved_questions / $reviewed) * 100, 1) : 0;
        $stats->ftar = $stats->total_questions > 0 ? round(($firsttime / $stats->total_questions) * 100, 1) : 0;

        // Total regenerations.
        $sql = "SELECT SUM(regeneration_count) FROM {local_hlai_quizgen_questions} WHERE userid = ?";
        $params = [$this->userid];
        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }
        $stats->total_regenerations = $DB->get_field_sql($sql, $params) ?: 0;
        $stats->avg_regenerations = $stats->total_questions > 0
            ? round($stats->total_regenerations / $stats->total_questions, 2)
            : 0;

        return $stats;
    }

    /**
     * Get question type distribution.
     *
     * @return array Distribution data keyed by question type
     */
    public function get_question_type_distribution() {
        global $DB;

        $sql = "SELECT questiontype, COUNT(*) as count
                FROM {local_hlai_quizgen_questions}
                WHERE userid = ?";
        $params = [$this->userid];

        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }

        $sql .= " GROUP BY questiontype ORDER BY count DESC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get difficulty distribution
     *
     * @return array Distribution data keyed by difficulty level
     */
    public function get_difficulty_distribution() {
        global $DB;

        $sql = "SELECT difficulty, COUNT(*) as count
                FROM {local_hlai_quizgen_questions}
                WHERE userid = ?";
        $params = [$this->userid];

        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }

        $sql .= " GROUP BY difficulty";

        $results = $DB->get_records_sql($sql, $params);

        // Normalize to expected keys.
        $distribution = [
            'easy' => 0,
            'medium' => 0,
            'hard' => 0,
        ];

        foreach ($results as $row) {
            $level = strtolower($row->difficulty);
            if (isset($distribution[$level])) {
                $distribution[$level] = (int)$row->count;
            }
        }

        return $distribution;
    }

    /**
     * Get Bloom's taxonomy distribution.
     *
     * @return array Distribution data keyed by Bloom's level
     */
    public function get_blooms_distribution() {
        global $DB;

        $sql = "SELECT blooms_level, COUNT(*) as count
                FROM {local_hlai_quizgen_questions}
                WHERE userid = ? AND blooms_level IS NOT NULL";
        $params = [$this->userid];

        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }

        $sql .= " GROUP BY blooms_level";

        $results = $DB->get_records_sql($sql, $params);

        // Normalize to expected keys.
        $distribution = [
            'remember' => 0,
            'understand' => 0,
            'apply' => 0,
            'analyze' => 0,
            'evaluate' => 0,
            'create' => 0,
        ];

        foreach ($results as $row) {
            $level = strtolower($row->blooms_level);
            if (isset($distribution[$level])) {
                $distribution[$level] = (int)$row->count;
            }
        }

        return $distribution;
    }

    /**
     * Get acceptance trend data over time.
     *
     * @param int $days Number of days to look back
     * @return array Trend data with labels, acceptance_rates, and ftar_rates
     */
    public function get_acceptance_trend($days = 30) {
        global $DB;

        $starttime = time() - ($days * 24 * 60 * 60);
        $interval = $days <= 7 ? 1 : ($days <= 30 ? 3 : 7);

        $data = [
            'labels' => [],
            'acceptance_rates' => [],
            'ftar_rates' => [],
        ];

        for ($i = 0; $i < ceil($days / $interval); $i++) {
            $periodstart = $starttime + ($i * $interval * 24 * 60 * 60);
            $periodend = $periodstart + ($interval * 24 * 60 * 60);

            // Format label.
            $data['labels'][] = date('M j', $periodstart);

            // Get stats for this period.
            $sql = "SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN status = 'approved' AND regeneration_count = 0 THEN 1 ELSE 0 END) as first_time
                    FROM {local_hlai_quizgen_questions}
                    WHERE userid = ? AND timecreated >= ? AND timecreated < ?";
            $params = [$this->userid, $periodstart, $periodend];

            if ($this->courseid) {
                $sql .= " AND courseid = ?";
                $params[] = $this->courseid;
            }

            $row = $DB->get_record_sql($sql, $params);

            $reviewed = ($row->approved ?? 0) + ($row->rejected ?? 0);
            $data['acceptance_rates'][] = $reviewed > 0
                ? round(($row->approved / $reviewed) * 100, 1)
                : 0;
            $data['ftar_rates'][] = $row->total > 0
                ? round(($row->first_time / $row->total) * 100, 1)
                : 0;
        }

        return $data;
    }

    /**
     * Get regeneration statistics by question type.
     *
     * @return array Regeneration stats keyed by question type
     */
    public function get_regeneration_by_type() {
        global $DB;

        $sql = "SELECT questiontype,
                       COUNT(*) as total,
                       SUM(regeneration_count) as total_regens,
                       AVG(regeneration_count) as avg_regenerations
                FROM {local_hlai_quizgen_questions}
                WHERE userid = ?";
        $params = [$this->userid];

        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }

        $sql .= " GROUP BY questiontype ORDER BY avg_regenerations DESC";

        $results = $DB->get_records_sql($sql, $params);

        $data = [];
        foreach ($results as $row) {
            if (!empty($row->questiontype)) {
                $data[$row->questiontype] = [
                    'total' => (int)$row->total,
                    'total_regenerations' => (int)$row->total_regens,
                    'avg_regenerations' => round((float)$row->avg_regenerations, 2),
                ];
            }
        }

        return $data;
    }

    /**
     * Get quality score distribution.
     *
     * @return array Distribution in ranges (0-20, 21-40, 41-60, 61-80, 81-100)
     */
    public function get_quality_distribution() {
        global $DB;

        $ranges = [
            '0-20' => [0, 20],
            '21-40' => [21, 40],
            '41-60' => [41, 60],
            '61-80' => [61, 80],
            '81-100' => [81, 100],
        ];

        $distribution = [];

        foreach ($ranges as $label => $range) {
            $sql = "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
                    WHERE userid = ? AND validation_score >= ? AND validation_score <= ?";
            $params = [$this->userid, $range[0], $range[1]];

            if ($this->courseid) {
                $sql .= " AND courseid = ?";
                $params[] = $this->courseid;
            }
            if ($this->timefilter) {
                $sql .= " AND timecreated >= ?";
                $params[] = $this->timefilter;
            }

            $distribution[$label] = $DB->count_records_sql($sql, $params);
        }

        return $distribution;
    }

    /**
     * Get recent requests/activity.
     *
     * @param int $limit Maximum number of records
     * @return array Recent requests with course info
     */
    public function get_recent_requests($limit = 5) {
        global $DB;

        $sql = "SELECT r.id, r.courseid, r.status, r.total_questions, r.questions_generated,
                       r.timecreated, c.fullname as coursename
                FROM {local_hlai_quizgen_requests} r
                JOIN {course} c ON c.id = r.courseid
                WHERE r.userid = ?";
        $params = [$this->userid];

        if ($this->courseid) {
            $sql .= " AND r.courseid = ?";
            $params[] = $this->courseid;
        }

        $sql .= " ORDER BY r.timecreated DESC LIMIT " . (int)$limit;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get rejection reasons distribution
     *
     * @param int $limit Maximum number of reasons to return
     * @return array Rejection reasons with counts
     */
    public function get_rejection_reasons($limit = 10) {
        global $DB;

        $sql = "SELECT COALESCE(rejection_reason, 'Not specified') as reason, COUNT(*) as count
                FROM {local_hlai_quizgen_questions}
                WHERE userid = ? AND status = 'rejected'";
        $params = [$this->userid];

        if ($this->courseid) {
            $sql .= " AND courseid = ?";
            $params[] = $this->courseid;
        }
        if ($this->timefilter) {
            $sql .= " AND timecreated >= ?";
            $params[] = $this->timefilter;
        }

        $sql .= " GROUP BY rejection_reason ORDER BY count DESC LIMIT " . (int)$limit;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get course-specific analytics.
     *
     * @return array Course analytics data
     */
    public function get_course_analytics() {
        global $DB;

        if (!$this->courseid) {
            return [];
        }

        $sql = "SELECT
                    c.id as courseid,
                    c.fullname as coursename,
                    COUNT(q.id) as total_questions,
                    SUM(CASE WHEN q.status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN q.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    AVG(q.validation_score) as avg_quality,
                    AVG(q.regeneration_count) as avg_regenerations,
                    COUNT(DISTINCT r.id) as total_requests
                FROM {course} c
                LEFT JOIN {local_hlai_quizgen_requests} r ON r.courseid = c.id AND r.userid = ?
                LEFT JOIN {local_hlai_quizgen_questions} q ON q.courseid = c.id AND q.userid = ?
                WHERE c.id = ?
                GROUP BY c.id, c.fullname";

        return $DB->get_record_sql($sql, [$this->userid, $this->userid, $this->courseid]);
    }
}
