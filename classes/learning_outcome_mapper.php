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
 * Learning outcome alignment and mapping.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Aligns questions with learning outcomes and competencies.
 */
class learning_outcome_mapper {
    /** @var array Bloom's taxonomy levels. */
    const BLOOMS_LEVELS = [
        'remember' => 1,
        'understand' => 2,
        'apply' => 3,
        'analyze' => 4,
        'evaluate' => 5,
        'create' => 6,
    ];

    /** @var array Common action verbs for each Bloom's level. */
    const BLOOMS_VERBS = [
        'remember' => ['define', 'list', 'recall', 'identify', 'name', 'state', 'describe'],
        'understand' => ['explain', 'summarize', 'interpret', 'classify', 'compare', 'exemplify'],
        'apply' => ['use', 'demonstrate', 'implement', 'solve', 'execute', 'operate'],
        'analyze' => ['differentiate', 'organize', 'attribute', 'deconstruct', 'examine'],
        'evaluate' => ['check', 'critique', 'judge', 'test', 'assess', 'appraise'],
        'create' => ['design', 'construct', 'produce', 'invent', 'develop', 'formulate'],
    ];

    /**
     * Extract learning outcomes from course.
     *
     * @param int $courseid Course ID
     * @return array Learning outcomes
     */
    public static function get_course_outcomes(int $courseid): array {
        global $DB;

        $outcomes = [];

        // Get Moodle competencies if available.
        if (class_exists('\\core_competency\\course_competency')) {
            try {
                $coursecompetencies = \core_competency\course_competency::list_competencies($courseid);

                foreach ($coursecompetencies as $competency) {
                    $outcomes[] = [
                        'id' => $competency->get('id'),
                        'type' => 'competency',
                        'shortname' => $competency->get('shortname'),
                        'description' => $competency->get('description'),
                        'idnumber' => $competency->get('idnumber'),
                        'source' => 'moodle_competency',
                    ];
                }
            } catch (\Exception $e) {
                // Competencies not available.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Get custom outcomes from course summary.
        $course = $DB->get_record('course', ['id' => $courseid]);
        if ($course && !empty($course->summary)) {
            $extracted = self::extract_outcomes_from_text($course->summary);
            $outcomes = array_merge($outcomes, $extracted);
        }

        // Get from course sections.
        $sections = $DB->get_records('course_sections', ['course' => $courseid]);
        foreach ($sections as $section) {
            if (!empty($section->summary)) {
                $extracted = self::extract_outcomes_from_text($section->summary);
                foreach ($extracted as $outcome) {
                    $outcome['section'] = $section->section;
                    $outcomes[] = $outcome;
                }
            }
        }

        return $outcomes;
    }

    /**
     * Extract learning outcomes from text using pattern matching.
     *
     * @param string $text Text to analyze
     * @return array Extracted outcomes
     */
    private static function extract_outcomes_from_text(string $text): array {
        $outcomes = [];

        // Common patterns for learning outcomes.
        $patterns = [
            '/(?:students? (?:will|should|can|able to))\s+([^.]+)/i',
            '/(?:learning outcomes?:)\s*([^.]+)/i',
            '/(?:objectives?:)\s*([^.]+)/i',
            '/(?:by the end|upon completion).*?students?\s+(?:will|should)\s+([^.]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $match) {
                    $outcome = trim($match);
                    if (strlen($outcome) > 10) { // Meaningful length.
                        $blooms = self::detect_blooms_level($outcome);
                        $outcomes[] = [
                            'type' => 'extracted',
                            'description' => $outcome,
                            'blooms_level' => $blooms,
                            'source' => 'text_extraction',
                        ];
                    }
                }
            }
        }

        return $outcomes;
    }

    /**
     * Detect Bloom's taxonomy level from text.
     *
     * @param string $text Text to analyze
     * @return string Bloom's level
     */
    public static function detect_blooms_level(string $text): string {
        $text = strtolower($text);

        // Check for action verbs.
        foreach (self::BLOOMS_VERBS as $level => $verbs) {
            foreach ($verbs as $verb) {
                if (strpos($text, $verb) !== false) {
                    return $level;
                }
            }
        }

        // Default to understand.
        return 'understand';
    }

    /**
     * Map question to learning outcomes.
     *
     * @param int $questionid Question ID
     * @param int $courseid Course ID
     * @return array Mapping results
     */
    public static function map_question_to_outcomes(int $questionid, int $courseid): array {
        global $DB;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid]);
        if (!$question) {
            return ['error' => 'Question not found'];
        }

        $outcomes = self::get_course_outcomes($courseid);

        if (empty($outcomes)) {
            return [
                'mapped' => false,
                'reason' => 'no_outcomes_defined',
                'suggestion' => 'Define learning outcomes in course settings',
            ];
        }

        // Analyze question content.
        $questiontext = $question->questiontext ?? '';
        $questionblooms = $question->blooms_level ?? self::detect_blooms_level($questiontext);

        // Find best matching outcomes.
        $matches = [];

        foreach ($outcomes as $outcome) {
            $score = self::calculate_alignment_score(
                $questiontext,
                $questionblooms,
                $outcome
            );

            if ($score > 0.3) { // Threshold for relevance.
                $matches[] = [
                    'outcome' => $outcome,
                    'alignment_score' => $score,
                    'confidence' => self::score_to_confidence($score),
                ];
            }
        }

        // Sort by score.
        usort($matches, function ($a, $b) {
            return $b['alignment_score'] <=> $a['alignment_score'];
        });

        // Store mapping.
        if (!empty($matches)) {
            $bestmatch = $matches[0];

            $mapping = new \stdClass();
            $mapping->questionid = $questionid;
            $mapping->outcome_data = json_encode($bestmatch['outcome']);
            $mapping->alignment_score = $bestmatch['alignment_score'];
            $mapping->confidence = $bestmatch['confidence'];
            $mapping->timecreated = time();

            // Check if mapping exists.
            $existing = $DB->get_record(
                'hlai_quizgen_outcome_map',
                ['questionid' => $questionid]
            );

            if ($existing) {
                $mapping->id = $existing->id;
                $DB->update_record('hlai_quizgen_outcome_map', $mapping);
            } else {
                $DB->insert_record('hlai_quizgen_outcome_map', $mapping);
            }
        }

        return [
            'mapped' => !empty($matches),
            'question_blooms' => $questionblooms,
            'matches' => array_slice($matches, 0, 5), // Top 5.
            'total_outcomes' => count($outcomes),
            'total_matches' => count($matches),
        ];
    }

    /**
     * Calculate alignment score between question and outcome.
     *
     * @param string $questiontext Question text
     * @param string $questionblooms Question Bloom's level
     * @param array $outcome Learning outcome
     * @return float Score (0-1)
     */
    private static function calculate_alignment_score(
        string $questiontext,
        string $questionblooms,
        array $outcome
    ): float {
        $score = 0.0;

        // Bloom's level alignment (40% weight).
        $outcomeblooms = $outcome['blooms_level'] ?? 'understand';
        if ($questionblooms === $outcomeblooms) {
            $score += 0.4;
        } else {
            // Partial credit for adjacent levels.
            $qlevel = self::BLOOMS_LEVELS[$questionblooms] ?? 2;
            $olevel = self::BLOOMS_LEVELS[$outcomeblooms] ?? 2;
            $diff = abs($qlevel - $olevel);
            if ($diff === 1) {
                $score += 0.2;
            } else if ($diff === 2) {
                $score += 0.1;
            }
        }

        // Content similarity (60% weight).
        $outcomedesc = $outcome['description'] ?? '';
        $similarity = self::calculate_text_similarity($questiontext, $outcomedesc);
        $score += $similarity * 0.6;

        return min(1.0, $score);
    }

    /**
     * Calculate text similarity (simple keyword overlap).
     *
     * @param string $text1 First text
     * @param string $text2 Second text
     * @return float Similarity score (0-1)
     */
    private static function calculate_text_similarity(string $text1, string $text2): float {
        // Normalize and tokenize.
        $words1 = self::tokenize(strtolower($text1));
        $words2 = self::tokenize(strtolower($text2));

        if (empty($words1) || empty($words2)) {
            return 0.0;
        }

        // Calculate Jaccard similarity.
        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Tokenize text into meaningful words.
     *
     * @param string $text Text to tokenize
     * @return array Words
     */
    private static function tokenize(string $text): array {
        // Remove common stop words.
        $stopwords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at',
                      'to', 'for', 'of', 'with', 'is', 'are', 'was', 'were'];

        $words = preg_split('/\W+/', $text);
        $words = array_filter($words, function ($word) use ($stopwords) {
            return strlen($word) > 2 && !in_array($word, $stopwords);
        });

        return array_values($words);
    }

    /**
     * Convert score to confidence level.
     *
     * @param float $score Alignment score
     * @return string Confidence level
     */
    private static function score_to_confidence(float $score): string {
        if ($score >= 0.8) {
            return 'very_high';
        } else if ($score >= 0.6) {
            return 'high';
        } else if ($score >= 0.4) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Generate outcome coverage report for request.
     *
     * @param int $requestid Request ID
     * @param int $courseid Course ID
     * @return array Coverage report
     */
    public static function generate_coverage_report(int $requestid, int $courseid): array {
        global $DB;

        $outcomes = self::get_course_outcomes($courseid);
        $questions = $DB->get_records('hlai_quizgen_questions', ['requestid' => $requestid]);

        $report = [
            'total_outcomes' => count($outcomes),
            'total_questions' => count($questions),
            'outcome_coverage' => [],
            'uncovered_outcomes' => [],
            'coverage_percentage' => 0,
            'blooms_distribution' => [],
        ];

        // Map all questions.
        $mappings = [];
        foreach ($questions as $question) {
            $mapping = self::map_question_to_outcomes($question->id, $courseid);
            if ($mapping['mapped'] && !empty($mapping['matches'])) {
                $mappings[$question->id] = $mapping['matches'][0]; // Best match.
            }
        }

        // Calculate coverage.
        $coveredoutcomes = [];
        foreach ($mappings as $qid => $match) {
            $outcomeid = $match['outcome']['id'] ?? $match['outcome']['description'];
            if (!isset($coveredoutcomes[$outcomeid])) {
                $coveredoutcomes[$outcomeid] = [
                    'outcome' => $match['outcome'],
                    'questions' => [],
                    'avg_confidence' => 0,
                ];
            }
            $coveredoutcomes[$outcomeid]['questions'][] = $qid;
        }

        // Calculate averages.
        foreach ($coveredoutcomes as $oid => &$data) {
            $confidences = [];
            foreach ($data['questions'] as $qid) {
                $confidences[] = $mappings[$qid]['alignment_score'] ?? 0;
            }
            $data['avg_confidence'] = count($confidences) > 0
                ? array_sum($confidences) / count($confidences)
                : 0;
        }

        $report['outcome_coverage'] = array_values($coveredoutcomes);
        $report['coverage_percentage'] = count($outcomes) > 0
            ? round(count($coveredoutcomes) / count($outcomes) * 100, 1)
            : 0;

        // Find uncovered.
        foreach ($outcomes as $outcome) {
            $outcomeid = $outcome['id'] ?? $outcome['description'];
            if (!isset($coveredoutcomes[$outcomeid])) {
                $report['uncovered_outcomes'][] = $outcome;
            }
        }

        // Bloom's distribution.
        foreach ($questions as $question) {
            $blooms = $question->blooms_level ?? 'understand';
            if (!isset($report['blooms_distribution'][$blooms])) {
                $report['blooms_distribution'][$blooms] = 0;
            }
            $report['blooms_distribution'][$blooms]++;
        }

        return $report;
    }

    /**
     * Suggest additional questions to improve coverage.
     *
     * @param int $requestid Request ID
     * @param int $courseid Course ID
     * @return array Suggestions
     */
    public static function suggest_additional_questions(int $requestid, int $courseid): array {
        $report = self::generate_coverage_report($requestid, $courseid);

        $suggestions = [];

        // Suggest questions for uncovered outcomes.
        foreach ($report['uncovered_outcomes'] as $outcome) {
            $suggestions[] = [
                'type' => 'uncovered_outcome',
                'priority' => 'high',
                'outcome' => $outcome,
                'suggested_blooms' => $outcome['blooms_level'] ?? 'understand',
                'suggested_count' => 2,
                'rationale' => 'No questions currently assess this outcome',
            ];
        }

        // Check Bloom's balance.
        $total = $report['total_questions'];
        foreach (self::BLOOMS_LEVELS as $level => $order) {
            $current = $report['blooms_distribution'][$level] ?? 0;
            $percentage = $total > 0 ? ($current / $total) * 100 : 0;

            // Target distribution: more lower levels, fewer higher levels.
            $target = match ($order) {
                1 => 15, // Remember 15%
                2 => 25, // Understand 25%
                3 => 30, // Apply 30%
                4 => 20, // Analyze 20%
                5 => 7, // Evaluate 7%
                6 => 3, // Create 3%
                default => 15
            };

            $diff = $target - $percentage;
            if ($diff > 10) { // More than 10% below target.
                $suggestions[] = [
                    'type' => 'blooms_balance',
                    'priority' => 'medium',
                    'blooms_level' => $level,
                    'current_percentage' => round($percentage, 1),
                    'target_percentage' => $target,
                    'suggested_count' => ceil(($diff / 100) * $total),
                    'rationale' => "Increase $level level questions to balance assessment",
                ];
            }
        }

        // Sort by priority.
        usort($suggestions, function ($a, $b) {
            $priority = ['high' => 3, 'medium' => 2, 'low' => 1];
            return ($priority[$b['priority']] ?? 0) <=> ($priority[$a['priority']] ?? 0);
        });

        return [
            'coverage_status' => $report['coverage_percentage'],
            'suggestions' => $suggestions,
            'total_suggested_questions' => array_sum(array_column($suggestions, 'suggested_count')),
        ];
    }
}
