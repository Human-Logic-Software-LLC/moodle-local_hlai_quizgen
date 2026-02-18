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
 * AJAX endpoints for the AI Quiz Generator plugin.
 *
 * Provides real-time data for dashboard, analytics, progress monitoring,
 * and inline editing without full page reloads.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

// Get action and validate.
$action = required_param('action', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$requestid = optional_param('requestid', 0, PARAM_INT);
$questionid = optional_param('questionid', 0, PARAM_INT);

// Basic security checks.
require_login();
require_sesskey();

// Set JSON header.
header('Content-Type: application/json; charset=utf-8');

/**
 * Send JSON response helper.
 *
 * @param bool $success Success status
 * @param array $data Response data
 * @param string $message Response message
 * @return void
 */
function local_hlai_quizgen_send_response($success, $data = [], $message = '') {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time(),
    ]);
    exit;
}

/**
 * Send error response.
 *
 * @param string $message Error message
 * @param int $code HTTP status code
 * @return void
 */
function local_hlai_quizgen_send_error($message, $code = 400) {
    http_response_code($code);
    local_hlai_quizgen_send_response(false, [], $message);
}

// Context validation for course-specific actions.
if ($courseid > 0) {
    $context = context_course::instance($courseid);
    require_capability('local/hlai_quizgen:generatequestions', $context);
}

try {
    switch ($action) {
        // DASHBOARD DATA ENDPOINTS.

        case 'dashboardstats':
            // Get quick stats for dashboard cards.
            $userid = $USER->id;

            // Total quizzes created by user.
            $totalquizzes = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT r.id)
                 FROM {local_hlai_quizgen_requests} r
                 WHERE r.userid = ? AND r.status = 'completed'",
                [$userid]
            );

            // Total questions generated.
            $totalquestions = $DB->count_records_sql(
                "SELECT COUNT(q.id)
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ?",
                [$userid]
            );

            // Questions approved.
            $approvedquestions = $DB->count_records_sql(
                "SELECT COUNT(q.id)
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ? AND q.status = 'approved'",
                [$userid]
            );

            // Average quality score.
            $avgquality = $DB->get_field_sql(
                "SELECT AVG(q.validation_score)
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ? AND q.validation_score IS NOT NULL",
                [$userid]
            );

            // Acceptance rate.
            $totalreviewed = $DB->count_records_sql(
                "SELECT COUNT(q.id)
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ? AND q.status IN ('approved', 'rejected')",
                [$userid]
            );
            $acceptancerate = $totalreviewed > 0 ? round(($approvedquestions / $totalreviewed) * 100, 1) : 0;

            // First-time acceptance rate.
            $firsttimeapproved = $DB->count_records_sql(
                "SELECT COUNT(q.id)
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ? AND q.status = 'approved' AND q.regeneration_count = 0",
                [$userid]
            );
            $ftar = $approvedquestions > 0 ? round(($firsttimeapproved / $approvedquestions) * 100, 1) : 0;

            // Average regeneration count.
            $avgregen = $DB->get_field_sql(
                "SELECT AVG(q.regeneration_count)
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ?",
                [$userid]
            );

            local_hlai_quizgen_send_response(true, [
                'total_quizzes' => (int)$totalquizzes,
                'total_questions' => (int)$totalquestions,
                'approved_questions' => (int)$approvedquestions,
                'avg_quality' => round((float)$avgquality, 1),
                'acceptance_rate' => $acceptancerate,
                'ftar' => $ftar,
                'avg_regenerations' => round((float)$avgregen, 2),
            ]);
            break;

        case 'questiontypedist':
            // Get question type distribution for charts.
            $userid = $USER->id;
            $filtercourseid = optional_param('filtercourseid', 0, PARAM_INT);

            $params = [$userid];
            $coursefilter = '';
            if ($filtercourseid > 0) {
                $coursefilter = ' AND q.courseid = ?';
                $params[] = $filtercourseid;
            }

            $types = $DB->get_records_sql(
                "SELECT q.questiontype, COUNT(q.id) as count
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ? $coursefilter
                 GROUP BY q.questiontype
                 ORDER BY count DESC",
                $params
            );

            $labels = [];
            $values = [];
            foreach ($types as $type) {
                $labels[] = ucfirst(str_replace('_', ' ', $type->questiontype));
                $values[] = (int)$type->count;
            }

            local_hlai_quizgen_send_response(true, [
                'labels' => $labels,
                'values' => $values,
            ]);
            break;

        case 'difficultydist':
            // Get difficulty distribution.
            $userid = $USER->id;

            $difficulties = $DB->get_records_sql(
                "SELECT q.difficulty, COUNT(q.id) as count
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ?
                 GROUP BY q.difficulty",
                [$userid]
            );

            $dist = ['easy' => 0, 'medium' => 0, 'hard' => 0];
            foreach ($difficulties as $d) {
                if (isset($dist[$d->difficulty])) {
                    $dist[$d->difficulty] = (int)$d->count;
                }
            }

            local_hlai_quizgen_send_response(true, $dist);
            break;

        case 'bloomsdist':
            // Get Bloom's taxonomy distribution.
            $userid = $USER->id;

            $blooms = $DB->get_records_sql(
                "SELECT q.blooms_level, COUNT(q.id) as count
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ? AND q.blooms_level IS NOT NULL
                 GROUP BY q.blooms_level",
                [$userid]
            );

            $dist = [
                'remember' => 0,
                'understand' => 0,
                'apply' => 0,
                'analyze' => 0,
                'evaluate' => 0,
                'create' => 0,
            ];
            foreach ($blooms as $b) {
                $level = strtolower($b->blooms_level);
                if (isset($dist[$level])) {
                    $dist[$level] = (int)$b->count;
                }
            }

            local_hlai_quizgen_send_response(true, $dist);
            break;

        case 'acceptancetrend':
            // Get acceptance rate trend over last N quiz generations.
            $userid = $USER->id;
            $limit = optional_param('limit', 10, PARAM_INT);

            $requests = $DB->get_records_sql(
                "SELECT r.id, r.timecreated,
                        (SELECT COUNT(*) FROM {local_hlai_quizgen_questions} q
                         WHERE q.requestid = r.id AND q.status = 'approved') as approved,
                        (SELECT COUNT(*) FROM {local_hlai_quizgen_questions} q
                         WHERE q.requestid = r.id AND q.status IN ('approved', 'rejected')) as total
                 FROM {local_hlai_quizgen_requests} r
                 WHERE r.userid = ? AND r.status = 'completed'
                 ORDER BY r.timecreated ASC",
                [$userid],
                0,
                $limit
            );

            $labels = [];
            $acceptancerates = [];
            $ftarrates = [];
            $i = 1;

            // Batch-fetch FTAR counts to avoid N+1 query pattern.
            $requestids = array_keys($requests);
            $ftarcounts = [];
            if (!empty($requestids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($requestids);
                $ftarrecords = $DB->get_records_sql(
                    "SELECT requestid, COUNT(*) as cnt
                     FROM {local_hlai_quizgen_questions}
                     WHERE requestid $insql AND status = 'approved' AND regeneration_count = 0
                     GROUP BY requestid",
                    $inparams
                );
                foreach ($ftarrecords as $rec) {
                    $ftarcounts[$rec->requestid] = (int)$rec->cnt;
                }
            }

            foreach ($requests as $r) {
                $labels[] = get_string('ajax_gen_label', 'local_hlai_quizgen', $i);
                $rate = $r->total > 0 ? round(($r->approved / $r->total) * 100, 1) : 0;
                $acceptancerates[] = $rate;

                // Use pre-fetched FTAR count.
                $firsttime = $ftarcounts[$r->id] ?? 0;
                $ftar = $r->approved > 0 ? round(($firsttime / $r->approved) * 100, 1) : 0;
                $ftarrates[] = $ftar;
                $i++;
            }

            local_hlai_quizgen_send_response(true, [
                'labels' => $labels,
                'acceptance_rates' => $acceptancerates,
                'ftar_rates' => $ftarrates,
            ]);
            break;

        case 'regenbytype':
            // Get regeneration statistics by question type.
            $userid = $USER->id;

            $stats = $DB->get_records_sql(
                "SELECT q.questiontype,
                        COUNT(q.id) as total,
                        SUM(CASE WHEN q.regeneration_count > 0 THEN 1 ELSE 0 END) as regenerated,
                        AVG(q.regeneration_count) as avg_regens
                 FROM {local_hlai_quizgen_questions} q
                 WHERE q.userid = ?
                 GROUP BY q.questiontype",
                [$userid]
            );

            // Return object keyed by question type for dashboard.js compatibility.
            $data = [];
            foreach ($stats as $s) {
                $data[$s->questiontype] = [
                    'total' => (int)$s->total,
                    'regenerated' => (int)$s->regenerated,
                    'regen_rate' => $s->total > 0 ? round(($s->regenerated / $s->total) * 100, 1) : 0,
                    'avg_regenerations' => round((float)$s->avg_regens, 2),
                ];
            }

            local_hlai_quizgen_send_response(true, $data);
            break;

        case 'qualitydist':
            // Get quality score distribution (histogram data).
            $userid = $USER->id;

            $ranges = [
                '0-10' => [0, 10],
                '11-20' => [11, 20],
                '21-30' => [21, 30],
                '31-40' => [31, 40],
                '41-50' => [41, 50],
                '51-60' => [51, 60],
                '61-70' => [61, 70],
                '71-80' => [71, 80],
                '81-90' => [81, 90],
                '91-100' => [91, 100],
            ];

            $distribution = [];
            foreach ($ranges as $label => $range) {
                $count = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
                     WHERE userid = ? AND validation_score >= ? AND validation_score <= ?",
                    [$userid, $range[0], $range[1]]
                );
                $distribution[] = (int)$count;
            }

            local_hlai_quizgen_send_response(true, [
                'labels' => array_keys($ranges),
                'values' => $distribution,
            ]);
            break;

        case 'recentrequests':
            // Get recent quiz generation requests for dashboard.
            $userid = $USER->id;
            $limit = optional_param('limit', 5, PARAM_INT);

            $requests = $DB->get_records_sql(
                "SELECT r.id, r.courseid, r.status, r.total_questions, r.questions_generated,
                        r.timecreated, r.timecompleted,
                        c.fullname as coursename
                 FROM {local_hlai_quizgen_requests} r
                 JOIN {course} c ON c.id = r.courseid
                 WHERE r.userid = ?
                 ORDER BY r.timecreated DESC",
                [$userid],
                0,
                $limit
            );

            // Batch-fetch approved counts to avoid N+1 query pattern.
            $requestids = array_keys($requests);
            $approvedcounts = [];
            if (!empty($requestids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($requestids);
                $approvedrecords = $DB->get_records_sql(
                    "SELECT requestid, COUNT(*) as cnt
                     FROM {local_hlai_quizgen_questions}
                     WHERE requestid $insql AND status = 'approved'
                     GROUP BY requestid",
                    $inparams
                );
                foreach ($approvedrecords as $rec) {
                    $approvedcounts[$rec->requestid] = (int)$rec->cnt;
                }
            }

            $items = [];
            foreach ($requests as $r) {
                $approved = $approvedcounts[$r->id] ?? 0;

                $items[] = [
                    'id' => $r->id,
                    'courseid' => $r->courseid,
                    'coursename' => $r->coursename,
                    'status' => $r->status,
                    'total' => (int)$r->total_questions,
                    'generated' => (int)$r->questions_generated,
                    'approved' => $approved,
                    'timecreated' => userdate($r->timecreated, '%d %b %Y, %H:%M'),
                    'timeago' => format_time(time() - $r->timecreated),
                ];
            }

            local_hlai_quizgen_send_response(true, ['requests' => $items]);
            break;

        // PROGRESS MONITORING (AJAX Polling for Step 3.5).

        case 'getprogress':
            // Get current generation progress for a request.
            if (!$requestid) {
                local_hlai_quizgen_send_error(get_string('ajax_requestid_required', 'local_hlai_quizgen'));
            }

            $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

            // Verify user owns this request.
            if ($request->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            // Get questions generated so far.
            $questions = $DB->get_records_sql(
                "SELECT q.id, q.questiontype, q.difficulty, q.blooms_level, q.status,
                        q.validation_score, t.title as topic_title
                 FROM {local_hlai_quizgen_questions} q
                 LEFT JOIN {local_hlai_quizgen_topics} t ON t.id = q.topicid
                 WHERE q.requestid = ?
                 ORDER BY q.timecreated DESC
                 LIMIT 5",
                [$requestid]
            );

            // Get topic progress.
            $topics = $DB->get_records_sql(
                "SELECT t.id, t.title, t.num_questions as target,
                        (SELECT COUNT(*) FROM {local_hlai_quizgen_questions} q WHERE q.topicid = t.id) as generated
                 FROM {local_hlai_quizgen_topics} t
                 WHERE t.requestid = ? AND t.selected = 1
                 ORDER BY t.id",
                [$requestid]
            );

            // Build activity log from recent questions.
            $activities = [];
            foreach ($questions as $q) {
                $activities[] = [
                    'type' => 'question_generated',
                    'message' => get_string(
                        'ajax_generated_question_on_topic',
                        'local_hlai_quizgen',
                        (object)['type' => $q->questiontype, 'topic' => $q->topic_title]
                    ),
                    'difficulty' => $q->difficulty,
                    'blooms' => $q->blooms_level,
                ];
            }

            // Calculate current topic being processed.
            $currenttopic = null;
            foreach ($topics as $t) {
                if ($t->generated < $t->target) {
                    $currenttopic = [
                        'id' => $t->id,
                        'title' => $t->title,
                        'progress' => $t->generated,
                        'target' => $t->target,
                    ];
                    break;
                }
            }

            local_hlai_quizgen_send_response(true, [
                'status' => $request->status,
                'progress' => round((float)$request->progress, 1),
                'message' => $request->progress_message,
                'questions_generated' => (int)$request->questions_generated,
                'total_questions' => (int)$request->total_questions,
                'current_topic' => $currenttopic,
                'topics' => array_values($topics),
                'activities' => $activities,
                'is_complete' => in_array($request->status, ['completed', 'failed']),
                'error' => $request->status === 'failed' ? $request->error_message : null,
            ]);
            break;

        // QUESTION INLINE EDITING (AJAX).

        case 'updatequestion':
            // Update question text inline.
            if (!$questionid) {
                local_hlai_quizgen_send_error(get_string('ajax_questionid_required', 'local_hlai_quizgen'));
            }

            $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);

            // Verify user owns this question.
            if ($question->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            $field = required_param('field', PARAM_ALPHA);
            $value = required_param('value', PARAM_CLEANHTML);

            $allowedfields = ['questiontext', 'difficulty', 'blooms_level', 'generalfeedback'];
            if (!in_array($field, $allowedfields)) {
                local_hlai_quizgen_send_error(get_string('ajax_invalid_field', 'local_hlai_quizgen'));
            }

            // Sanitize based on field type.
            if ($field === 'questiontext' || $field === 'generalfeedback') {
                $value = clean_param($value, PARAM_TEXT);
            } else {
                $value = clean_param($value, PARAM_ALPHANUMEXT);
            }

            $DB->set_field('local_hlai_quizgen_questions', $field, $value, ['id' => $questionid]);
            $DB->set_field('local_hlai_quizgen_questions', 'timemodified', time(), ['id' => $questionid]);

            local_hlai_quizgen_send_response(true, ['field' => $field, 'value' => $value]);
            break;

        case 'updateanswer':
            // Update answer text inline.
            $answerid = required_param('answerid', PARAM_INT);

            $answer = $DB->get_record('local_hlai_quizgen_answers', ['id' => $answerid], '*', MUST_EXIST);
            $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $answer->questionid], '*', MUST_EXIST);

            // Verify user owns the question.
            if ($question->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            $field = required_param('field', PARAM_ALPHA);
            $value = required_param('value', PARAM_CLEANHTML);

            $allowedfields = ['answer', 'feedback', 'fraction'];
            if (!in_array($field, $allowedfields)) {
                local_hlai_quizgen_send_error(get_string('ajax_invalid_field', 'local_hlai_quizgen'));
            }

            if ($field === 'fraction') {
                $value = (float)$value;
            } else {
                $value = clean_param($value, PARAM_TEXT);
            }

            $DB->set_field('local_hlai_quizgen_answers', $field, $value, ['id' => $answerid]);

            local_hlai_quizgen_send_response(true, ['field' => $field, 'value' => $value]);
            break;

        case 'reorderanswers':
            // Reorder answers for a question (drag & drop).
            if (!$questionid) {
                local_hlai_quizgen_send_error(get_string('ajax_questionid_required', 'local_hlai_quizgen'));
            }

            $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
            if ($question->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            // PARAM_RAW required for JSON input, validated via json_decode below.
            $order = required_param('order', PARAM_RAW);
            $order = json_decode($order, true);

            if (!is_array($order)) {
                local_hlai_quizgen_send_error(get_string('ajax_invalid_order_format', 'local_hlai_quizgen'));
            }

            foreach ($order as $sortorder => $answerid) {
                $DB->set_field('local_hlai_quizgen_answers', 'sortorder', $sortorder, [
                    'id' => (int)$answerid,
                    'questionid' => $questionid,
                ]);
            }

            local_hlai_quizgen_send_response(true, ['reordered' => count($order)]);
            break;

        case 'approvequestion':
            // Approve a question with optional confidence rating.
            if (!$questionid) {
                local_hlai_quizgen_send_error(get_string('ajax_questionid_required', 'local_hlai_quizgen'));
            }

            $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
            if ($question->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            $confidence = optional_param('confidence', 0, PARAM_INT);

            $DB->set_field('local_hlai_quizgen_questions', 'status', 'approved', ['id' => $questionid]);
            $DB->set_field('local_hlai_quizgen_questions', 'timemodified', time(), ['id' => $questionid]);

            // Log approval with confidence if provided.
            if ($confidence > 0 && $confidence <= 5) {
                // Store confidence - would need a review record or new field.
                // For now, log it.
                $DB->insert_record('local_hlai_quizgen_logs', [
                    'requestid' => $question->requestid,
                    'userid' => $USER->id,
                    'action' => 'question_approved',
                    'component' => 'ajax',
                    'details' => json_encode(['questionid' => $questionid, 'confidence' => $confidence]),
                    'status' => 'success',
                    'timecreated' => time(),
                ]);
            }

            local_hlai_quizgen_send_response(true, ['status' => 'approved']);
            break;

        case 'rejectquestion':
            // Reject a question with reason.
            if (!$questionid) {
                local_hlai_quizgen_send_error(get_string('ajax_questionid_required', 'local_hlai_quizgen'));
            }

            $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
            if ($question->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            $reason = optional_param('reason', '', PARAM_TEXT);
            $feedback = optional_param('feedback', '', PARAM_TEXT);

            $DB->set_field('local_hlai_quizgen_questions', 'status', 'rejected', ['id' => $questionid]);
            $DB->set_field('local_hlai_quizgen_questions', 'timemodified', time(), ['id' => $questionid]);

            // Log rejection with reason.
            $DB->insert_record('local_hlai_quizgen_logs', [
                'requestid' => $question->requestid,
                'userid' => $USER->id,
                'action' => 'question_rejected',
                'component' => 'ajax',
                'details' => json_encode([
                    'questionid' => $questionid,
                    'reason' => $reason,
                    'feedback' => $feedback,
                ]),
                'status' => 'success',
                'timecreated' => time(),
            ]);

            local_hlai_quizgen_send_response(true, ['status' => 'rejected', 'reason' => $reason]);
            break;

        case 'bulkapprove':
            // Bulk approve multiple questions.
            // PARAM_RAW required for JSON input, validated via json_decode below.
            $questionids = required_param('questionids', PARAM_RAW);
            $questionids = json_decode($questionids, true);

            if (!is_array($questionids) || empty($questionids)) {
                local_hlai_quizgen_send_error(get_string('ajax_no_questions_specified', 'local_hlai_quizgen'));
            }

            // Bulk-fetch all questions in one query to avoid N+1 SELECT per question ID.
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $questionids));
            $questions = $DB->get_records_select(
                'local_hlai_quizgen_questions',
                "id $insql AND userid = ?",
                array_merge($inparams, [$USER->id])
            );
            $approved = 0;
            $now = time();
            foreach ($questions as $q) {
                $q->status = 'approved';
                $q->timemodified = $now;
                $DB->update_record('local_hlai_quizgen_questions', $q);
                $approved++;
            }

            local_hlai_quizgen_send_response(true, ['approved' => $approved]);
            break;

        case 'bulkreject':
            // Bulk reject multiple questions.
            // PARAM_RAW required for JSON input, validated via json_decode below.
            $questionids = required_param('questionids', PARAM_RAW);
            $questionids = json_decode($questionids, true);
            $reason = optional_param('reason', '', PARAM_TEXT);

            if (!is_array($questionids) || empty($questionids)) {
                local_hlai_quizgen_send_error(get_string('ajax_no_questions_specified', 'local_hlai_quizgen'));
            }

            // Bulk-fetch all questions in one query to avoid N+1 SELECT per question ID.
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $questionids));
            $questions = $DB->get_records_select(
                'local_hlai_quizgen_questions',
                "id $insql AND userid = ?",
                array_merge($inparams, [$USER->id])
            );
            $rejected = 0;
            $now = time();
            foreach ($questions as $q) {
                $q->status = 'rejected';
                $q->timemodified = $now;
                $DB->update_record('local_hlai_quizgen_questions', $q);
                $rejected++;
            }

            local_hlai_quizgen_send_response(true, ['rejected' => $rejected, 'reason' => $reason]);
            break;

        // TOPIC MANAGEMENT (Step 2).

        case 'updatetopic':
            // Update topic title inline.
            $topicid = required_param('topicid', PARAM_INT);

            $topic = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid], '*', MUST_EXIST);
            $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $topic->requestid], '*', MUST_EXIST);

            if ($request->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            $title = required_param('title', PARAM_TEXT);
            $DB->set_field('local_hlai_quizgen_topics', 'title', $title, ['id' => $topicid]);

            local_hlai_quizgen_send_response(true, ['title' => $title]);
            break;

        case 'mergetopics':
            // Merge two topics into one.
            $topicid1 = required_param('topicid1', PARAM_INT);
            $topicid2 = required_param('topicid2', PARAM_INT);

            $topic1 = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid1], '*', MUST_EXIST);
            $topic2 = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid2], '*', MUST_EXIST);

            // Verify same request and user owns it.
            if ($topic1->requestid != $topic2->requestid) {
                local_hlai_quizgen_send_error(get_string('ajax_topics_same_request', 'local_hlai_quizgen'));
            }

            $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $topic1->requestid]);
            if ($request->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            // Merge: combine titles, sum questions, keep first topic.
            $newtitle = $topic1->title . ' + ' . $topic2->title;
            $newquestions = $topic1->num_questions + $topic2->num_questions;

            // Merge content excerpts.
            $newcontent = trim($topic1->content_excerpt . "\n\n" . $topic2->content_excerpt);

            $DB->update_record('local_hlai_quizgen_topics', [
                'id' => $topicid1,
                'title' => $newtitle,
                'num_questions' => $newquestions,
                'content_excerpt' => $newcontent,
            ]);

            // Move any questions from topic2 to topic1.
            $DB->set_field('local_hlai_quizgen_questions', 'topicid', $topicid1, ['topicid' => $topicid2]);

            // Delete topic2.
            $DB->delete_records('local_hlai_quizgen_topics', ['id' => $topicid2]);

            local_hlai_quizgen_send_response(true, [
                'merged_into' => $topicid1,
                'deleted' => $topicid2,
                'new_title' => $newtitle,
                'new_questions' => $newquestions,
            ]);
            break;

        case 'deletetopic':
            // Delete a topic.
            $topicid = required_param('topicid', PARAM_INT);

            $topic = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid], '*', MUST_EXIST);
            $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $topic->requestid]);

            if ($request->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
            }

            // Delete associated questions first.
            $DB->delete_records('local_hlai_quizgen_questions', ['topicid' => $topicid]);
            $DB->delete_records('local_hlai_quizgen_topics', ['id' => $topicid]);

            local_hlai_quizgen_send_response(true, ['deleted' => $topicid]);
            break;

        // ANALYTICS DATA.

        case 'courseanalytics':
            // Get analytics for a specific course.
            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $context = context_course::instance($courseid);
            require_capability('local/hlai_quizgen:generatequestions', $context);

            // Questions by type in this course.
            $types = $DB->get_records_sql(
                "SELECT questiontype, COUNT(*) as count,
                        AVG(validation_score) as avg_quality,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        AVG(regeneration_count) as avg_regens
                 FROM {local_hlai_quizgen_questions}
                 WHERE courseid = ?
                 GROUP BY questiontype",
                [$courseid]
            );

            // Questions by difficulty.
            $difficulties = $DB->get_records_sql(
                "SELECT difficulty, COUNT(*) as count,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
                 FROM {local_hlai_quizgen_questions}
                 WHERE courseid = ?
                 GROUP BY difficulty",
                [$courseid]
            );

            // Recent generation trend.
            $recent = $DB->get_records_sql(
                "SELECT DATE(FROM_UNIXTIME(timecreated)) as date, COUNT(*) as count
                 FROM {local_hlai_quizgen_questions}
                 WHERE courseid = ? AND timecreated > ?
                 GROUP BY DATE(FROM_UNIXTIME(timecreated))
                 ORDER BY date",
                [$courseid, time() - (30 * 24 * 60 * 60)] // Last 30 days.
            );

            local_hlai_quizgen_send_response(true, [
                'by_type' => array_values($types),
                'by_difficulty' => array_values($difficulties),
                'trend' => array_values($recent),
            ]);
            break;

        case 'teacherconfidence':
            // Get teacher confidence trend data.
            $userid = $USER->id;

            // Get confidence ratings from logs.
            $logs = $DB->get_records_sql(
                "SELECT l.id, l.details, l.timecreated
                 FROM {local_hlai_quizgen_logs} l
                 WHERE l.userid = ? AND l.action = 'question_approved'
                 ORDER BY l.timecreated ASC
                 LIMIT 100",
                [$userid]
            );

            $confidences = [];
            foreach ($logs as $log) {
                $details = json_decode($log->details, true);
                if (isset($details['confidence'])) {
                    $confidences[] = (int)$details['confidence'];
                }
            }

            // Calculate rolling average (groups of 10).
            $averages = [];
            $chunksize = 10;
            $chunks = array_chunk($confidences, $chunksize);
            foreach ($chunks as $i => $chunk) {
                $averages[] = [
                    'group' => get_string('ajax_group_label', 'local_hlai_quizgen', ($i + 1)),
                    'avg' => round(array_sum($chunk) / count($chunk), 2),
                ];
            }

            $overallavg = count($confidences) > 0 ? round(array_sum($confidences) / count($confidences), 2) : 0;

            local_hlai_quizgen_send_response(true, [
                'overall_average' => $overallavg,
                'total_ratings' => count($confidences),
                'trend' => $averages,
            ]);
            break;

        // FILE UPLOAD (Drag and Drop).

        case 'uploadfile':
            // Handle file upload via AJAX.
            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $context = context_course::instance($courseid);
            require_capability('local/hlai_quizgen:generatequestions', $context);

            if (empty($_FILES['file'])) {
                local_hlai_quizgen_send_error(get_string('ajax_no_file_uploaded', 'local_hlai_quizgen'));
            }

            $file = $_FILES['file'];

            // Validate file.
            $allowedtypes = ['application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain'];

            $maxsize = 50 * 1024 * 1024; // 50MB.

            if ($file['size'] > $maxsize) {
                local_hlai_quizgen_send_error(get_string('ajax_file_too_large', 'local_hlai_quizgen'));
            }

            // Get file info.
            $filename = clean_param($file['name'], PARAM_FILE);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            $allowedextensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt'];
            if (!in_array($extension, $allowedextensions)) {
                local_hlai_quizgen_send_error(get_string('ajax_file_type_not_allowed', 'local_hlai_quizgen'));
            }

            // Store in Moodle file area.
            $fs = get_file_storage();
            $fileinfo = [
                'contextid' => $context->id,
                'component' => 'local_hlai_quizgen',
                'filearea' => 'content',
                'itemid' => time(),
                'filepath' => '/',
                'filename' => $filename,
            ];

            $storedfile = $fs->create_file_from_pathname($fileinfo, $file['tmp_name']);

            local_hlai_quizgen_send_response(true, [
                'filename' => $filename,
                'size' => $file['size'],
                'size_formatted' => display_size($file['size']),
                'extension' => $extension,
                'itemid' => $fileinfo['itemid'],
            ]);
            break;

        case 'removefile':
            // Remove an uploaded file.
            $itemid = required_param('itemid', PARAM_INT);

            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $context = context_course::instance($courseid);
            $fs = get_file_storage();

            $files = $fs->get_area_files($context->id, 'local_hlai_quizgen', 'content', $itemid);
            foreach ($files as $file) {
                $file->delete();
            }

            local_hlai_quizgen_send_response(true, ['removed' => $itemid]);
            break;

        // TEMPLATES AND PRESETS.

        case 'savetemplate':
            // Save current configuration as template.
            $name = required_param('name', PARAM_TEXT);
            // PARAM_RAW required for JSON input, validated via json_decode below.
            $config = required_param('config', PARAM_RAW);

            $configdata = json_decode($config, true);
            if (!$configdata) {
                local_hlai_quizgen_send_error(get_string('ajax_invalid_configuration', 'local_hlai_quizgen'));
            }

            // Save as user setting.
            $DB->insert_record('local_hlai_quizgen_settings', [
                'userid' => $USER->id,
                'courseid' => $courseid ?: null,
                'setting_name' => 'template_' . time(),
                'setting_value' => json_encode([
                    'name' => $name,
                    'config' => $configdata,
                    'created' => time(),
                ]),
                'timecreated' => time(),
                'timemodified' => time(),
            ]);

            local_hlai_quizgen_send_response(true, ['saved' => $name]);
            break;

        case 'gettemplates':
            // Get user's saved templates.
            $templates = $DB->get_records_sql(
                "SELECT id, setting_name, setting_value
                 FROM {local_hlai_quizgen_settings}
                 WHERE userid = ? AND setting_name LIKE 'template_%'
                 ORDER BY timecreated DESC",
                [$USER->id]
            );

            $items = [];
            foreach ($templates as $t) {
                $data = json_decode($t->setting_value, true);
                if ($data) {
                    $items[] = [
                        'id' => $t->id,
                        'name' => $data['name'],
                        'config' => $data['config'],
                        'created' => isset($data['created']) ? userdate($data['created']) : '',
                    ];
                }
            }

            local_hlai_quizgen_send_response(true, ['templates' => $items]);
            break;

        case 'deletetemplate':
            // Delete a template.
            $templateid = required_param('templateid', PARAM_INT);

            $template = $DB->get_record('local_hlai_quizgen_settings', ['id' => $templateid]);
            if (!$template || $template->userid != $USER->id) {
                local_hlai_quizgen_send_error(get_string('ajax_template_not_found', 'local_hlai_quizgen'), 403);
            }

            $DB->delete_records('local_hlai_quizgen_settings', ['id' => $templateid]);

            local_hlai_quizgen_send_response(true, ['deleted' => $templateid]);
            break;

        // SESSION AND STATE MANAGEMENT.

        case 'savewizardstate':
            // Save wizard state for session resumption.
            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $step = required_param('step', PARAM_INT);
            // PARAM_RAW required for JSON input, validated via json_decode below.
            $state = required_param('state', PARAM_RAW);

            $statedata = json_decode($state, true);
            if (!$statedata) {
                $statedata = [];
            }

            $existing = $DB->get_record('local_hlai_quizgen_wizard_state', [
                'userid' => $USER->id,
                'courseid' => $courseid,
            ]);

            if ($existing) {
                $DB->update_record('local_hlai_quizgen_wizard_state', [
                    'id' => $existing->id,
                    'current_step' => $step,
                    'state_data' => json_encode($statedata),
                    'request_id' => $requestid ?: null,
                    'timemodified' => time(),
                ]);
            } else {
                $DB->insert_record('local_hlai_quizgen_wizard_state', [
                    'userid' => $USER->id,
                    'courseid' => $courseid,
                    'current_step' => $step,
                    'state_data' => json_encode($statedata),
                    'request_id' => $requestid ?: null,
                    'timecreated' => time(),
                    'timemodified' => time(),
                ]);
            }

            local_hlai_quizgen_send_response(true, ['step' => $step]);
            break;

        case 'getwizardstate':
            // Get saved wizard state.
            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $state = $DB->get_record('local_hlai_quizgen_wizard_state', [
                'userid' => $USER->id,
                'courseid' => $courseid,
            ]);

            if (!$state) {
                local_hlai_quizgen_send_response(true, ['hasstate' => false]);
            } else {
                local_hlai_quizgen_send_response(true, [
                    'hasstate' => true,
                    'step' => (int)$state->current_step,
                    'state' => json_decode($state->state_data, true),
                    'requestid' => $state->request_id,
                    'lastmodified' => userdate($state->timemodified),
                ]);
            }
            break;

        case 'clearwizardstate':
            // Clear wizard state.
            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $DB->delete_records('local_hlai_quizgen_wizard_state', [
                'userid' => $USER->id,
                'courseid' => $courseid,
            ]);

            local_hlai_quizgen_send_response(true, ['cleared' => true]);
            break;

        // DIAGNOSTIC ENDPOINT.

        case 'fixcategory':
            // Force-fix the question.category field by adding it if missing.
            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $coursecontext = context_course::instance($courseid);
            require_capability('local/hlai_quizgen:generatequestions', $coursecontext);

            $result = [
                'columns' => [],
                'questions_checked' => 0,
                'questions_fixed' => 0,
                'errors' => [],
            ];

            // Get actual column info from question table.
            $columns = $DB->get_columns('question');
            foreach ($columns as $name => $col) {
                $result['columns'][$name] = $col->type ?? 'unknown';
            }

            // Check if category column exists.
            $hascategory = isset($columns['category']);
            $result['has_category_column'] = $hascategory;

            // Get our questions with their bank entry category IDs.
            $sql = "SELECT q.id as questionid, qbe.questioncategoryid, qc.contextid as cat_contextid
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                    WHERE qc.contextid = ?";

            $questions = $DB->get_records_sql($sql, [$coursecontext->id]);
            $result['questions_checked'] = count($questions);

            // Try to fix each question using direct SQL if needed.
            foreach ($questions as $q) {
                try {
                    if ($hascategory) {
                        // Column exists, use set_field.
                        $DB->set_field('question', 'category', $q->questioncategoryid, ['id' => $q->questionid]);
                    } else {
                        // Column might not exist or be detected, try raw SQL.
                        // First check if we can read the current value.
                        try {
                            $current = $DB->get_field('question', 'category', ['id' => $q->questionid]);
                            // If we got here, column exists - update it.
                            if (empty($current) || $current != $q->questioncategoryid) {
                                $DB->set_field('question', 'category', $q->questioncategoryid, ['id' => $q->questionid]);
                                $result['questions_fixed']++;
                            }
                        } catch (Exception $e) {
                            // Column truly doesn't exist, can't fix.
                            $result['errors'][] = get_string(
                                'ajax_question_column_missing',
                                'local_hlai_quizgen',
                                (object)['id' => $q->questionid, 'error' => $e->getMessage()]
                            );
                        }
                    }
                    if ($hascategory) {
                        $result['questions_fixed']++;
                    }
                } catch (Exception $e) {
                    $result['errors'][] = get_string(
                        'ajax_question_error',
                        'local_hlai_quizgen',
                        (object)['id' => $q->questionid, 'error' => $e->getMessage()]
                    );
                }
            }

            // Verify by reading back a sample.
            if (!empty($questions)) {
                $sampleid = array_key_first($questions);
                try {
                    $sample = $DB->get_record('question', ['id' => $questions[$sampleid]->questionid]);
                    $result['sample_question'] = [
                        'id' => $sample->id,
                        'category' => $sample->category ?? get_string('ajax_not_set', 'local_hlai_quizgen'),
                        'qtype' => $sample->qtype,
                    ];
                } catch (Exception $e) {
                    $result['sample_error'] = $e->getMessage();
                }
            }

            $stringparams = (object)[
                'checked' => $result['questions_checked'],
                'fixed' => $result['questions_fixed'],
            ];
            $result['message'] = get_string('ajax_questions_checked_fixed', 'local_hlai_quizgen', $stringparams);

            local_hlai_quizgen_send_response(true, $result);
            break;

        case 'checkqtypes':
            // Check if question type-specific data exists for our questions.
            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $coursecontext = context_course::instance($courseid);
            require_capability('local/hlai_quizgen:generatequestions', $coursecontext);

            // Get all questions in this course's categories.
            $sql = "SELECT q.id, q.qtype, q.name, qv.status as version_status
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                    WHERE qc.contextid = ?";

            $questions = $DB->get_records_sql($sql, [$coursecontext->id]);

            $result = [
                'total_questions' => count($questions),
                'by_type' => [],
                'missing_type_data' => [],
                'draft_status' => [],
                'repaired_status' => 0,
            ];

            // Pre-load type-specific data existence to avoid N+1 queries.
            $questionids = array_keys($questions);
            $typedatasets = [];
            if (!empty($questionids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
                // Batch-check each question type table.
                $typetables = [
                    'multichoice' => ['qtype_multichoice_options', 'questionid'],
                    'truefalse' => ['question_truefalse', 'question'],
                    'shortanswer' => ['qtype_shortanswer_options', 'questionid'],
                    'essay' => ['qtype_essay_options', 'questionid'],
                    'match' => ['qtype_match_options', 'questionid'],
                    'matching' => ['qtype_match_options', 'questionid'],
                ];
                foreach ($typetables as $qtype => $tableinfo) {
                    [$tbl, $col] = $tableinfo;
                    $sql = "SELECT $col FROM {{$tbl}} WHERE $col $insql";
                    $found = $DB->get_fieldset_sql($sql, $inparams);
                    foreach ($found as $qid) {
                        $typedatasets[$qid] = true;
                    }
                }
            }

            foreach ($questions as $q) {
                // Count by type.
                if (!isset($result['by_type'][$q->qtype])) {
                    $result['by_type'][$q->qtype] = 0;
                }
                $result['by_type'][$q->qtype]++;

                // Check if version status is not 'ready'.
                if ($q->version_status !== 'ready') {
                    $result['draft_status'][] = [
                        'id' => $q->id,
                        'name' => substr($q->name, 0, 50),
                        'status' => $q->version_status,
                    ];
                }

                // Check type-specific data from pre-loaded sets.
                $knowntype = in_array($q->qtype, [
                    'multichoice', 'truefalse', 'shortanswer',
                    'essay', 'match', 'matching',
                ]);
                $hastypedata = !$knowntype || !empty($typedatasets[$q->id]);

                if (!$hastypedata) {
                    $result['missing_type_data'][] = [
                        'id' => $q->id,
                        'name' => substr($q->name, 0, 50),
                        'qtype' => $q->qtype,
                    ];
                }
            }

            // Repair: Set all non-ready questions to ready.
            if (!empty($result['draft_status'])) {
                foreach ($result['draft_status'] as $draft) {
                    try {
                        $DB->set_field(
                            'question_versions',
                            'status',
                            'ready',
                            ['questionid' => $draft['id']]
                        );
                        $result['repaired_status']++;
                    } catch (Exception $e) {
                        // Ignore errors.
                        debugging($e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }

            $stringparams = (object)[
                'missing' => count($result['missing_type_data']),
                'notready' => count($result['draft_status']),
                'repaired' => $result['repaired_status'],
            ];
            $result['message'] = get_string('ajax_checkqtypes_result', 'local_hlai_quizgen', $stringparams);

            local_hlai_quizgen_send_response(true, $result);
            break;

        case 'repairquestions':
            // Repair questions that have NULL category field.
            // This fixes the "Invalid context id" error when viewing questions.
            if (!$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_courseid_required', 'local_hlai_quizgen'));
            }

            $coursecontext = context_course::instance($courseid);
            require_capability('local/hlai_quizgen:generatequestions', $coursecontext);

            // Check if the question table has a 'category' column.
            $questioncolumns = $DB->get_columns('question');
            $hascategorycolumn = isset($questioncolumns['category']);

            $result = [
                'has_category_column' => $hascategorycolumn,
                'found' => 0,
                'repaired' => 0,
                'errors' => [],
                'message' => '',
            ];

            if (!$hascategorycolumn) {
                // Moodle 4.x without category column - check question_bank_entries instead.
                $result['message'] = get_string('ajax_no_category_column', 'local_hlai_quizgen');

                // Verify question_bank_entries are properly linked.
                $sql = "SELECT COUNT(*) as cnt
                        FROM {question_bank_entries} qbe
                        JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                        WHERE qc.contextid = ?";
                $count = $DB->get_field_sql($sql, [$coursecontext->id]);
                $result['questions_in_bank_entries'] = (int)$count;

                // Check for orphaned questions (no bank entry).
                $sql = "SELECT COUNT(q.id) as cnt
                        FROM {question} q
                        LEFT JOIN {question_versions} qv ON qv.questionid = q.id
                        WHERE qv.id IS NULL";
                $orphaned = $DB->get_field_sql($sql);
                $result['orphaned_questions'] = (int)$orphaned;
            } else {
                // Has category column - try to repair.
                // Find all questions that have bank entries but NULL/mismatched category.
                $sql = "SELECT q.id as questionid, qbe.questioncategoryid, q.category as current_category
                        FROM {question} q
                        JOIN {question_versions} qv ON qv.questionid = q.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                        JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                        WHERE qc.contextid = ?";

                $questions = $DB->get_records_sql($sql, [$coursecontext->id]);
                $result['found'] = count($questions);

                $repaired = 0;
                $errors = [];

                foreach ($questions as $q) {
                    // Check if category needs repair.
                    if (empty($q->current_category) || $q->current_category != $q->questioncategoryid) {
                        try {
                            $DB->set_field('question', 'category', $q->questioncategoryid, ['id' => $q->questionid]);
                            $repaired++;
                        } catch (Exception $e) {
                            $errors[] = get_string(
                                'ajax_question_error',
                                'local_hlai_quizgen',
                                (object)['id' => $q->questionid, 'error' => $e->getMessage()]
                            );
                        }
                    }
                }

                $result['repaired'] = $repaired;
                $result['errors'] = $errors;
                $result['message'] = get_string(
                    'ajax_repair_result',
                    'local_hlai_quizgen',
                    (object)['found' => $result['found'], 'repaired' => $repaired]
                );
            }

            local_hlai_quizgen_send_response(true, $result);
            break;

        case 'diagnose':
            // Diagnose question deployment status.
            // Can use requestid OR courseid.
            if (!$requestid && !$courseid) {
                local_hlai_quizgen_send_error(get_string('ajax_requestid_or_courseid_required', 'local_hlai_quizgen'));
            }

            if ($requestid) {
                // Diagnose specific request.
                $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid]);
                if (!$request) {
                    local_hlai_quizgen_send_error(get_string('ajax_request_not_found', 'local_hlai_quizgen'), 404);
                }
                $coursecontext = context_course::instance($request->courseid);
                if ($request->userid != $USER->id && !has_capability('moodle/site:config', context_system::instance())) {
                    local_hlai_quizgen_send_error(get_string('ajax_access_denied', 'local_hlai_quizgen'), 403);
                }
                $diagnostic = \local_hlai_quizgen\api::diagnose_deployment($requestid);
            } else {
                // Diagnose all requests for a course.
                $coursecontext = context_course::instance($courseid);
                require_capability('local/hlai_quizgen:generatequestions', $coursecontext);

                // Get all requests for this course.
                $requests = $DB->get_records('local_hlai_quizgen_requests', ['courseid' => $courseid], 'id DESC', 'id', 0, 5);

                $diagnostic = [
                    'course_id' => $courseid,
                    'context_id' => $coursecontext->id,
                    'requests_found' => count($requests),
                    'request_diagnostics' => [],
                ];

                // Get categories in course context.
                $categories = $DB->get_records_sql(
                    "SELECT qc.id, qc.name, qc.contextid, qc.parent,
                            (SELECT COUNT(*) FROM {question_bank_entries} qbe
                             WHERE qbe.questioncategoryid = qc.id) as question_count
                     FROM {question_categories} qc
                     WHERE qc.contextid = ?
                     ORDER BY qc.id DESC",
                    [$coursecontext->id]
                );
                $diagnostic['categories_in_course'] = [];
                foreach ($categories as $cat) {
                    $diagnostic['categories_in_course'][] = [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'question_count' => (int)$cat->question_count,
                    ];
                }

                // Diagnose each request.
                foreach ($requests as $req) {
                    $diagnostic['request_diagnostics'][$req->id] = \local_hlai_quizgen\api::diagnose_deployment($req->id);
                }
            }

            local_hlai_quizgen_send_response(true, $diagnostic);
            break;

        default:
            local_hlai_quizgen_send_error(get_string('ajax_unknown_action', 'local_hlai_quizgen', $action), 400);
    }
} catch (Exception $e) {
    local_hlai_quizgen_send_error(get_string('ajax_error', 'local_hlai_quizgen', $e->getMessage()), 500);
}
