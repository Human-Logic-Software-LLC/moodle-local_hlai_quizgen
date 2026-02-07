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
 * Wizard state manager page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 STARTER
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Wizard state manager for persistent wizard sessions.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages wizard state persistence across sessions.
 */
class wizard_state_manager {
    /**
     * Save wizard state to database.
     *
     * @param int $userid User ID
     * @param int $courseid Course ID
     * @param int $step Current step
     * @param array $data State data
     * @param int|null $requestid Request ID (optional)
     * @return void
     */
    public static function save_state(int $userid, int $courseid, int $step, array $data, ?int $requestid = null): void {
        global $DB;

        $now = time();

        // Check if state exists for this user/course.
        $existing = $DB->get_record('hlai_quizgen_wizard_state', [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        if ($existing) {
            // Update existing state.
            $existing->current_step = $step;
            $existing->state_data = json_encode($data);
            $existing->request_id = $requestid;
            $existing->timemodified = $now;
            $DB->update_record('hlai_quizgen_wizard_state', $existing);
        } else {
            // Create new state.
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->current_step = $step;
            $record->state_data = json_encode($data);
            $record->request_id = $requestid;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $DB->insert_record('hlai_quizgen_wizard_state', $record);
        }
    }

    /**
     * Load wizard state from database.
     *
     * @param int $userid User ID
     * @param int $courseid Course ID
     * @return array|null Array with 'step', 'data', 'request_id' or null if not found
     */
    public static function load_state(int $userid, int $courseid): ?array {
        global $DB;

        $record = $DB->get_record('hlai_quizgen_wizard_state', [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        if (!$record) {
            return null;
        }

        // Check if state is stale (older than 7 days).
        if ($record->timemodified < (time() - 7 * 24 * 3600)) {
            self::clear_state($userid, $courseid);
            return null;
        }

        return [
            'step' => $record->current_step,
            'data' => json_decode($record->state_data, true) ?: [],
            'request_id' => $record->request_id,
        ];
    }

    /**
     * Clear wizard state for a user and course.
     *
     * @param int $userid User ID
     * @param int $courseid Course ID
     * @return void
     */
    public static function clear_state(int $userid, int $courseid): void {
        global $DB;
        $DB->delete_records('hlai_quizgen_wizard_state', [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);
    }

    /**
     * Clean up old state records (for scheduled task).
     *
     * @param int $days Delete states older than this many days (default: 30)
     * @return int Number of records deleted
     */
    public static function cleanup_old_states(int $days = 30): int {
        global $DB;
        $cutoff = time() - ($days * 24 * 3600);
        return $DB->delete_records_select('hlai_quizgen_wizard_state', 'timemodified < ?', [$cutoff]);
    }
}
