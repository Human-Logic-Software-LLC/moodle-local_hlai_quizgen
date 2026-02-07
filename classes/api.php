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
 * Main API for the AI Quiz Generator plugin.
 *
 * This is the primary interface for creating and managing quiz generation requests.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

/**
 * AI Quiz Generator API class.
 */
class api {
    /** @var array Valid status values and their allowed transitions */
    const REQUEST_STATUSES = [
        'pending' => ['analyzing', 'processing', 'failed'],
        'analyzing' => ['topics_ready', 'failed'],
        'topics_ready' => ['processing', 'pending', 'failed'],
        'processing' => ['completed', 'failed'],
        'completed' => ['pending'], // Allow regeneration.
        'failed' => ['pending'], // Allow retry.
    ];

    /**
     * Update request status with validation.
     *
     * @param int $requestid Request ID
     * @param string $newstatus New status value
     * @param string|null $errormessage Optional error message for failed status
     * @return bool Success
     * @throws \moodle_exception If invalid status transition
     */
    public static function update_request_status(int $requestid, string $newstatus, ?string $errormessage = null): bool {
        global $DB, $USER;

        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);
        $oldstatus = $request->status;

        // Validate status transition.
        if (!isset(self::REQUEST_STATUSES[$oldstatus])) {
            throw new \moodle_exception('error:invalidstatus', 'local_hlai_quizgen', '', $oldstatus);
        }

        $allowedtransitions = self::REQUEST_STATUSES[$oldstatus];
        if (!in_array($newstatus, $allowedtransitions) && $oldstatus !== $newstatus) {
            throw new \moodle_exception(
                'error:invalidstatustransition',
                'local_hlai_quizgen',
                '',
                "Cannot transition from {$oldstatus} to {$newstatus}"
            );
        }

        // Update status.
        $update = new \stdClass();
        $update->id = $requestid;
        $update->status = $newstatus;
        $update->timemodified = time();

        if ($newstatus === 'completed') {
            $update->timecompleted = time();
        }

        if ($newstatus === 'failed' && $errormessage) {
            $update->error_message = $errormessage;
            $update->timecompleted = time();
        }

        $result = $DB->update_record('local_hlai_quizgen_requests', $update);

        // Log status change.
        self::log_action('status_changed', $requestid, $USER->id ?? 0, [
            'old_status' => $oldstatus,
            'new_status' => $newstatus,
            'error_message' => $errormessage,
        ]);

        return $result;
    }

    /**
     * Create a new generation request.
     *
     * @param int $courseid Course ID
     * @param array $contentsources Array of content sources
     * @param array $config Request configuration
     * @return int Request ID
     * @throws \moodle_exception If creation fails or rate limit exceeded
     */
    public static function create_request(int $courseid, array $contentsources, array $config = []): int {
        global $DB, $USER;

        // Check rate limit first.
        if (rate_limiter::is_rate_limiting_enabled() && !rate_limiter::is_user_exempt($USER->id)) {
            $ratelimitcheck = rate_limiter::check_rate_limit($USER->id, $courseid);

            if (!$ratelimitcheck['allowed']) {
                // Record violation.
                rate_limiter::record_violation($USER->id, $ratelimitcheck['limit_type'] ?? 'unknown', $ratelimitcheck);

                throw new \moodle_exception(
                    'error:rate_limit_exceeded',
                    'local_hlai_quizgen',
                    '',
                    $ratelimitcheck['reason']
                );
            }
        }

        // Validate course.
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = \context_course::instance($courseid);
        require_capability('local/hlai_quizgen:generatequestions', $context);

        // Create request record.
        $request = new \stdClass();
        $request->courseid = $courseid;
        $request->userid = $USER->id;
        $request->status = 'pending';
        $request->content_sources = json_encode($contentsources);
        $request->total_questions = $config['total_questions'] ?? 0;
        $request->questions_generated = 0;
        $request->processing_mode = $config['processing_mode'] ?? get_config('local_hlai_quizgen', 'default_quality_mode');
        $defaultdifficulty = ['easy' => 20, 'medium' => 60, 'hard' => 20];
        $request->difficulty_distribution = json_encode(
            $config['difficulty_distribution'] ?? $defaultdifficulty
        );
        $request->question_types = json_encode($config['question_types'] ?? ['multichoice']);
        $request->custom_instructions = $config['custom_instructions'] ?? '';
        $request->timecreated = time();
        $request->timemodified = time();

        $requestid = $DB->insert_record('local_hlai_quizgen_requests', $request);

        // Log creation.
        self::log_action('request_created', $requestid, $USER->id, [
            'courseid' => $courseid,
            'total_questions' => $request->total_questions,
        ]);

        return $requestid;
    }

    /**
     * Get request details.
     *
     * @param int $requestid Request ID
     * @return \stdClass Request object
     * @throws \moodle_exception If not found
     */
    public static function get_request(int $requestid): \stdClass {
        global $DB;

        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

        // Decode JSON fields.
        $request->content_sources = json_decode($request->content_sources, true);
        $request->difficulty_distribution = json_decode($request->difficulty_distribution, true);
        $request->question_types = json_decode($request->question_types, true);

        return $request;
    }

    /**
     * Get questions for a request.
     *
     * @param int $requestid Request ID
     * @param string|null $status Filter by status (optional)
     * @return array Array of question objects
     */
    public static function get_questions(int $requestid, ?string $status = null): array {
        global $DB;

        $params = ['requestid' => $requestid];
        if ($status) {
            $params['status'] = $status;
        }

        $questions = $DB->get_records('local_hlai_quizgen_questions', $params, 'timecreated ASC');

        // Load answers for each question.
        foreach ($questions as $question) {
            $question->answers = $DB->get_records(
                'local_hlai_quizgen_answers',
                ['questionid' => $question->id],
                'sortorder ASC'
            );

            // Add validation badge if available.
            if (!empty($question->quality_rating)) {
                $question->quality_badge = get_string('quality_' . $question->quality_rating, 'local_hlai_quizgen');
            }
        }

        return $questions;
    }

    /**
     * Update question.
     *
     * @param int $questionid Question ID
     * @param array $data Updated data
     * @return bool Success
     */
    public static function update_question(int $questionid, array $data): bool {
        global $DB, $USER;

        $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);

        $record = new \stdClass();
        $record->id = $questionid;

        if (isset($data['questiontext'])) {
            $record->questiontext = $data['questiontext'];
        }
        if (isset($data['generalfeedback'])) {
            $record->generalfeedback = $data['generalfeedback'];
        }
        if (isset($data['difficulty'])) {
            $record->difficulty = $data['difficulty'];
        }
        if (isset($data['status'])) {
            $record->status = $data['status'];
        }

        $record->timemodified = time();

        $result = $DB->update_record('local_hlai_quizgen_questions', $record);

        // Log update.
        self::log_action('question_updated', $question->requestid, $USER->id, [
            'questionid' => $questionid,
            'changes' => array_keys($data),
        ]);

        return $result;
    }

    /**
     * Delete question.
     *
     * @param int $questionid Question ID
     * @return bool Success
     */
    public static function delete_question(int $questionid): bool {
        global $DB, $USER;

        $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);

        // Delete answers.
        $DB->delete_records('local_hlai_quizgen_answers', ['questionid' => $questionid]);

        // Delete question.
        $result = $DB->delete_records('local_hlai_quizgen_questions', ['id' => $questionid]);

        // Log deletion.
        self::log_action('question_deleted', $question->requestid, $USER->id, [
            'questionid' => $questionid,
        ]);

        return $result;
    }

    /**
     * Approve questions.
     *
     * @param array $questionids Array of question IDs
     * @return int Number of questions approved
     */
    public static function approve_questions(array $questionids): int {
        global $DB, $USER;

        $count = 0;
        foreach ($questionids as $questionid) {
            $result = self::update_question($questionid, ['status' => 'approved']);
            if ($result) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Log an action.
     *
     * @param string $action Action type
     * @param int|null $requestid Request ID
     * @param int|null $userid User ID
     * @param array $details Additional details
     * @param string $status Status (success/error)
     * @param string $error Error message
     * @return void
     */
    public static function log_action(
        string $action,
        ?int $requestid = null,
        ?int $userid = null,
        array $details = [],
        string $status = 'success',
        string $error = ''
    ): void {
        global $DB, $USER;

        $log = new \stdClass();
        $log->requestid = $requestid;
        $log->userid = $userid ?? $USER->id;
        $log->action = $action;
        $log->component = 'local_hlai_quizgen';
        $log->details = json_encode($details);
        $log->status = $status;
        $log->error_message = $error;
        $log->timecreated = time();

        $DB->insert_record('local_hlai_quizgen_logs', $log);
    }

    /**
     * Get request history for a course.
     *
     * @param int $courseid Course ID
     * @param int|null $userid User ID (optional, defaults to current user)
     * @return array Array of request objects
     */
    public static function get_request_history(int $courseid, ?int $userid = null): array {
        global $DB, $USER;

        $params = ['courseid' => $courseid];
        if ($userid) {
            $params['userid'] = $userid;
        } else {
            $params['userid'] = $USER->id;
        }

        return $DB->get_records('local_hlai_quizgen_requests', $params, 'timecreated DESC');
    }

    /**
     * Check if AI provider is available (hlai_hub or hlai_hubproxy).
     *
     * @return bool True if available
     */
    public static function is_ai_hub_available(): bool {
        return gateway_client::is_ready();
    }

    /**
     * Get plugin statistics.
     *
     * @param int|null $courseid Course ID (optional)
     * @return array Statistics
     */
    public static function get_statistics(?int $courseid = null): array {
        global $DB;

        $stats = [
            'total_requests' => 0,
            'total_questions' => 0,
            'questions_by_type' => [],
            'questions_by_difficulty' => [],
            'average_questions_per_request' => 0,
        ];

        $params = [];
        $where = '';
        if ($courseid) {
            $where = 'WHERE r.courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        // Total requests.
        $sql = "SELECT COUNT(*) FROM {local_hlai_quizgen_requests} r $where";
        $stats['total_requests'] = $DB->count_records_sql($sql, $params);

        // Total questions.
        $sql = "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} q
                JOIN {local_hlai_quizgen_requests} r ON q.requestid = r.id
                $where";
        $stats['total_questions'] = $DB->count_records_sql($sql, $params);

        // Questions by type.
        $sql = "SELECT q.questiontype, COUNT(*) as count
                FROM {local_hlai_quizgen_questions} q
                JOIN {local_hlai_quizgen_requests} r ON q.requestid = r.id
                $where
                GROUP BY q.questiontype";
        $types = $DB->get_records_sql($sql, $params);
        foreach ($types as $type) {
            $stats['questions_by_type'][$type->questiontype] = $type->count;
        }

        // Questions by difficulty.
        $sql = "SELECT q.difficulty, COUNT(*) as count
                FROM {local_hlai_quizgen_questions} q
                JOIN {local_hlai_quizgen_requests} r ON q.requestid = r.id
                $where
                GROUP BY q.difficulty";
        $difficulties = $DB->get_records_sql($sql, $params);
        foreach ($difficulties as $diff) {
            $stats['questions_by_difficulty'][$diff->difficulty] = $diff->count;
        }

        // Average questions per request.
        if ($stats['total_requests'] > 0) {
            $stats['average_questions_per_request'] = round($stats['total_questions'] / $stats['total_requests'], 1);
        }

        // Quality distribution (if validation enabled).
        $sql = "SELECT q.quality_rating, COUNT(*) as count
                FROM {local_hlai_quizgen_questions} q
                JOIN {local_hlai_quizgen_requests} r ON q.requestid = r.id
                $where AND q.quality_rating IS NOT NULL
                GROUP BY q.quality_rating";
        $qualities = $DB->get_records_sql($sql, $params);
        $stats['questions_by_quality'] = [];
        foreach ($qualities as $quality) {
            $stats['questions_by_quality'][$quality->quality_rating] = $quality->count;
        }

        // Average validation score.
        $sql = "SELECT AVG(q.validation_score) as avg_score
                FROM {local_hlai_quizgen_questions} q
                JOIN {local_hlai_quizgen_requests} r ON q.requestid = r.id
                $where AND q.validation_score IS NOT NULL";
        $result = $DB->get_record_sql($sql, $params);
        $stats['average_validation_score'] = $result && $result->avg_score ? round($result->avg_score, 1) : null;

        return $stats;
    }

    /**
     * Diagnose question deployment status.
     *
     * This method checks the state of deployed questions in both the plugin tables
     * and the Moodle question bank tables to help identify issues.
     *
     * @param int $requestid Request ID to diagnose
     * @return array Diagnostic information
     */
    public static function diagnose_deployment(int $requestid): array {
        global $DB;

        $result = [
            'request_id' => $requestid,
            'timestamp' => date('Y-m-d H:i:s'),
            'plugin_questions' => [],
            'moodle_questions' => [],
            'categories' => [],
            'issues' => [],
        ];

        // Get request info.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid]);
        if (!$request) {
            $result['issues'][] = "Request ID $requestid not found";
            return $result;
        }
        $result['request_status'] = $request->status;
        $result['course_id'] = $request->courseid;

        // Get questions from plugin table.
        $pluginquestions = $DB->get_records('local_hlai_quizgen_questions', ['requestid' => $requestid]);
        $result['plugin_question_count'] = count($pluginquestions);

        foreach ($pluginquestions as $pq) {
            $qinfo = [
                'id' => $pq->id,
                'type' => $pq->questiontype,
                'status' => $pq->status,
                'moodle_questionid' => $pq->moodle_questionid ?? null,
                'timedeployed' => $pq->timedeployed ? date('Y-m-d H:i:s', $pq->timedeployed) : null,
            ];

            // If deployed, check Moodle tables.
            if (!empty($pq->moodle_questionid)) {
                // Check question table.
                $mq = $DB->get_record('question', ['id' => $pq->moodle_questionid]);
                if ($mq) {
                    $qinfo['moodle_question_exists'] = true;
                    $qinfo['moodle_category_id'] = $mq->category;
                    $qinfo['moodle_hidden'] = $mq->hidden;
                    $qinfo['moodle_qtype'] = $mq->qtype;

                    // Check question_bank_entries.
                    $qbe = $DB->get_record_sql(
                        "SELECT qbe.*, qv.status as version_status, qv.version
                         FROM {question_bank_entries} qbe
                         JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                         WHERE qv.questionid = ?
                         ORDER BY qv.version DESC",
                        [$pq->moodle_questionid]
                    );
                    if ($qbe) {
                        $qinfo['bank_entry_exists'] = true;
                        $qinfo['bank_entry_category_id'] = $qbe->questioncategoryid;
                        $qinfo['version_status'] = $qbe->version_status;
                        $qinfo['version'] = $qbe->version;

                        // Check if categories match.
                        if ($mq->category != $qbe->questioncategoryid) {
                            $qinfo['issue'] = "Category mismatch: question.category={$mq->category},"
                                . " bank_entry.categoryid={$qbe->questioncategoryid}";
                            $result['issues'][] = $qinfo['issue'];
                        }
                        if ($qbe->version_status !== 'ready') {
                            $qinfo['issue'] = "Question version status is '{$qbe->version_status}' instead of 'ready'";
                            $result['issues'][] = $qinfo['issue'];
                        }
                    } else {
                        $qinfo['bank_entry_exists'] = false;
                        $qinfo['issue'] = "No question_bank_entries/question_versions record found";
                        $result['issues'][] = "Question {$pq->moodle_questionid}: " . $qinfo['issue'];
                    }
                } else {
                    $qinfo['moodle_question_exists'] = false;
                    $qinfo['issue'] = "Moodle question ID {$pq->moodle_questionid} not found in question table";
                    $result['issues'][] = $qinfo['issue'];
                }
            } else if ($pq->status === 'deployed') {
                $qinfo['issue'] = "Status is 'deployed' but no moodle_questionid set";
                $result['issues'][] = "Question {$pq->id}: " . $qinfo['issue'];
            }

            $result['plugin_questions'][] = $qinfo;
        }

        // Get categories created for this course.
        $context = \context_course::instance($request->courseid);
        $categories = $DB->get_records_sql(
            "SELECT qc.id, qc.name, qc.contextid, qc.parent,
                    (SELECT COUNT(*) FROM {question_bank_entries} qbe WHERE qbe.questioncategoryid = qc.id) as question_count
             FROM {question_categories} qc
             WHERE qc.contextid = ?
             ORDER BY qc.id DESC",
            [$context->id]
        );
        foreach ($categories as $cat) {
            $result['categories'][] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'contextid' => $cat->contextid,
                'parent' => $cat->parent,
                'question_count' => $cat->question_count,
            ];
        }

        // Summary.
        $result['summary'] = [
            'total_plugin_questions' => count($pluginquestions),
            'deployed_count' => count(array_filter($pluginquestions, fn($q) => $q->status === 'deployed')),
            'with_moodle_id' => count(array_filter($pluginquestions, fn($q) => !empty($q->moodle_questionid))),
            'categories_in_course_context' => count($categories),
            'issues_found' => count($result['issues']),
        ];

        return $result;
    }

    /**
     * Regenerate a single question.
     *
     * @param int $questionid Question ID to regenerate
     * @return \stdClass Regenerated question
     * @throws \moodle_exception If regeneration limit reached or generation fails
     */
    public static function regenerate_question(int $questionid): \stdClass {
        global $DB, $USER;

        // Get current question.
        $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);

        // Check permissions - user must own the question or have managequestions capability.
        $context = \context_course::instance($question->courseid);
        if ($question->userid != $USER->id && !has_capability('local/hlai_quizgen:managequestions', $context)) {
            throw new \moodle_exception('nopermission', 'error');
        }

        // Check regeneration limit (default to 0 if missing column/old data).
        $currentregen = isset($question->regeneration_count) ? (int)$question->regeneration_count : 0;
        $maxregenerations = get_config('local_hlai_quizgen', 'max_regenerations') ?: 5;
        if ($currentregen >= $maxregenerations) {
            throw new \moodle_exception(
                'error:maxregenerations',
                'local_hlai_quizgen',
                '',
                $maxregenerations,
                'Maximum regeneration limit reached for this question'
            );
        }

        // Get topic info for context.
        $topic = $DB->get_record('local_hlai_quizgen_topics', ['id' => $question->topicid], '*', MUST_EXIST);

        // Get request for config.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $question->requestid], '*', MUST_EXIST);

        try {
            // Build config array for generate_for_topic().
            $config = [
                'num_questions' => 1,
                'question_types' => [$question->questiontype],
                'difficulty' => $question->difficulty,
                'blooms_level' => $question->blooms_level,
                'processing_mode' => 'balanced',
                'global_question_index' => 0,
                'allow_completed' => true, // FIX: Allow regeneration even when request is completed.
                'is_regeneration' => true, // Flag to indicate this is a regeneration.
                'old_question_text' => $question->questiontext, // Pass old question to avoid duplicating it.
            ];

            // Generate new question using the same topic and type.
            // FIX: Pass topicid (int) and requestid (int), not objects.
            $newquestions = \local_hlai_quizgen\question_generator::generate_for_topic(
                $question->topicid,
                $request->id,
                $config
            );

            if (empty($newquestions)) {
                throw new \moodle_exception('error:questiongeneration', 'local_hlai_quizgen');
            }

            $newquestion = $newquestions[0];

            // FIX: Preserve original question's timecreated to maintain position in list.
            // Questions are sorted by timecreated, so we need to keep the same timestamp.
            $originaltimecreated = $question->timecreated;

            // Delete old question and answers.
            $DB->delete_records('local_hlai_quizgen_answers', ['questionid' => $questionid]);
            $DB->delete_records('local_hlai_quizgen_questions', ['id' => $questionid]);

            // Update new question's timecreated to match original position.
            $DB->set_field('local_hlai_quizgen_questions', 'timecreated', $originaltimecreated, ['id' => $newquestion->id]);
            $newquestion->timecreated = $originaltimecreated;

            // Increment regeneration count on new question.
            // Only attempt to write if the column exists in DB (older installs may miss it).
            $columns = $DB->get_columns('local_hlai_quizgen_questions');
            if (isset($columns['regeneration_count'])) {
                $DB->set_field(
                    'local_hlai_quizgen_questions',
                    'regeneration_count',
                    $currentregen + 1,
                    ['id' => $newquestion->id]
                );
                $newquestion->regeneration_count = $currentregen + 1;
            } else {
                // Column missing, continue without failing regeneration.
                $newquestion->regeneration_count = $currentregen + 1;
            }

            // Return updated question with new count.
            $newquestion->regeneration_count = $newquestion->regeneration_count ?? ($currentregen + 1);

            return $newquestion;
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:questiongeneration',
                'local_hlai_quizgen',
                '',
                null,
                'Failed to regenerate question: ' . $e->getMessage()
            );
        }
    }
}
