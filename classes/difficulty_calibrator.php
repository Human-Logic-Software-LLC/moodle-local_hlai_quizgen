<?php
// This file is part of Moodle - http://moodle.org/.
//
// Moodle is free software: you can redistribute it and/or modify.
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,.
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the.
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License.
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Difficulty calibrator page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 STARTER
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Automatic difficulty calibration based on student performance.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Analyzes student performance to automatically calibrate question difficulty.
 */
class difficulty_calibrator {
    /** Minimum attempts required for calibration. */
    /** MIN_ATTEMPTS_FOR_CALIBRATION constant. */
    const MIN_ATTEMPTS_FOR_CALIBRATION = 10;

    /** Difficulty thresholds based on success rate. */
    /** DIFFICULTY_THRESHOLDS constant. */
    const DIFFICULTY_THRESHOLDS = [
        'very_easy' => ['min' => 90, 'max' => 100],
        'easy' => ['min' => 70, 'max' => 89],
        'medium' => ['min' => 40, 'max' => 69],
        'hard' => ['min' => 20, 'max' => 39],
        'very_hard' => ['min' => 0, 'max' => 19],
    ];

    /**
     * Calibrate question difficulty based on actual performance.
     *
     * @param int $questionid Moodle question ID
     * @return array Calibration results
     */
    public static function calibrate_question(int $questionid): array {
        global $DB;

        // Get question attempts from quiz statistics.
        $stats = self::get_question_statistics($questionid);

        if ($stats['attempt_count'] < self::MIN_ATTEMPTS_FOR_CALIBRATION) {
            return [
                'calibrated' => false,
                'reason' => 'insufficient_data',
                'attempts' => $stats['attempt_count'],
                'required' => self::MIN_ATTEMPTS_FOR_CALIBRATION,
            ];
        }

        // Calculate success rate.
        $successrate = $stats['correct_count'] / $stats['attempt_count'] * 100;

        // Determine actual difficulty.
        $actualdifficulty = self::calculate_difficulty_from_rate($successrate);

        // Get originally assigned difficulty.
        $question = $DB->get_record('hlai_quizgen_questions', ['moodle_questionid' => $questionid]);
        $originaldifficulty = $question ? $question->difficulty : 'medium';

        // Calculate discrimination index (how well it separates strong/weak students).
        $discrimination = self::calculate_discrimination_index($questionid);

        // Calculate average time spent.
        $avgtime = self::calculate_average_time($questionid);

        // Update question record with calibrated data.
        if ($question) {
            $update = new \stdClass();
            $update->id = $question->id;
            $update->calibrated_difficulty = $actualdifficulty;
            $update->success_rate = round($successrate, 2);
            $update->discrimination_index = round($discrimination, 3);
            $update->average_time_seconds = round($avgtime);
            $update->attempt_count = $stats['attempt_count'];
            $update->last_calibrated = time();

            $DB->update_record('hlai_quizgen_questions', $update);
        }

        return [
            'calibrated' => true,
            'original_difficulty' => $originaldifficulty,
            'actual_difficulty' => $actualdifficulty,
            'success_rate' => round($successrate, 2),
            'discrimination_index' => round($discrimination, 3),
            'average_time' => round($avgtime),
            'attempt_count' => $stats['attempt_count'],
            'mismatch' => $originaldifficulty !== $actualdifficulty,
            'quality_indicator' => self::assess_question_quality($successrate, $discrimination),
        ];
    }

    /**
     * Batch calibrate all questions for a request.
     *
     * @param int $requestid Request ID
     * @return array Calibration summary
     */
    public static function calibrate_request_questions(int $requestid): array {
        global $DB;

        $questions = $DB->get_records('hlai_quizgen_questions', ['requestid' => $requestid]);

        $results = [
            'total' => count($questions),
            'calibrated' => 0,
            'insufficient_data' => 0,
            'mismatches' => 0,
            'questions' => [],
        ];

        foreach ($questions as $question) {
            if ($question->moodle_questionid) {
                $calibration = self::calibrate_question($question->moodle_questionid);

                if ($calibration['calibrated']) {
                    $results['calibrated']++;
                    if ($calibration['mismatch']) {
                        $results['mismatches']++;
                    }
                } else {
                    $results['insufficient_data']++;
                }

                $results['questions'][$question->id] = $calibration;
            }
        }

        return $results;
    }

    /**
     * Get question performance statistics from Moodle.
     *
     * @param int $questionid Question ID
     * @return array Statistics
     */
    private static function get_question_statistics(int $questionid): array {
        global $DB;

        // Query question attempt data.
        $sql = "SELECT
                    COUNT(*) as attempt_count,
                    SUM(CASE WHEN qa.responsesummary = qa.rightanswer THEN 1 ELSE 0 END) as correct_count,
                    SUM(CASE WHEN qa.fraction >= 0.5 THEN 1 ELSE 0 END) as partial_correct_count
                FROM {question_attempts} qa
                WHERE qa.questionid = ?
                AND qa.responsesummary IS NOT NULL";

        $stats = $DB->get_record_sql($sql, [$questionid]);

        return [
            'attempt_count' => (int)($stats->attempt_count ?? 0),
            'correct_count' => (int)($stats->correct_count ?? 0),
            'partial_correct_count' => (int)($stats->partial_correct_count ?? 0),
        ];
    }

    /**
     * Calculate difficulty level from success rate.
     *
     * @param float $successrate Success rate percentage
     * @return string Difficulty level
     */
    private static function calculate_difficulty_from_rate(float $successrate): string {
        foreach (self::DIFFICULTY_THRESHOLDS as $level => $range) {
            if ($successrate >= $range['min'] && $successrate <= $range['max']) {
                return $level;
            }
        }
        return 'medium'; // Default fallback.
    }

    /**
     * Calculate discrimination index.
     * Measures how well question separates high vs low performers.
     *
     * @param int $questionid Question ID
     * @return float Discrimination index (-1 to 1, higher is better)
     */
    private static function calculate_discrimination_index(int $questionid): float {
        global $DB;

        // Get top 27% and bottom 27% of students by overall quiz performance.
        $sql = "SELECT
                    qa.userid,
                    qa.fraction as question_score,
                    quiz_totals.total_score
                FROM {question_attempts} qa
                JOIN (
                    SELECT
                        qua.userid,
                        AVG(qua.sumgrades / q.sumgrades) as total_score
                    FROM {quiz_attempts} qua
                    JOIN {quiz} q ON q.id = qua.quiz
                    GROUP BY qua.userid
                ) quiz_totals ON quiz_totals.userid = qa.userid
                WHERE qa.questionid = ?
                ORDER BY quiz_totals.total_score DESC";

        $attempts = $DB->get_records_sql($sql, [$questionid]);

        if (count($attempts) < 20) {
            return 0.0; // Not enough data.
        }

        // Get 27% boundaries.
        $count = count($attempts);
        $topcount = (int)ceil($count * 0.27);
        $bottomcount = (int)ceil($count * 0.27);

        $attemptarray = array_values($attempts);

        // Top group.
        $topgroup = array_slice($attemptarray, 0, $topcount);
        $topsuccess = array_sum(array_column($topgroup, 'question_score')) / $topcount;

        // Bottom group.
        $bottomgroup = array_slice($attemptarray, -$bottomcount);
        $bottomsuccess = array_sum(array_column($bottomgroup, 'question_score')) / $bottomcount;

        // Discrimination index = top_success - bottom_success.
        return $topsuccess - $bottomsuccess;
    }

    /**
     * Calculate average time spent on question.
     *
     * @param int $questionid Question ID
     * @return float Average time in seconds
     */
    private static function calculate_average_time(int $questionid): float {
        global $DB;

        $sql = "SELECT AVG(qa.timemodified - qa.timecreated) as avg_time
                FROM {question_attempts} qa
                WHERE qa.questionid = ?
                AND (qa.timemodified - qa.timecreated) BETWEEN 1 AND 600"; // 1s to 10min.

        $result = $DB->get_record_sql($sql, [$questionid]);

        return (float)($result->avg_time ?? 0);
    }

    /**
     * Assess overall question quality.
     *
     * @param float $successrate Success rate
     * @param float $discrimination Discrimination index
     * @return string Quality assessment
     */
    private static function assess_question_quality(float $successrate, float $discrimination): string {
        // Ideal: 40-70% success rate, discrimination > 0.3.

        if ($discrimination < 0) {
            return 'poor_discriminator'; // Question doesn't distinguish ability.
        }

        if ($successrate < 10) {
            return 'too_hard'; // Almost nobody gets it.
        }

        if ($successrate > 95) {
            return 'too_easy'; // Almost everyone gets it.
        }

        if ($successrate >= 40 && $successrate <= 70 && $discrimination >= 0.3) {
            return 'excellent'; // Ideal difficulty and discrimination.
        }

        if ($discrimination >= 0.3) {
            return 'good_discriminator'; // Good at separating abilities.
        }

        if ($successrate >= 30 && $successrate <= 80) {
            return 'acceptable'; // Reasonable difficulty.
        }

        return 'needs_review'; // Unclear quality.
    }

    /**
     * Get calibration recommendations for improving questions.
     *
     * @param int $questionid Question ID
     * @return array Recommendations
     */
    public static function get_recommendations(int $questionid): array {
        $calibration = self::calibrate_question($questionid);

        if (!$calibration['calibrated']) {
            return [
                'type' => 'insufficient_data',
                'message' => 'Need more attempts for calibration',
                'actions' => [],
            ];
        }

        $recommendations = [];

        // Check success rate.
        $rate = $calibration['success_rate'];
        if ($rate > 95) {
            $recommendations[] = [
                'issue' => 'too_easy',
                'severity' => 'high',
                'message' => 'Question is too easy (>95% success rate)',
                'suggestions' => [
                    'Add more complex distractors',
                    'Increase cognitive level (Bloom\'s)',
                    'Add time pressure',
                    'Consider removing obvious incorrect answers',
                ],
            ];
        } else if ($rate < 10) {
            $recommendations[] = [
                'issue' => 'too_hard',
                'severity' => 'high',
                'message' => 'Question is too hard (<10% success rate)',
                'suggestions' => [
                    'Simplify question wording',
                    'Remove trick elements',
                    'Check if content was covered adequately',
                    'Consider providing hints',
                ],
            ];
        }

        // Check discrimination.
        $disc = $calibration['discrimination_index'];
        if ($disc < 0) {
            $recommendations[] = [
                'issue' => 'negative_discrimination',
                'severity' => 'critical',
                'message' => 'Weak students perform better than strong students',
                'suggestions' => [
                    'Review answer key - may be incorrect',
                    'Check for ambiguous wording',
                    'Look for trick questions',
                    'Consider removing this question',
                ],
            ];
        } else if ($disc < 0.2) {
            $recommendations[] = [
                'issue' => 'poor_discrimination',
                'severity' => 'medium',
                'message' => 'Question doesn\'t effectively distinguish ability levels',
                'suggestions' => [
                    'Improve distractor quality',
                    'Ensure single clear correct answer',
                    'Check for partial credit issues',
                    'Review alignment with learning objectives',
                ],
            ];
        }

        // Check difficulty mismatch.
        if ($calibration['mismatch']) {
            $recommendations[] = [
                'issue' => 'difficulty_mismatch',
                'severity' => 'low',
                'message' => sprintf(
                    'Actual difficulty (%s) differs from intended (%s)',
                    $calibration['actual_difficulty'],
                    $calibration['original_difficulty']
                ),
                'suggestions' => [
                    'Update difficulty classification',
                    'Adjust question or adjust expectations',
                    'Review learning materials coverage',
                ],
            ];
        }

        // Overall assessment.
        $quality = $calibration['quality_indicator'];

        return [
            'type' => 'analysis',
            'quality' => $quality,
            'calibration' => $calibration,
            'recommendations' => $recommendations,
            'overall_status' => empty($recommendations) ? 'excellent' :
                (count($recommendations) > 2 ? 'needs_improvement' : 'good'),
        ];
    }

    /**
     * Generate calibration report for course or request.
     *
     * @param int $requestid Request ID
     * @return array Report data
     */
    public static function generate_calibration_report(int $requestid): array {
        $calibration = self::calibrate_request_questions($requestid);

        $report = [
            'summary' => [
                'total_questions' => $calibration['total'],
                'calibrated' => $calibration['calibrated'],
                'insufficient_data' => $calibration['insufficient_data'],
                'difficulty_mismatches' => $calibration['mismatches'],
                'calibration_rate' => $calibration['total'] > 0
                    ? round($calibration['calibrated'] / $calibration['total'] * 100, 1)
                    : 0,
            ],
            'questions' => [],
            'recommendations' => [],
        ];

        // Analyze each question.
        foreach ($calibration['questions'] as $qid => $data) {
            if ($data['calibrated']) {
                $report['questions'][$qid] = [
                    'calibration' => $data,
                    'recommendations' => self::get_recommendations($data['questionid'] ?? 0),
                ];
            }
        }

        // Generate overall recommendations.
        $poorquality = 0;
        $needsreview = 0;

        foreach ($report['questions'] as $qdata) {
            $quality = $qdata['calibration']['quality_indicator'] ?? 'unknown';
            if (in_array($quality, ['poor_discriminator', 'too_hard', 'too_easy'])) {
                $poorquality++;
            } else if ($quality === 'needs_review') {
                $needsreview++;
            }
        }

        $report['overall_assessment'] = [
            'quality_rating' => self::calculate_overall_quality_rating($calibration),
            'poor_quality_count' => $poorquality,
            'needs_review_count' => $needsreview,
            'action_required' => $poorquality > 0 || $needsreview > 2,
        ];

        return $report;
    }

    /**
     * Calculate overall quality rating.
     *
     * @param array $calibration Calibration data
     * @return string Quality rating
     */
    private static function calculate_overall_quality_rating(array $calibration): string {
        if ($calibration['calibrated'] === 0) {
            return 'insufficient_data';
        }

        $excellentcount = 0;
        $goodcount = 0;
        $poorcount = 0;

        foreach ($calibration['questions'] as $data) {
            if (!$data['calibrated']) {
                continue;
            }

            $quality = $data['quality_indicator'] ?? 'unknown';

            if ($quality === 'excellent') {
                $excellentcount++;
            } else if (in_array($quality, ['good_discriminator', 'acceptable'])) {
                $goodcount++;
            } else {
                $poorcount++;
            }
        }

        $total = $calibration['calibrated'];
        $excellentpct = ($excellentcount / $total) * 100;
        $poorpct = ($poorcount / $total) * 100;

        if ($excellentpct >= 70) {
            return 'excellent';
        } else if ($poorpct >= 30) {
            return 'poor';
        } else if ($excellentpct >= 40) {
            return 'good';
        } else {
            return 'fair';
        }
    }
}
