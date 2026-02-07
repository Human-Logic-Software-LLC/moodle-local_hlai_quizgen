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
 * Batch export and import functionality for questions.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Handles batch export/import of AI-generated questions.
 */
class batch_exporter {
    /** @var array Supported export formats */
    const SUPPORTED_FORMATS = ['moodle_xml', 'gift', 'aiken', 'json', 'csv'];

    /**
     * Export questions from a request to specified format.
     *
     * @param int $requestid Request ID
     * @param string $format Export format (moodle_xml, gift, aiken, json, csv)
     * @param array $options Export options
     * @return array Export result with file content
     */
    public static function export_request(int $requestid, string $format = 'json', array $options = []): array {
        global $DB;

        if (!in_array($format, self::SUPPORTED_FORMATS)) {
            throw new \moodle_exception('Unsupported export format: ' . $format);
        }

        $request = $DB->get_record('hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);
        $questions = $DB->get_records('hlai_quizgen_questions', ['requestid' => $requestid]);

        switch ($format) {
            case 'json':
                return self::export_as_json($request, $questions, $options);
            case 'csv':
                return self::export_as_csv($request, $questions, $options);
            case 'moodle_xml':
                return self::export_as_moodle_xml($request, $questions, $options);
            case 'gift':
                return self::export_as_gift($request, $questions, $options);
            case 'aiken':
                return self::export_as_aiken($request, $questions, $options);
            default:
                throw new \moodle_exception('Format not implemented: ' . $format);
        }
    }

    /**
     * Export as JSON format.
     *
     * @param object $request Request record
     * @param array $questions Question records
     * @param array $options Export options
     * @return array Export result
     */
    private static function export_as_json(object $request, array $questions, array $options): array {
        global $DB;

        $exportdata = [
            'metadata' => [
                'export_version' => '1.0',
                'plugin_version' => get_config('local_hlai_quizgen', 'version'),
                'export_date' => date('Y-m-d H:i:s'),
                'request_id' => $request->id,
                'course_id' => $request->courseid,
                'total_questions' => count($questions),
            ],
            'request_config' => [
                'difficulty_distribution' => json_decode($request->difficulty_distribution ?? '{}'),
                'blooms_distribution' => json_decode($request->blooms_distribution ?? '{}'),
                'question_types' => json_decode($request->question_types ?? '[]'),
                'custom_instructions' => $request->custom_instructions,
            ],
            'questions' => [],
        ];

        foreach ($questions as $question) {
            $answers = $DB->get_records(
                'hlai_quizgen_answers',
                ['questionid' => $question->id],
                'sortorder ASC'
            );

            $questiondata = [
                'id' => $question->id,
                'type' => $question->questiontype,
                'text' => $question->questiontext,
                'difficulty' => $question->difficulty,
                'blooms_level' => $question->blooms_level,
                'general_feedback' => $question->generalfeedback,
                'ai_reasoning' => $question->ai_reasoning,
                'validation_score' => $question->validation_score,
                'quality_rating' => $question->quality_rating,
                'answers' => [],
            ];

            foreach ($answers as $answer) {
                $questiondata['answers'][] = [
                    'text' => $answer->answer,
                    'is_correct' => (bool)$answer->is_correct,
                    'fraction' => $answer->fraction,
                    'feedback' => $answer->feedback,
                    'distractor_reasoning' => $answer->distractor_reasoning,
                ];
            }

            // Include analytics if requested.
            if (!empty($options['include_analytics']) && $question->moodle_questionid) {
                $analytics = \local_hlai_quizgen\question_analytics::get_question_analytics(
                    $question->moodle_questionid
                );
                $questiondata['analytics'] = $analytics;
            }

            // Include calibration if requested.
            if (!empty($options['include_calibration']) && $question->moodle_questionid) {
                $calibration = $DB->get_records(
                    'hlai_quizgen_calibration',
                    ['questionid' => $question->id],
                    'timecreated DESC',
                    '*',
                    0,
                    1
                );
                if (!empty($calibration)) {
                    $questiondata['calibration'] = array_values($calibration)[0];
                }
            }

            $exportdata['questions'][] = $questiondata;
        }

        return [
            'success' => true,
            'format' => 'json',
            'content' => json_encode($exportdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'filename' => 'hlai_questions_' . $request->id . '_' . date('Ymd_His') . '.json',
            'mimetype' => 'application/json',
            'count' => count($questions),
        ];
    }

    /**
     * Export as CSV format.
     *
     * @param object $request Request record
     * @param array $questions Question records
     * @param array $options Export options
     * @return array Export result
     */
    private static function export_as_csv(object $request, array $questions, array $options): array {
        global $DB;

        $csv = [];

        // Header row.
        $headers = [
            'ID',
            'Type',
            'Difficulty',
            'Blooms Level',
            'Question Text',
            'Answer 1',
            'Answer 1 Correct',
            'Answer 2',
            'Answer 2 Correct',
            'Answer 3',
            'Answer 3 Correct',
            'Answer 4',
            'Answer 4 Correct',
            'General Feedback',
            'Validation Score',
            'Quality Rating',
        ];
        $csv[] = $headers;

        foreach ($questions as $question) {
            $answers = $DB->get_records(
                'hlai_quizgen_answers',
                ['questionid' => $question->id],
                'sortorder ASC'
            );

            $row = [
                $question->id,
                $question->questiontype,
                $question->difficulty,
                $question->blooms_level,
                self::clean_html($question->questiontext),
            ];

            // Add up to 4 answers.
            $answerarray = array_values($answers);
            for ($i = 0; $i < 4; $i++) {
                if (isset($answerarray[$i])) {
                    $row[] = self::clean_html($answerarray[$i]->answer);
                    $row[] = $answerarray[$i]->is_correct ? 'YES' : 'NO';
                } else {
                    $row[] = '';
                    $row[] = '';
                }
            }

            $row[] = self::clean_html($question->generalfeedback ?? '');
            $row[] = $question->validation_score ?? '';
            $row[] = $question->quality_rating ?? '';

            $csv[] = $row;
        }

        // Convert to CSV string.
        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return [
            'success' => true,
            'format' => 'csv',
            'content' => $content,
            'filename' => 'hlai_questions_' . $request->id . '_' . date('Ymd_His') . '.csv',
            'mimetype' => 'text/csv',
            'count' => count($questions),
        ];
    }

    /**
     * Export as Moodle XML format.
     *
     * @param object $request Request record
     * @param array $questions Question records
     * @param array $options Export options
     * @return array Export result
     */
    private static function export_as_moodle_xml(object $request, array $questions, array $options): array {
        global $DB;

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><quiz></quiz>');
        $xml->addAttribute('timestamp', time());

        foreach ($questions as $question) {
            $answers = $DB->get_records(
                'hlai_quizgen_answers',
                ['questionid' => $question->id],
                'sortorder ASC'
            );

            $questionxml = $xml->addChild('question');
            $questionxml->addAttribute('type', self::map_question_type($question->questiontype));

            $questionxml->addChild('name')->addChild('text', 'Question ' . $question->id);

            $questiontext = $questionxml->addChild('questiontext');
            $questiontext->addAttribute('format', 'html');
            $questiontext->addChild('text', htmlspecialchars($question->questiontext));

            $generalfeedback = $questionxml->addChild('generalfeedback');
            $generalfeedback->addAttribute('format', 'html');
            $generalfeedback->addChild('text', htmlspecialchars($question->generalfeedback ?? ''));

            $questionxml->addChild('defaultgrade', '1.0000000');
            $questionxml->addChild('penalty', '0.3333333');
            $questionxml->addChild('hidden', '0');

            // Add answers.
            foreach ($answers as $answer) {
                $answerxml = $questionxml->addChild('answer');
                $answerxml->addAttribute('fraction', $answer->fraction * 100);
                $answerxml->addAttribute('format', 'html');

                $answerxml->addChild('text', htmlspecialchars($answer->answer));

                $feedbackxml = $answerxml->addChild('feedback');
                $feedbackxml->addAttribute('format', 'html');
                $feedbackxml->addChild('text', htmlspecialchars($answer->feedback ?? ''));
            }
        }

        return [
            'success' => true,
            'format' => 'moodle_xml',
            'content' => $xml->asXML(),
            'filename' => 'hlai_questions_' . $request->id . '_' . date('Ymd_His') . '.xml',
            'mimetype' => 'application/xml',
            'count' => count($questions),
        ];
    }

    /**
     * Export as GIFT format.
     *
     * @param object $request Request record
     * @param array $questions Question records
     * @param array $options Export options
     * @return array Export result
     */
    private static function export_as_gift(object $request, array $questions, array $options): array {
        global $DB;

        $gift = "// AI-Generated Questions Export\n";
        $gift .= "// Request ID: {$request->id}\n";
        $gift .= "// Exported: " . date('Y-m-d H:i:s') . "\n\n";

        foreach ($questions as $question) {
            $answers = $DB->get_records(
                'hlai_quizgen_answers',
                ['questionid' => $question->id],
                'sortorder ASC'
            );

            // Add category comment.
            $gift .= "// Question {$question->id} - {$question->difficulty} - {$question->blooms_level}\n";

            // Question text.
            $gift .= self::escape_gift_text($question->questiontext) . " {\n";

            // Answers.
            foreach ($answers as $answer) {
                $prefix = $answer->is_correct ? '=' : '~';
                $gift .= "    {$prefix}" . self::escape_gift_text($answer->answer);

                if ($answer->feedback) {
                    $gift .= " # " . self::escape_gift_text($answer->feedback);
                }
                $gift .= "\n";
            }

            $gift .= "}\n\n";
        }

        return [
            'success' => true,
            'format' => 'gift',
            'content' => $gift,
            'filename' => 'hlai_questions_' . $request->id . '_' . date('Ymd_His') . '.gift',
            'mimetype' => 'text/plain',
            'count' => count($questions),
        ];
    }

    /**
     * Export as Aiken format.
     *
     * @param object $request Request record
     * @param array $questions Question records
     * @param array $options Export options
     * @return array Export result
     */
    private static function export_as_aiken(object $request, array $questions, array $options): array {
        global $DB;

        $aiken = '';

        foreach ($questions as $question) {
            // Only multichoice supported in Aiken.
            if ($question->questiontype !== 'multichoice') {
                continue;
            }

            $answers = $DB->get_records(
                'hlai_quizgen_answers',
                ['questionid' => $question->id],
                'sortorder ASC'
            );

            // Question text.
            $aiken .= self::clean_html($question->questiontext) . "\n";

            // Answers (A, B, C, D...).
            $letters = range('A', 'Z');
            $correctletter = '';
            $index = 0;

            foreach ($answers as $answer) {
                $letter = $letters[$index];
                $aiken .= "{$letter}. " . self::clean_html($answer->answer) . "\n";

                if ($answer->is_correct) {
                    $correctletter = $letter;
                }
                $index++;
            }

            $aiken .= "ANSWER: {$correctletter}\n\n";
        }

        return [
            'success' => true,
            'format' => 'aiken',
            'content' => $aiken,
            'filename' => 'hlai_questions_' . $request->id . '_' . date('Ymd_His') . '.txt',
            'mimetype' => 'text/plain',
            'count' => count($questions),
        ];
    }

    /**
     * Import questions from file.
     *
     * @param string $content File content
     * @param string $format Import format
     * @param int $courseid Target course ID
     * @param int $userid User performing import
     * @return array Import result
     */
    public static function import_questions(
        string $content,
        string $format,
        int $courseid,
        int $userid
    ): array {
        if (!in_array($format, self::SUPPORTED_FORMATS)) {
            throw new \moodle_exception('Unsupported import format: ' . $format);
        }

        switch ($format) {
            case 'json':
                return self::import_from_json($content, $courseid, $userid);
            default:
                throw new \moodle_exception('Import format not yet implemented: ' . $format);
        }
    }

    /**
     * Import from JSON format.
     *
     * @param string $content JSON content
     * @param int $courseid Target course ID
     * @param int $userid User performing import
     * @return array Import result
     */
    private static function import_from_json(string $content, int $courseid, int $userid): array {
        global $DB;

        $data = json_decode($content, true);
        if (!$data) {
            throw new \moodle_exception('Invalid JSON format');
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            // Create new request record.
            $request = new \stdClass();
            $request->courseid = $courseid;
            $request->userid = $userid;
            $request->status = 'completed';
            $request->total_questions = count($data['questions'] ?? []);
            $request->questions_generated = $request->total_questions;
            $request->timecreated = time();
            $request->timemodified = time();
            $request->timecompleted = time();

            if (isset($data['request_config'])) {
                $config = $data['request_config'];
                $request->difficulty_distribution = json_encode($config['difficulty_distribution'] ?? null);
                $request->blooms_distribution = json_encode($config['blooms_distribution'] ?? null);
                $request->question_types = json_encode($config['question_types'] ?? null);
                $request->custom_instructions = $config['custom_instructions'] ?? null;
            }

            $requestid = $DB->insert_record('hlai_quizgen_requests', $request);

            $imported = 0;
            foreach ($data['questions'] as $questiondata) {
                $question = new \stdClass();
                $question->requestid = $requestid;
                $question->questiontype = $questiondata['type'];
                $question->questiontext = $questiondata['text'];
                $question->questiontextformat = FORMAT_HTML;
                $question->difficulty = $questiondata['difficulty'] ?? 'medium';
                $question->blooms_level = $questiondata['blooms_level'] ?? null;
                $question->generalfeedback = $questiondata['general_feedback'] ?? null;
                $question->ai_reasoning = $questiondata['ai_reasoning'] ?? null;
                $question->status = 'approved';
                $question->validation_score = $questiondata['validation_score'] ?? null;
                $question->quality_rating = $questiondata['quality_rating'] ?? null;
                $question->timecreated = time();
                $question->timemodified = time();

                $questionid = $DB->insert_record('hlai_quizgen_questions', $question);

                // Import answers.
                $answerorder = 0;
                foreach ($questiondata['answers'] as $answerdata) {
                    $answer = new \stdClass();
                    $answer->questionid = $questionid;
                    $answer->answer = $answerdata['text'];
                    $answer->answerformat = FORMAT_HTML;
                    $answer->fraction = $answerdata['fraction'] ?? ($answerdata['is_correct'] ? 1.0 : 0.0);
                    $answer->is_correct = $answerdata['is_correct'] ? 1 : 0;
                    $answer->feedback = $answerdata['feedback'] ?? '';
                    $answer->distractor_reasoning = $answerdata['distractor_reasoning'] ?? '';
                    $answer->sortorder = $answerorder++;
                    $answer->timecreated = time();

                    $DB->insert_record('hlai_quizgen_answers', $answer);
                }

                $imported++;
            }

            $transaction->allow_commit();

            return [
                'success' => true,
                'request_id' => $requestid,
                'questions_imported' => $imported,
                'message' => "Successfully imported {$imported} questions",
            ];
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Map internal question type to Moodle XML type.
     *
     * @param string $type Internal type
     * @return string Moodle type
     */
    private static function map_question_type(string $type): string {
        $map = [
            'multichoice' => 'multichoice',
            'truefalse' => 'truefalse',
            'shortanswer' => 'shortanswer',
            'essay' => 'essay',
            'matching' => 'matching',
        ];
        return $map[$type] ?? 'multichoice';
    }

    /**
     * Clean HTML from text.
     *
     * @param string $text Text with HTML
     * @return string Plain text
     */
    private static function clean_html(string $text): string {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        return trim($text);
    }

    /**
     * Escape text for GIFT format.
     *
     * @param string $text Text to escape
     * @return string Escaped text
     */
    private static function escape_gift_text(string $text): string {
        $text = self::clean_html($text);
        // Escape special GIFT characters.
        $text = str_replace(
            ['~', '=', '#', '{', '}', ':'],
            ['\~', '\=', '\#', '\{', '\}', '\:'],
            $text
        );
        return $text;
    }
}
