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
 * External services definitions for the AI Quiz Generator plugin.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // Dashboard statistics endpoints.
    'local_hlai_quizgen_get_dashboard_stats' => [
        'classname' => 'local_hlai_quizgen\external\dashboard_external',
        'methodname' => 'get_dashboard_stats',
        'description' => 'Get dashboard statistics for the current user',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_question_type_distribution' => [
        'classname' => 'local_hlai_quizgen\external\dashboard_external',
        'methodname' => 'get_question_type_distribution',
        'description' => 'Get question type distribution for charts',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_difficulty_distribution' => [
        'classname' => 'local_hlai_quizgen\external\dashboard_external',
        'methodname' => 'get_difficulty_distribution',
        'description' => 'Get difficulty level distribution',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_blooms_distribution' => [
        'classname' => 'local_hlai_quizgen\external\dashboard_external',
        'methodname' => 'get_blooms_distribution',
        'description' => 'Get Bloom\'s taxonomy level distribution',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_acceptance_trend' => [
        'classname' => 'local_hlai_quizgen\external\dashboard_external',
        'methodname' => 'get_acceptance_trend',
        'description' => 'Get acceptance rate trend over recent generations',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_regeneration_by_type' => [
        'classname' => 'local_hlai_quizgen\external\dashboard_external',
        'methodname' => 'get_regeneration_by_type',
        'description' => 'Get regeneration statistics by question type',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_quality_distribution' => [
        'classname' => 'local_hlai_quizgen\external\dashboard_external',
        'methodname' => 'get_quality_distribution',
        'description' => 'Get quality score distribution histogram data',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_recent_requests' => [
        'classname' => 'local_hlai_quizgen\external\dashboard_external',
        'methodname' => 'get_recent_requests',
        'description' => 'Get recent quiz generation requests',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],

    // Question action endpoints.
    'local_hlai_quizgen_update_question' => [
        'classname' => 'local_hlai_quizgen\external\question_external',
        'methodname' => 'update_question',
        'description' => 'Update a question field inline',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_update_answer' => [
        'classname' => 'local_hlai_quizgen\external\question_external',
        'methodname' => 'update_answer',
        'description' => 'Update an answer field inline',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_reorder_answers' => [
        'classname' => 'local_hlai_quizgen\external\question_external',
        'methodname' => 'reorder_answers',
        'description' => 'Reorder answers for a question',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_approve_question' => [
        'classname' => 'local_hlai_quizgen\external\question_external',
        'methodname' => 'approve_question',
        'description' => 'Approve a generated question',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_reject_question' => [
        'classname' => 'local_hlai_quizgen\external\question_external',
        'methodname' => 'reject_question',
        'description' => 'Reject a generated question',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_bulk_approve' => [
        'classname' => 'local_hlai_quizgen\external\question_external',
        'methodname' => 'bulk_approve',
        'description' => 'Bulk approve multiple questions',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_bulk_reject' => [
        'classname' => 'local_hlai_quizgen\external\question_external',
        'methodname' => 'bulk_reject',
        'description' => 'Bulk reject multiple questions',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],

    // Topic management endpoints.
    'local_hlai_quizgen_update_topic' => [
        'classname' => 'local_hlai_quizgen\external\topic_external',
        'methodname' => 'update_topic',
        'description' => 'Update a topic title',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_merge_topics' => [
        'classname' => 'local_hlai_quizgen\external\topic_external',
        'methodname' => 'merge_topics',
        'description' => 'Merge two topics into one',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_delete_topic' => [
        'classname' => 'local_hlai_quizgen\external\topic_external',
        'methodname' => 'delete_topic',
        'description' => 'Delete a topic and its questions',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],

    // Analytics endpoints.
    'local_hlai_quizgen_get_course_analytics' => [
        'classname' => 'local_hlai_quizgen\external\analytics_external',
        'methodname' => 'get_course_analytics',
        'description' => 'Get analytics data for a specific course',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:viewreports',
    ],
    'local_hlai_quizgen_get_teacher_confidence' => [
        'classname' => 'local_hlai_quizgen\external\analytics_external',
        'methodname' => 'get_teacher_confidence',
        'description' => 'Get teacher confidence trend data',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:viewreports',
    ],

    // Wizard state endpoints.
    'local_hlai_quizgen_get_progress' => [
        'classname' => 'local_hlai_quizgen\external\wizard_external',
        'methodname' => 'get_progress',
        'description' => 'Get generation progress for a request',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_save_wizard_state' => [
        'classname' => 'local_hlai_quizgen\external\wizard_external',
        'methodname' => 'save_wizard_state',
        'description' => 'Save wizard state for session resumption',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_wizard_state' => [
        'classname' => 'local_hlai_quizgen\external\wizard_external',
        'methodname' => 'get_wizard_state',
        'description' => 'Get saved wizard state',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_clear_wizard_state' => [
        'classname' => 'local_hlai_quizgen\external\wizard_external',
        'methodname' => 'clear_wizard_state',
        'description' => 'Clear wizard state',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],

    // File management endpoints.
    'local_hlai_quizgen_upload_file' => [
        'classname' => 'local_hlai_quizgen\external\file_external',
        'methodname' => 'upload_file',
        'description' => 'Upload a file for content extraction',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_remove_file' => [
        'classname' => 'local_hlai_quizgen\external\file_external',
        'methodname' => 'remove_file',
        'description' => 'Remove an uploaded file',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],

    // Template endpoints.
    'local_hlai_quizgen_save_template' => [
        'classname' => 'local_hlai_quizgen\external\template_external',
        'methodname' => 'save_template',
        'description' => 'Save current configuration as a template',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_get_templates' => [
        'classname' => 'local_hlai_quizgen\external\template_external',
        'methodname' => 'get_templates',
        'description' => 'Get user saved templates',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_delete_template' => [
        'classname' => 'local_hlai_quizgen\external\template_external',
        'methodname' => 'delete_template',
        'description' => 'Delete a saved template',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],

    // Diagnostic endpoints.
    'local_hlai_quizgen_fix_category' => [
        'classname' => 'local_hlai_quizgen\external\diagnostic_external',
        'methodname' => 'fix_category',
        'description' => 'Fix question category field for deployed questions',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_check_question_types' => [
        'classname' => 'local_hlai_quizgen\external\diagnostic_external',
        'methodname' => 'check_question_types',
        'description' => 'Check question type data integrity',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_repair_questions' => [
        'classname' => 'local_hlai_quizgen\external\diagnostic_external',
        'methodname' => 'repair_questions',
        'description' => 'Repair questions with missing category data',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
    'local_hlai_quizgen_diagnose' => [
        'classname' => 'local_hlai_quizgen\external\diagnostic_external',
        'methodname' => 'diagnose',
        'description' => 'Diagnose question deployment status',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/hlai_quizgen:generatequestions',
    ],
];
