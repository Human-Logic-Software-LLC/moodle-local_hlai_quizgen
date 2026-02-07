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
 * Question generator for the AI Quiz Generator plugin.
 *
 * Generates questions using AI Hub across 5 types:
 * - Multiple Choice (multichoice)
 * - True/False (truefalse)
 * - Short Answer (shortanswer)
 * - Essay (essay)
 * - Matching (matching)
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Question generator class.
 */
class question_generator {
    /** @var array Supported question types */
    const QUESTION_TYPES = ['multichoice', 'truefalse', 'shortanswer', 'essay', 'matching', 'scenario'];

    /** @var array Difficulty levels */
    const DIFFICULTY_LEVELS = ['easy', 'medium', 'hard'];

    /** @var array Bloom's taxonomy levels */
    const BLOOMS_LEVELS = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];

    /**
     * Select difficulty based on distribution percentages.
     *
     * @param array $distribution Distribution array ['easy' => 20, 'medium' => 60, 'hard' => 20]
     * @return string Selected difficulty level
     */
    private static function select_difficulty_from_distribution(array $distribution): string {
        $rand = rand(1, 100);
        $cumulative = 0;

        foreach ($distribution as $level => $percentage) {
            $cumulative += $percentage;
            if ($rand <= $cumulative) {
                return $level;
            }
        }

        return 'medium'; // Fallback
    }

    /**
     * Select Bloom's level based on distribution percentages.
     *
     * @param array $distribution Distribution array ['remember' => 20, 'understand' => 25, ...]
     * @return string Selected Bloom's level
     */
    private static function select_blooms_from_distribution(array $distribution): string {
        $rand = rand(1, 100);
        $cumulative = 0;

        foreach ($distribution as $level => $percentage) {
            $cumulative += $percentage;
            if ($rand <= $cumulative) {
                return $level;
            }
        }

        return 'understand'; // Fallback
    }

    /** @var array Static cache for content to avoid repeated fetching. */
    private static $contentcache = [];

    /**
     * Generate questions for a topic.
     *
     * @param int $topicid Topic ID
     * @param int $requestid Request ID
     * @param array $config Configuration
     *        - 'question_types' => array of types to generate
     *        - 'difficulty' => 'easy|medium|hard'
     *        - 'num_questions' => number to generate
     *        - 'processing_mode' => 'fast|balanced|best'
     *        - 'custom_instructions' => optional custom instructions
     * @return array Array of generated question objects
     * @throws \moodle_exception If generation fails
     */
    public static function generate_for_topic(int $topicid, int $requestid, array $config): array {
        global $DB;

        // Log the start of topic generation.
        debug_logger::info("Starting question generation for topic", [
            'topic_id' => $topicid,
            'request_id' => $requestid,
            'num_questions' => $config['num_questions'] ?? 'not set',
            'question_types' => $config['question_types'] ?? [],
        ], $requestid);

        // Validate request state (allow completed for regeneration).
        $allowcompleted = $config['allow_completed'] ?? false;
        error_handler::validate_request_state($requestid, $allowcompleted);

        // Get request details (needed for courseid and userid).
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

        // Get topic details.
        $topic = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid], '*', MUST_EXIST);

        // OPTIMIZATION: Cache content to avoid repeated expensive extractions.
        // Only fetch content once per request, not once per topic.
        $cachekey = "request_{$requestid}_content";
        if (!isset(self::$contentcache[$cachekey])) {
            self::$contentcache[$cachekey] = self::get_full_content_for_request($request);
            // DEBUG: Log content length
            \local_hlai_quizgen\debug_logger::debug('Content extracted for request', [
                'request_id' => $requestid,
                'content_length' => strlen(self::$contentcache[$cachekey]),
                'content_preview' => substr(self::$contentcache[$cachekey], 0, 200),
            ], $requestid);
        }

        // Use cached content for this topic.
        $topic->full_content = self::$contentcache[$cachekey];

        // Validate configuration.
        self::validate_config($config);

        // DEDUPLICATION: Ensure requestid is in config for duplicate checking.
        $config['requestid'] = $requestid;

        $questions = [];
        $numquestions = $config['num_questions'] ?? 1;
        $questiontypes = $config['question_types'] ?? ['multichoice'];

        // Validate we have questions to generate.
        if ($numquestions < 1) {
            return [];
        }

        // Track token usage.
        $totalprompt = 0;
        $totalresponse = 0;

        // Get global question index (tracks across all topics).
        $globalindex = $config['global_question_index'] ?? 0;

        // OPTIMIZATION: Generate in batches to reduce API calls and tokens.
        $batchsize = min(10, $numquestions); // Generate up to 10 questions per API call.
        $batches = ceil($numquestions / $batchsize);

        for ($batch = 0; $batch < $batches; $batch++) {
            $batchstart = $batch * $batchsize;
            $batchcount = min($batchsize, $numquestions - $batchstart);

            // Determine types for this batch using GLOBAL index.
            $batchtypes = [];
            for ($i = 0; $i < $batchcount; $i++) {
                $questionindex = $globalindex + $batchstart + $i;
                // Prevent division by zero - fallback to multichoice if questiontypes is empty.
                if (empty($questiontypes)) {
                    $batchtypes[] = 'multichoice';
                } else {
                    $batchtypes[] = $questiontypes[$questionindex % count($questiontypes)];
                }
            }

            // Retry up to 2 times if batch fails.
            $maxretries = 2;
            $batchsuccess = false;

            for ($retry = 0; $retry <= $maxretries && !$batchsuccess; $retry++) {
                try {
                    $result = self::generate_question_batch($topic, $batchtypes, $config);

                    // Accumulate tokens.
                    $totalprompt += $result['tokens']->prompt ?? 0;
                    $totalresponse += $result['tokens']->completion ?? 0;

                    // Save each question in batch.
                    foreach ($result['questions'] as $questionobj) {
                        // Ensure properties are set as object properties.
                        if (!is_object($questionobj)) {
                            $questionobj = (object)$questionobj;
                        }
                        $questionobj->requestid = $requestid;
                        $questionobj->topicid = $topicid;
                        $questionobj->courseid = $request->courseid;
                        $questionobj->userid = $request->userid;

                        $savedquestion = self::save_question($questionobj);
                        $questions[] = $savedquestion;
                    }

                    $batchsuccess = true;
                } catch (\Exception $e) {
                    if ($retry >= $maxretries) {
                        // Final retry failed - log error and continue.
                        error_handler::handle_exception($e, $requestid, 'question_generator', error_handler::SEVERITY_WARNING);
                    } else {
                        // Wait before retry.
                        sleep(1);
                    }
                }
            }
        }

        // Update request token totals.
        if ($totalprompt > 0 || $totalresponse > 0) {
            $DB->execute(
                "UPDATE {local_hlai_quizgen_requests}
                 SET prompt_tokens = prompt_tokens + ?,
                     response_tokens = response_tokens + ?,
                     total_tokens = total_tokens + ?
                 WHERE id = ?",
                [$totalprompt, $totalresponse, ($totalprompt + $totalresponse), $requestid]
            );
        }

        // Log the completion of topic generation.
        debug_logger::question_generation(
            $requestid,
            $topicid,
            count($questions),
            $numquestions,
            $questiontypes
        );

        return $questions;
    }

    /**
     * Generate a batch of questions in one API call (MAJOR TOKEN SAVER).
     *
     * @param \stdClass $topic Topic object
     * @param array $types Array of question types to generate
     * @param array $config Configuration
     * @return array Array with 'questions' => array of question objects, 'tokens' => token usage
     * @throws \moodle_exception If generation fails
     */
    private static function generate_question_batch(\stdClass $topic, array $types, array $config): array {
        global $DB;

        // Require gateway client.
        if (!gateway_client::is_ready()) {
            throw new \moodle_exception(
                'error:noaiprovider',
                'local_hlai_quizgen',
                '',
                'Gateway not configured. Please configure the AI Service URL and API Key in plugin settings.'
            );
        }

        $count = count($types);
        $requestid = $config['requestid'] ?? ($topic->requestid ?? 0);

        // Get distributions from config.
        $difficultydist = $config['difficulty_distribution'] ?? ['easy' => 20, 'medium' => 60, 'hard' => 20];
        $bloomsdist = $config['blooms_distribution'] ?? [
            'remember' => 20, 'understand' => 25, 'apply' => 25,
            'analyze' => 15, 'evaluate' => 10, 'create' => 5,
        ];

        // DEDUPLICATION: Get previously generated questions for this request.
        $existingquestions = [];
        if ($requestid > 0) {
            $existingrecords = $DB->get_records(
                'local_hlai_quizgen_questions',
                ['requestid' => $requestid],
                'id ASC',
                'id, questiontext'
            );
            foreach ($existingrecords as $rec) {
                // Store just the first 100 chars of each question for context.
                $existingquestions[] = substr(strip_tags($rec->questiontext), 0, 100);
            }
        }

        // Use FULL content from activities (cached, extracted once per request).
        // This ensures questions are based on actual content, not AI summaries.
        $fullcontent = $topic->full_content ?? '';

        // REGENERATION: Check if this is a regeneration request.
        $isregeneration = $config['is_regeneration'] ?? false;
        $oldquestiontext = $config['old_question_text'] ?? '';

        // Build payload for gateway.
        $payload = [
            'topic_title' => $topic->title,
            'topic_content' => $fullcontent,
            'question_types' => $types,
            'difficulty_distribution' => $difficultydist,
            'blooms_distribution' => $bloomsdist,
            'num_questions' => count($types),
            'existing_questions' => $existingquestions,
            'is_regeneration' => $isregeneration,
            'old_question_text' => $oldquestiontext,
        ];

        // Determine quality mode from config.
        $quality = $config['processing_mode'] ?? 'balanced';

        // DEBUG: Simple error_log
        \local_hlai_quizgen\debug_logger::debug('About to call gateway for question generation', [
            'topic_title' => $topic->title,
            'payload_content_length' => strlen($payload['topic_content']),
            'num_questions' => count($types),
            'quality' => $quality,
        ], $requestid);

        // Call gateway for question generation.
        $response = gateway_client::generate_questions($payload, $quality);

        \local_hlai_quizgen\debug_logger::debug('Gateway response received', [
            'questions_returned' => count($response['questions'] ?? []),
        ], $requestid);

        // Extract questions and tokens from response.
        $questions = $response['questions'] ?? [];
        $tokensobj = (object)($response['tokens'] ?? ['prompt' => 0, 'completion' => 0, 'total' => 0]);

        return [
            'questions' => $questions,
            'tokens' => $tokensobj,
        ];
    }

    /**
     * Generate a single question.
     *
     * @param \stdClass $topic Topic object
     * @param string $type Question type
     * @param array $config Configuration
     * @return array Array with 'question' => question object, 'tokens' => token usage
     * @throws \moodle_exception If generation fails
     */
    private static function generate_single_question(\stdClass $topic, string $type, array $config): array {
        // This method is now a wrapper around generate_question_batch for a single question.
        // Call gateway to generate one question.
        $result = self::generate_question_batch($topic, [$type], $config);

        return [
            'question' => $result['questions'][0] ?? null,
            'tokens' => $result['tokens'],
        ];
    }

    // NOTE: OLD prompt building functions have been removed.
    // All AI prompts are now server-side on the Human Logic AI Gateway.

    /**
     * Parse batch AI response into array of question objects.
     *
     * @param string $response AI response
     * @param array $types Expected question types
     * @return array Array of question objects
     * @throws \moodle_exception If parsing fails
     */
    private static function parse_batch_response(string $response, array $types): array {
        $response = trim($response);

        // Extract JSON array.
        if (preg_match('/```json\\s*(.*?)\\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } else if (preg_match('/```\\s*(.*?)\\s*```/s', $response, $matches)) {
            $response = $matches[1];
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'error:questiongeneration',
                'local_hlai_quizgen',
                '',
                null,
                'Failed to parse batch JSON: ' . json_last_error_msg()
            );
        }

        // Handle both array of questions and single question wrapped in array.
        if (!isset($data[0]) && isset($data['questiontext'])) {
            $data = [$data]; // Wrap single question.
        }

        $questions = [];
        // FIX: Only process the number of questions we requested (count of $types).
        $expectedcount = count($types);
        $actualcount = count($data);

        // Only process the number of questions we requested.

        for ($i = 0; $i < $expectedcount && $i < $actualcount; $i++) {
            $qdata = $data[$i];
            $type = $types[$i] ?? 'multichoice';
            $question = new \stdClass();
            $question->questiontext = $qdata['questiontext'] ?? '';
            $question->questiontype = $type;
            $question->questiontextformat = FORMAT_HTML;
            // FIX: Ensure generalfeedback is always a string (AI may return array).
            $genfeedback = $qdata['generalfeedback'] ?? '';
            if (is_array($genfeedback)) {
                $genfeedback = json_encode($genfeedback);
            }
            $question->generalfeedback = (string) $genfeedback;
            $question->difficulty = $qdata['difficulty'] ?? 'medium';
            $question->blooms_level = $qdata['blooms_level'] ?? 'understand';
            $question->ai_reasoning = $qdata['ai_reasoning'] ?? $qdata['rationale'] ?? '';
            $question->status = 'pending';
            $question->answers = $qdata['answers'] ?? [];

            if ($type === 'matching' && isset($qdata['subquestions'])) {
                $question->subquestions = $qdata['subquestions'];
            }

            $questions[] = $question;
        }

        return $questions;
    }

    /**
     * Parse AI response into question object.
     *
     * @param string $response AI response
     * @param string $type Question type
     * @return \stdClass Question object
     * @throws \moodle_exception If parsing fails
     */
    private static function parse_question_response(string $response, string $type): \stdClass {
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
                'error:questiongeneration',
                'local_hlai_quizgen',
                '',
                null,
                'Failed to parse question JSON: ' . json_last_error_msg()
            );
        }

        // Convert to question object.
        $question = new \stdClass();
        $question->questiontext = $data['questiontext'] ?? '';
        $question->questiontype = $type;
        $question->questiontextformat = FORMAT_HTML;
        // FIX: Ensure generalfeedback is always a string (AI may return array).
        $genfeedback = $data['generalfeedback'] ?? '';
        if (is_array($genfeedback)) {
            $genfeedback = json_encode($genfeedback);
        }
        $question->generalfeedback = (string) $genfeedback;
        $question->difficulty = $data['difficulty'] ?? 'medium';
        $question->blooms_level = $data['blooms_level'] ?? 'understand';
        $question->ai_reasoning = $data['ai_reasoning'] ?? '';
        $question->status = 'pending';
        $question->answers = $data['answers'] ?? [];

        // For essay questions, store grading criteria.
        if ($type === 'essay' && isset($data['grading_criteria'])) {
            $question->grading_criteria = json_encode($data['grading_criteria']);
        }

        // For matching questions, store subquestions.
        if ($type === 'matching' && isset($data['subquestions'])) {
            $question->subquestions = $data['subquestions'];
        }

        return $question;
    }

    /**
     * Save question to database.
     *
     * @param \stdClass $question Question object
     * @return \stdClass Saved question with ID
     */
    private static function save_question(\stdClass $question): \stdClass {
        global $DB;

        $now = time();

        // Validate question before saving if validation is enabled.
        $validationenabled = get_config('local_hlai_quizgen', 'enable_question_validation') !== '0';
        if ($validationenabled && class_exists('\\local_hlai_quizgen\\question_validator')) {
            $validation = \local_hlai_quizgen\question_validator::validate_question(
                $question,
                $question->answers ?? []
            );

            // Store validation metadata.
            $question->validation_score = $validation['score'];
            $question->quality_rating = $validation['quality_rating'];
        }

        // Save main question record.
        $record = new \stdClass();
        $record->requestid = $question->requestid ?? 0;
        $record->topicid = $question->topicid ?? null;
        $record->courseid = $question->courseid ?? 0;
        $record->userid = $question->userid ?? 0;
        $record->questiontype = $question->questiontype;
        $record->questiontext = $question->questiontext;
        $record->questiontextformat = FORMAT_HTML;
        // FIX: Ensure generalfeedback is a string before saving to database.
        $savefeedback = $question->generalfeedback ?? '';
        if (is_array($savefeedback)) {
            $savefeedback = json_encode($savefeedback);
        }
        $record->generalfeedback = (string) $savefeedback;
        $record->difficulty = $question->difficulty;
        $record->blooms_level = $question->blooms_level;
        $record->ai_reasoning = $question->ai_reasoning ?? '';
        $record->status = 'pending';
        $record->timecreated = $now;
        $record->timemodified = $now;

        // Include validation scores if present.
        if (isset($question->validation_score)) {
            $record->validation_score = $question->validation_score;
        }
        if (isset($question->quality_rating)) {
            $record->quality_rating = $question->quality_rating;
        }

        // Validate required fields.
        if (empty($record->requestid)) {
            throw new \moodle_exception(
                'error:missingrequestid',
                'local_hlai_quizgen',
                '',
                null,
                'Question must have a request ID before saving. RequestID=' . ($question->requestid ?? 'undefined')
            );
        }

        if (empty($record->courseid)) {
            throw new \moodle_exception(
                'error:missingcourseid',
                'local_hlai_quizgen',
                '',
                null,
                'Question must have a course ID before saving. CourseID=' . ($question->courseid ?? 'undefined')
            );
        }

        if (empty($record->userid)) {
            throw new \moodle_exception(
                'error:missinguserid',
                'local_hlai_quizgen',
                '',
                null,
                'Question must have a user ID before saving. UserID=' . ($question->userid ?? 'undefined')
            );
        }

        $questionid = $DB->insert_record('local_hlai_quizgen_questions', $record);
        $record->id = $questionid;

        // Save answers if present.
        if (!empty($question->answers)) {
            $answerorder = 0;
            foreach ($question->answers as $answer) {
                $answerrecord = new \stdClass();
                $answerrecord->questionid = $questionid;
                $answerrecord->answer = $answer['text'] ?? '';
                $answerrecord->answerformat = FORMAT_HTML;
                $answerrecord->fraction = $answer['fraction'] ?? 0.0;
                $answerrecord->feedback = $answer['feedback'] ?? '';
                $answerrecord->is_correct = ($answer['fraction'] ?? 0) > 0 ? 1 : 0;
                $answerrecord->distractor_reasoning = $answer['reasoning'] ?? '';
                $answerrecord->sortorder = $answerorder++;

                $DB->insert_record('local_hlai_quizgen_answers', $answerrecord);
            }
        }

        return $record;
    }

    /**
     * Get Bloom's level for difficulty.
     *
     * @param string $difficulty Difficulty level
     * @return string Bloom's taxonomy level
     */
    private static function get_blooms_for_difficulty(string $difficulty): string {
        switch ($difficulty) {
            case 'easy':
                return 'remember or understand';
            case 'hard':
                return 'analyze, evaluate, or create';
            case 'medium':
            default:
                return 'apply or analyze';
        }
    }

    /**
     * Distribute questions across types.
     *
     * @param int $total Total number of questions
     * @param array $types Question types
     * @return array Questions per type
     */
    private static function distribute_questions(int $total, array $types): array {
        $distribution = [];
        $pertype = floor($total / count($types));
        $remainder = $total % count($types);

        foreach ($types as $i => $type) {
            $distribution[$type] = $pertype + ($i < $remainder ? 1 : 0);
        }

        return $distribution;
    }


    /**
     * Validate configuration.
     *
     * @param array $config Configuration array
     * @throws \moodle_exception If invalid
     */
    private static function validate_config(array $config): void {
        if (empty($config['question_types'])) {
            throw new \moodle_exception('error:noquestiontypes', 'local_hlai_quizgen');
        }

        foreach ($config['question_types'] as $type) {
            if (!in_array($type, self::QUESTION_TYPES)) {
                throw new \moodle_exception('error:invalidquestiontype', 'local_hlai_quizgen', '', $type);
            }
        }

        if (isset($config['difficulty']) && !in_array($config['difficulty'], self::DIFFICULTY_LEVELS)) {
            throw new \moodle_exception('error:invaliddifficulty', 'local_hlai_quizgen');
        }
    }

    /**
     * Get full content for a request from all original sources.
     *
     * This is called ONCE per request and cached, not once per topic.
     * Prevents redundant content extraction that wastes tokens.
     *
     * Instead of relying on AI-generated excerpts, this fetches the actual
     * content from activities, files, and other sources to ensure questions
     * are generated from real content.
     *
     * @param \stdClass $request Request object
     * @return string Full content text
     */
    private static function get_full_content_for_request(\stdClass $request): string {
        global $DB;

        $fullcontent = '';

        // Get manual text from custom_instructions.
        if (!empty($request->custom_instructions)) {
            $fullcontent .= $request->custom_instructions . "\n\n";
        }

        // Get uploaded files.
        $fs = get_file_storage();
        $context = \context_course::instance($request->courseid);
        $files = $fs->get_area_files($context->id, 'local_hlai_quizgen', 'content', $request->id, 'filename', false);

        if (!empty($files)) {
            foreach ($files as $file) {
                $filepath = $file->copy_content_to_temp();
                $filename = $file->get_filename();

                try {
                    $result = \local_hlai_quizgen\content_extractor::extract_from_file($filepath, $filename);
                    if (!empty($result['text'])) {
                        $fullcontent .= "\n\n=== Content from $filename ===\n\n";
                        $fullcontent .= $result['text'];
                    }
                } catch (\Exception $e) {
                    // Silently skip files that fail to extract.
                    debugging($e->getMessage(), DEBUG_DEVELOPER);
                } finally {
                    if (file_exists($filepath)) {
                        @unlink($filepath);
                    }
                }
            }
        }

        // Get content from activities.
        if (!empty($request->content_sources)) {
            $sources = json_decode($request->content_sources, true);
            foreach ($sources as $source) {
                if (strpos($source, 'course_activities:') === 0) {
                    $activityidsstr = substr($source, strlen('course_activities:'));
                    $activityids = array_map('intval', explode(',', $activityidsstr));

                    if (!empty($activityids)) {
                        try {
                            $activitycontent = \local_hlai_quizgen\content_extractor::extract_from_activities(
                                $request->courseid,
                                $activityids
                            );
                            if (!empty(trim($activitycontent))) {
                                $fullcontent .= "\n\n" . $activitycontent;
                            }
                        } catch (\Exception $e) {
                            // Silently skip activities that fail to extract.
                            debugging($e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                } else if (strpos($source, 'bulk_scan:') === 0) {
                    // For bulk scans, get content from all topics' descriptions.
                    // DEBUG: Log that we're handling bulk scan
                    \local_hlai_quizgen\debug_logger::debug('Handling bulk_scan content source', [
                        'request_id' => $request->id,
                        'source' => $source,
                    ], $request->id);

                    $topics = $DB->get_records('local_hlai_quizgen_topics', ['requestid' => $request->id], 'id ASC');
                    \local_hlai_quizgen\debug_logger::debug('Found topics for bulk scan', [
                        'topic_count' => count($topics),
                    ], $request->id);

                    foreach ($topics as $topic) {
                        if (!empty($topic->description)) {
                            $fullcontent .= "\n\n=== TOPIC: {$topic->title} ===\n\n";
                            $fullcontent .= $topic->description;
                        }
                        if (!empty($topic->content_excerpt)) {
                            $fullcontent .= "\n\n" . $topic->content_excerpt;
                        }
                    }

                    // DEBUG: Log bulk scan content length
                    \local_hlai_quizgen\debug_logger::debug('Bulk scan content extracted', [
                        'content_length' => strlen($fullcontent),
                    ], $request->id);
                }
            }
        }

        // Get URL content.
        $urlcontent = $DB->get_records('local_hlai_quizgen_url_content', ['requestid' => $request->id]);
        foreach ($urlcontent as $url) {
            $fullcontent .= "\n\n=== Content from {$url->title} ===\n\n";
            $fullcontent .= $url->content;
        }

        // Return full content (trimmed).
        return trim($fullcontent);
    }
}
