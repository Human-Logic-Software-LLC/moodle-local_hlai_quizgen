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
 * External API functions for topic management.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\external;

/**
 * External API class for topic management in the AI Quiz Generator.
 *
 * Provides web service functions for updating, merging, and deleting topics.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topic_external extends \external_api {
    // Update topic methods.

    /**
     * Describes the parameters for update_topic.
     *
     * @return \external_function_parameters
     */
    public static function update_topic_parameters() {
        return new \external_function_parameters([
            'topicid' => new \external_value(PARAM_INT, 'The ID of the topic to update'),
            'title' => new \external_value(PARAM_TEXT, 'The new title for the topic'),
        ]);
    }

    /**
     * Update the title of a topic.
     *
     * @param int $topicid The topic ID.
     * @param string $title The new title.
     * @return array The updated title.
     */
    public static function update_topic($topicid, $title) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::update_topic_parameters(), [
            'topicid' => $topicid,
            'title' => $title,
        ]);
        $topicid = $params['topicid'];
        $title = $params['title'];

        // Get the topic and its parent request.
        $topic = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid], '*', MUST_EXIST);
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $topic->requestid], '*', MUST_EXIST);

        // Validate context.
        $context = \context_course::instance($request->courseid);
        self::validate_context($context);

        // Validate ownership.
        if ($request->userid != $USER->id) {
            throw new \moodle_exception('ajax_access_denied', 'local_hlai_quizgen');
        }

        // Update the topic title.
        $DB->set_field('local_hlai_quizgen_topics', 'title', $title, ['id' => $topicid]);

        return ['title' => $title];
    }

    /**
     * Describes the return value for update_topic.
     *
     * @return \external_single_structure
     */
    public static function update_topic_returns() {
        return new \external_single_structure([
            'title' => new \external_value(PARAM_TEXT, 'The updated topic title'),
        ]);
    }

    // Merge topics methods.

    /**
     * Describes the parameters for merge_topics.
     *
     * @return \external_function_parameters
     */
    public static function merge_topics_parameters() {
        return new \external_function_parameters([
            'topicid1' => new \external_value(PARAM_INT, 'The ID of the first topic (merge target)'),
            'topicid2' => new \external_value(PARAM_INT, 'The ID of the second topic (to be merged and deleted)'),
        ]);
    }

    /**
     * Merge two topics into one.
     *
     * Combines titles, sums question counts, merges content excerpts,
     * moves questions from topic2 to topic1, and deletes topic2.
     *
     * @param int $topicid1 The ID of the first topic (merge target).
     * @param int $topicid2 The ID of the second topic (to be merged and deleted).
     * @return array The merge result details.
     */
    public static function merge_topics($topicid1, $topicid2) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::merge_topics_parameters(), [
            'topicid1' => $topicid1,
            'topicid2' => $topicid2,
        ]);
        $topicid1 = $params['topicid1'];
        $topicid2 = $params['topicid2'];

        // Get both topics.
        $topic1 = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid1], '*', MUST_EXIST);
        $topic2 = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid2], '*', MUST_EXIST);

        // Verify both topics belong to the same request.
        if ($topic1->requestid != $topic2->requestid) {
            throw new \moodle_exception('ajax_topics_same_request', 'local_hlai_quizgen');
        }

        // Get the parent request and validate context.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $topic1->requestid], '*', MUST_EXIST);

        $context = \context_course::instance($request->courseid);
        self::validate_context($context);

        // Validate ownership.
        if ($request->userid != $USER->id) {
            throw new \moodle_exception('ajax_access_denied', 'local_hlai_quizgen');
        }

        // Merge: combine titles, sum questions, merge content excerpts.
        $newtitle = $topic1->title . ' + ' . $topic2->title;
        $newquestions = $topic1->num_questions + $topic2->num_questions;
        $newcontent = trim($topic1->content_excerpt . "\n\n" . $topic2->content_excerpt);

        $DB->update_record('local_hlai_quizgen_topics', (object) [
            'id' => $topicid1,
            'title' => $newtitle,
            'num_questions' => $newquestions,
            'content_excerpt' => $newcontent,
        ]);

        // Move any questions from topic2 to topic1.
        $DB->set_field('local_hlai_quizgen_questions', 'topicid', $topicid1, ['topicid' => $topicid2]);

        // Delete topic2.
        $DB->delete_records('local_hlai_quizgen_topics', ['id' => $topicid2]);

        return [
            'merged_into' => $topicid1,
            'deleted' => $topicid2,
            'new_title' => $newtitle,
            'new_questions' => $newquestions,
        ];
    }

    /**
     * Describes the return value for merge_topics.
     *
     * @return \external_single_structure
     */
    public static function merge_topics_returns() {
        return new \external_single_structure([
            'merged_into' => new \external_value(PARAM_INT, 'The ID of the topic that was merged into'),
            'deleted' => new \external_value(PARAM_INT, 'The ID of the topic that was deleted'),
            'new_title' => new \external_value(PARAM_TEXT, 'The new combined title'),
            'new_questions' => new \external_value(PARAM_INT, 'The new total number of questions'),
        ]);
    }

    // Delete topic methods.

    /**
     * Describes the parameters for delete_topic.
     *
     * @return \external_function_parameters
     */
    public static function delete_topic_parameters() {
        return new \external_function_parameters([
            'topicid' => new \external_value(PARAM_INT, 'The ID of the topic to delete'),
        ]);
    }

    /**
     * Delete a topic and its associated questions.
     *
     * @param int $topicid The topic ID.
     * @return array The ID of the deleted topic.
     */
    public static function delete_topic($topicid) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::delete_topic_parameters(), [
            'topicid' => $topicid,
        ]);
        $topicid = $params['topicid'];

        // Get the topic and its parent request.
        $topic = $DB->get_record('local_hlai_quizgen_topics', ['id' => $topicid], '*', MUST_EXIST);
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $topic->requestid], '*', MUST_EXIST);

        // Validate context.
        $context = \context_course::instance($request->courseid);
        self::validate_context($context);

        // Validate ownership.
        if ($request->userid != $USER->id) {
            throw new \moodle_exception('ajax_access_denied', 'local_hlai_quizgen');
        }

        // Delete associated questions first, then the topic.
        $DB->delete_records('local_hlai_quizgen_questions', ['topicid' => $topicid]);
        $DB->delete_records('local_hlai_quizgen_topics', ['id' => $topicid]);

        return ['deleted' => $topicid];
    }

    /**
     * Describes the return value for delete_topic.
     *
     * @return \external_single_structure
     */
    public static function delete_topic_returns() {
        return new \external_single_structure([
            'deleted' => new \external_value(PARAM_INT, 'The ID of the deleted topic'),
        ]);
    }
}
