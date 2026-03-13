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
 * Adhoc task for background question generation with progress tracking.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\task;


/**
 * Adhoc task to generate questions in the background.
 */
class generate_questions_adhoc extends \core\task\adhoc_task {
    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $requestid = $data->request_id;

        // Log task start.
        \local_hlai_quizgen\debug_logger::info('Adhoc task started', [
            'task' => 'generate_questions_adhoc',
            'request_id' => $requestid,
        ], $requestid);

        try {
            // Update status to processing.
            self::update_progress($requestid, 'processing', 0, 'Starting generation...');

            // Get request details.
            $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

            // Get selected topics.
            $topics = \local_hlai_quizgen\topic_analyzer::get_selected_topics($requestid);

            \local_hlai_quizgen\debug_logger::debug('Topics retrieved', [
                'topics_count' => count($topics),
                'topic_ids' => array_keys($topics),
            ], $requestid);

            if (empty($topics)) {
                \local_hlai_quizgen\debug_logger::error('No topics selected for generation', [
                    'request_id' => $requestid,
                ], $requestid);
                throw new \moodle_exception('error:notopicsselected', 'local_hlai_quizgen');
            }

            // Calculate total questions from topics.
            $totalfromtopics = 0;
            foreach ($topics as $topic) {
                $totalfromtopics += $topic->num_questions;
            }

            // CRITICAL FIX: Use request's total_questions as authoritative source.
            // If there's a mismatch, redistribute questions across topics.
            $totalquestionsrequested = $request->total_questions ?? $totalfromtopics;

            if ($totalfromtopics != $totalquestionsrequested && $totalquestionsrequested > 0) {
                // Redistribute questions evenly across topics.
                $topiccount = count($topics);
                $questionspertopic = floor($totalquestionsrequested / $topiccount);
                $remainder = $totalquestionsrequested % $topiccount;

                $index = 0;
                foreach ($topics as $topic) {
                    $topicquestions = $questionspertopic + ($index < $remainder ? 1 : 0);
                    $topic->num_questions = $topicquestions;
                    $DB->set_field('local_hlai_quizgen_topics', 'num_questions', $topicquestions, ['id' => $topic->id]);
                    $index++;
                }
            }

            $currentquestion = 0;
            $totalquestionsgenerated = 0;

            // Build base config from request (as array, not object).
            $questiontypedist = json_decode($request->question_types ?? '{}', true);

            // If stored as expanded array (old format), convert to distribution.
            if (isset($questiontypedist[0])) {
                $dist = [];
                foreach ($questiontypedist as $type) {
                    $dist[$type] = ($dist[$type] ?? 0) + 1;
                }
                $questiontypedist = $dist;
            }

            // CRITICAL FIX: Expand question types into a global array, INTERLEAVED across types.
            // This ensures each topic gets a MIX of question types, not all of one type.
            // E.g. with 4 MCQ + 4 T/F + 4 SA: [mcq, tf, sa, mcq, tf, sa, mcq, tf, sa, mcq, tf, sa].
            $globalquestiontypes = [];
            if (!empty($questiontypedist)) {
                // Build per-type queues.
                $typequeues = [];
                foreach ($questiontypedist as $type => $count) {
                    for ($i = 0; $i < $count; $i++) {
                        $typequeues[$type][] = $type;
                    }
                }
                // Interleave: round-robin across types.
                $maxcount = max(array_map('count', $typequeues));
                for ($i = 0; $i < $maxcount; $i++) {
                    foreach ($typequeues as $type => &$queue) {
                        if (isset($queue[$i])) {
                            $globalquestiontypes[] = $queue[$i];
                        }
                    }
                    unset($queue);
                }
            }
            // Fallback if no types specified.
            if (empty($globalquestiontypes)) {
                for ($i = 0; $i < $totalquestionsrequested; $i++) {
                    $globalquestiontypes[] = 'multichoice';
                }
            }

            $baseconfig = [
                'processing_mode' => $request->processing_mode ?? 'balanced',
                'difficulty_distribution' => json_decode($request->difficulty_distribution ?? '{}', true),
                'blooms_distribution' => json_decode($request->blooms_distribution ?? '{}', true),
                'question_type_distribution' => $questiontypedist, // Store as distribution.
                'custom_instructions' => $request->custom_instructions ?? '',
                'global_question_index' => 0,
                'global_question_types' => $globalquestiontypes, // Pass the expanded global array.
            ];

            // Generate questions for each topic with progress updates.
            foreach ($topics as $topic) {
                // Update progress for this topic.
                self::update_progress(
                    $requestid,
                    'processing',
                    ($currentquestion / $totalquestionsrequested) * 100,
                    "Generating questions for topic: {$topic->title}"
                );

                // Build topic-specific config.
                $topicconfig = $baseconfig;
                $topicconfig['num_questions'] = $topic->num_questions;
                $topicconfig['global_question_index'] = $currentquestion;

                // ITEM 7 FIX: Use topic-specific distributions if available, fallback to request-level.
                if (!empty($topic->difficulty_distribution)) {
                    $topicconfig['difficulty_distribution'] = json_decode($topic->difficulty_distribution, true);
                }
                if (!empty($topic->blooms_distribution)) {
                    $topicconfig['blooms_distribution'] = json_decode($topic->blooms_distribution, true);
                }

                // CRITICAL FIX: Extract this topic's slice from the global question types array.
                // This ensures each topic gets its correct share of each question type.
                $topicquestiontypes = [];
                if (!empty($globalquestiontypes)) {
                    // Get the slice of question types for this topic based on global index.
                    for ($i = 0; $i < $topic->num_questions; $i++) {
                        $globalindex = $currentquestion + $i;
                        if (isset($globalquestiontypes[$globalindex])) {
                            $topicquestiontypes[] = $globalquestiontypes[$globalindex];
                        } else {
                            // Fallback: wrap around if we exceed array bounds.
                            $topicquestiontypes[] = $globalquestiontypes[$globalindex % count($globalquestiontypes)];
                        }
                    }
                }
                $topicconfig['question_types'] = !empty($topicquestiontypes) ? $topicquestiontypes : ['multichoice'];

                // Generate questions for this topic.
                $questions = \local_hlai_quizgen\question_generator::generate_for_topic(
                    $topic->id,
                    $requestid,
                    $topicconfig
                );

                $questionsgenerated = count($questions);
                $currentquestion += $topic->num_questions; // Use requested count for progress.
                $totalquestionsgenerated += $questionsgenerated; // Track actual generated.

                debugging("HLAI adhoc topic '{$topic->title}' (id={$topic->id}): requested={$topic->num_questions}, " .
                    "generated={$questionsgenerated}, types=" . json_encode($topicconfig['question_types']), DEBUG_DEVELOPER);

                // Delete excess questions if more were generated than requested.
                if ($questionsgenerated > $topic->num_questions) {
                    // Get all questions for this topic in this request.
                    $topicquestions = $DB->get_records(
                        'local_hlai_quizgen_questions',
                        ['requestid' => $requestid, 'topicid' => $topic->id],
                        'id ASC'
                    );
                    $excess = $questionsgenerated - $topic->num_questions;
                    $deleted = 0;
                    // Delete the last N questions (keep the first ones).
                    $topicquestions = array_values($topicquestions);
                    for ($i = count($topicquestions) - 1; $i >= 0 && $deleted < $excess; $i--) {
                        $qid = $topicquestions[$i]->id;
                        $DB->delete_records('local_hlai_quizgen_answers', ['questionid' => $qid]);
                        $DB->delete_records('local_hlai_quizgen_questions', ['id' => $qid]);
                        $deleted++;
                    }
                    $questionsgenerated -= $deleted;
                    $totalquestionsgenerated -= $deleted;
                }

                // Update progress after each topic.
                self::update_progress(
                    $requestid,
                    'processing',
                    ($currentquestion / $totalquestionsrequested) * 100,
                    "Generated {$totalquestionsgenerated} of {$totalquestionsrequested} questions (processing)"
                );
            }

            // Update request with actual generated count.
            debugging(
                "HLAI adhoc SUMMARY: total_requested={$totalquestionsrequested}, total_generated={$totalquestionsgenerated}, " .
                "topics=" . count($topics),
                DEBUG_DEVELOPER
            );
            if ($totalquestionsgenerated < $totalquestionsrequested) {
                debugging("HLAI adhoc WARNING: Missing " . ($totalquestionsrequested - $totalquestionsgenerated) .
                    " questions. Check per-topic and save_question logs above.", DEBUG_DEVELOPER);
            }
            $DB->set_field('local_hlai_quizgen_requests', 'total_questions', $totalquestionsgenerated, ['id' => $requestid]);

            // POST-GENERATION: Detect and remove duplicate questions.
            self::update_progress($requestid, 'processing', 95, "Checking for duplicate questions...");
            $allquestions = $DB->get_records('local_hlai_quizgen_questions', ['requestid' => $requestid], 'id ASC');
            $questiontexts = [];
            $questionids = [];
            $questionanswers = []; // Track answers for answer-based dedup.
            foreach ($allquestions as $q) {
                $questiontexts[] = $q->questiontext;
                $questionids[] = $q->id;
                // Get the correct answer for this question (for shortanswer dedup).
                $correctanswer = $DB->get_record(
                    'local_hlai_quizgen_answers',
                    ['questionid' => $q->id, 'is_correct' => 1],
                    'answer'
                );
                $questionanswers[] = $correctanswer ? strtolower(trim($correctanswer->answer)) : '';
            }

            $idstoremove = [];

            // Method 1: Text similarity dedup.
            if (count($questiontexts) > 1) {
                $duplicates = \local_hlai_quizgen\question_validator::check_for_duplicates($questiontexts);
                if (!empty($duplicates)) {
                    foreach ($duplicates as $dup) {
                        $dupid = $questionids[$dup['index2']];
                        $idstoremove[$dupid] = true;
                    }
                }
            }

            // Method 2: Same-answer dedup for shortanswer questions.
            // If two shortanswer questions have the same correct answer, keep only the first.
            $seenanswers = [];
            $seqidx = 0;
            foreach ($allquestions as $q) {
                if ($q->questiontype === 'shortanswer' && !empty($questionanswers[$seqidx])) {
                    $ans = $questionanswers[$seqidx];
                    if (isset($seenanswers[$ans])) {
                        $idstoremove[$q->id] = true;
                    } else {
                        $seenanswers[$ans] = true;
                    }
                }
                $seqidx++;
            }

            // Delete all flagged duplicates.
            if (!empty($idstoremove)) {
                foreach (array_keys($idstoremove) as $removeid) {
                    $DB->delete_records('local_hlai_quizgen_answers', ['questionid' => $removeid]);
                    $DB->delete_records('local_hlai_quizgen_questions', ['id' => $removeid]);
                    $totalquestionsgenerated--;
                }

                // Generate replacement questions to fill the gap.
                $shortfall = $totalquestionsrequested - $totalquestionsgenerated;
                if ($shortfall > 0 && !empty($topics)) {
                    self::update_progress($requestid, 'processing', 97, "Generating {$shortfall} replacement questions...");
                    // Pick the first topic for replacements.
                    $replacetopic = reset($topics);
                    $replacetypes = [];
                    for ($i = 0; $i < $shortfall; $i++) {
                        $replacetypes[] = $globalquestiontypes[$i % count($globalquestiontypes)];
                    }
                    $replaceconfig = $baseconfig;
                    $replaceconfig['num_questions'] = $shortfall;
                    $replaceconfig['question_types'] = $replacetypes;
                    try {
                        $replacequestions = \local_hlai_quizgen\question_generator::generate_for_topic(
                            $replacetopic->id,
                            $requestid,
                            $replaceconfig
                        );
                        $totalquestionsgenerated += count($replacequestions);
                    } catch (\Exception $replaceex) {
                        \local_hlai_quizgen\debug_logger::debug(
                            "Replacement generation failed: " . $replaceex->getMessage(),
                            [],
                            $requestid
                        );
                    }
                }

                // Update count after deduplication and replacement.
                $DB->set_field('local_hlai_quizgen_requests', 'total_questions', $totalquestionsgenerated, ['id' => $requestid]);
            }

            // Mark as completed.
            self::update_progress(
                $requestid,
                'completed',
                100,
                "Generation complete: {$totalquestionsgenerated} questions created"
            );

            // Update request status.
            \local_hlai_quizgen\api::update_request_status($requestid, 'completed');
        } catch (\Exception $e) {
            // Log the exception with full details.
            \local_hlai_quizgen\debug_logger::exception($e, 'generate_questions_adhoc', $requestid);

            // Mark as failed.
            self::update_progress($requestid, 'failed', 0, $e->getMessage());

            // Update request status.
            \local_hlai_quizgen\api::update_request_status($requestid, 'failed', $e->getMessage());

            // Log task failure.
            \local_hlai_quizgen\debug_logger::error('Adhoc task failed', [
                'task' => 'generate_questions_adhoc',
                'request_id' => $requestid,
                'error' => $e->getMessage(),
            ], $requestid);

            throw $e;
        }
    }

    /**
     * Update progress in database.
     *
     * @param int $requestid Request ID
     * @param string $status Status (processing, completed, failed)
     * @param float $progress Progress percentage (0-100)
     * @param string $message Progress message
     * @return void
     */
    private static function update_progress(int $requestid, string $status, float $progress, string $message): void {
        global $DB;

        $record = new \stdClass();
        $record->id = $requestid;
        $record->status = $status;
        $record->progress = round($progress, 2);
        $record->progress_message = $message;
        $record->timemodified = time();

        $DB->update_record('local_hlai_quizgen_requests', $record);
    }
}
