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
 * Distractor generator page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 STARTER
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Distractor generator for the AI Quiz Generator plugin.
 *
 * Generates plausible wrong answers (distractors) for multiple choice questions.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Distractor generator class.
 */
class distractor_generator {
    /**
     * Generate distractors for a multiple choice question.
     *
     * @param string $questiontext The question text
     * @param string $correctanswer The correct answer
     * @param string $topiccontext Topic context/content
     * @param int $numdistractors Number of distractors to generate (default: 3)
     * @return array Array of distractor objects
     * @throws \moodle_exception If generation fails
     */
    public static function generate_distractors(
        string $questiontext,
        string $correctanswer,
        string $topiccontext,
        int $numdistractors = 3
    ): array {
        // Require gateway client.
        if (!gateway_client::is_ready()) {
            throw new \moodle_exception(
                'error:noaiprovider',
                'local_hlai_quizgen',
                '',
                'Gateway not configured. Please configure the AI Service URL and API Key in plugin settings.'
            );
        }

        // Build payload for gateway.
        $payload = [
            'question_text' => $questiontext,
            'correct_answer' => $correctanswer,
            'difficulty' => 'medium', // Default difficulty
            'num_distractors' => $numdistractors,
        ];

        // Call gateway for distractor generation.
        try {
            $response = gateway_client::generate_distractors($payload, 'balanced');

            // Extract distractors from response.
            $distractors = $response['distractors'] ?? [];

            return $distractors;
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:distractorgeneration',
                'local_hlai_quizgen',
                '',
                null,
                $e->getMessage()
            );
        }
    }

    // NOTE: AI prompts for distractor generation are proprietary and located on the
    // Human Logic AI Gateway server. This plugin only sends data payloads.

    /**
     * Parse AI response to extract distractors.
     *
     * @param string $response AI response
     * @return array Array of distractor objects
     * @throws \moodle_exception If parsing fails
     */
    private static function parse_distractor_response(string $response): array {
        // Extract JSON from response.
        $response = trim($response);

        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } else if (preg_match('/```\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'error:distractorgeneration',
                'local_hlai_quizgen',
                '',
                null,
                'Failed to parse distractor JSON: ' . json_last_error_msg()
            );
        }

        if (empty($data['distractors'])) {
            throw new \moodle_exception(
                'error:distractorgeneration',
                'local_hlai_quizgen',
                '',
                null,
                'No distractors found in AI response'
            );
        }

        return $data['distractors'];
    }

    /**
     * Regenerate distractors for an existing question.
     *
     * @param int $questionid Question ID
     * @return array Array of new distractor objects
     * @throws \moodle_exception If regeneration fails
     */
    public static function regenerate_for_question(int $questionid): array {
        global $DB;

        // Get question.
        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);

        // Get correct answer.
        $correctanswer = $DB->get_record('hlai_quizgen_answers', [
            'questionid' => $questionid,
            'is_correct' => 1,
        ], '*', MUST_EXIST);

        // Get topic for context.
        $topic = $DB->get_record('hlai_quizgen_topics', ['id' => $question->topicid]);
        $topiccontext = $topic ? $topic->content_excerpt : '';

        // Generate new distractors.
        $distractors = self::generate_distractors(
            $question->questiontext,
            $correctanswer->answer,
            $topiccontext
        );

        // Delete old distractors.
        $DB->delete_records('hlai_quizgen_answers', [
            'questionid' => $questionid,
            'is_correct' => 0,
        ]);

        // Save new distractors.
        $sortorder = 1;  // 0 is reserved for correct answer.
        foreach ($distractors as $distractor) {
            $record = new \stdClass();
            $record->questionid = $questionid;
            $record->answer = $distractor['text'];
            $record->answerformat = FORMAT_HTML;
            $record->fraction = 0.0;
            $record->feedback = $distractor['feedback'] ?? '';
            $record->feedbackformat = FORMAT_HTML;
            $record->is_correct = 0;
            $record->distractor_reasoning = $distractor['reasoning'] ?? '';
            $record->sortorder = $sortorder++;

            $DB->insert_record('hlai_quizgen_answers', $record);
        }

        return $distractors;
    }

    /**
     * Evaluate distractor quality.
     *
     * @param array $distractors Array of distractor texts
     * @param string $correctanswer Correct answer
     * @return array Quality scores and suggestions
     */
    public static function evaluate_quality(array $distractors, string $correctanswer): array {
        $evaluation = [
            'overall_score' => 0,
            'issues' => [],
            'suggestions' => [],
        ];

        $score = 100;

        // Check for duplicates.
        $unique = array_unique($distractors);
        if (count($unique) < count($distractors)) {
            $score -= 20;
            $evaluation['issues'][] = 'Duplicate distractors found';
            $evaluation['suggestions'][] = 'Ensure each distractor is unique';
        }

        // Check if any distractor matches correct answer.
        foreach ($distractors as $distractor) {
            if (strcasecmp(trim($distractor), trim($correctanswer)) === 0) {
                $score -= 30;
                $evaluation['issues'][] = 'Distractor matches correct answer';
                $evaluation['suggestions'][] = 'Regenerate this distractor';
            }
        }

        // Check for extreme language.
        $extremewords = ['always', 'never', 'all', 'none', 'impossible', 'definitely'];
        foreach ($distractors as $distractor) {
            foreach ($extremewords as $word) {
                if (stripos($distractor, $word) !== false) {
                    $score -= 10;
                    $evaluation['issues'][] = "Distractor contains extreme language: '{$word}'";
                    $evaluation['suggestions'][] = 'Avoid absolute terms that make distractors obviously wrong';
                    break;
                }
            }
        }

        // Check length similarity.
        $correctlength = strlen($correctanswer);
        foreach ($distractors as $distractor) {
            $ratio = strlen($distractor) / max($correctlength, 1);
            if ($ratio < 0.3 || $ratio > 3.0) {
                $score -= 5;
                $evaluation['issues'][] = 'Distractor length significantly different from correct answer';
            }
        }

        $evaluation['overall_score'] = max(0, $score);

        return $evaluation;
    }
}
