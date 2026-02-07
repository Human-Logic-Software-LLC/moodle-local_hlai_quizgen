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
 * Process generation queue page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 STARTER
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Scheduled task to process question generation queue.
 *
 * Runs every minute to process pending generation requests.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Process generation queue task.
 */
class process_generation_queue extends \core\task\scheduled_task {
    /**
     * Get task name.
     *
     * @return string Task name
     */
    public function get_name() {
        return get_string('task:processgenerationqueue', 'local_hlai_quizgen');
    }

    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        mtrace('AI Quiz Generator: Processing generation queue...');

        // Find pending requests.
        $requests = $DB->get_records('hlai_quizgen_requests', ['status' => 'pending'], 'timecreated ASC', '*', 0, 5);

        if (empty($requests)) {
            mtrace('No pending requests found.');
            return;
        }

        mtrace('Found ' . count($requests) . ' pending request(s)');

        foreach ($requests as $request) {
            try {
                $this->process_request($request);
            } catch (\Exception $e) {
                // Log error and mark request as failed.
                mtrace('ERROR processing request ' . $request->id . ': ' . $e->getMessage());
                $this->mark_request_failed($request->id, $e->getMessage());
            }
        }

        mtrace('Queue processing complete.');
    }

    /**
     * Process a single generation request.
     *
     * @param \stdClass $request Request record
     * @throws \Exception If processing fails
     */
    private function process_request(\stdClass $request): void {
        global $DB;

        mtrace('Processing request ID: ' . $request->id);

        // Mark as processing using centralized status tracking.
        \local_hlai_quizgen\api::update_request_status($request->id, 'processing');

        // Get selected topics.
        $topics = $DB->get_records('hlai_quizgen_topics', [
            'requestid' => $request->id,
            'selected' => 1,
        ]);

        if (empty($topics)) {
            throw new \Exception('No topics selected for this request');
        }

        mtrace('  Topics to process: ' . count($topics));

        // Decode configuration with validation.
        $difficultydist = json_decode($request->difficulty_distribution, true);
        if (!is_array($difficultydist) || empty($difficultydist)) {
            // Fallback to default balanced distribution.
            $difficultydist = ['easy' => 20, 'medium' => 60, 'hard' => 20];
            mtrace('  Using default difficulty distribution');
        }

        $questiontypes = json_decode($request->question_types, true);
        if (!is_array($questiontypes) || empty($questiontypes)) {
            $questiontypes = ['multichoice', 'truefalse'];
            mtrace('  Using default question types');
        }

        // Decode Bloom's taxonomy distribution.
        $bloomsdist = json_decode($request->blooms_distribution ?? '{}', true);
        if (!is_array($bloomsdist) || empty($bloomsdist)) {
            $bloomsdist = [
                'remember' => 20,
                'understand' => 25,
                'apply' => 25,
                'analyze' => 15,
                'evaluate' => 10,
                'create' => 5,
            ];
            mtrace('  Using default Bloom\'s distribution');
        }

        $config = [
            'processing_mode' => $request->processing_mode ?? 'balanced',
            'difficulty_distribution' => $difficultydist,
            'blooms_distribution' => $bloomsdist,
            'question_types' => $questiontypes,
            'custom_instructions' => $request->custom_instructions ?? '',
        ];

        $totalgenerated = 0;
        $globalquestionindex = 0; // Track question index across ALL topics

        // Process each topic.
        foreach ($topics as $topic) {
            if ($topic->num_questions <= 0) {
                continue;
            }

            mtrace('  Processing topic: ' . $topic->title . ' (' . $topic->num_questions . ' questions)');

            // Distribute questions by difficulty.
            $questionsbydiff = $this->distribute_by_difficulty(
                $topic->num_questions,
                $config['difficulty_distribution']
            );

            foreach ($questionsbydiff as $difficulty => $count) {
                if ($count <= 0) {
                    continue;
                }

                $topicconfig = $config;
                $topicconfig['difficulty'] = $difficulty;
                $topicconfig['num_questions'] = $count;
                $topicconfig['global_question_index'] = $globalquestionindex; // Pass global index

                // Generate questions.
                $questions = \local_hlai_quizgen\question_generator::generate_for_topic(
                    $topic->id,
                    $request->id,
                    $topicconfig
                );

                // Update global index after generating questions.
                $globalquestionindex += count($questions);

                $totalgenerated += count($questions);
                mtrace('    Generated ' . count($questions) . ' ' . $difficulty . ' questions');

                // Update progress.
                $DB->set_field('hlai_quizgen_requests', 'questions_generated', $totalgenerated, ['id' => $request->id]);
            }
        }

        // Mark as completed using centralized status tracking.
        \local_hlai_quizgen\api::update_request_status($request->id, 'completed');

        mtrace('  Request completed. Total questions generated: ' . $totalgenerated);

        // Send notification to user.
        $this->send_completion_notification($request);
    }

    /**
     * Distribute questions by difficulty.
     *
     * @param int $total Total questions
     * @param array $distribution Difficulty distribution (e.g., ['easy' => 30, 'medium' => 50, 'hard' => 20])
     * @return array Questions per difficulty
     */
    private function distribute_by_difficulty(int $total, array $distribution): array {
        $result = [
            'easy' => 0,
            'medium' => 0,
            'hard' => 0,
        ];

        if (empty($distribution)) {
            // Default distribution.
            $distribution = ['easy' => 20, 'medium' => 60, 'hard' => 20];
        }

        // Calculate based on percentages.
        foreach ($distribution as $difficulty => $percentage) {
            $result[$difficulty] = round(($percentage / 100) * $total);
        }

        // Adjust for rounding errors.
        $sum = array_sum($result);
        if ($sum != $total) {
            $diff = $total - $sum;
            $result['medium'] += $diff;  // Add/subtract difference to medium.
        }

        return $result;
    }

    /**
     * Mark request as failed.
     *
     * @param int $requestid Request ID
     * @param string $error Error message
     * @return void
     */
    private function mark_request_failed(int $requestid, string $error): void {
        global $DB;

        // Use centralized status update.
        \local_hlai_quizgen\api::update_request_status($requestid, 'failed', $error);

        // Send failure notification.
        $request = $DB->get_record('hlai_quizgen_requests', ['id' => $requestid]);
        if ($request) {
            $this->send_failure_notification($request, $error);
        }
    }

    /**
     * Send completion notification to user.
     *
     * @param \stdClass $request Request record
     * @return void
     */
    private function send_completion_notification(\stdClass $request): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $request->userid]);
        $course = $DB->get_record('course', ['id' => $request->courseid]);

        if (!$user || !$course) {
            return;
        }

        $message = new \core\message\message();
        $message->component = 'local_hlai_quizgen';
        $message->name = 'generation_complete';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('notification:generation_complete_subject', 'local_hlai_quizgen');
        $message->fullmessage = get_string('notification:generation_complete_body', 'local_hlai_quizgen', [
            'coursename' => $course->fullname,
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = get_string('notification:generation_complete_subject', 'local_hlai_quizgen');
        $message->notification = 1;

        message_send($message);
    }

    /**
     * Send failure notification to user.
     *
     * @param \stdClass $request Request record
     * @param string $error Error message
     * @return void
     */
    private function send_failure_notification(\stdClass $request, string $error): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $request->userid]);
        $course = $DB->get_record('course', ['id' => $request->courseid]);

        if (!$user || !$course) {
            return;
        }

        $message = new \core\message\message();
        $message->component = 'local_hlai_quizgen';
        $message->name = 'generation_complete';  // Use same message provider.
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('notification:generation_failed_subject', 'local_hlai_quizgen');
        $message->fullmessage = get_string('notification:generation_failed_body', 'local_hlai_quizgen', [
            'coursename' => $course->fullname,
            'error' => $error,
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = get_string('notification:generation_failed_subject', 'local_hlai_quizgen');
        $message->notification = 1;

        message_send($message);
    }
}
