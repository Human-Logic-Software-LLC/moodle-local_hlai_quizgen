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
 * Privacy provider for the AI Quiz Generator plugin.
 *
 * Implements GDPR compliance.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider class.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Get metadata about data stored by this plugin.
     *
     * @param collection $collection Collection to add items to
     * @return collection Updated collection
     */
    public static function get_metadata(collection $collection): collection {
        // Request data.
        $collection->add_database_table(
            'hlai_quizgen_requests',
            [
                'userid' => 'privacy:metadata:hlai_quizgen_requests:userid',
                'timecreated' => 'privacy:metadata:hlai_quizgen_requests:timecreated',
            ],
            'privacy:metadata:hlai_quizgen_requests'
        );

        // User settings.
        $collection->add_database_table(
            'hlai_quizgen_settings',
            [
                'userid' => 'privacy:metadata:hlai_quizgen_settings:userid',
                'setting_value' => 'privacy:metadata:hlai_quizgen_settings:setting_value',
            ],
            'privacy:metadata:hlai_quizgen_settings'
        );

        // Logs.
        $collection->add_database_table(
            'hlai_quizgen_logs',
            [
                'userid' => 'privacy:metadata:hlai_quizgen_logs:userid',
                'action' => 'privacy:metadata:hlai_quizgen_logs:action',
                'timecreated' => 'privacy:metadata:hlai_quizgen_logs:timecreated',
            ],
            'privacy:metadata:hlai_quizgen_logs'
        );

        // External data sent to AI Hub.
        $collection->add_external_location_link(
            'aihub',
            [
                'content' => 'privacy:metadata:external:aihub:content',
            ],
            'privacy:metadata:external:aihub:purpose'
        );

        return $collection;
    }

    /**
     * Get contexts containing user data.
     *
     * @param int $userid User ID
     * @return contextlist Context list
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Get contexts where user has requests.
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :courselevel
                  JOIN {hlai_quizgen_requests} r ON r.courseid = c.id
                 WHERE r.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'courselevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get users in a context.
     *
     * @param userlist $userlist User list
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        // Get users with requests in this course.
        $sql = "SELECT r.userid
                  FROM {hlai_quizgen_requests} r
                 WHERE r.courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist Approved contexts
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            // Export requests.
            $requests = $DB->get_records('hlai_quizgen_requests', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);

            if (!empty($requests)) {
                $data = [];
                foreach ($requests as $request) {
                    $data[] = [
                        'status' => $request->status,
                        'total_questions' => $request->total_questions,
                        'questions_generated' => $request->questions_generated,
                        'processing_mode' => $request->processing_mode,
                        'timecreated' => transform::datetime($request->timecreated),
                        'timecompleted' => $request->timecompleted ? transform::datetime($request->timecompleted) : '-',
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_hlai_quizgen'), 'requests'],
                    (object)['requests' => $data]
                );
            }

            // Export settings.
            $settings = $DB->get_records('hlai_quizgen_settings', [
                'userid' => $userid,
                'courseid' => $courseid,
            ]);

            if (!empty($settings)) {
                $data = [];
                foreach ($settings as $setting) {
                    $data[$setting->setting_name] = $setting->setting_value;
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_hlai_quizgen'), 'settings'],
                    (object)$data
                );
            }
        }
    }

    /**
     * Delete user data for approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $courseid = $context->instanceid;

        // Get all requests for this course.
        $requests = $DB->get_records('hlai_quizgen_requests', ['courseid' => $courseid]);

        foreach ($requests as $request) {
            self::delete_request_data($request->id);
        }
    }

    /**
     * Delete user data.
     *
     * @param approved_contextlist $contextlist Approved contexts
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            // Delete user's requests.
            $requests = $DB->get_records('hlai_quizgen_requests', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);

            foreach ($requests as $request) {
                self::delete_request_data($request->id);
            }

            // Delete user settings.
            $DB->delete_records('hlai_quizgen_settings', [
                'userid' => $userid,
                'courseid' => $courseid,
            ]);

            // Delete user logs.
            $DB->delete_records('hlai_quizgen_logs', [
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Delete data for users in approved userlist.
     *
     * @param approved_userlist $userlist Approved users
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $courseid = $context->instanceid;
        $userids = $userlist->get_userids();

        foreach ($userids as $userid) {
            // Delete user's requests.
            $requests = $DB->get_records('hlai_quizgen_requests', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);

            foreach ($requests as $request) {
                self::delete_request_data($request->id);
            }

            // Delete user settings.
            $DB->delete_records('hlai_quizgen_settings', [
                'userid' => $userid,
                'courseid' => $courseid,
            ]);

            // Delete user logs.
            $DB->delete_records('hlai_quizgen_logs', ['userid' => $userid]);
        }
    }

    /**
     * Delete all data for a request.
     *
     * @param int $requestid Request ID
     */
    private static function delete_request_data(int $requestid): void {
        global $DB;

        // Get questions.
        $questions = $DB->get_records('hlai_quizgen_questions', ['requestid' => $requestid]);

        foreach ($questions as $question) {
            $DB->delete_records('hlai_quizgen_answers', ['questionid' => $question->id]);
        }

        $DB->delete_records('hlai_quizgen_questions', ['requestid' => $requestid]);
        $DB->delete_records('hlai_quizgen_topics', ['requestid' => $requestid]);
        $DB->delete_records('hlai_quizgen_logs', ['requestid' => $requestid]);
        $DB->delete_records('hlai_quizgen_requests', ['id' => $requestid]);
    }
}
