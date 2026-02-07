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
 * Advanced analytics for question performance and trends.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides comprehensive analytics on question usage and performance.
 */
class question_analytics {
    /**
     * Get comprehensive analytics for a question.
     *
     * @param int $questionid Moodle question ID
     * @return array Analytics data
     */
    public static function get_question_analytics(int $questionid): array {
        return [
            'usage' => self::get_usage_stats($questionid),
            'performance' => self::get_performance_stats($questionid),
            'trends' => self::get_trend_analysis($questionid),
            'comparisons' => self::get_comparative_stats($questionid),
            'recommendations' => self::get_improvement_recommendations($questionid),
        ];
    }

    /**
     * Get usage statistics.
     *
     * @param int $questionid Question ID
     * @return array Usage stats
     */
    private static function get_usage_stats(int $questionid): array {
        global $DB;

        // Total attempts.
        $totalattempts = $DB->count_records('question_attempts', ['questionid' => $questionid]);

        // Unique students.
        $sql = "SELECT COUNT(DISTINCT userid) as unique_students
                FROM {question_attempts}
                WHERE questionid = ?";
        $uniquestudents = $DB->get_field_sql($sql, [$questionid]) ?? 0;

        // Quizzes using this question.
        $sql = "SELECT COUNT(DISTINCT qua.quiz) as quiz_count
                FROM {question_attempts} qa
                JOIN {quiz_attempts} qua ON qua.uniqueid = qa.questionusageid
                WHERE qa.questionid = ?";
        $quizcount = $DB->get_field_sql($sql, [$questionid]) ?? 0;

        // First and last usage.
        $sql = "SELECT MIN(timecreated) as first_used, MAX(timecreated) as last_used
                FROM {question_attempts}
                WHERE questionid = ?";
        $usage = $DB->get_record_sql($sql, [$questionid]);

        return [
            'total_attempts' => $totalattempts,
            'unique_students' => $uniquestudents,
            'quiz_count' => $quizcount,
            'first_used' => $usage->first_used ?? null,
            'last_used' => $usage->last_used ?? null,
            'days_in_use' => $usage->first_used
                ? ceil((time() - $usage->first_used) / 86400)
                : 0,
        ];
    }

    /**
     * Get performance statistics.
     *
     * @param int $questionid Question ID
     * @return array Performance stats
     */
    private static function get_performance_stats(int $questionid): array {
        global $DB;

        $sql = "SELECT
                    AVG(qa.fraction) as avg_score,
                    MIN(qa.fraction) as min_score,
                    MAX(qa.fraction) as max_score,
                    STDDEV(qa.fraction) as std_dev,
                    SUM(CASE WHEN qa.fraction >= 1.0 THEN 1 ELSE 0 END) as fully_correct,
                    SUM(CASE WHEN qa.fraction > 0 AND qa.fraction < 1.0 THEN 1 ELSE 0 END) as partially_correct,
                    SUM(CASE WHEN qa.fraction = 0 THEN 1 ELSE 0 END) as incorrect,
                    AVG(qa.timemodified - qa.timecreated) as avg_time_spent
                FROM {question_attempts} qa
                WHERE qa.questionid = ?
                AND qa.fraction IS NOT NULL";

        $stats = $DB->get_record_sql($sql, [$questionid]);

        $totalattempts = $DB->count_records('question_attempts', ['questionid' => $questionid]);

        return [
            'average_score' => round(($stats->avg_score ?? 0) * 100, 1),
            'score_std_dev' => round(($stats->std_dev ?? 0) * 100, 1),
            'min_score' => round(($stats->min_score ?? 0) * 100, 1),
            'max_score' => round(($stats->max_score ?? 0) * 100, 1),
            'fully_correct_count' => (int)($stats->fully_correct ?? 0),
            'partially_correct_count' => (int)($stats->partially_correct ?? 0),
            'incorrect_count' => (int)($stats->incorrect ?? 0),
            'fully_correct_pct' => $totalattempts > 0
                ? round(($stats->fully_correct / $totalattempts) * 100, 1)
                : 0,
            'average_time_seconds' => round($stats->avg_time_spent ?? 0),
        ];
    }

    /**
     * Get trend analysis over time.
     *
     * @param int $questionid Question ID
     * @return array Trend data
     */
    private static function get_trend_analysis(int $questionid): array {
        global $DB;

        // Performance by month.
        $sql = "SELECT
                    DATE_FORMAT(FROM_UNIXTIME(qa.timecreated), '%Y-%m') as month,
                    AVG(qa.fraction) as avg_score,
                    COUNT(*) as attempt_count
                FROM {question_attempts} qa
                WHERE qa.questionid = ?
                AND qa.fraction IS NOT NULL
                GROUP BY DATE_FORMAT(FROM_UNIXTIME(qa.timecreated), '%Y-%m')
                ORDER BY month DESC
                LIMIT 12";

        $monthly = $DB->get_records_sql($sql, [$questionid]);

        $trend = [];
        foreach ($monthly as $data) {
            $trend[] = [
                'period' => $data->month,
                'average_score' => round($data->avg_score * 100, 1),
                'attempts' => $data->attempt_count,
            ];
        }

        // Calculate trend direction.
        $direction = 'stable';
        if (count($trend) >= 3) {
            $recent = array_slice($trend, 0, 3);
            $older = array_slice($trend, -3);

            $recentavg = array_sum(array_column($recent, 'average_score')) / count($recent);
            $olderavg = array_sum(array_column($older, 'average_score')) / count($older);

            if ($recentavg > $olderavg + 5) {
                $direction = 'improving';
            } else if ($recentavg < $olderavg - 5) {
                $direction = 'declining';
            }
        }

        return [
            'monthly_data' => $trend,
            'trend_direction' => $direction,
            'months_tracked' => count($trend),
        ];
    }

    /**
     * Get comparative statistics.
     *
     * @param int $questionid Question ID
     * @return array Comparative stats
     */
    private static function get_comparative_stats(int $questionid): array {
        global $DB;

        $question = $DB->get_record('question', ['id' => $questionid]);
        if (!$question) {
            return ['error' => 'Question not found'];
        }

        // Get average for same question type.
        $sql = "SELECT AVG(avg_fraction) as type_avg
                FROM (
                    SELECT qa.questionid, AVG(qa.fraction) as avg_fraction
                    FROM {question_attempts} qa
                    JOIN {question} q ON q.id = qa.questionid
                    WHERE q.qtype = ?
                    GROUP BY qa.questionid
                ) subquery";

        $typeavg = $DB->get_field_sql($sql, [$question->qtype]) ?? 0;

        // Get this question's average.
        $thisavg = $DB->get_field_sql(
            "SELECT AVG(fraction) FROM {question_attempts} WHERE questionid = ?",
            [$questionid]
        ) ?? 0;

        // Percentile rank.
        $sql = "SELECT COUNT(*) as better_count
                FROM (
                    SELECT qa.questionid, AVG(qa.fraction) as avg_fraction
                    FROM {question_attempts} qa
                    JOIN {question} q ON q.id = qa.questionid
                    WHERE q.qtype = ?
                    GROUP BY qa.questionid
                    HAVING AVG(qa.fraction) > ?
                ) subquery";

        $bettercount = $DB->get_field_sql($sql, [$question->qtype, $thisavg]) ?? 0;

        $sql = "SELECT COUNT(DISTINCT questionid) as total_questions
                FROM {question_attempts} qa
                JOIN {question} q ON q.id = qa.questionid
                WHERE q.qtype = ?";

        $totalquestions = $DB->get_field_sql($sql, [$question->qtype]) ?? 1;

        $percentile = $totalquestions > 0
            ? round((1 - ($bettercount / $totalquestions)) * 100, 1)
            : 50;

        return [
            'question_type' => $question->qtype,
            'this_question_avg' => round($thisavg * 100, 1),
            'type_average' => round($typeavg * 100, 1),
            'vs_type_avg' => round(($thisavg - $typeavg) * 100, 1),
            'percentile_rank' => $percentile,
            'performance_category' => self::categorize_performance($percentile),
        ];
    }

    /**
     * Categorize performance based on percentile.
     *
     * @param float $percentile Percentile rank
     * @return string Category
     */
    private static function categorize_performance(float $percentile): string {
        if ($percentile >= 90) {
            return 'top_performer';
        } else if ($percentile >= 75) {
            return 'above_average';
        } else if ($percentile >= 50) {
            return 'average';
        } else if ($percentile >= 25) {
            return 'below_average';
        } else {
            return 'needs_improvement';
        }
    }

    /**
     * Get improvement recommendations.
     *
     * @param int $questionid Question ID
     * @return array Recommendations
     */
    private static function get_improvement_recommendations(int $questionid): array {
        $performance = self::get_performance_stats($questionid);
        $comparative = self::get_comparative_stats($questionid);
        $trends = self::get_trend_analysis($questionid);

        $recommendations = [];

        // Low success rate.
        if ($performance['average_score'] < 40) {
            $recommendations[] = [
                'type' => 'difficulty',
                'priority' => 'high',
                'issue' => 'Low success rate (' . $performance['average_score'] . '%)',
                'suggestions' => [
                    'Review question clarity and wording',
                    'Check if content was adequately covered',
                    'Consider providing hints or scaffolding',
                    'Verify answer key is correct',
                ],
            ];
        }

        // High success rate.
        if ($performance['average_score'] > 90) {
            $recommendations[] = [
                'type' => 'difficulty',
                'priority' => 'medium',
                'issue' => 'Very high success rate (' . $performance['average_score'] . '%)',
                'suggestions' => [
                    'Question may be too easy',
                    'Consider increasing complexity',
                    'Add more plausible distractors',
                    'Elevate Bloom\'s taxonomy level',
                ],
            ];
        }

        // High variability.
        if ($performance['score_std_dev'] > 30) {
            $recommendations[] = [
                'type' => 'consistency',
                'priority' => 'medium',
                'issue' => 'High score variability (SD: ' . $performance['score_std_dev'] . ')',
                'suggestions' => [
                    'Question may be ambiguous',
                    'Multiple interpretations possible',
                    'Review for clarity',
                    'Consider partial credit rubric',
                ],
            ];
        }

        // Declining trend.
        if ($trends['trend_direction'] === 'declining') {
            $recommendations[] = [
                'type' => 'trend',
                'priority' => 'high',
                'issue' => 'Performance declining over time',
                'suggestions' => [
                    'Content may need refreshing',
                    'Question may have become outdated',
                    'Check if teaching approach changed',
                    'Review prerequisite knowledge',
                ],
            ];
        }

        // Below average for type.
        if (isset($comparative['vs_type_avg']) && $comparative['vs_type_avg'] < -10) {
            $recommendations[] = [
                'type' => 'comparative',
                'priority' => 'medium',
                'issue' => 'Below average for question type (' . $comparative['vs_type_avg'] . '% difference)',
                'suggestions' => [
                    'Review similar high-performing questions',
                    'Analyze what makes other questions effective',
                    'Consider revising or replacing',
                    'Consult with peers for feedback',
                ],
            ];
        }

        // Insufficient usage.
        $usage = self::get_usage_stats($questionid);
        if ($usage['total_attempts'] < 10) {
            $recommendations[] = [
                'type' => 'usage',
                'priority' => 'low',
                'issue' => 'Limited usage data (only ' . $usage['total_attempts'] . ' attempts)',
                'suggestions' => [
                    'Use in more quizzes to gather data',
                    'Preliminary analysis may not be reliable',
                    'Monitor after more attempts',
                    'Consider pilot testing',
                ],
            ];
        }

        return [
            'has_recommendations' => !empty($recommendations),
            'recommendation_count' => count($recommendations),
            'recommendations' => $recommendations,
            'overall_health' => self::calculate_overall_health($performance, $comparative, $trends),
        ];
    }

    /**
     * Calculate overall question health score.
     *
     * @param array $performance Performance stats
     * @param array $comparative Comparative stats
     * @param array $trends Trend data
     * @return array Health assessment
     */
    private static function calculate_overall_health(
        array $performance,
        array $comparative,
        array $trends
    ): array {
        $score = 50; // Start at middle.

        // Success rate contribution (30 points).
        $successrate = $performance['average_score'];
        if ($successrate >= 50 && $successrate <= 80) {
            $score += 30; // Ideal range.
        } else if ($successrate >= 40 && $successrate <= 90) {
            $score += 20; // Acceptable.
        } else if ($successrate >= 30 && $successrate <= 95) {
            $score += 10; // Marginal.
        }

        // Comparative performance (20 points).
        if (isset($comparative['vs_type_avg'])) {
            $vsavg = $comparative['vs_type_avg'];
            if ($vsavg > 0) {
                $score += min(20, $vsavg); // Bonus for above average.
            } else {
                $score += max(-20, $vsavg); // Penalty for below average.
            }
        }

        // Trend contribution (20 points).
        if ($trends['trend_direction'] === 'improving') {
            $score += 20;
        } else if ($trends['trend_direction'] === 'declining') {
            $score -= 20;
        } else {
            $score += 10; // Stable is good.
        }

        // Consistency (10 points).
        $stddev = $performance['score_std_dev'];
        if ($stddev < 20) {
            $score += 10; // Consistent.
        } else if ($stddev < 30) {
            $score += 5; // Moderately consistent.
        }

        // Cap score at 0-100.
        $score = max(0, min(100, $score));

        // Categorize health.
        $category = 'poor';
        if ($score >= 80) {
            $category = 'excellent';
        } else if ($score >= 65) {
            $category = 'good';
        } else if ($score >= 50) {
            $category = 'fair';
        }

        return [
            'health_score' => round($score),
            'health_category' => $category,
            'status' => $score >= 50 ? 'healthy' : 'needs_attention',
        ];
    }

    /**
     * Generate comprehensive analytics report for request.
     *
     * @param int $requestid Request ID
     * @return array Analytics report
     */
    public static function generate_analytics_report(int $requestid): array {
        global $DB;

        $questions = $DB->get_records('hlai_quizgen_questions', ['requestid' => $requestid]);

        $report = [
            'summary' => [
                'total_questions' => count($questions),
                'analyzed_questions' => 0,
                'overall_health_avg' => 0,
                'questions_needing_attention' => 0,
            ],
            'questions' => [],
        ];

        $healthscores = [];

        foreach ($questions as $question) {
            if (!$question->moodle_questionid) {
                continue; // Not deployed yet.
            }

            $analytics = self::get_question_analytics($question->moodle_questionid);

            // Only include if has usage.
            if ($analytics['usage']['total_attempts'] > 0) {
                $report['questions'][$question->id] = $analytics;
                $report['summary']['analyzed_questions']++;

                $healthscores[] = $analytics['recommendations']['overall_health']['health_score'];

                if ($analytics['recommendations']['overall_health']['status'] === 'needs_attention') {
                    $report['summary']['questions_needing_attention']++;
                }
            }
        }

        if (!empty($healthscores)) {
            $report['summary']['overall_health_avg'] = round(
                array_sum($healthscores) / count($healthscores)
            );
        }

        return $report;
    }
}
