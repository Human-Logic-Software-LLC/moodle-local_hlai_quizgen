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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled task to cleanup old generation requests.
 *
 * Runs daily at 2 AM to delete old requests and associated data.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Cleanup old requests task.
 */
class cleanup_old_requests extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string Task name
     */
    public function get_name() {
        return get_string('task:cleanupoldrequest', 'local_hlai_quizgen');
    }

    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        mtrace('AI Quiz Generator: Cleaning up old requests...');

        $cleanupdays = get_config('local_hlai_quizgen', 'cleanup_days');

        if (empty($cleanupdays) || $cleanupdays <= 0) {
            mtrace('Cleanup disabled (cleanup_days = 0)');
            return;
        }

        $cutofftime = time() - ($cleanupdays * 86400);

        // Find old requests that are completed or failed.
        $sql = "SELECT id FROM {hlai_quizgen_requests}
                 WHERE (status = 'completed' OR status = 'failed')
                   AND timecompleted < :cutoff";

        $requests = $DB->get_records_sql($sql, ['cutoff' => $cutofftime]);

        if (empty($requests)) {
            mtrace('No old requests to cleanup');
            return;
        }

        mtrace('Found ' . count($requests) . ' old requests to delete');

        $deletecount = 0;
        foreach ($requests as $request) {
            try {
                $this->delete_request($request->id);
                $deletecount++;
            } catch (\Exception $e) {
                mtrace('ERROR deleting request ' . $request->id . ': ' . $e->getMessage());
            }
        }

        mtrace('Deleted ' . $deletecount . ' old requests');
    }

    /**
     * Delete a request and all associated data.
     *
     * @param int $requestid Request ID
     * @return void
     */
    private function delete_request(int $requestid): void {
        global $DB;

        // Start transaction.
        $transaction = $DB->start_delegated_transaction();

        try {
            // Get questions for this request.
            $questions = $DB->get_records('hlai_quizgen_questions', ['requestid' => $requestid]);

            // Delete answers for each question.
            foreach ($questions as $question) {
                $DB->delete_records('hlai_quizgen_answers', ['questionid' => $question->id]);
            }

            // Delete questions.
            $DB->delete_records('hlai_quizgen_questions', ['requestid' => $requestid]);

            // Delete topics.
            $DB->delete_records('hlai_quizgen_topics', ['requestid' => $requestid]);

            // Delete logs.
            $DB->delete_records('hlai_quizgen_logs', ['requestid' => $requestid]);

            // Delete request.
            $DB->delete_records('hlai_quizgen_requests', ['id' => $requestid]);

            // Commit transaction.
            $transaction->allow_commit();

        } catch (\Exception $e) {
            // Rollback on error.
            $transaction->rollback($e);
            throw $e;
        }
    }
}
