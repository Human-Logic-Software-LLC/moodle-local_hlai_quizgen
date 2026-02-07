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
 * Database upgrade script for the Human Logic AI Quiz Generator plugin.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the local_hlai_quizgen plugin.
 *
 * @param int $oldversion The old version of the plugin
 * @return bool
 */
function xmldb_local_hlai_quizgen_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Automatically generated Moodle v4.1.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2025111300) {
        // Initial install - no upgrade needed yet.
        upgrade_plugin_savepoint(true, 2025111300, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111303) {
        $dbman = $DB->get_manager();

        // Recreate topics table with correct schema.
        $table = new xmldb_table('hlai_quizgen_topics');
        $DB->delete_records('hlai_quizgen_topics');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('parent_topic_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('level', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('selected', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');  // Default to unselected
        $table->add_field('num_questions', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '1');  // Default 1 question per topic
        $table->add_field('content_excerpt', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('learning_objectives', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('requestid', XMLDB_KEY_FOREIGN, array('requestid'), 'hlai_quizgen_requests', array('id'));
        $table->add_key('parent_topic_id', XMLDB_KEY_FOREIGN, array('parent_topic_id'), 'hlai_quizgen_topics', array('id'));
        $table->add_index('requestid_selected', XMLDB_INDEX_NOTUNIQUE, array('requestid', 'selected'));

        $dbman->create_table($table);

        // Create questions table if it doesn't exist.
        $questionstable = new xmldb_table('hlai_quizgen_questions');

        if (!$dbman->table_exists($questionstable)) {
            $questionstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $questionstable->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $questionstable->add_field('topicid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $questionstable->add_field('questiontype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $questionstable->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $questionstable->add_field('questiontextformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            $questionstable->add_field('generalfeedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $questionstable->add_field('difficulty', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
            $questionstable->add_field('blooms_level', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $questionstable->add_field('ai_reasoning', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $questionstable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $questionstable->add_field('moodle_questionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $questionstable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $questionstable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $questionstable->add_field('timedeployed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $questionstable->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $questionstable->add_key('requestid', XMLDB_KEY_FOREIGN, array('requestid'), 'hlai_quizgen_requests', array('id'));
            $questionstable->add_key('topicid', XMLDB_KEY_FOREIGN, array('topicid'), 'hlai_quizgen_topics', array('id'));
            $questionstable->add_index('requestid_status', XMLDB_INDEX_NOTUNIQUE, array('requestid', 'status'));
            $questionstable->add_index('questiontype', XMLDB_INDEX_NOTUNIQUE, array('questiontype'));

            $dbman->create_table($questionstable);
        }

        // Create answers table if it doesn't exist.
        $answerstable = new xmldb_table('hlai_quizgen_answers');

        if (!$dbman->table_exists($answerstable)) {
            $answerstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $answerstable->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $answerstable->add_field('answer', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $answerstable->add_field('answerformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            $answerstable->add_field('fraction', XMLDB_TYPE_NUMBER, '12, 7', null, XMLDB_NOTNULL, null, '0.0');
            $answerstable->add_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $answerstable->add_field('feedbackformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            $answerstable->add_field('is_correct', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $answerstable->add_field('distractor_reasoning', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $answerstable->add_field('sortorder', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');

            $answerstable->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $answerstable->add_key('questionid', XMLDB_KEY_FOREIGN, array('questionid'), 'hlai_quizgen_questions', array('id'));
            $answerstable->add_index('questionid_sortorder', XMLDB_INDEX_NOTUNIQUE, array('questionid', 'sortorder'));

            $dbman->create_table($answerstable);
        }

        upgrade_plugin_savepoint(true, 2025111303, 'local', 'hlai_quizgen');
    }

    // Ensure regeneration_count exists on questions (some sites may have missed it).
    if ($oldversion < 2025120901) {
        $table = new xmldb_table('hlai_quizgen_questions');
        $field = new xmldb_field('regeneration_count', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0', 'status');

        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025120901, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111304) {
        // Add content_hash field to hlai_quizgen_requests table for deduplication.
        $table = new xmldb_table('hlai_quizgen_requests');
        $field = new xmldb_field('content_hash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'custom_instructions');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add index on content_hash for fast duplicate lookups.
        $index = new xmldb_index('content_hash', XMLDB_INDEX_NOTUNIQUE, array('content_hash'));
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2025111304, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111305) {
        // Add blooms_distribution field to hlai_quizgen_requests table.
        $table = new xmldb_table('hlai_quizgen_requests');
        $field = new xmldb_field('blooms_distribution', XMLDB_TYPE_TEXT, null, null, null, null, null, 'difficulty_distribution');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111305, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111306) {
        // Create table for URL content extraction.
        $table = new xmldb_table('hlai_quizgen_url_content');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('url', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('word_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('requestid', XMLDB_KEY_FOREIGN, ['requestid'], 'hlai_quizgen_requests', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025111306, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111307) {
        // Add token tracking fields to requests table.
        $table = new xmldb_table('hlai_quizgen_requests');

        $field = new xmldb_field('prompt_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'processing_mode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('response_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'prompt_tokens');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('total_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'response_tokens');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }


        upgrade_plugin_savepoint(true, 2025111307, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111600) {
        // No database changes - this upgrade is for field naming fixes and code improvements.
        // All field naming corrections are handled by the corrected code itself.

        upgrade_plugin_savepoint(true, 2025111600, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111601) {
        // Add question validation fields.
        $table = new xmldb_table('hlai_quizgen_questions');

        $field = new xmldb_field('validation_score', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('quality_rating', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'validation_score');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111601, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111602) {
        // Add cache table for AI responses.
        $table = new xmldb_table('hlai_quizgen_cache');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('cachetype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cachekey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('metadata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('hits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lastaccessed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('cachetype_key', XMLDB_INDEX_UNIQUE, ['cachetype', 'cachekey']);
            $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $table->add_index('lastaccessed', XMLDB_INDEX_NOTUNIQUE, ['lastaccessed']);

            $dbman->create_table($table);
        }

        // Add rate limit violations table.
        $table = new xmldb_table('hlai_quizgen_ratelimit_log');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('limittype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('userid_time', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025111602, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111603) {

        // Define table hlai_quizgen_outcome_map.
        $table = new xmldb_table('hlai_quizgen_outcome_map');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('outcome_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('blooms_level', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('alignment_score', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0.0');
        $table->add_field('similarity_score', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0.0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'hlai_quizgen_questions', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        $table->add_index('questionid_course', XMLDB_INDEX_NOTUNIQUE, ['questionid', 'courseid']);
        $table->add_index('blooms_level', XMLDB_INDEX_NOTUNIQUE, ['blooms_level']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_calibration.
        $table = new xmldb_table('hlai_quizgen_calibration');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('moodle_questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('attempts_analyzed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('average_score', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0.0');
        $table->add_field('discrimination_index', XMLDB_TYPE_NUMBER, '5, 3', null, XMLDB_NOTNULL, null, '0.0');
        $table->add_field('actual_difficulty', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('intended_difficulty', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('quality_rating', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('recommendations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'hlai_quizgen_questions', ['id']);

        $table->add_index('questionid_time', XMLDB_INDEX_NOTUNIQUE, ['questionid', 'timecreated']);
        $table->add_index('quality_rating', XMLDB_INDEX_NOTUNIQUE, ['quality_rating']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_analytics_cache.
        $table = new xmldb_table('hlai_quizgen_analytics_cache');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('entity_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('entity_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('analytics_data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('last_updated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expires_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('entity', XMLDB_INDEX_UNIQUE, ['entity_type', 'entity_id']);
        $table->add_index('expires_at', XMLDB_INDEX_NOTUNIQUE, ['expires_at']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025111603, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111604) {

        // Define table hlai_quizgen_reviews.
        $table = new xmldb_table('hlai_quizgen_reviews');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('submitterid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('review_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'peer');
        $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'normal');
        $table->add_field('instructions', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('decision', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('decision_comments', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('decision_userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('due_date', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'hlai_quizgen_questions', ['id']);
        $table->add_key('reviewerid', XMLDB_KEY_FOREIGN, ['reviewerid'], 'user', ['id']);
        $table->add_key('submitterid', XMLDB_KEY_FOREIGN, ['submitterid'], 'user', ['id']);

        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('reviewer_status', XMLDB_INDEX_NOTUNIQUE, ['reviewerid', 'status']);
        $table->add_index('due_date', XMLDB_INDEX_NOTUNIQUE, ['due_date']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_review_comments.
        $table = new xmldb_table('hlai_quizgen_review_comments');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reviewid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('comment_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'review');
        $table->add_field('is_resolved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('reviewid', XMLDB_KEY_FOREIGN, ['reviewid'], 'hlai_quizgen_reviews', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('reviewid_time', XMLDB_INDEX_NOTUNIQUE, ['reviewid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_review_ratings.
        $table = new xmldb_table('hlai_quizgen_review_ratings');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reviewid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('clarity', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $table->add_field('accuracy', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $table->add_field('difficulty', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $table->add_field('pedagogical_value', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $table->add_field('distractor_quality', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $table->add_field('overall_rating', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_rating_review', XMLDB_KEY_FOREIGN, ['reviewid'], 'hlai_quizgen_reviews', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_revision_issues.
        $table = new xmldb_table('hlai_quizgen_revision_issues');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reviewid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('issue_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('severity', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
        $table->add_field('suggested_fix', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('is_resolved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('reviewid', XMLDB_KEY_FOREIGN, ['reviewid'], 'hlai_quizgen_reviews', ['id']);

        $table->add_index('reviewid_resolved', XMLDB_INDEX_NOTUNIQUE, ['reviewid', 'is_resolved']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_revisions.
        $table = new xmldb_table('hlai_quizgen_revisions');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reviewid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('changes', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('revision_notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('reviewid', XMLDB_KEY_FOREIGN, ['reviewid'], 'hlai_quizgen_reviews', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('reviewid_time', XMLDB_INDEX_NOTUNIQUE, ['reviewid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_review_log.
        $table = new xmldb_table('hlai_quizgen_review_log');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reviewid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('reviewid', XMLDB_KEY_FOREIGN, ['reviewid'], 'hlai_quizgen_reviews', ['id']);

        $table->add_index('reviewid_time', XMLDB_INDEX_NOTUNIQUE, ['reviewid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_refinements.
        $table = new xmldb_table('hlai_quizgen_refinements');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('refinement_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('changes', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('improvements_applied', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'hlai_quizgen_questions', ['id']);

        $table->add_index('questionid_time', XMLDB_INDEX_NOTUNIQUE, ['questionid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_refine_suggest.
        $table = new xmldb_table('hlai_quizgen_refine_suggest');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('refinement_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('suggestions', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'hlai_quizgen_questions', ['id']);

        $table->add_index('questionid_type', XMLDB_INDEX_NOTUNIQUE, ['questionid', 'refinement_type']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table hlai_quizgen_alternatives.
        $table = new xmldb_table('hlai_quizgen_alternatives');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('original_questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('answers', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('rationale', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('original_questionid', XMLDB_KEY_FOREIGN, ['original_questionid'], 'hlai_quizgen_questions', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025111604, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111605) {
        // Fix: Ensure requestid field exists in questions table.
        $table = new xmldb_table('hlai_quizgen_questions');
        $field = new xmldb_field('requestid', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'id');

        if (!$dbman->field_exists($table, $field)) {
            // Add field as nullable first.
            $dbman->add_field($table, $field);

            // Try to populate from any existing data or set to 0.
            $DB->execute("UPDATE {hlai_quizgen_questions} SET requestid = 0 WHERE requestid IS NULL");

            // Now make it NOT NULL.
            $field = new xmldb_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
            $dbman->change_field_notnull($table, $field);

            // Add foreign key.
            $key = new xmldb_key('requestid', XMLDB_KEY_FOREIGN, ['requestid'], 'hlai_quizgen_requests', ['id']);
            $dbman->add_key($table, $key);
        }

        upgrade_plugin_savepoint(true, 2025111605, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111606) {
        // Fix: Remove duplicate/legacy fields from questions table.
        // The table has both 'requestid' and 'request_id', 'topicid' and 'topic_id', etc.
        // We need to keep the non-underscore versions as per install.xml.

        $table = new xmldb_table('hlai_quizgen_questions');

        // Remove legacy 'request_id' field if it exists (we use 'requestid').
        $field = new xmldb_field('request_id');
        if ($dbman->field_exists($table, $field)) {
            // First, drop any keys/indexes using this field.
            $key = new xmldb_key('fk_request_id', XMLDB_KEY_FOREIGN, ['request_id'], 'hlai_quizgen_requests', ['id']);
            if ($dbman->find_key_name($table, $key)) {
                $dbman->drop_key($table, $key);
            }
            $index = new xmldb_index('request_id', XMLDB_INDEX_NOTUNIQUE, ['request_id']);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
            // Now drop the field.
            $dbman->drop_field($table, $field);
        }

        // Remove legacy 'topic_id' field if it exists (we use 'topicid').
        $field = new xmldb_field('topic_id');
        if ($dbman->field_exists($table, $field)) {
            $key = new xmldb_key('fk_topic_id', XMLDB_KEY_FOREIGN, ['topic_id'], 'hlai_quizgen_topics', ['id']);
            if ($dbman->find_key_name($table, $key)) {
                $dbman->drop_key($table, $key);
            }
            $index = new xmldb_index('topic_id', XMLDB_INDEX_NOTUNIQUE, ['topic_id']);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
            $dbman->drop_field($table, $field);
        }

        // Remove other legacy underscore fields if they exist.
        $legacy_fields = [
            'question_name', 'question_text', 'question_format', 'question_type',
            'general_feedback', 'default_mark', 'grading_criteria', 'ai_confidence',
            'regeneration_count', 'moodle_question_id', 'category_id', 'timereviewed'
        ];

        foreach ($legacy_fields as $fieldname) {
            $field = new xmldb_field($fieldname);
            if ($dbman->field_exists($table, $field)) {
                // Drop any keys/indexes first.
                $key = new xmldb_key('fk_' . $fieldname, XMLDB_KEY_FOREIGN, [$fieldname], 'question', ['id']);
                if ($dbman->find_key_name($table, $key)) {
                    $dbman->drop_key($table, $key);
                }
                $index = new xmldb_index($fieldname, XMLDB_INDEX_NOTUNIQUE, [$fieldname]);
                if ($dbman->index_exists($table, $index)) {
                    $dbman->drop_index($table, $index);
                }
                $dbman->drop_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2025111606, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111607) {
        // Add missing fields to questions table to match install.xml schema.
        $table = new xmldb_table('hlai_quizgen_questions');

        // Add topicid field (nullable).
        $field = new xmldb_field('topicid', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'requestid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add questiontype field (nullable first, then set default for existing rows).
        $field = new xmldb_field('questiontype', XMLDB_TYPE_CHAR, '50', null, false, null, null, 'topicid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Set default for existing rows.
            $DB->execute("UPDATE {hlai_quizgen_questions} SET questiontype = 'multichoice' WHERE questiontype IS NULL");
            // Now make it NOT NULL.
            $field = new xmldb_field('questiontype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'multichoice', 'topicid');
            $dbman->change_field_notnull($table, $field);
        }

        // Add questiontext field (nullable first).
        $field = new xmldb_field('questiontext', XMLDB_TYPE_TEXT, null, null, false, null, null, 'questiontype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Set default for existing rows.
            $DB->execute("UPDATE {hlai_quizgen_questions} SET questiontext = '' WHERE questiontext IS NULL");
            // Now make it NOT NULL.
            $field = new xmldb_field('questiontext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null, 'questiontype');
            $dbman->change_field_notnull($table, $field);
        }

        // Add questiontextformat field (nullable first).
        $field = new xmldb_field('questiontextformat', XMLDB_TYPE_INTEGER, '2', null, false, null, null, 'questiontext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Set default for existing rows.
            $DB->execute("UPDATE {hlai_quizgen_questions} SET questiontextformat = 1 WHERE questiontextformat IS NULL");
            // Now make it NOT NULL.
            $field = new xmldb_field('questiontextformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'questiontext');
            $dbman->change_field_notnull($table, $field);
        }

        // Add generalfeedback field (nullable).
        $field = new xmldb_field('generalfeedback', XMLDB_TYPE_TEXT, null, null, false, null, null, 'questiontextformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add moodle_questionid field (nullable).
        $field = new xmldb_field('moodle_questionid', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'quality_rating');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add foreign key for topicid.
        $key = new xmldb_key('topicid', XMLDB_KEY_FOREIGN, ['topicid'], 'hlai_quizgen_topics', ['id']);
        if (!$dbman->find_key_name($table, $key)) {
            $dbman->add_key($table, $key);
        }

        // Add index for questiontype.
        $index = new xmldb_index('questiontype', XMLDB_INDEX_NOTUNIQUE, ['questiontype']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2025111607, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111608) {
        // Add wizard_state table for persistent wizard sessions.
        $table = new xmldb_table('hlai_quizgen_wizard_state');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('current_step', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('state_data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('request_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('request_id', XMLDB_KEY_FOREIGN, ['request_id'], 'hlai_quizgen_requests', ['id']);

        $table->add_index('userid_courseid', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);
        $table->add_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025111608, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111609) {
        // Add progress tracking fields to requests table for real-time updates.
        $table = new xmldb_table('hlai_quizgen_requests');

        // Add progress percentage field (0-100).
        $field = new xmldb_field('progress', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0', 'questions_generated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add progress message field for status updates.
        $field = new xmldb_field('progress_message', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'progress');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111609, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111610) {
        // Add ratelimit_log table for tracking rate limiting.
        $table = new xmldb_table('hlai_quizgen_ratelimit_log');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('action', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('ip_address', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('userid_action_time', XMLDB_INDEX_NOTUNIQUE, ['userid', 'action', 'timecreated']);
            $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

            $dbman->create_table($table);
        }

        // Add courseid and userid fields to questions table if missing.
        $table = new xmldb_table('hlai_quizgen_questions');

        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timedeployed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Populate from request table.
            $DB->execute("UPDATE {hlai_quizgen_questions} q
                         SET courseid = (SELECT courseid FROM {hlai_quizgen_requests} r WHERE r.id = q.requestid)
                         WHERE courseid = 0");
        }

        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Populate from request table.
            $DB->execute("UPDATE {hlai_quizgen_questions} q
                         SET userid = (SELECT userid FROM {hlai_quizgen_requests} r WHERE r.id = q.requestid)
                         WHERE userid = 0");
        }

        // Add foreign keys.
        $key = new xmldb_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        if (!$dbman->find_key_name($table, $key)) {
            $dbman->add_key($table, $key);
        }

        $key = new xmldb_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        if (!$dbman->find_key_name($table, $key)) {
            $dbman->add_key($table, $key);
        }

        upgrade_plugin_savepoint(true, 2025111610, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111611) {
        // Fix field naming inconsistency in hlai_quizgen_answers table.
        // Old installs may have 'question_id' instead of 'questionid'.
        $table = new xmldb_table('hlai_quizgen_answers');

        // Check if the old field name exists.
        $oldfield = new xmldb_field('question_id');
        if ($dbman->field_exists($table, $oldfield)) {
            // Rename question_id to questionid.
            $oldfield->set_attributes(XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'id');
            $dbman->rename_field($table, $oldfield, 'questionid');
        }

        // Ensure questionid field has correct attributes and foreign key.
        $field = new xmldb_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add foreign key if missing.
        $key = new xmldb_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'hlai_quizgen_questions', ['id']);
        if (!$dbman->find_key_name($table, $key)) {
            $dbman->add_key($table, $key);
        }

        upgrade_plugin_savepoint(true, 2025111611, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111812) {
        // Fix progress field LENGTH attribute for number type.
        // The field was defined with LENGTH=5 which caused validation errors.
        // Number fields need sufficient precision for their range.
        $table = new xmldb_table('hlai_quizgen_requests');
        $field = new xmldb_field('progress', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'error_message');

        // Change field precision.
        $dbman->change_field_precision($table, $field);

        upgrade_plugin_savepoint(true, 2025111812, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111813) {
        // Fix all remaining NUMBER field LENGTH attributes to prevent validation errors.

        // Fix alignment_score and similarity_score in hlai_quizgen_outcome_map.
        $table = new xmldb_table('hlai_quizgen_outcome_map');

        $field = new xmldb_field('alignment_score', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.0', 'blooms_level');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        $field = new xmldb_field('similarity_score', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.0', 'alignment_score');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        // Fix average_score and discrimination_index in hlai_quizgen_calibration.
        $table = new xmldb_table('hlai_quizgen_calibration');

        $field = new xmldb_field('average_score', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.0', 'attempts_analyzed');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        $field = new xmldb_field('discrimination_index', XMLDB_TYPE_NUMBER, '10, 3', null, XMLDB_NOTNULL, null, '0.0', 'average_score');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111813, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111814) {
        // ITEM 7: Add distribution fields to hlai_quizgen_topics table.
        // These fields are critical for proper distribution handling when generating questions.
        $table = new xmldb_table('hlai_quizgen_topics');

        // Add difficulty_distribution field.
        $field = new xmldb_field('difficulty_distribution', XMLDB_TYPE_TEXT, null, null, null, null, null, 'learning_objectives');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add blooms_distribution field.
        $field = new xmldb_field('blooms_distribution', XMLDB_TYPE_TEXT, null, null, null, null, null, 'difficulty_distribution');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add question_types field.
        $field = new xmldb_field('question_types', XMLDB_TYPE_TEXT, null, null, null, null, null, 'blooms_distribution');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111814, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025111815) {
        // ITEM 8: Add plausibility_score field to hlai_quizgen_answers table.
        // This field stores distractor plausibility scores for MCQ quality improvement.
        $table = new xmldb_table('hlai_quizgen_answers');

        // Add plausibility_score field.
        $field = new xmldb_field('plausibility_score', XMLDB_TYPE_NUMBER, '3, 2', null, null, null, null, 'distractor_reasoning');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111815, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120201) {
        // Add question_history table for version tracking.
        $table = new xmldb_table('hlai_quizgen_qst_history');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('original_questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('topicid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('questiontype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('questiontextformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('generalfeedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('difficulty', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
        $table->add_field('blooms_level', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('ai_reasoning', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('validation_score', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('quality_rating', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('version_number', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('answers_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('replaced_by_questionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('requestid', XMLDB_KEY_FOREIGN, ['requestid'], 'hlai_quizgen_requests', ['id']);
        $table->add_key('topicid', XMLDB_KEY_FOREIGN, ['topicid'], 'hlai_quizgen_topics', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('original_questionid', XMLDB_INDEX_NOTUNIQUE, ['original_questionid']);
        $table->add_index('original_version', XMLDB_INDEX_NOTUNIQUE, ['original_questionid', 'version_number']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120201, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120202) {
        // CRITICAL FIX: Remove duplicate columns from hlai_quizgen_answers table.
        // Bug discovered: answer_text, answer_format, and answer_order were duplicates causing INSERT failures.
        // The table uses 'answer' and 'answerformat' fields, not these duplicates.

        $table = new xmldb_table('hlai_quizgen_answers');

        // First, make answer_text nullable to avoid constraint errors during drop.
        $field = new xmldb_field('answer_text', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
        }

        // Drop duplicate columns that were causing database errors.
        $field = new xmldb_field('answer_text');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('answer_format');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('answer_order');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025120202, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120203) {
        // Add category_name field to hlai_quizgen_requests for custom quiz naming.
        // Allows users to specify custom category names instead of auto-generated timestamps.

        $table = new xmldb_table('hlai_quizgen_requests');
        $field = new xmldb_field('category_name', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        // Guard missing previous column and avoid "AFTER" on unknown column.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025120203, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120204) {
        // Fix orphan top-level categories that were created with parent=0.
        // These cause "more than one record" errors in the question bank.
        // Proper hierarchy should have only one top-level category per context.

        // Get all contexts with multiple top-level categories.
        $sql = "SELECT contextid, COUNT(*) as count
                FROM {question_categories}
                WHERE parent = 0
                GROUP BY contextid
                HAVING COUNT(*) > 1";

        $contexts = $DB->get_records_sql($sql);

        foreach ($contexts as $context) {
            // Get all top-level categories for this context.
            $categories = $DB->get_records('question_categories',
                ['contextid' => $context->contextid, 'parent' => 0],
                'id ASC');

            // Keep the first one as the real top category, fix the rest.
            $first = true;
            $topcategoryid = null;

            foreach ($categories as $category) {
                if ($first) {
                    $topcategoryid = $category->id;
                    $first = false;
                } else {
                    // Move orphan category to be a child of the real top category.
                    $DB->set_field('question_categories', 'parent', $topcategoryid, ['id' => $category->id]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2025120204, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120300) {
        // v1.1.0 STABLE RELEASE - ALL ISSUES FIXED:
        // - Fixed regeneration button (allow_completed flag).
        // - Fixed excess question generation (strict count enforcement).
        // - CRITICAL: Fixed question bank visibility by removing transaction wrapper.
        // - Improved question naming (strip HTML, better formatting).
        // - Enhanced error handling and logging throughout.
        // - Moodle 4.1.9+ compatibility verified.
        // No database schema changes in this version.

        upgrade_plugin_savepoint(true, 2025120300, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120301) {
        // v1.1.1 - Enhanced question naming for better question bank visibility:
        // - Question names now include category name, question number, and snippet.
        // - Format: "Quiz Name: Q1 - Question snippet" (matches working plugin).
        // - Automatically strips " - AI Generated Questions" suffix for cleaner names.
        // - Questions numbered sequentially (Q1, Q2, Q3...) for easy identification.
        // - Improves searchability and organization in question bank interface.
        // No database schema changes - existing questions retain their names.

        upgrade_plugin_savepoint(true, 2025120301, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120302) {
        // v1.1.2 - Fixed duplicate top category error:
        // - Handles cases where multiple top categories exist for same context.
        // - Uses get_records with LIMIT 1 instead of get_record to avoid "more than one record" error.
        // - Falls back to question_get_top_category() only when no top category exists.
        // - Prevents "mdb->get_record() found more than one record" debugging message.
        // No database schema changes - works with existing category structure.

        upgrade_plugin_savepoint(true, 2025120302, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120303) {
        // v1.1.3 - Moodle coding standards compliance:
        // - Added proper GPL header to view_logs.php.
        // - Fixed hardcoded Mac path to use ini_get('error_log').
        // - Improved output methods to use $OUTPUT->heading().
        // - Fixed config.php path to use correct relative path.
        // - Ensures cross-platform compatibility for log viewing.
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025120303, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120800) {
        // v1.1.4 - Improved AI topic extraction:
        // - Modified AI prompt to extract EXACT topic/section names from content.
        // - AI now instructed to use existing activity names, module titles, chapter names.
        // - Prevents AI from creating generic/invented topic names.
        // - Uses actual section structure from course content (e.g., "Week 1: Introduction").
        // - Content extractor already includes activity names with === markers for clear identification.
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025120800, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120801) {
        // v1.2.0 - MAJOR IMPROVEMENT - Questions generated from FULL activity content:
        // - Question generator now fetches complete content from activities, files, URLs.
        // - No longer relies on AI-generated 500-char excerpts.
        // - Retrieves full text from all content sources for each topic.
        // - Questions based on actual activity content up to 15,000 chars (~3,750 words).
        // - Ensures questions directly relate to what's actually taught in activities.
        // - Added get_full_content_for_topic() method to fetch original content.
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025120801, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120802) {
        // v1.2.1 - TOKEN OPTIMIZATION - Content caching and size limits:
        // - Content now fetched ONCE per request and cached in memory.
        // - Prevents redundant extraction for multiple topics (saves tokens).
        // - Reduced content limit from 15,000 to 8,000 chars per batch (~2,000 words).
        // - Static cache prevents repeated database queries and file operations.
        // - Renamed get_full_content_for_topic() to get_full_content_for_request().
        // - Significantly reduces token consumption while maintaining quality.
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025120802, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120903) {
        // v1.2.3 - Production release with all optimizations:
        // - Consolidated all previous fixes and improvements.
        // - Regeneration button working with allow_completed flag.
        // - Enhanced question naming (Quiz Name: Q1, Q2, Q3...).
        // - Fixed duplicate top category error handling.
        // - Moodle coding standards compliance.
        // - Improved topic extraction from actual content.
        // - Full activity content used for question generation.
        // - Optimized content caching to reduce token usage by ~90%.
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025120903, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120904) {
        // v1.2.4 - Structural content parsing:
        // - NEW: html_to_structured_text() method preserves HTML headings (H1-H6) as markdown.
        // - Updated extract_from_page() to preserve heading structure.
        // - Updated extract_from_book() to preserve chapter titles and content headings.
        // - Updated extract_from_lesson() to preserve page titles and content headings.
        // - Updated extract_from_label() to preserve HTML structure.
        // - Updated extract_from_forum() to preserve discussion structure.
        // - Enhanced topic_analyzer prompt to identify structural elements from content.
        // - Topics now extracted from actual headings/sections WITHIN activities.
        // - Content format: "# Heading" for H1, "## Subheading" for H2, etc.
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025120904, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025120905) {
        // v1.2.5 - Topic filtering:
        // - NEW: filter_invalid_topics() method removes non-educational topics.
        // - Filters out pure numbers (1, 2, 3.5, etc.).
        // - Filters out symbols and special characters.
        // - Filters out exercise markers (Exercise 1, Practice, Quiz, Homework, etc.).
        // - Enhanced AI prompt with exclusion rules for invalid topics.
        // - Only meaningful educational topics are saved to database.
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025120905, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025121100) {
        // v1.3.0 - Fix content extraction for URL, Folder, and Resource activities:
        // - FIXED: Added extract_from_url_activity() handler for URL activities.
        // - FIXED: Added extract_from_folder_activity() handler for Folder activities.
        // - FIXED: Resource file extraction now passes original filename for proper type detection.
        // - FIXED: Resource/Folder extraction catches file type errors and continues with other activities.
        // - IMPROVED: SCORM extraction failures logged but return empty to allow other content extraction.
        // - NEW: fetch_url_content() method to extract content from external URLs.
        // - IMPROVED: Better error logging for all extraction failures (non-blocking).
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025121100, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025121101) {
        // v1.3.1 - Fix bulk scan options not working:
        // - FIXED: Added missing section placeholders for bulk scan checkboxes (scan_course, scan_resources, scan_activities).
        // - FIXED: JavaScript toggleContentSection() now handles missing sections gracefully.
        // - IMPROVED: Bulk scan options now work correctly across all Moodle installations.
        // No database schema changes.

        upgrade_plugin_savepoint(true, 2025121101, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2025121701) {
        // v1.4.1 - UI/UX Enhancement - Analytics Dashboard Support:
        // - NEW: Add rejection_reason field to questions table for tracking why questions were rejected.
        // - NEW: Add rejection_feedback field for detailed teacher feedback on rejections.
        // - NEW: Add accepted_on_first_try field (boolean) for FTAR metric tracking.
        // - NEW: Add time_approved field (timestamp) for measuring review duration.
        // - NEW: Add review_duration field for time spent reviewing a question.
        // - NEW: Add templates table for storing reusable configuration presets.
        // - IMPROVED: Enhanced analytics tracking for dashboard charts.

        $table = new xmldb_table('hlai_quizgen_questions');

        // Add rejection_reason field.
        $field = new xmldb_field('rejection_reason', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add rejection_feedback field.
        $field = new xmldb_field('rejection_feedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'rejection_reason');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add accepted_on_first_try field (1 = yes, 0 = no, null = not yet reviewed).
        $field = new xmldb_field('accepted_on_first_try', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'rejection_feedback');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Populate for existing approved questions: first try if regeneration_count = 0.
            $DB->execute("UPDATE {hlai_quizgen_questions} SET accepted_on_first_try = 1 WHERE status = 'approved' AND regeneration_count = 0");
            $DB->execute("UPDATE {hlai_quizgen_questions} SET accepted_on_first_try = 0 WHERE status = 'approved' AND regeneration_count > 0");
        }

        // Add time_approved field (timestamp when question was approved).
        $field = new xmldb_field('time_approved', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'accepted_on_first_try');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add review_duration field (seconds spent reviewing).
        $field = new xmldb_field('review_duration', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'time_approved');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add index for rejection tracking.
        $index = new xmldb_index('status_rejection', XMLDB_INDEX_NOTUNIQUE, ['status', 'rejection_reason']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add index for FTAR analysis.
        $index = new xmldb_index('accepted_first_try', XMLDB_INDEX_NOTUNIQUE, ['accepted_on_first_try', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Create templates table for storing configuration presets.
        $table = new xmldb_table('hlai_quizgen_templates');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('is_public', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('is_default', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('config_data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('usage_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

            $table->add_index('userid_public', XMLDB_INDEX_NOTUNIQUE, ['userid', 'is_public']);
            $table->add_index('courseid_public', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'is_public']);
            $table->add_index('is_default', XMLDB_INDEX_NOTUNIQUE, ['is_default']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025121701, 'local', 'hlai_quizgen');
    }

    if ($oldversion < 2026020401) {
        // v1.5.2 - Installation universality fix:
        // - FIXED: Language strings now load properly on all sites with fallbacks.
        // - FIXED: Navigation uses TYPE_SETTING instead of TYPE_CUSTOM for better compatibility.
        // - FIXED: Icon loading failures no longer break navigation display.
        // - NEW: install.php automatically purges caches after installation.
        // - IMPROVED: Error handling in navigation prevents silent failures.
        // - IMPROVED: String loading with fallbacks prevents [[pluginname]] display issues.
        // No database schema changes - this is purely a compatibility/UX fix.

        // Force cache purge to ensure language strings load.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2026020401, 'local', 'hlai_quizgen');
    }

    return true;
}
