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
 * External API for wizard state management and progress tracking.
 *
 * Provides web service functions for the quiz generation wizard,
 * including progress polling, state persistence, and state cleanup.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\external;

global $CFG;
require_once("$CFG->libdir/externallib.php");

/**
 * Wizard external API class.
 *
 * Implements external functions for wizard step state management
 * and generation progress tracking.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_external extends \external_api {
    // Get progress - poll generation progress for a request.

    /**
     * Describes the parameters for get_progress.
     *
     * @return \external_function_parameters
     */
    public static function get_progress_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'requestid' => new \external_value(PARAM_INT, 'The generation request ID'),
        ]);
    }

    /**
     * Get the current generation progress for a request.
     *
     * Retrieves progress percentage, questions generated, topic breakdown,
     * recent activity log, and completion/error status.
     *
     * @param int $requestid The generation request ID.
     * @return array Progress data.
     */
    public static function get_progress(int $requestid): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_progress_parameters(), [
            'requestid' => $requestid,
        ]);
        $requestid = $params['requestid'];

        // Get the request record.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

        // Validate context using the course from the request.
        $context = \context_course::instance($request->courseid);
        self::validate_context($context);

        // Verify the user owns this request.
        if ($request->userid != $USER->id) {
            throw new \moodle_exception('ajax_access_denied', 'local_hlai_quizgen');
        }

        // Get the 5 most recent questions generated for this request.
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

        // Get topic progress (selected topics only).
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

        // Calculate the current topic being processed (first incomplete topic).
        $currenttopic = null;
        foreach ($topics as $t) {
            if ($t->generated < $t->target) {
                $currenttopic = [
                    'id' => (int)$t->id,
                    'title' => $t->title,
                    'progress' => (int)$t->generated,
                    'target' => (int)$t->target,
                ];
                break;
            }
        }

        // Build the topics array with consistent types.
        $topicsarray = [];
        foreach ($topics as $t) {
            $topicsarray[] = [
                'id' => (int)$t->id,
                'title' => $t->title,
                'target' => (int)$t->target,
                'generated' => (int)$t->generated,
            ];
        }

        return [
            'status' => $request->status,
            'progress' => round((float)$request->progress, 1),
            'message' => $request->progress_message ?? '',
            'questions_generated' => (int)$request->questions_generated,
            'total_questions' => (int)$request->total_questions,
            'is_complete' => in_array($request->status, ['completed', 'failed']),
            'error' => $request->status === 'failed' ? ($request->error_message ?? '') : '',
            // WARNING: current_topic, topics, and activities are returned as JSON-encoded strings
            // because their nested structures cannot be fully described by Moodle's external_value.
            // The caller must JSON.parse() these fields.
            'current_topic' => json_encode($currenttopic),
            'topics' => json_encode($topicsarray),
            'activities' => json_encode($activities),
        ];
    }

    /**
     * Describes the return value for get_progress.
     *
     * @return \external_single_structure
     */
    public static function get_progress_returns(): \external_single_structure {
        return new \external_single_structure([
            'status' => new \external_value(
                PARAM_TEXT,
                'Request status (pending, analyzing, topics_ready, processing, completed, failed)'
            ),
            'progress' => new \external_value(PARAM_FLOAT, 'Progress percentage 0-100'),
            'message' => new \external_value(PARAM_TEXT, 'Current progress message', VALUE_OPTIONAL),
            'questions_generated' => new \external_value(PARAM_INT, 'Number of questions generated so far'),
            'total_questions' => new \external_value(PARAM_INT, 'Total number of questions to generate'),
            'is_complete' => new \external_value(PARAM_BOOL, 'Whether generation is complete (success or failure)'),
            'error' => new \external_value(PARAM_TEXT, 'Error message if status is failed', VALUE_OPTIONAL),
            // These fields are JSON-encoded strings. The caller must JSON.parse() them.
            // PARAM_RAW is required because the JSON contains nested objects/arrays that
            // cannot be described with external_value alone.
            'current_topic' => new \external_value(PARAM_RAW, 'JSON-encoded current topic object or null'),
            'topics' => new \external_value(PARAM_RAW, 'JSON-encoded array of topic progress objects'),
            'activities' => new \external_value(PARAM_RAW, 'JSON-encoded array of recent activity objects'),
        ]);
    }

    // Save wizard state - persist wizard step state for session resumption.

    /**
     * Describes the parameters for save_wizard_state.
     *
     * @return \external_function_parameters
     */
    public static function save_wizard_state_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'The course ID'),
            'step' => new \external_value(PARAM_INT, 'The current wizard step number'),
            'requestid' => new \external_value(PARAM_INT, 'The associated request ID (0 if none)', VALUE_DEFAULT, 0),
            'state' => new \external_value(PARAM_RAW, 'JSON-encoded wizard state data'),
        ]);
    }

    /**
     * Save the wizard state for the current user and course.
     *
     * Upserts into local_hlai_quizgen_wizard_state. If a record already
     * exists for the user+course combination, it is updated; otherwise
     * a new record is inserted.
     *
     * @param int $courseid The course ID.
     * @param int $step The current wizard step number.
     * @param int $requestid The associated request ID (0 if none).
     * @param string $state JSON-encoded wizard state data.
     * @return array Contains the saved step number.
     */
    public static function save_wizard_state(int $courseid, int $step, int $requestid, string $state): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::save_wizard_state_parameters(), [
            'courseid' => $courseid,
            'step' => $step,
            'requestid' => $requestid,
            'state' => $state,
        ]);
        $courseid = $params['courseid'];
        $step = $params['step'];
        $requestid = $params['requestid'];
        $state = $params['state'];

        // Validate course context.
        $context = \context_course::instance($courseid);
        self::validate_context($context);

        // Decode and re-encode state to ensure valid JSON is stored.
        $statedata = json_decode($state, true);
        if (!$statedata) {
            $statedata = [];
        }

        // Upsert: check for existing record for this user+course.
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

        return [
            'step' => $step,
        ];
    }

    /**
     * Describes the return value for save_wizard_state.
     *
     * @return \external_single_structure
     */
    public static function save_wizard_state_returns(): \external_single_structure {
        return new \external_single_structure([
            'step' => new \external_value(PARAM_INT, 'The saved wizard step number'),
        ]);
    }

    // Get wizard state - retrieve saved wizard state for a course.

    /**
     * Describes the parameters for get_wizard_state.
     *
     * @return \external_function_parameters
     */
    public static function get_wizard_state_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'The course ID'),
        ]);
    }

    /**
     * Get the saved wizard state for the current user and course.
     *
     * Returns the persisted wizard step, state data, associated request ID,
     * and last modification time. If no state exists, returns hasstate=false.
     *
     * @param int $courseid The course ID.
     * @return array The wizard state or a flag indicating no state exists.
     */
    public static function get_wizard_state(int $courseid): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::get_wizard_state_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate course context.
        $context = \context_course::instance($courseid);
        self::validate_context($context);

        // Look up state for this user+course.
        $state = $DB->get_record('local_hlai_quizgen_wizard_state', [
            'userid' => $USER->id,
            'courseid' => $courseid,
        ]);

        if (!$state) {
            return [
                'hasstate' => false,
            ];
        }

        return [
            'hasstate' => true,
            'step' => (int)$state->current_step,
            'state' => $state->state_data,
            'requestid' => (int)$state->request_id,
            'lastmodified' => userdate($state->timemodified),
        ];
    }

    /**
     * Describes the return value for get_wizard_state.
     *
     * @return \external_single_structure
     */
    public static function get_wizard_state_returns(): \external_single_structure {
        return new \external_single_structure([
            'hasstate' => new \external_value(PARAM_BOOL, 'Whether a saved state exists'),
            'step' => new \external_value(PARAM_INT, 'The saved wizard step number', VALUE_OPTIONAL),
            'state' => new \external_value(PARAM_RAW, 'JSON-encoded wizard state data', VALUE_OPTIONAL),
            'requestid' => new \external_value(PARAM_INT, 'The associated request ID', VALUE_OPTIONAL),
            'lastmodified' => new \external_value(PARAM_TEXT, 'Human-readable last modification time', VALUE_OPTIONAL),
        ]);
    }

    // Clear wizard state - delete wizard state for a course.

    /**
     * Describes the parameters for clear_wizard_state.
     *
     * @return \external_function_parameters
     */
    public static function clear_wizard_state_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'The course ID'),
        ]);
    }

    /**
     * Clear (delete) the wizard state for the current user and course.
     *
     * Removes the persisted wizard state so the user starts fresh
     * on their next visit to the wizard.
     *
     * @param int $courseid The course ID.
     * @return array Contains a boolean cleared flag.
     */
    public static function clear_wizard_state(int $courseid): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::clear_wizard_state_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate course context.
        $context = \context_course::instance($courseid);
        self::validate_context($context);

        // Delete wizard state for this user+course.
        $DB->delete_records('local_hlai_quizgen_wizard_state', [
            'userid' => $USER->id,
            'courseid' => $courseid,
        ]);

        return [
            'cleared' => true,
        ];
    }

    /**
     * Describes the return value for clear_wizard_state.
     *
     * @return \external_single_structure
     */
    public static function clear_wizard_state_returns(): \external_single_structure {
        return new \external_single_structure([
            'cleared' => new \external_value(PARAM_BOOL, 'Whether the state was cleared'),
        ]);
    }
}
