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
 * Collaborative review workflow for question quality assurance.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages peer review and approval workflows for AI-generated questions.
 */
class review_workflow {
    /** @var array Review statuses */
    const STATUS_PENDING = 'pending_review';
    /** STATUS_IN_REVIEW constant. */
    const STATUS_IN_REVIEW = 'in_review';
    /** STATUS_APPROVED constant. */
    const STATUS_APPROVED = 'approved';
    /** STATUS_REJECTED constant. */
    const STATUS_REJECTED = 'rejected';
    /** STATUS_NEEDS_REVISION constant. */
    const STATUS_NEEDS_REVISION = 'needs_revision';
    /** STATUS_REVISED constant. */
    const STATUS_REVISED = 'revised';

    /** @var array Review roles */
    const ROLE_REVIEWER = 'reviewer';
    /** ROLE_APPROVER constant. */
    const ROLE_APPROVER = 'approver';
    /** ROLE_EDITOR constant. */
    const ROLE_EDITOR = 'editor';

    /**
     * Submit question for peer review.
     *
     * @param int $questionid Question ID
     * @param int $reviewerid User ID of reviewer
     * @param array $options Review options
     * @return array Submission result
     */
    public static function submit_for_review(int $questionid, int $reviewerid, array $options = []): array {
        global $DB, $USER;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);

        // Check permissions.
        if (!self::can_submit_for_review($question->requestid, $USER->id)) {
            throw new \moodle_exception('No permission to submit for review');
        }

        // Create review record.
        $review = new \stdClass();
        $review->questionid = $questionid;
        $review->reviewerid = $reviewerid;
        $review->submitterid = $USER->id;
        $review->status = self::STATUS_PENDING;
        $review->review_type = $options['review_type'] ?? 'peer';
        $review->priority = $options['priority'] ?? 'normal';
        $review->instructions = $options['instructions'] ?? '';
        $review->due_date = $options['due_date'] ?? (time() + (7 * 86400)); // 7 days default.
        $review->timecreated = time();
        $review->timemodified = time();

        $reviewid = $DB->insert_record('hlai_quizgen_reviews', $review);

        // Update question status.
        $DB->set_field('hlai_quizgen_questions', 'status', self::STATUS_PENDING, ['id' => $questionid]);

        // Send notification.
        self::send_review_notification($reviewid, 'submitted');

        // Log action.
        self::log_review_action($reviewid, 'review_submitted', $USER->id);

        return [
            'success' => true,
            'review_id' => $reviewid,
            'message' => 'Question submitted for review',
            'reviewer' => $reviewerid,
            'due_date' => $review->due_date,
        ];
    }

    /**
     * Add review comment with rating.
     *
     * @param int $reviewid Review ID
     * @param string $comment Comment text
     * @param array $ratings Category ratings
     * @return array Comment result
     */
    public static function add_review_comment(int $reviewid, string $comment, array $ratings = []): array {
        global $DB, $USER;

        $review = $DB->get_record('hlai_quizgen_reviews', ['id' => $reviewid], '*', MUST_EXIST);

        // Check permissions.
        if (!self::can_review($reviewid, $USER->id)) {
            throw new \moodle_exception('No permission to review this question');
        }

        // Create comment record.
        $reviewcomment = new \stdClass();
        $reviewcomment->reviewid = $reviewid;
        $reviewcomment->userid = $USER->id;
        $reviewcomment->comment = $comment;
        $reviewcomment->comment_type = 'review';
        $reviewcomment->is_resolved = 0;
        $reviewcomment->timecreated = time();

        $commentid = $DB->insert_record('hlai_quizgen_review_comments', $reviewcomment);

        // Store ratings if provided.
        if (!empty($ratings)) {
            $ratingrecord = new \stdClass();
            $ratingrecord->reviewid = $reviewid;
            $ratingrecord->userid = $USER->id;
            $ratingrecord->clarity = $ratings['clarity'] ?? null;
            $ratingrecord->accuracy = $ratings['accuracy'] ?? null;
            $ratingrecord->difficulty = $ratings['difficulty'] ?? null;
            $ratingrecord->pedagogical_value = $ratings['pedagogical_value'] ?? null;
            $ratingrecord->distractor_quality = $ratings['distractor_quality'] ?? null;
            $ratingrecord->overall_rating = $ratings['overall'] ?? null;
            $ratingrecord->timecreated = time();

            $DB->insert_record('hlai_quizgen_review_ratings', $ratingrecord);
        }

        // Update review status to in_review if pending.
        if ($review->status === self::STATUS_PENDING) {
            $DB->set_field('hlai_quizgen_reviews', 'status', self::STATUS_IN_REVIEW, ['id' => $reviewid]);
            $DB->set_field('hlai_quizgen_reviews', 'timestarted', time(), ['id' => $reviewid]);
        }

        // Log action.
        self::log_review_action($reviewid, 'comment_added', $USER->id);

        return [
            'success' => true,
            'comment_id' => $commentid,
            'has_ratings' => !empty($ratings),
        ];
    }

    /**
     * Approve or reject question.
     *
     * @param int $reviewid Review ID
     * @param string $decision 'approve' or 'reject'
     * @param string $comments Decision comments
     * @return array Decision result
     */
    public static function make_decision(int $reviewid, string $decision, string $comments = ''): array {
        global $DB, $USER;

        $review = $DB->get_record('hlai_quizgen_reviews', ['id' => $reviewid], '*', MUST_EXIST);

        // Check permissions.
        if (!self::can_approve($reviewid, $USER->id)) {
            throw new \moodle_exception('No permission to approve/reject this question');
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            // Update review status.
            $newstatus = $decision === 'approve' ? self::STATUS_APPROVED : self::STATUS_REJECTED;
            $DB->set_field('hlai_quizgen_reviews', 'status', $newstatus, ['id' => $reviewid]);
            $DB->set_field('hlai_quizgen_reviews', 'decision', $decision, ['id' => $reviewid]);
            $DB->set_field('hlai_quizgen_reviews', 'decision_comments', $comments, ['id' => $reviewid]);
            $DB->set_field('hlai_quizgen_reviews', 'decision_userid', $USER->id, ['id' => $reviewid]);
            $DB->set_field('hlai_quizgen_reviews', 'timecompleted', time(), ['id' => $reviewid]);

            // Update question status.
            $questionstatus = $decision === 'approve' ? 'approved' : 'rejected';
            $DB->set_field('hlai_quizgen_questions', 'status', $questionstatus, ['id' => $review->questionid]);

            // Add decision comment.
            $commentrecord = new \stdClass();
            $commentrecord->reviewid = $reviewid;
            $commentrecord->userid = $USER->id;
            $commentrecord->comment = $comments ?: 'Question ' . $decision . 'd';
            $commentrecord->comment_type = 'decision';
            $commentrecord->is_resolved = 1;
            $commentrecord->timecreated = time();
            $DB->insert_record('hlai_quizgen_review_comments', $commentrecord);

            // Log action.
            self::log_review_action($reviewid, 'decision_' . $decision, $USER->id);

            // Send notification.
            self::send_review_notification($reviewid, $decision . 'd');

            $transaction->allow_commit();

            return [
                'success' => true,
                'decision' => $decision,
                'review_id' => $reviewid,
                'question_id' => $review->questionid,
                'message' => 'Question ' . $decision . 'd successfully',
            ];
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Request revision with specific feedback.
     *
     * @param int $reviewid Review ID
     * @param array $issues Issues to address
     * @return array Revision request result
     */
    public static function request_revision(int $reviewid, array $issues): array {
        global $DB, $USER;

        $review = $DB->get_record('hlai_quizgen_reviews', ['id' => $reviewid], '*', MUST_EXIST);

        // Check permissions.
        if (!self::can_review($reviewid, $USER->id)) {
            throw new \moodle_exception('No permission to request revision');
        }

        // Update review status.
        $DB->set_field('hlai_quizgen_reviews', 'status', self::STATUS_NEEDS_REVISION, ['id' => $reviewid]);
        $DB->set_field(
            'hlai_quizgen_questions',
            'status',
            self::STATUS_NEEDS_REVISION,
            ['id' => $review->questionid]
        );

        // Store revision issues.
        foreach ($issues as $issue) {
            $issuerecord = new \stdClass();
            $issuerecord->reviewid = $reviewid;
            $issuerecord->issue_type = $issue['type'] ?? 'general';
            $issuerecord->description = $issue['description'];
            $issuerecord->severity = $issue['severity'] ?? 'medium';
            $issuerecord->suggested_fix = $issue['suggested_fix'] ?? '';
            $issuerecord->is_resolved = 0;
            $issuerecord->timecreated = time();

            $DB->insert_record('hlai_quizgen_revision_issues', $issuerecord);
        }

        // Add comment.
        $commenttext = "Revision requested. " . count($issues) . " issue(s) need to be addressed.";
        $commentrecord = new \stdClass();
        $commentrecord->reviewid = $reviewid;
        $commentrecord->userid = $USER->id;
        $commentrecord->comment = $commenttext;
        $commentrecord->comment_type = 'revision_request';
        $commentrecord->is_resolved = 0;
        $commentrecord->timecreated = time();
        $DB->insert_record('hlai_quizgen_review_comments', $commentrecord);

        // Log action.
        self::log_review_action($reviewid, 'revision_requested', $USER->id);

        // Send notification.
        self::send_review_notification($reviewid, 'revision_requested');

        return [
            'success' => true,
            'review_id' => $reviewid,
            'issues_count' => count($issues),
            'message' => 'Revision requested with ' . count($issues) . ' issue(s)',
        ];
    }

    /**
     * Submit revised question.
     *
     * @param int $reviewid Review ID
     * @param array $changes Changes made
     * @return array Submission result
     */
    public static function submit_revision(int $reviewid, array $changes): array {
        global $DB, $USER;

        $review = $DB->get_record('hlai_quizgen_reviews', ['id' => $reviewid], '*', MUST_EXIST);

        // Check permissions.
        if (!self::can_edit($review->questionid, $USER->id)) {
            throw new \moodle_exception('No permission to submit revision');
        }

        // Update status.
        $DB->set_field('hlai_quizgen_reviews', 'status', self::STATUS_REVISED, ['id' => $reviewid]);
        $DB->set_field(
            'hlai_quizgen_questions',
            'status',
            self::STATUS_REVISED,
            ['id' => $review->questionid]
        );

        // Log changes.
        $changerecord = new \stdClass();
        $changerecord->reviewid = $reviewid;
        $changerecord->userid = $USER->id;
        $changerecord->changes = json_encode($changes);
        $changerecord->revision_notes = $changes['notes'] ?? '';
        $changerecord->timecreated = time();
        $DB->insert_record('hlai_quizgen_revisions', $changerecord);

        // Mark addressed issues as resolved.
        if (!empty($changes['resolved_issues'])) {
            [$insql, $params] = $DB->get_in_or_equal($changes['resolved_issues']);
            $DB->set_field_select(
                'hlai_quizgen_revision_issues',
                'is_resolved',
                1,
                "id $insql",
                $params
            );
        }

        // Add comment.
        $commentrecord = new \stdClass();
        $commentrecord->reviewid = $reviewid;
        $commentrecord->userid = $USER->id;
        $commentrecord->comment = 'Question revised. ' . $changes['notes'];
        $commentrecord->comment_type = 'revision';
        $commentrecord->is_resolved = 0;
        $commentrecord->timecreated = time();
        $DB->insert_record('hlai_quizgen_review_comments', $commentrecord);

        // Log action.
        self::log_review_action($reviewid, 'revision_submitted', $USER->id);

        // Send notification.
        self::send_review_notification($reviewid, 'revised');

        return [
            'success' => true,
            'review_id' => $reviewid,
            'message' => 'Revision submitted successfully',
        ];
    }

    /**
     * Get review status and comments.
     *
     * @param int $reviewid Review ID
     * @return array Review details
     */
    public static function get_review_details(int $reviewid): array {
        global $DB;

        $review = $DB->get_record('hlai_quizgen_reviews', ['id' => $reviewid], '*', MUST_EXIST);
        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $review->questionid], '*', MUST_EXIST);

        // Get comments.
        $comments = $DB->get_records(
            'hlai_quizgen_review_comments',
            ['reviewid' => $reviewid],
            'timecreated ASC'
        );

        // Get ratings.
        $ratings = $DB->get_records('hlai_quizgen_review_ratings', ['reviewid' => $reviewid]);

        // Get issues if any.
        $issues = $DB->get_records(
            'hlai_quizgen_revision_issues',
            ['reviewid' => $reviewid],
            'timecreated DESC'
        );

        // Get revision history.
        $revisions = $DB->get_records(
            'hlai_quizgen_revisions',
            ['reviewid' => $reviewid],
            'timecreated DESC'
        );

        // Calculate review metrics.
        $metrics = self::calculate_review_metrics($reviewid);

        return [
            'review' => $review,
            'question' => $question,
            'comments' => array_values($comments),
            'ratings' => array_values($ratings),
            'issues' => array_values($issues),
            'revisions' => array_values($revisions),
            'metrics' => $metrics,
            'timeline' => self::build_review_timeline($reviewid),
        ];
    }

    /**
     * Get reviews pending for user.
     *
     * @param int $userid User ID
     * @return array Pending reviews
     */
    public static function get_pending_reviews(int $userid): array {
        global $DB;

        $sql = "SELECT r.*, q.questiontext, q.questiontype, q.difficulty,
                       u.firstname, u.lastname
                FROM {hlai_quizgen_reviews} r
                JOIN {hlai_quizgen_questions} q ON q.id = r.questionid
                JOIN {user} u ON u.id = r.submitterid
                WHERE r.reviewerid = ?
                AND r.status IN (?, ?)
                ORDER BY r.priority DESC, r.due_date ASC";

        $reviews = $DB->get_records_sql($sql, [
            $userid,
            self::STATUS_PENDING,
            self::STATUS_IN_REVIEW,
        ]);

        foreach ($reviews as $review) {
            $review->is_overdue = $review->due_date < time();
            $review->days_remaining = ceil(($review->due_date - time()) / 86400);
        }

        return array_values($reviews);
    }

    /**
     * Calculate review metrics.
     *
     * @param int $reviewid Review ID
     * @return array Metrics
     */
    private static function calculate_review_metrics(int $reviewid): array {
        global $DB;

        $review = $DB->get_record('hlai_quizgen_reviews', ['id' => $reviewid]);

        $metrics = [
            'total_comments' => $DB->count_records('hlai_quizgen_review_comments', ['reviewid' => $reviewid]),
            'unresolved_comments' => $DB->count_records(
                'hlai_quizgen_review_comments',
                ['reviewid' => $reviewid, 'is_resolved' => 0]
            ),
            'total_issues' => $DB->count_records('hlai_quizgen_revision_issues', ['reviewid' => $reviewid]),
            'resolved_issues' => $DB->count_records(
                'hlai_quizgen_revision_issues',
                ['reviewid' => $reviewid, 'is_resolved' => 1]
            ),
            'revision_count' => $DB->count_records('hlai_quizgen_revisions', ['reviewid' => $reviewid]),
        ];

        // Average ratings.
        $sql = "SELECT AVG(clarity) as avg_clarity,
                       AVG(accuracy) as avg_accuracy,
                       AVG(difficulty) as avg_difficulty,
                       AVG(pedagogical_value) as avg_pedagogical,
                       AVG(distractor_quality) as avg_distractor,
                       AVG(overall_rating) as avg_overall
                FROM {hlai_quizgen_review_ratings}
                WHERE reviewid = ?";

        $avgratings = $DB->get_record_sql($sql, [$reviewid]);
        if ($avgratings) {
            $metrics['average_ratings'] = [
                'clarity' => round($avgratings->avg_clarity ?? 0, 1),
                'accuracy' => round($avgratings->avg_accuracy ?? 0, 1),
                'difficulty' => round($avgratings->avg_difficulty ?? 0, 1),
                'pedagogical_value' => round($avgratings->avg_pedagogical ?? 0, 1),
                'distractor_quality' => round($avgratings->avg_distractor ?? 0, 1),
                'overall' => round($avgratings->avg_overall ?? 0, 1),
            ];
        }

        // Time metrics.
        if ($review->timestarted) {
            $duration = ($review->timecompleted ?? time()) - $review->timestarted;
            $metrics['review_duration_hours'] = round($duration / 3600, 1);
        }

        $metrics['is_overdue'] = $review->due_date < time() && !$review->timecompleted;

        return $metrics;
    }

    /**
     * Build review timeline.
     *
     * @param int $reviewid Review ID
     * @return array Timeline events
     */
    private static function build_review_timeline(int $reviewid): array {
        global $DB;

        $sql = "SELECT 'comment' as type, timecreated, userid, comment as details
                FROM {hlai_quizgen_review_comments}
                WHERE reviewid = ?
                UNION ALL
                SELECT 'revision' as type, timecreated, userid, revision_notes as details
                FROM {hlai_quizgen_revisions}
                WHERE reviewid = ?
                ORDER BY timecreated ASC";

        $events = $DB->get_records_sql($sql, [$reviewid, $reviewid]);

        return array_values($events);
    }

    /**
     * Check if user can submit for review.
     *
     * @param int $requestid Request ID
     * @param int $userid User ID
     * @return bool
     */
    private static function can_submit_for_review(int $requestid, int $userid): bool {
        global $DB;

        $request = $DB->get_record('hlai_quizgen_requests', ['id' => $requestid]);
        return $request && ($request->userid == $userid || has_capability(
            'local/hlai_quizgen:managequestions',
            \context_course::instance($request->courseid),
            $userid
        ));
    }

    /**
     * Check if user can review.
     *
     * @param int $reviewid Review ID
     * @param int $userid User ID
     * @return bool
     */
    private static function can_review(int $reviewid, int $userid): bool {
        global $DB;

        $review = $DB->get_record('hlai_quizgen_reviews', ['id' => $reviewid]);
        return $review && ($review->reviewerid == $userid ||
            has_capability(
                'local/hlai_quizgen:reviewquestions',
                \context_system::instance(),
                $userid
            ));
    }

    /**
     * Check if user can approve.
     *
     * @param int $reviewid Review ID
     * @param int $userid User ID
     * @return bool
     */
    private static function can_approve(int $reviewid, int $userid): bool {
        return has_capability(
            'local/hlai_quizgen:approvequestions',
            \context_system::instance(),
            $userid
        );
    }

    /**
     * Check if user can edit.
     *
     * @param int $questionid Question ID
     * @param int $userid User ID
     * @return bool
     */
    private static function can_edit(int $questionid, int $userid): bool {
        global $DB;

        $question = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid]);
        if (!$question) {
            return false;
        }

        $request = $DB->get_record('hlai_quizgen_requests', ['id' => $question->requestid]);
        return $request && ($request->userid == $userid ||
            has_capability(
                'local/hlai_quizgen:editquestions',
                \context_course::instance($request->courseid),
                $userid
            ));
    }

    /**
     * Send review notification.
     *
     * @param int $reviewid Review ID
     * @param string $event Event type
     */
    private static function send_review_notification(int $reviewid, string $event): void {
        // Notification implementation - using Moodle messaging API.
        // Would send emails/notifications based on event type.
    }

    /**
     * Log review action.
     *
     * @param int $reviewid Review ID
     * @param string $action Action type
     * @param int $userid User ID
     */
    private static function log_review_action(int $reviewid, string $action, int $userid): void {
        global $DB;

        $log = new \stdClass();
        $log->reviewid = $reviewid;
        $log->userid = $userid;
        $log->action = $action;
        $log->timecreated = time();

        $DB->insert_record('hlai_quizgen_review_log', $log);
    }
}
