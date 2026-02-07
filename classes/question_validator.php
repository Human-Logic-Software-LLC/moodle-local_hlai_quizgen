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
 * Question validator for the AI Quiz Generator plugin.
 *
 * Validates generated questions for quality, correctness, and pedagogical soundness.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Question validator class.
 */
class question_validator {
    /** @var int Minimum question text length */
    const MIN_QUESTION_LENGTH = 10;

    /** @var int Maximum question text length */
    const MAX_QUESTION_LENGTH = 1000;

    /** @var int Minimum answer length */
    const MIN_ANSWER_LENGTH = 1;

    /** @var int Maximum answer length */
    const MAX_ANSWER_LENGTH = 500;

    /** @var int Minimum number of answers for multichoice */
    const MIN_MULTICHOICE_ANSWERS = 2;

    /** @var int Maximum number of answers for multichoice */
    const MAX_MULTICHOICE_ANSWERS = 10;

    /** @var array Quality score thresholds */
    const QUALITY_EXCELLENT = 90;
    /** @var int Good quality threshold. */
    const QUALITY_GOOD = 70;
    /** @var int Acceptable quality threshold. */
    const QUALITY_ACCEPTABLE = 50;
    /** @var int Poor quality threshold. */
    const QUALITY_POOR = 30;

    /**
     * Validate a generated question.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @return array Validation result with 'valid' boolean, 'score' int, 'issues' array, 'warnings' array
     */
    public static function validate_question(\stdClass $question, array $answers): array {
        $result = [
            'valid' => true,
            'score' => 100,
            'issues' => [],
            'warnings' => [],
            'quality_rating' => 'excellent',
        ];

        // Basic structure validation.
        $result = self::validate_structure($question, $answers, $result);

        // Type-specific validation.
        $result = self::validate_by_type($question, $answers, $result);

        // Content quality checks.
        $result = self::validate_content_quality($question, $answers, $result);

        // Pedagogical validation.
        $result = self::validate_pedagogical($question, $answers, $result);

        // Answer validation.
        $result = self::validate_answers($question, $answers, $result);

        // Determine final validity.
        $result['valid'] = empty($result['issues']) && $result['score'] >= self::QUALITY_ACCEPTABLE;

        // Set quality rating.
        $result['quality_rating'] = self::get_quality_rating($result['score']);

        return $result;
    }

    /**
     * Validate basic question structure.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_structure(\stdClass $question, array $answers, array $result): array {
        // Check required fields.
        if (empty($question->questiontext)) {
            $result['issues'][] = 'Question text is missing';
            $result['score'] -= 50;
            $result['valid'] = false;
        }

        if (empty($question->questiontype)) {
            $result['issues'][] = 'Question type is missing';
            $result['score'] -= 50;
            $result['valid'] = false;
        }

        // Check question text length.
        $questionlength = strlen(strip_tags($question->questiontext ?? ''));
        if ($questionlength < self::MIN_QUESTION_LENGTH) {
            $result['issues'][] = 'Question text too short (minimum ' . self::MIN_QUESTION_LENGTH . ' characters)';
            $result['score'] -= 20;
        }

        if ($questionlength > self::MAX_QUESTION_LENGTH) {
            $result['warnings'][] = 'Question text very long (over ' . self::MAX_QUESTION_LENGTH . ' characters)';
            $result['score'] -= 5;
        }

        return $result;
    }

    /**
     * Validate question by type.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_by_type(\stdClass $question, array $answers, array $result): array {
        switch ($question->questiontype) {
            case 'multichoice':
                $result = self::validate_multichoice($question, $answers, $result);
                break;

            case 'truefalse':
                $result = self::validate_truefalse($question, $answers, $result);
                break;

            case 'shortanswer':
                $result = self::validate_shortanswer($question, $answers, $result);
                break;

            case 'essay':
                $result = self::validate_essay($question, $answers, $result);
                break;

            case 'matching':
                $result = self::validate_matching($question, $answers, $result);
                break;
        }

        return $result;
    }

    /**
     * Validate multiple choice question.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_multichoice(\stdClass $question, array $answers, array $result): array {
        $answercount = count($answers);

        // Check answer count.
        if ($answercount < self::MIN_MULTICHOICE_ANSWERS) {
            $result['issues'][] = 'Too few answers (minimum ' . self::MIN_MULTICHOICE_ANSWERS . ')';
            $result['score'] -= 30;
        }

        if ($answercount > self::MAX_MULTICHOICE_ANSWERS) {
            $result['warnings'][] = 'Too many answers (recommended maximum ' . self::MAX_MULTICHOICE_ANSWERS . ')';
            $result['score'] -= 5;
        }

        // Check for correct answer.
        $correctcount = 0;
        $incorrectcount = 0;
        foreach ($answers as $answer) {
            if (isset($answer->fraction) && $answer->fraction > 0) {
                $correctcount++;
            } else {
                $incorrectcount++;
            }
        }

        if ($correctcount === 0) {
            $result['issues'][] = 'No correct answer marked';
            $result['score'] -= 50;
        }

        if ($incorrectcount < 2) {
            $result['warnings'][] = 'Insufficient distractors (recommended at least 3 distractors)';
            $result['score'] -= 10;
        }

        // Check answer similarity (avoid identical answers).
        $result = self::check_answer_similarity($answers, $result);

        return $result;
    }

    /**
     * Validate true/false question.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_truefalse(\stdClass $question, array $answers, array $result): array {
        if (count($answers) !== 2) {
            $result['issues'][] = 'True/False must have exactly 2 answers';
            $result['score'] -= 30;
        }

        // Check for one correct answer.
        $correctcount = 0;
        foreach ($answers as $answer) {
            if (isset($answer->fraction) && $answer->fraction > 0) {
                $correctcount++;
            }
        }

        if ($correctcount !== 1) {
            $result['issues'][] = 'True/False must have exactly 1 correct answer';
            $result['score'] -= 30;
        }

        return $result;
    }

    /**
     * Validate short answer question.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_shortanswer(\stdClass $question, array $answers, array $result): array {
        if (empty($answers)) {
            $result['issues'][] = 'Short answer must have at least one acceptable answer';
            $result['score'] -= 40;
        }

        // Check answer lengths.
        foreach ($answers as $answer) {
            $answerlength = strlen(strip_tags($answer->answer ?? ''));
            if ($answerlength > 100) {
                $result['warnings'][] = 'Short answer response is too long (over 100 characters)';
                $result['score'] -= 5;
            }
        }

        return $result;
    }

    /**
     * Validate essay question.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_essay(\stdClass $question, array $answers, array $result): array {
        // Essay questions don't require predefined answers but should have feedback.
        // FIX: Ensure generalfeedback is a string (AI may return array in some cases).
        $feedback = $question->generalfeedback ?? '';
        if (is_array($feedback)) {
            $feedback = json_encode($feedback);
        }
        $feedback = (string) $feedback;

        if (empty($feedback)) {
            $result['issues'][] = 'Essay question missing model answer and grading rubric';
            $result['score'] -= 20;
        } else {
            // Check if feedback is too short to be a proper rubric.
            $feedbacklength = strlen(strip_tags($feedback));
            if ($feedbacklength < 100) {
                $result['warnings'][] = 'Essay grading rubric is too short (should include model answer, key points, and criteria)';
                $result['score'] -= 10;
            }
            // Check if it contains expected sections.
            $feedbacklower = strtolower($feedback);
            $haskeypoints = strpos($feedbacklower, 'key point') !== false || strpos($feedbacklower, 'should include') !== false;
            $hasmodelorcriteria = strpos($feedbacklower, 'model') !== false || strpos($feedbacklower, 'criteria') !== false || strpos($feedbacklower, 'grading') !== false;
            if (!$haskeypoints && !$hasmodelorcriteria) {
                $result['warnings'][] = 'Essay feedback may not include proper grading guidance';
                $result['score'] -= 5;
            }
        }

        return $result;
    }

    /**
     * Validate matching question.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_matching(\stdClass $question, array $answers, array $result): array {
        $paircount = count($answers);

        if ($paircount < 3) {
            $result['issues'][] = 'Matching questions should have at least 3 pairs';
            $result['score'] -= 20;
        }

        if ($paircount > 10) {
            $result['warnings'][] = 'Too many matching pairs (recommended maximum 10)';
            $result['score'] -= 5;
        }

        return $result;
    }

    /**
     * Validate content quality.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_content_quality(\stdClass $question, array $answers, array $result): array {
        $questiontext = strip_tags($question->questiontext ?? '');
        $lowercaseptext = strtolower($questiontext);

        // Check for incomplete or placeholder text.
        $placeholders = ['lorem ipsum', 'todo', 'tbd', 'xxx', 'placeholder', '[insert', 'fill in'];
        foreach ($placeholders as $placeholder) {
            if (stripos($questiontext, $placeholder) !== false) {
                $result['issues'][] = 'Question contains placeholder text: ' . $placeholder;
                $result['score'] -= 25;
            }
        }

        // CHECK FOR DUMB QUESTIONS ABOUT DELIVERY FORMAT (SCORM, Lesson, Module, etc.).
        // These questions are about the platform/format, not the educational content.
        $deliveryformatpatterns = [
            'what is scorm' => 'Asking about SCORM format instead of content',
            'why do students need' => 'Asking about why students need to do something',
            'why is this lesson' => 'Asking about lesson format instead of content',
            'what is this module' => 'Asking about module format instead of content',
            'what is the purpose of this activity' => 'Asking about activity purpose instead of content',
            'why should students complete' => 'Asking about completion instead of content',
            'what is the benefit of completing' => 'Asking about completion benefits instead of content',
            'how does this lesson' => 'Asking about lesson mechanics instead of content',
            'what format is' => 'Asking about format instead of content',
            'what type of activity' => 'Asking about activity type instead of content',
            'how many pages' => 'Asking about structural elements instead of content',
            'how long is this' => 'Asking about length instead of content',
            'what is the navigation' => 'Asking about navigation instead of content',
        ];

        foreach ($deliveryformatpatterns as $pattern => $reason) {
            if (strpos($lowercaseptext, $pattern) !== false) {
                $result['issues'][] = 'Question is about delivery format, not educational content: ' . $reason;
                $result['score'] -= 40;
                break; // Only flag once.
            }
        }

        // Check if question is ONLY about generic module types without educational context.
        $genericmodulepatterns = [
            '/^what is a scorm(\s|$|\?)/i' => 'Asking what SCORM is',
            '/^what is a lesson(\s|$|\?)/i' => 'Asking what a lesson is',
            '/^what is a forum(\s|$|\?)/i' => 'Asking what a forum is',
            '/^what is a page(\s|$|\?)/i' => 'Asking what a page is',
            '/^what is a book(\s|$|\?)/i' => 'Asking what a book is (the module type)',
            '/^why use scorm/i' => 'Asking why use SCORM',
            '/^why use a lesson/i' => 'Asking why use lessons',
        ];

        foreach ($genericmodulepatterns as $pattern => $reason) {
            if (preg_match($pattern, $questiontext)) {
                $result['issues'][] = 'Question is about module type definition: ' . $reason;
                $result['score'] -= 50;
                break;
            }
        }

        // Check for proper sentence structure.
        if (!preg_match('/[.?!]$/', trim($questiontext))) {
            $result['warnings'][] = 'Question text missing proper punctuation';
            $result['score'] -= 5;
        }

        // Check for excessive capitalization.
        $uppercasecount = preg_match_all('/[A-Z]/', $questiontext);
        $totalchars = strlen($questiontext);
        if ($totalchars > 0 && ($uppercasecount / $totalchars) > 0.5) {
            $result['warnings'][] = 'Excessive capitalization in question text';
            $result['score'] -= 5;
        }

        return $result;
    }

    /**
     * Validate pedagogical quality.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_pedagogical(\stdClass $question, array $answers, array $result): array {
        // Check difficulty alignment.
        if (isset($question->difficulty) && !in_array($question->difficulty, ['easy', 'medium', 'hard'])) {
            $result['warnings'][] = 'Invalid difficulty level specified';
            $result['score'] -= 5;
        }

        // Check Bloom's taxonomy alignment.
        $validblooms = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
        if (isset($question->blooms_level) && !in_array($question->blooms_level, $validblooms)) {
            $result['warnings'][] = 'Invalid Bloom\'s taxonomy level';
            $result['score'] -= 5;
        }

        // DIFFICULTY-CONTENT ALIGNMENT CHECK.
        // Detect if stated difficulty matches question complexity.
        $questiontext = strtolower(strip_tags($question->questiontext ?? ''));
        $difficulty = $question->difficulty ?? 'medium';

        // Patterns that suggest EASY questions (recall-based).
        $easypatterns = [
            '/^what is (the |a |an )?/', '/^which (of the following )?is/',
            '/^name the/', '/^list the/', '/^identify the/',
            '/^true or false/', '/^define /',
        ];

        // Patterns that suggest HARD questions (analysis/evaluation).
        $hardpatterns = [
            '/analyze/', '/evaluate/', '/compare and contrast/',
            '/what would happen if/', '/in (this |the )?scenario/',
            '/best (approach|solution|method|practice)/',
            '/most (appropriate|effective|likely)/',
            '/implications of/', '/consequences of/',
            '/critically/', '/synthesize/', '/design a/',
        ];

        // Check for mismatch: question marked easy but has hard patterns.
        if ($difficulty === 'easy') {
            foreach ($hardpatterns as $pattern) {
                if (preg_match($pattern, $questiontext)) {
                    $result['warnings'][] = 'Question marked as EASY but appears to require analysis/evaluation';
                    $result['score'] -= 5;
                    break;
                }
            }
        }

        // Check for mismatch: question marked hard but has only easy patterns.
        if ($difficulty === 'hard') {
            $haseasypatternsonly = false;
            foreach ($easypatterns as $pattern) {
                if (preg_match($pattern, $questiontext)) {
                    $haseasypatternsonly = true;
                    break;
                }
            }
            // Only flag if it matches easy patterns AND doesn't match any hard patterns.
            if ($haseasypatternsonly) {
                $hashardpattern = false;
                foreach ($hardpatterns as $pattern) {
                    if (preg_match($pattern, $questiontext)) {
                        $hashardpattern = true;
                        break;
                    }
                }
                if (!$hashardpattern) {
                    $result['warnings'][] = 'Question marked as HARD but appears to be simple recall';
                    $result['score'] -= 5;
                }
            }
        }

        // Check for feedback.
        if (empty($question->generalfeedback) && $question->questiontype !== 'essay') {
            $result['warnings'][] = 'Question missing general feedback';
            $result['score'] -= 5;
        }

        return $result;
    }

    /**
     * Validate answers.
     *
     * @param \stdClass $question Question object
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function validate_answers(\stdClass $question, array $answers, array $result): array {
        foreach ($answers as $index => $answer) {
            $answertext = strip_tags($answer->answer ?? '');
            $answerlength = strlen($answertext);

            // Check answer length.
            if ($answerlength < self::MIN_ANSWER_LENGTH) {
                $result['issues'][] = "Answer " . ($index + 1) . " is too short";
                $result['score'] -= 10;
            }

            if ($answerlength > self::MAX_ANSWER_LENGTH) {
                $result['warnings'][] = "Answer " . ($index + 1) . " is very long";
                $result['score'] -= 3;
            }

            // Check for placeholder text in answers.
            if (preg_match('/(lorem|placeholder|xxx|tbd)/i', $answertext)) {
                $result['issues'][] = "Answer " . ($index + 1) . " contains placeholder text";
                $result['score'] -= 15;
            }
        }

        return $result;
    }

    /**
     * Check answer similarity to avoid duplicates.
     *
     * @param array $answers Array of answer objects
     * @param array $result Current validation result
     * @return array Updated validation result
     */
    private static function check_answer_similarity(array $answers, array $result): array {
        $answertexts = [];
        foreach ($answers as $answer) {
            $normalized = strtolower(trim(strip_tags($answer->answer ?? '')));
            if (in_array($normalized, $answertexts)) {
                $result['issues'][] = 'Duplicate or very similar answers detected';
                $result['score'] -= 20;
                break;
            }
            $answertexts[] = $normalized;
        }

        return $result;
    }

    /**
     * Get quality rating from score.
     *
     * @param int $score Quality score
     * @return string Quality rating
     */
    private static function get_quality_rating(int $score): string {
        if ($score >= self::QUALITY_EXCELLENT) {
            return 'excellent';
        } else if ($score >= self::QUALITY_GOOD) {
            return 'good';
        } else if ($score >= self::QUALITY_ACCEPTABLE) {
            return 'acceptable';
        } else if ($score >= self::QUALITY_POOR) {
            return 'poor';
        } else {
            return 'unacceptable';
        }
    }

    /**
     * Batch validate multiple questions.
     *
     * @param array $questions Array of question objects with their answers
     * @return array Validation results for all questions
     */
    public static function validate_batch(array $questions): array {
        $results = [];

        foreach ($questions as $questiondata) {
            $question = $questiondata['question'] ?? null;
            $answers = $questiondata['answers'] ?? [];

            if ($question) {
                $results[] = self::validate_question($question, $answers);
            }
        }

        return $results;
    }

    /**
     * Check for duplicate or very similar questions within a batch.
     *
     * Uses simple text similarity to detect questions that are rephrased versions of each other.
     *
     * @param array $questions Array of question texts
     * @return array Array of duplicate pairs found [['index1' => i, 'index2' => j, 'similarity' => float]]
     */
    public static function check_for_duplicates(array $questions): array {
        $duplicates = [];
        $count = count($questions);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $q1 = strtolower(strip_tags($questions[$i]));
                $q2 = strtolower(strip_tags($questions[$j]));

                // Skip if either is too short.
                if (strlen($q1) < 20 || strlen($q2) < 20) {
                    continue;
                }

                // Calculate similarity using multiple methods.
                $similarity = self::calculate_question_similarity($q1, $q2);

                // Threshold: 70% similar is considered a duplicate.
                if ($similarity >= 0.70) {
                    $duplicates[] = [
                        'index1' => $i,
                        'index2' => $j,
                        'similarity' => round($similarity * 100, 1),
                        'question1' => substr($questions[$i], 0, 80) . '...',
                        'question2' => substr($questions[$j], 0, 80) . '...',
                    ];
                }
            }
        }

        return $duplicates;
    }

    /**
     * Calculate similarity between two question texts.
     *
     * Uses a combination of methods for better accuracy.
     *
     * @param string $q1 First question (lowercase, stripped)
     * @param string $q2 Second question (lowercase, stripped)
     * @return float Similarity score (0.0 to 1.0)
     */
    private static function calculate_question_similarity(string $q1, string $q2): float {
        // Method 1: Levenshtein-based similarity (for short texts).
        $maxlen = max(strlen($q1), strlen($q2));
        if ($maxlen <= 255) { // Levenshtein has length limit.
            $lev = levenshtein($q1, $q2);
            $levsim = 1 - ($lev / $maxlen);
        } else {
            $levsim = 0;
        }

        // Method 2: Word overlap (Jaccard similarity).
        $words1 = array_unique(preg_split('/\s+/', $q1));
        $words2 = array_unique(preg_split('/\s+/', $q2));

        // Remove common stop words for better comparison.
        $stopwords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
                      'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
                      'what', 'which', 'who', 'whom', 'this', 'that', 'these', 'those',
                      'of', 'in', 'to', 'for', 'with', 'on', 'at', 'by', 'from', 'as',
                      'and', 'or', 'but', 'if', 'than', 'because', 'while'];

        $words1 = array_diff($words1, $stopwords);
        $words2 = array_diff($words2, $stopwords);

        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));

        $jaccard = $union > 0 ? $intersection / $union : 0;

        // Method 3: Similar text percentage.
        similar_text($q1, $q2, $simtextpercent);
        $simtext = $simtextpercent / 100;

        // Combine methods with weights.
        // Give more weight to Jaccard (word overlap) as it's more meaningful for questions.
        $combined = ($levsim * 0.2) + ($jaccard * 0.5) + ($simtext * 0.3);

        return $combined;
    }

    /**
     * Get validation summary statistics.
     *
     * @param array $validationresults Array of validation results
     * @return array Summary statistics
     */
    public static function get_validation_summary(array $validationresults): array {
        $totalquestions = count($validationresults);
        $validcount = 0;
        $totalscore = 0;
        $qualitydistribution = [
            'excellent' => 0,
            'good' => 0,
            'acceptable' => 0,
            'poor' => 0,
            'unacceptable' => 0,
        ];

        foreach ($validationresults as $result) {
            if ($result['valid']) {
                $validcount++;
            }
            $totalscore += $result['score'];
            $qualitydistribution[$result['quality_rating']]++;
        }

        return [
            'total_questions' => $totalquestions,
            'valid_questions' => $validcount,
            'invalid_questions' => $totalquestions - $validcount,
            'average_score' => $totalquestions > 0 ? round($totalscore / $totalquestions, 2) : 0,
            'quality_distribution' => $qualitydistribution,
            'pass_rate' => $totalquestions > 0 ? round(($validcount / $totalquestions) * 100, 2) : 0,
        ];
    }
}
