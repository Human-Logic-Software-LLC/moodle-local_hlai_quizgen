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
 * AI-assisted question refinement and improvement.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Uses AI to suggest improvements and refinements to questions.
 *
 * Uses the ai_provider abstraction to work with either hlai_hub or hlai_hubproxy.
 */
class question_refiner {
    /** @var array Refinement types */
    const REFINE_CLARITY = 'clarity';
    /** REFINE_DIFFICULTY constant. */
    const REFINE_DIFFICULTY = 'difficulty';
    /** REFINE_DISTRACTORS constant. */
    const REFINE_DISTRACTORS = 'distractors';
    /** REFINE_FEEDBACK constant. */
    const REFINE_FEEDBACK = 'feedback';
    /** REFINE_BLOOMS constant. */
    const REFINE_BLOOMS = 'blooms_level';
    /** REFINE_COMPREHENSIVE constant. */
    const REFINE_COMPREHENSIVE = 'comprehensive';

    /**
     * Suggest improvements for a question using AI.
     *
     * @param int $questionid Question ID
     * @param string $refinementtype Type of refinement
     * @param array $context Additional context
     * @return array Refinement suggestions
     */
    public static function suggest_improvements(
        int $questionid,
        string $refinementtype = self::REFINE_COMPREHENSIVE,
        array $context = []
    ): array {
        global $DB;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $questionid], 'sortorder ASC');

        // Get performance data if available.
        $performancedata = [];
        if ($question->moodle_questionid) {
            $analytics = \local_hlai_quizgen\question_analytics::get_question_analytics($question->moodle_questionid);
            $performancedata = [
                'average_score' => $analytics['performance']['average_score'],
                'attempts' => $analytics['usage']['total_attempts'],
                'discrimination' => $analytics['performance']['score_std_dev'],
            ];
        }

        // Require gateway client.
        if (!gateway_client::is_ready()) {
            return [
                'success' => false,
                'error' => 'Gateway not configured. Please configure the AI Service URL and API Key in plugin settings.',
            ];
        }

        // Build payload for gateway.
        $payload = [
            'question' => [
                'questiontext' => $question->questiontext,
                'questiontype' => $question->questiontype,
                'difficulty' => $question->difficulty,
                'blooms_level' => $question->blooms_level,
            ],
            'answers' => array_values(array_map(function ($answer) {
                return [
                    'text' => $answer->answer,
                    'is_correct' => $answer->is_correct,
                    'feedback' => $answer->feedback ?? '',
                ];
            }, $answers)),
            'refinement_type' => $refinementtype,
            'performance' => $performancedata,
            'context' => $context,
        ];

        // Call gateway for question refinement.
        try {
            $response = gateway_client::refine_question($payload, 'balanced');

            // Extract suggestions from response.
            $suggestions = $response['suggestions'] ?? [];

            // Store suggestions.
            self::store_refinement_suggestions($questionid, $refinementtype, $suggestions);

            return [
                'success' => true,
                'refinement_type' => $refinementtype,
                'suggestions' => $suggestions,
                'tokens_used' => $response['tokens']['total'] ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Apply AI-suggested improvements to question.
     *
     * @param int $questionid Question ID
     * @param array $improvements Improvements to apply
     * @return array Application result
     */
    public static function apply_improvements(int $questionid, array $improvements): array {
        global $DB, $USER;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);

        $transaction = $DB->start_delegated_transaction();

        try {
            $changes = [];

            // Update question text if provided.
            if (!empty($improvements['questiontext'])) {
                $DB->set_field(
                    'hlai_quizgen_questions',
                    'questiontext',
                    $improvements['questiontext'],
                    ['id' => $questionid]
                );
                $changes[] = 'Updated question text';
            }

            // Update difficulty if provided.
            if (!empty($improvements['difficulty'])) {
                $DB->set_field(
                    'hlai_quizgen_questions',
                    'difficulty',
                    $improvements['difficulty'],
                    ['id' => $questionid]
                );
                $changes[] = 'Updated difficulty level';
            }

            // Update Bloom's level if provided.
            if (!empty($improvements['blooms_level'])) {
                $DB->set_field(
                    'hlai_quizgen_questions',
                    'blooms_level',
                    $improvements['blooms_level'],
                    ['id' => $questionid]
                );
                $changes[] = 'Updated Bloom\'s taxonomy level';
            }

            // Update feedback if provided.
            if (!empty($improvements['generalfeedback'])) {
                $DB->set_field(
                    'hlai_quizgen_questions',
                    'generalfeedback',
                    $improvements['generalfeedback'],
                    ['id' => $questionid]
                );
                $changes[] = 'Updated general feedback';
            }

            // Update answers if provided.
            if (!empty($improvements['answers'])) {
                foreach ($improvements['answers'] as $answerupdate) {
                    if (!empty($answerupdate['id'])) {
                        $updatedata = new \stdClass();
                        $updatedata->id = $answerupdate['id'];
                        if (isset($answerupdate['answer'])) {
                            $updatedata->answer = $answerupdate['answer'];
                        }
                        if (isset($answerupdate['feedback'])) {
                            $updatedata->feedback = $answerupdate['feedback'];
                        }
                        $DB->update_record('hlai_quizgen_answers', $updatedata);
                        $changes[] = 'Updated answer ID ' . $answerupdate['id'];
                    }
                }
            }

            // Update modification time.
            $DB->set_field('hlai_quizgen_questions', 'timemodified', time(), ['id' => $questionid]);

            // Log refinement.
            $log = new \stdClass();
            $log->questionid = $questionid;
            $log->userid = $USER->id;
            $log->refinement_type = $improvements['refinement_type'] ?? 'manual';
            $log->changes = json_encode($changes);
            $log->improvements_applied = json_encode($improvements);
            $log->timecreated = time();
            $DB->insert_record('hlai_quizgen_refinements', $log);

            $transaction->allow_commit();

            return [
                'success' => true,
                'changes_count' => count($changes),
                'changes' => $changes,
                'message' => 'Improvements applied successfully',
            ];
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Generate alternative versions of a question.
     *
     * @param int $questionid Question ID
     * @param int $count Number of alternatives
     * @return array Alternative questions
     */
    public static function generate_alternatives(int $questionid, int $count = 3): array {
        global $DB;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $questionid], 'sortorder ASC');

        $prompt = "Create {$count} alternative versions of this question that assess the same learning objective " .
                  "but use different wording, examples, or approaches.\n\n" .
                  "Original Question:\n{$question->questiontext}\n\n" .
                  "Difficulty: {$question->difficulty}\n" .
                  "Bloom's Level: {$question->blooms_level}\n\n" .
                  "Original Answers:\n";

        foreach ($answers as $answer) {
            $correct = $answer->is_correct ? '[CORRECT]' : '[INCORRECT]';
            $prompt .= "{$correct} {$answer->answer}\n";
        }

        $prompt .= "\nProvide {$count} alternative question versions in JSON format:\n" .
                   "[\n" .
                   "  {\n" .
                   "    \"questiontext\": \"...\",\n" .
                   "    \"answers\": [\n" .
                   "      {\"answer\": \"...\", \"is_correct\": true/false}\n" .
                   "    ],\n" .
                   "    \"rationale\": \"Why this alternative is effective\"\n" .
                   "  }\n" .
                   "]";

        try {
            $response = ai_provider::generate(
                'generate_alternatives',
                $prompt,
                [
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ]
            );

            $alternatives = self::parse_alternatives($response->content);

            // Store alternatives.
            foreach ($alternatives as $alt) {
                $altrecord = new \stdClass();
                $altrecord->original_questionid = $questionid;
                $altrecord->questiontext = $alt['questiontext'];
                $altrecord->answers = json_encode($alt['answers']);
                $altrecord->rationale = $alt['rationale'];
                $altrecord->timecreated = time();
                $DB->insert_record('hlai_quizgen_alternatives', $altrecord);
            }

            return [
                'success' => true,
                'alternatives' => $alternatives,
                'count' => count($alternatives),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Improve distractors for multiple choice question.
     *
     * @param int $questionid Question ID
     * @return array Improved distractors
     */
    public static function improve_distractors(int $questionid): array {
        global $DB;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $questionid], 'sortorder ASC');

        // Get current performance on each distractor.
        $distractorperformance = [];
        if ($question->moodle_questionid) {
            $sql = "SELECT qas.answer, COUNT(*) as selection_count
                    FROM {question_attempt_steps} qas
                    JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
                    WHERE qa.questionid = ?
                    AND qas.state != 'complete'
                    GROUP BY qas.answer";
            $distractorperformance = $DB->get_records_sql($sql, [$question->moodle_questionid]);
        }

        $prompt = "Analyze and improve the distractors for this multiple-choice question.\n\n" .
                  "Question: {$question->questiontext}\n\n" .
                  "Current Answers:\n";

        foreach ($answers as $idx => $answer) {
            $status = $answer->is_correct ? '[CORRECT]' : '[DISTRACTOR]';
            $selected = isset($distractorperformance[$idx]) ?
                " (Selected by {$distractorperformance[$idx]->selection_count} students)" : '';
            $prompt .= "{$status} {$answer->answer}{$selected}\n";
        }

        $prompt .= "\nProvide improved distractors that are:\n" .
                   "1. Plausible but clearly incorrect\n" .
                   "2. Based on common misconceptions\n" .
                   "3. Similar in length and complexity to the correct answer\n" .
                   "4. Mutually exclusive\n\n" .
                   "Return JSON format:\n" .
                   "{\n" .
                   "  \"improved_distractors\": [\n" .
                   "    {\n" .
                   "      \"answer\": \"...\",\n" .
                   "      \"rationale\": \"Why students might choose this\",\n" .
                   "      \"feedback\": \"Feedback for students who choose this\"\n" .
                   "    }\n" .
                   "  ],\n" .
                   "  \"analysis\": \"Overall analysis of distractors\"\n" .
                   "}";

        try {
            $response = ai_provider::generate(
                'improve_distractors',
                $prompt,
                [
                    'temperature' => 0.4,
                    'max_tokens' => 1500,
                ]
            );

            $improvements = self::parse_distractor_improvements($response->content);

            return [
                'success' => true,
                'improvements' => $improvements,
                'original_count' => count($answers),
                'improved_count' => count($improvements['improved_distractors'] ?? []),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enhance feedback for a question.
     *
     * @param int $questionid Question ID
     * @return array Enhanced feedback
     */
    public static function enhance_feedback(int $questionid): array {
        global $DB;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $questionid], 'sortorder ASC');

        $prompt = "Generate comprehensive, educational feedback for this question and its answers.\n\n" .
                  "Question: {$question->questiontext}\n" .
                  "Difficulty: {$question->difficulty}\n" .
                  "Bloom's Level: {$question->blooms_level}\n\n" .
                  "Answers:\n";

        foreach ($answers as $answer) {
            $status = $answer->is_correct ? '[CORRECT]' : '[INCORRECT]';
            $currentfeedback = $answer->feedback ? " (Current feedback: {$answer->feedback})" : '';
            $prompt .= "{$status} {$answer->answer}{$currentfeedback}\n";
        }

        $prompt .= "\nProvide:\n" .
                   "1. Enhanced general feedback explaining the concept\n" .
                   "2. Specific feedback for each answer (correct and incorrect)\n" .
                   "3. Learning tips and resources\n\n" .
                   "Return JSON format:\n" .
                   "{\n" .
                   "  \"general_feedback\": \"Overall explanation and learning points\",\n" .
                   "  \"answer_feedback\": [\n" .
                   "    {\n" .
                   "      \"answer_index\": 0,\n" .
                   "      \"feedback\": \"Specific feedback for this answer\"\n" .
                   "    }\n" .
                   "  ],\n" .
                   "  \"learning_resources\": [\"Suggested topics to review\"]\n" .
                   "}";

        try {
            $response = ai_provider::generate(
                'enhance_feedback',
                $prompt,
                [
                    'temperature' => 0.5,
                    'max_tokens' => 1500,
                ]
            );

            $feedback = self::parse_feedback_enhancements($response->content);

            return [
                'success' => true,
                'feedback' => $feedback,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Auto-fix common question issues.
     *
     * @param int $questionid Question ID
     * @return array Fix result
     */
    public static function auto_fix_issues(int $questionid): array {
        global $DB;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $questionid]);

        $issues = [];
        $fixes = [];

        // Check for common issues.

        // Issue 1: Question too short or vague.
        if (strlen(strip_tags($question->questiontext)) < 20) {
            $issues[] = 'Question text is too short';
        }

        // Issue 2: Answer length disparity.
        $answerlengths = array_map(function ($a) {
            return strlen(strip_tags($a->answer));
        }, $answers);
        $avglen = array_sum($answerlengths) / count($answerlengths);
        foreach ($answers as $answer) {
            $len = strlen(strip_tags($answer->answer));
            if ($answer->is_correct && $len > $avglen * 1.5) {
                $issues[] = 'Correct answer is notably longer (answer stands out)';
                break;
            }
        }

        // Issue 3: Missing feedback.
        if (empty($question->generalfeedback)) {
            $issues[] = 'Missing general feedback';
        }
        foreach ($answers as $answer) {
            if (empty($answer->feedback)) {
                $issues[] = 'Missing answer-specific feedback';
                break;
            }
        }

        // Issue 4: Only one correct answer in MC.
        $correctcount = 0;
        foreach ($answers as $answer) {
            if ($answer->is_correct) {
                $correctcount++;
            }
        }
        if ($correctcount === 0) {
            $issues[] = 'No correct answer marked';
        }

        // Generate fixes using AI if issues found.
        if (!empty($issues)) {
            $prompt = "Fix the following issues in this question:\n\n" .
                      "Issues:\n" . implode("\n", $issues) . "\n\n" .
                      "Question: {$question->questiontext}\n\n" .
                      "Provide fixes in JSON format:\n" .
                      "{\n" .
                      "  \"fixes\": [\n" .
                      "    {\"issue\": \"...\", \"fix\": \"...\", \"field\": \"questiontext/answer/feedback\"}\n" .
                      "  ]\n" .
                      "}";

            try {
                $response = ai_provider::generate(
                    'auto_fix',
                    $prompt,
                    [
                        'temperature' => 0.3,
                        'max_tokens' => 1000,
                    ]
                );

                $fixes = self::parse_auto_fixes($response->content);
            } catch (\Exception $e) {
                // Continue with detected issues even if AI fails.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return [
            'has_issues' => !empty($issues),
            'issues' => $issues,
            'fixes' => $fixes,
            'issue_count' => count($issues),
        ];
    }

    // NOTE: AI prompts for question refinement are proprietary and located on the
    // Human Logic AI Gateway server. This plugin only sends data payloads.

    /**
     * Parse AI suggestions from response.
     *
     * @param string $response AI response
     * @param string $type Refinement type
     * @return array Parsed suggestions
     */
    private static function parse_ai_suggestions(string $response, string $type): array {
        // Extract JSON from response.
        preg_match('/\{[\s\S]*\}|\[[\s\S]*\]/', $response, $matches);
        if (empty($matches)) {
            return ['raw_response' => $response];
        }

        return json_decode($matches[0], true) ?? ['raw_response' => $response];
    }

    /**
     * Parse alternative questions.
     *
     * @param string $response The AI response to parse
     * @return array Parsed alternative questions
     */
    private static function parse_alternatives(string $response): array {
        preg_match('/\[[\s\S]*\]/', $response, $matches);
        return !empty($matches) ? (json_decode($matches[0], true) ?? []) : [];
    }

    /**
     * Parse distractor improvements.
     *
     * @param string $response The AI response to parse
     * @return array Parsed distractor improvements
     */
    private static function parse_distractor_improvements(string $response): array {
        preg_match('/\{[\s\S]*\}/', $response, $matches);
        return !empty($matches) ? (json_decode($matches[0], true) ?? []) : [];
    }

    /**
     * Parse feedback enhancements.
     *
     * @param string $response The AI response to parse
     * @return array Parsed feedback enhancements
     */
    private static function parse_feedback_enhancements(string $response): array {
        preg_match('/\{[\s\S]*\}/', $response, $matches);
        return !empty($matches) ? (json_decode($matches[0], true) ?? []) : [];
    }

    /**
     * Parse auto-fix suggestions.
     *
     * @param string $response The AI response to parse
     * @return array Parsed auto-fix suggestions
     */
    private static function parse_auto_fixes(string $response): array {
        preg_match('/\{[\s\S]*\}/', $response, $matches);
        return !empty($matches) ? (json_decode($matches[0], true) ?? []) : [];
    }

    /**
     * Store refinement suggestions.
     *
     * @param int $questionid The question ID
     * @param string $type The refinement type
     * @param array $suggestions The suggestions to store
     * @return void
     */
    private static function store_refinement_suggestions(int $questionid, string $type, array $suggestions): void {
        global $DB, $USER;

        $record = new \stdClass();
        $record->questionid = $questionid;
        $record->refinement_type = $type;
        $record->suggestions = json_encode($suggestions);
        $record->userid = $USER->id;
        $record->timecreated = time();

        $DB->insert_record('hlai_quizgen_refine_suggest', $record);
    }
}
