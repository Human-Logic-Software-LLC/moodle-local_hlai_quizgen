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
 * Local hlai quizgen page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 STARTER
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * English language strings for the Human Logic AI Quiz Generator plugin.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin identification.
$string['pluginname'] = 'AI Quiz Generator';
$string['pluginname_desc'] = 'Generate high-quality quiz questions from course content using AI';

// Capabilities.
$string['hlai_quizgen:generatequestions'] = 'Generate quiz questions using AI';
$string['hlai_quizgen:viewreports'] = 'View question generation history';
$string['hlai_quizgen:configure'] = 'Manage AI Quiz Generator settings';
$string['hlai_quizgen:deletequestions'] = 'Delete generation requests and questions';

// Navigation.
$string['navtitle'] = 'AI Quiz Generator';
$string['dashboard'] = 'AI Quiz Dashboard';
$string['dashboard_title'] = 'AI Quiz Generator Dashboard';
$string['dashboard_subtitle'] = 'Create AI-powered assessments from your course content';
$string['admin_dashboard'] = 'AI Quiz Generator Dashboard';
$string['admin_dashboard_title'] = 'AI Quiz Generator - Site Administration';
$string['settings'] = 'Settings';
$string['admin_dashboard_heading'] = 'AI Quiz Generator - Site-Wide Analytics';
$string['create_new_quiz'] = 'Create New Quiz';
$string['view_analytics'] = 'View Analytics';
$string['quick_actions'] = 'Quick Actions';
$string['recent_activity'] = 'Recent Activity';
$string['generate_new_questions'] = 'Generate New Questions';
$string['review_pending'] = 'Review Pending ({$a})';
$string['view_activity_logs'] = 'View Activity Logs';
$string['quizzes_created'] = 'Quizzes Created';
$string['questions_generated_heading'] = 'Questions Generated';
$string['avg_quality_score'] = 'Avg Quality Score';
$string['acceptance_rate'] = 'Acceptance Rate';
$string['in_this_course'] = '{$a} in this course';
$string['pending_review'] = '{$a} pending review';
$string['quality_good'] = 'Good';
$string['quality_needs_attention'] = 'Needs attention';
$string['ftar'] = 'FTAR: {$a}%';
$string['first_time_acceptance_rate'] = 'First-Time Acceptance Rate';
$string['questions_approved_without_regen'] = 'Questions approved without regeneration';
$string['quality_trends'] = 'Quality Trends Over Time';
$string['quality_trends_subtitle'] = 'Acceptance and first-time success rates across quiz generations';
$string['question_types'] = 'Question Types';
$string['difficulty_distribution'] = 'Difficulty Distribution';
$string['regeneration_by_type'] = 'Regeneration by Type';
$string['regeneration_by_type_subtitle'] = 'Which question types need most refinement';
$string['blooms_coverage'] = "Bloom's Taxonomy Coverage";
$string['blooms_coverage_subtitle'] = 'Cognitive level distribution across your generated questions';
$string['tips_title'] = 'Tips to improve question quality';
$string['tip_detailed_content'] = 'Provide more detailed and structured content in Step 1';
$string['tip_specific_topics'] = 'Select specific topics rather than entire courses for better focus';
$string['tip_assessment_purpose'] = 'Use the "Assessment Purpose" selector (Formative vs Summative)';
$string['tip_question_types'] = 'Multiple Choice and True/False questions have highest acceptance rates';
$string['no_recent_activity'] = 'No recent activity';
$string['start_creating'] = 'Start by creating your first quiz!';
$string['no_questions_yet'] = 'No questions generated yet for this course.';
$string['create_first_quiz'] = 'Create Your First Quiz';
$string['ftar_excellent'] = 'Excellent! AI is generating high-quality questions';
$string['ftar_good'] = 'Good - Room for improvement';
$string['ftar_fair'] = 'Fair - Consider refining content input';
$string['ftar_needs_attention'] = 'Needs attention - Try more specific topics';

// Wizard - General.
$string['wizard_title'] = 'AI Quiz Generator Wizard';
$string['wizard_subtitle'] = 'Generate quiz questions in 5 easy steps';
$string['step'] = 'Step';
$string['next'] = 'Next';
$string['previous'] = 'Previous';
$string['cancel'] = 'Cancel';
$string['finish'] = 'Finish';

// Wizard - Step 1: Content Selection.
$string['step1_title'] = 'Select Course Content';
$string['step1_description'] = 'Choose the course materials you want to analyze for quiz questions';
$string['select_content_sources'] = 'Select Content Source(s)';
$string['select_content_sources_help'] = 'Choose one or more methods to provide content. You can combine multiple sources.';
$string['source_manual'] = 'Manual Text Entry';
$string['source_manual_desc'] = 'Paste or type content directly';
$string['source_upload'] = 'Upload Files';
$string['source_upload_desc'] = 'Upload PDF, Word, PowerPoint files';
$string['source_activities'] = 'Course Activities';
$string['source_activities_desc'] = 'Use existing course content';
$string['selected_sources'] = 'Selected content sources';
$string['manual_text_entry'] = 'Manual Text Entry';
$string['manual_text_entry_help'] = 'Paste or type the content you want to generate questions from';
$string['manual_text_placeholder'] = 'Paste your content here... (e.g., lecture notes, study materials, textbook excerpts)';
$string['upload_files'] = 'Upload Files';
$string['upload_files_help'] = 'Upload PDF, DOCX, PPTX, or TXT files containing the content';
$string['choose_files'] = 'Choose files...';
$string['or_select_activities'] = '--- OR Select Existing Course Activities ---';
$string['select_activities'] = 'Select Existing Course Activities';
$string['select_activities_help'] = 'Choose from Pages, Lessons, Books, or Resources already in your course';
$string['supported_formats'] = 'Supported formats: PDF, DOC, DOCX, PPT, PPTX, TXT';
$string['max_file_size'] = 'Maximum file size: {$a} MB';
$string['no_content_selected'] = 'Please select or upload at least one content source';
$string['content_selected'] = '{$a} content source(s) selected';
$string['analyzing_content'] = 'Analyzing content...';
$string['content_analysis_complete'] = 'Content analysis complete';

// Wizard - Step 2: Topic Configuration.
$string['step2_title'] = 'Configure Topics';
$string['step2_description'] = 'Review extracted topics and select which ones to assess';
$string['topics_found'] = 'Topics found';
$string['topics_select_help'] = 'Select topics you want to generate questions for';
$string['numquestions'] = 'Number of questions';
$string['select_all_topics'] = 'Select All';
$string['deselect_all_topics'] = 'Deselect All';
$string['main_topic'] = 'Main Topic';
$string['subtopic'] = 'Subtopic';
$string['questions_per_topic'] = 'Questions per topic';
$string['no_topics_selected'] = 'Please select at least one topic';
$string['topics_selected'] = '{$a} topic(s) selected';
$string['noactivities'] = 'No activities found in this course. Please add some content first.';
$string['select_question_types'] = 'Select at least one question type';
$string['total_questions_help'] = 'Total number of questions to generate across all topics';
$string['processing_mode'] = 'Processing Mode';
$string['processing_mode_help'] = 'Choose between speed and quality';
$string['mode_fast'] = 'Fast - Quick generation';
$string['mode_balanced'] = 'Balanced - Good balance of speed and quality';
$string['mode_best'] = 'Best - Highest quality, slower';
$string['easy_only'] = 'Easy Only';
$string['balanced'] = 'Balanced (Mix of Easy, Medium, Hard)';
$string['hard_only'] = 'Hard Only';
$string['custom'] = 'Custom Distribution';

// Wizard - Step 3: Question Parameters.
$string['step3_title'] = 'Define Question Parameters';
$string['step3_description'] = 'Configure question types, difficulty, and quality settings';
$string['total_questions'] = 'Total Questions';
$string['question_types'] = 'Question Types';
$string['question_types_help'] = 'Select which types of questions to generate';
$string['multichoice'] = 'Multiple Choice';
$string['truefalse'] = 'True/False';
$string['shortanswer'] = 'Short Answer';
$string['essay'] = 'Essay';
$string['matching'] = 'Matching';
$string['difficulty_distribution'] = 'Difficulty Distribution';
$string['difficulty_distribution_help'] = 'Set the percentage of easy, medium, and hard questions';
$string['difficulty_easy'] = 'Easy (Remember/Understand)';
$string['difficulty_medium'] = 'Medium (Apply/Analyze)';
$string['difficulty_hard'] = 'Hard (Evaluate/Create)';
$string['blooms_taxonomy'] = "Bloom's Taxonomy Distribution";
$string['blooms_taxonomy_desc'] = 'Set the cognitive level distribution for generated questions';
$string['quality_mode'] = 'Quality Mode';
$string['quality_mode_help'] = 'Choose generation speed vs quality tradeoff';
$string['quality_fast'] = 'Fast - Quick generation for practice quizzes';
$string['quality_balanced'] = 'Balanced - Good quality with reasonable speed';
$string['quality_best'] = 'Best - Maximum quality for high-stakes assessments';
$string['custom_instructions'] = 'Custom Instructions (Optional)';
$string['custom_instructions_help'] = 'Add specific requirements or focus areas for question generation';
$string['generate_questions'] = 'Generate Questions';

// Wizard - Step 4: Review & Edit.
$string['step4_title'] = 'Review Generated Questions';
$string['step4_description'] = 'Review, edit, and approve questions before deployment';
$string['questions_generated'] = '{$a} questions generated';
$string['questions_generated_count'] = 'Generated {$a} questions for review';
$string['generating_questions'] = 'Generating questions...';
$string['no_questions_generated'] = 'No questions were generated. Please try again.';
$string['question_type'] = 'Type';
$string['question_difficulty'] = 'Difficulty';
$string['blooms_level'] = 'Bloom\'s Level';
$string['ai_reasoning'] = 'AI Reasoning';
$string['correct_answer'] = 'Correct Answer';
$string['distractors'] = 'Distractors';
$string['edit_question'] = 'Edit';
$string['delete_question'] = 'Delete';
$string['regenerate_question'] = 'Regenerate';
$string['approve_question'] = 'Approve';
$string['approve_all'] = 'Approve All';
$string['no_questions_approved'] = 'Please approve at least one question';
$string['questions_approved'] = '{$a} question(s) approved';
$string['generation_in_progress'] = 'Question generation in progress. This may take several minutes...';
$string['generation_complete'] = 'Question generation complete!';
$string['generation_failed'] = 'Question generation failed. Please try again.';
$string['retry'] = 'Retry';

// Wizard - Step 5: Deployment.
$string['step5_title'] = 'Deploy Questions';
$string['step5_description'] = 'Choose how to deploy your approved questions';
$string['deployment_option'] = 'Deployment Option';
$string['deployment_options'] = 'Deployment Options';
$string['questions_ready_deploy'] = '{$a} questions ready to deploy';
$string['no_questions_to_deploy'] = 'No questions available to deploy';
$string['create_new_quiz'] = 'Create New Quiz Activity';
$string['add_to_existing_quiz'] = 'Add to Existing Quiz';
$string['export_to_question_bank'] = 'Export to Question Bank Only';
$string['quiz_name'] = 'Quiz Name';
$string['quiz_name_help'] = 'Enter a name for the new quiz activity';
$string['select_existing_quiz'] = 'Select Existing Quiz';
$string['question_category'] = 'Question Category';
$string['question_category_help'] = 'Questions will be saved to this category in the question bank';
$string['create_new_category'] = 'Create New Category';
$string['category_name'] = 'Category Name';
$string['deploy_questions'] = 'Deploy Questions';
$string['deployment_successful'] = 'Questions deployed successfully!';
$string['deployment_failed'] = 'Deployment failed. Please try again.';

// Question Types - Full Names.
$string['qtype_multichoice'] = 'Multiple Choice';
$string['qtype_truefalse'] = 'True/False';
$string['qtype_shortanswer'] = 'Short Answer';
$string['qtype_essay'] = 'Essay';
$string['qtype_matching'] = 'Matching';

// Difficulty Levels.
$string['diff_easy'] = 'Easy';
$string['diff_medium'] = 'Medium';
$string['diff_hard'] = 'Hard';

// Bloom's Taxonomy Levels.
$string['bloom_remember'] = 'Remember';
$string['bloom_understand'] = 'Understand';
$string['bloom_apply'] = 'Apply';
$string['bloom_analyze'] = 'Analyze';
$string['bloom_evaluate'] = 'Evaluate';
$string['bloom_create'] = 'Create';

// Processing Status.
$string['status_pending'] = 'Pending';
$string['status_processing'] = 'Processing';
$string['status_completed'] = 'Completed';
$string['status_failed'] = 'Failed';
$string['status_approved'] = 'Approved';
$string['status_rejected'] = 'Rejected';
$string['status_deployed'] = 'Deployed';

// Settings.
$string['settings_heading'] = 'AI Quiz Generator Settings';

// AI Provider settings.
$string['aiprovider_heading'] = 'AI Provider';
$string['aiprovider_heading_desc'] = 'Configure which AI provider to use for question generation. ' .
    'Both hlai_hub (direct AI connection) and hlai_hubproxy (proxy server) are supported.';
$string['aiprovider_select'] = 'Preferred AI Provider';
$string['aiprovider_select_desc'] = 'Select which AI provider to use. "Auto" will prefer hlai_hub if available, otherwise use hlai_hubproxy.';
$string['aiprovider_auto'] = 'Auto (use first available)';
$string['aiprovider_hub'] = 'hlai_hub (Direct AI Connection)';
$string['aiprovider_proxy'] = 'hlai_hubproxy (Proxy Server)';
$string['aiprovider_ready'] = 'AI provider ready: {$a}';

$string['enable_multichoice'] = 'Enable Multiple Choice Questions';
$string['enable_multichoice_desc'] = 'Allow generation of multiple choice questions';
$string['enable_truefalse'] = 'Enable True/False Questions';
$string['enable_truefalse_desc'] = 'Allow generation of true/false questions';
$string['enable_shortanswer'] = 'Enable Short Answer Questions';
$string['enable_shortanswer_desc'] = 'Allow generation of short answer questions';
$string['enable_essay'] = 'Enable Essay Questions';
$string['enable_essay_desc'] = 'Allow generation of essay questions';
$string['enable_matching'] = 'Enable Matching Questions';
$string['enable_matching_desc'] = 'Allow generation of matching questions';
$string['max_questions_per_request'] = 'Maximum Questions Per Request';
$string['max_questions_per_request_desc'] = 'Maximum number of questions that can be generated in a single request';
$string['max_file_size_mb'] = 'Maximum File Upload Size (MB)';
$string['max_file_size_mb_desc'] = 'Maximum size for uploaded content files';
$string['default_quality_mode'] = 'Default Quality Mode';
$string['default_quality_mode_desc'] = 'Default AI processing quality mode';
$string['cleanup_days'] = 'Cleanup Old Requests (Days)';
$string['cleanup_days_desc'] = 'Delete generation requests older than this many days (0 to disable)';
$string['enable_provider_fallback'] = 'Enable AI Provider Fallback';
$string['enable_provider_fallback_desc'] = 'Automatically try alternative AI providers if the primary provider fails';
$string['enable_content_deduplication'] = 'Enable Content Deduplication';
$string['enable_content_deduplication_desc'] = 'Reuse topic analysis for identical content to save processing time and tokens';
$string['enable_question_validation'] = 'Enable Question Validation';
$string['enable_question_validation_desc'] = 'Automatically validate generated questions for quality, correctness, and pedagogical soundness';

// Question quality ratings.
$string['quality_excellent'] = 'Excellent';
$string['quality_good'] = 'Good';
$string['quality_acceptable'] = 'Acceptable';
$string['quality_poor'] = 'Poor';
$string['quality_unacceptable'] = 'Unacceptable';

// Errors.
$string['error:nopermission'] = 'You do not have permission to generate quiz questions';
$string['error:invalidcourseid'] = 'Invalid course ID';
$string['error:invalidrequestid'] = 'Invalid request ID';
$string['error:invalidquestionid'] = 'Invalid question ID';
$string['error:nocontent'] = 'No content could be extracted. Please check your files or selected activities.';
$string['error:analysisfailed'] = 'Content analysis failed';
$string['error:notopics'] = 'No topics selected';
$string['error:notopicsselected'] = 'No topics selected. Please select at least one topic to generate questions.';
$string['error:noquestions'] = 'No questions approved';
$string['error:noquestionstodeploy'] = 'No approved questions to deploy. Please approve at least one question first.';
$string['error:aihubnotavailable'] = 'AI Hub is not available. Please contact your administrator.';
$string['error:aihubnotconfigured'] = 'AI Hub is not configured. Please contact your administrator.';
$string['error:noaiprovider'] = 'No AI provider is available. Please install and configure hlai_hub or hlai_hubproxy.';
$string['error:aiprovidernotready'] = 'AI provider found but not ready. Please check configuration.';
$string['error:contentextraction'] = 'Failed to extract content from file';
$string['error:topicanalysis'] = 'Failed to analyze content for topics';
$string['error:questiongeneration'] = 'Failed to generate questions';
$string['error:deployment'] = 'Failed to deploy questions';
$string['error:deploymentfailed'] = 'Deployment failed';
$string['error:filetoobig'] = 'File "{$a->filename}" exceeds maximum size of {$a->maxsize}';
$string['error:invalidfiletype'] = 'Invalid file type. Supported: PDF, DOCX, PPTX';
$string['error:uploadfailed'] = 'File upload failed';
$string['error:fileupload'] = 'File upload error for "{$a->filename}": {$a->error}';
$string['error:noquestionsselected'] = 'No questions selected for bulk action';
$string['error:invalidstatus'] = 'Invalid status: {$a}';
$string['error:invalidstatustransition'] = 'Invalid status transition: {$a}';
$string['error:requestalreadycompleted'] = 'Request has already been completed';
$string['error:requestfailed'] = 'Request has failed and cannot be processed';
$string['error:rate_limit_exceeded'] = 'Rate limit exceeded: {$a}';
$string['error:unknown'] = 'An unknown error occurred. Please try again or contact support.';
$string['error:scormnopackage'] = 'No SCORM package file found for: {$a}';
$string['error:scormextractfailed'] = 'Failed to extract SCORM package: {$a}';
$string['error:scormzipfailed'] = 'Failed to open SCORM ZIP file: {$a}';

// Bulk actions.
$string['select_all'] = 'Select All';
$string['bulk_action'] = 'Bulk Action';
$string['choose_action'] = '-- Choose Action --';
$string['approve_selected'] = 'Approve Selected';
$string['reject_selected'] = 'Reject Selected';
$string['delete_selected'] = 'Delete Selected';
$string['apply'] = 'Apply';
$string['bulk_approved'] = 'Approved {$a} question(s)';
$string['bulk_rejected'] = 'Rejected {$a} question(s)';
$string['bulk_deleted'] = 'Deleted {$a} question(s)';

// Filters.
$string['filter_status'] = 'Filter by Status';
$string['filter_type'] = 'Filter by Type';
$string['filter_difficulty'] = 'Filter by Difficulty';
$string['all'] = 'All';
$string['approved'] = 'Approved';
$string['pending'] = 'Pending';
$string['rejected'] = 'Rejected';

// Privacy.
$string['privacy:metadata:hlai_quizgen_requests'] = 'Stores question generation requests';
$string['privacy:metadata:hlai_quizgen_requests:userid'] = 'The ID of the user who created the request';
$string['privacy:metadata:hlai_quizgen_requests:timecreated'] = 'The time when the request was created';
$string['privacy:metadata:hlai_quizgen_settings'] = 'Stores user preferences';
$string['privacy:metadata:hlai_quizgen_settings:userid'] = 'The ID of the user';
$string['privacy:metadata:hlai_quizgen_settings:setting_value'] = 'The user\'s preference value';
$string['privacy:metadata:hlai_quizgen_logs'] = 'Audit log of user actions';
$string['privacy:metadata:hlai_quizgen_logs:userid'] = 'The ID of the user who performed the action';
$string['privacy:metadata:hlai_quizgen_logs:action'] = 'The action performed';
$string['privacy:metadata:hlai_quizgen_logs:timecreated'] = 'The time when the action was performed';
$string['privacy:metadata:external:aihub'] = 'Course content is sent to AI Hub for analysis and question generation';
$string['privacy:metadata:external:aihub:content'] = 'Course materials (no student data)';
$string['privacy:metadata:external:aihub:purpose'] = 'To generate quiz questions from course content';

// Success messages.
$string['success:quizcreated'] = 'Quiz created successfully!';
$string['success:questionsdeployed'] = 'Questions deployed to question bank successfully!';

// Tasks.
$string['task:processgenerationqueue'] = 'Process question generation queue';
$string['task:cleanupoldrequest'] = 'Clean up old generation requests';

// Notifications.
$string['messageprovider:generation_complete'] = 'Question generation complete';
$string['notification:generation_complete_subject'] = 'AI Quiz Generator: Questions Ready for Review';
$string['notification:generation_complete_body'] = 'Your quiz questions have been generated and are ready for review in course: {$a->coursename}';
$string['notification:generation_failed_subject'] = 'AI Quiz Generator: Generation Failed';
$string['notification:generation_failed_body'] = 'Question generation failed in course: {$a->coursename}. Error: {$a->error}';

// Help.
$string['help:wizard'] = 'The AI Quiz Generator wizard guides you through 5 steps to create quiz questions from your course content.';
$string['help:contentselection'] = 'Select existing course activities or upload new files to analyze.';
$string['help:topicselection'] = 'Review the topics AI identified and choose which ones you want to assess.';
$string['help:questionparams'] = 'Configure how many questions of each type and difficulty to generate.';
$string['help:review'] = 'Review each generated question. You can edit, delete, or regenerate any question.';
$string['help:deployment'] = 'Deploy approved questions to a quiz or save them to the question bank.';

// Error messages.
$string['error:aihubnotavailable'] = 'AI Hub plugin is not available or not configured';
$string['error:questiongeneration'] = 'Failed to generate questions';
$string['error:noquestiontypes'] = 'No question types specified';
$string['error:invalidquestiontype'] = 'Invalid question type: {$a}';
$string['error:invaliddifficulty'] = 'Invalid difficulty level';
$string['error:topicanalysis'] = 'Failed to analyze content topics';
$string['error:contentextraction'] = 'Failed to extract content from file';
$string['error:deployment'] = 'Failed to deploy questions';
$string['error:distractorgeneration'] = 'Failed to generate distractors';
$string['error:invalidfiletype'] = 'Invalid file type. Supported types: PDF, DOCX, PPTX, TXT';
$string['error:contenttoobig'] = 'Content is too large. Maximum size: 50MB';
$string['error:maxregenerations'] = 'Maximum regeneration limit ({$a}) reached for this question';

// Regeneration.
$string['regenerations_remaining'] = 'Regenerations remaining: {$a}';
$string['max_regenerations_reached'] = 'Max regenerations reached';
$string['max_regenerations'] = 'Maximum regenerations per question';
$string['max_regenerations_desc'] = 'Maximum number of times a user can regenerate a single question (default: 5)';
$string['wizard_state_restored'] = 'Your previous wizard session has been restored';

// Progress monitoring.
$string['generating_questions'] = 'Generating Questions';
$string['generating_questions_desc'] = 'Please wait while we generate your questions. This may take a few moments.';

// Phase 6: Production hardening strings.
$string['production_heading'] = 'Production Settings';
$string['enable_caching'] = 'Enable Response Caching';
$string['enable_caching_desc'] = 'Cache AI responses to reduce API calls and costs. Recommended for production.';
$string['enable_rate_limiting'] = 'Enable Rate Limiting';
$string['enable_rate_limiting_desc'] = 'Prevent API abuse by limiting requests per user/hour. Recommended for production.';
$string['rate_limit_per_hour'] = 'Rate Limit Per Hour (User)';
$string['rate_limit_per_hour_desc'] = 'Maximum requests per user per hour. Default: 10';
$string['rate_limit_per_day'] = 'Rate Limit Per Day (User)';
$string['rate_limit_per_day_desc'] = 'Maximum requests per user per day. Default: 50';
$string['site_rate_limit_per_hour'] = 'Site Rate Limit Per Hour';
$string['site_rate_limit_per_hour_desc'] = 'Maximum requests site-wide per hour. Default: 200';
$string['health_check_token'] = 'Health Check Token';
$string['health_check_token_desc'] = 'Secret token for accessing health check endpoint without login. Used for monitoring.';
$string['admin_dashboard'] = 'AI Quiz Generator Dashboard';
$string['error:rate_limit_exceeded'] = 'Rate limit exceeded. {$a}';
$string['cache_hit'] = 'Using cached response (saves time and cost)';

// Debug Logs Page.
$string['debuglogs_title'] = 'Debug Logs';
$string['debuglogs_pagetitle'] = 'AI Quiz Generator - Debug Logs';
$string['debuglogs_aiprovider_heading'] = 'AI Provider Status';
$string['debuglogs_activeprovider'] = 'Active Provider';
$string['debuglogs_hubavailable'] = 'Hub Available';
$string['debuglogs_proxyavailable'] = 'Proxy Available';
$string['debuglogs_yes'] = 'Yes';
$string['debuglogs_no'] = 'No';
$string['debuglogs_noprovider_warning'] = 'No AI provider is configured! Questions cannot be generated. ' .
    'Please configure <code>local_hlai_hub</code> or <code>local_hlai_hubproxy</code>.';
$string['debuglogs_provider_error'] = 'Error checking AI provider: {$a}';
$string['debuglogs_tab_database'] = 'Database Logs';
$string['debuglogs_tab_file'] = 'File Logs';
$string['debuglogs_tab_requests'] = 'Recent Requests';
$string['debuglogs_tab_system'] = 'System Info';
$string['debuglogs_btn_logsysteminfo'] = 'Log System Info';
$string['debuglogs_btn_createtestlog'] = 'Create Test Log';
$string['debuglogs_btn_refresh'] = 'Refresh';
$string['debuglogs_filters_heading'] = 'Filters';
$string['debuglogs_filter_requestid'] = 'Request ID';
$string['debuglogs_filter_level'] = 'Level';
$string['debuglogs_filter_limit'] = 'Limit';
$string['debuglogs_filter_all'] = 'All';
$string['debuglogs_filter_btn'] = 'Filter';
$string['debuglogs_nologs'] = 'No log entries found.';
$string['debuglogs_table_time'] = 'Time';
$string['debuglogs_table_level'] = 'Level';
$string['debuglogs_table_request'] = 'Request';
$string['debuglogs_table_user'] = 'User';
$string['debuglogs_table_component'] = 'Component';
$string['debuglogs_table_message'] = 'Message';
$string['debuglogs_table_details'] = 'Details';
$string['debuglogs_btn_viewdetails'] = 'View';
$string['debuglogs_logfile_heading'] = 'Log File';
$string['debuglogs_btn_clearlogfile'] = 'Clear Log File';
$string['debuglogs_clearfile_confirm'] = 'Clear log file?';
$string['debuglogs_logfile_path'] = 'Log file path';
$string['debuglogs_logfile_size'] = 'File size';
$string['debuglogs_logfile_empty'] = 'Log file is empty.';
$string['debuglogs_logfile_showing'] = 'Showing last {$a} entries';
$string['debuglogs_logfile_notexist'] = 'Log file does not exist yet. It will be created when the first log entry is written.';
$string['debuglogs_logfile_notfound'] = 'Could not determine log file path.';
$string['debuglogs_requests_nofound'] = 'No requests found.';
$string['debuglogs_requests_table_id'] = 'ID';
$string['debuglogs_requests_table_course'] = 'Course';
$string['debuglogs_requests_table_user'] = 'User';
$string['debuglogs_requests_table_status'] = 'Status';
$string['debuglogs_requests_table_questions'] = 'Questions';
$string['debuglogs_requests_table_tokens'] = 'Tokens';
$string['debuglogs_requests_table_created'] = 'Created';
$string['debuglogs_requests_table_error'] = 'Error';
$string['debuglogs_requests_table_actions'] = 'Actions';
$string['debuglogs_requests_btn_viewlogs'] = 'View Logs';
$string['debuglogs_system_phpconfig'] = 'PHP Configuration';
$string['debuglogs_system_phpversion'] = 'PHP Version';
$string['debuglogs_system_memorylimit'] = 'Memory Limit';
$string['debuglogs_system_maxexectime'] = 'Max Execution Time';
$string['debuglogs_system_postmaxsize'] = 'Post Max Size';
$string['debuglogs_system_uploadmaxfilesize'] = 'Upload Max Filesize';
$string['debuglogs_system_errorlog'] = 'Error Log';
$string['debuglogs_system_notset'] = 'Not set';
$string['debuglogs_system_extensions'] = 'Required PHP Extensions';
$string['debuglogs_system_ext_loaded'] = 'Loaded';
$string['debuglogs_system_ext_missing'] = 'Missing';
$string['debuglogs_system_moodleconfig'] = 'Moodle Configuration';
$string['debuglogs_system_moodleversion'] = 'Moodle Version';
$string['debuglogs_system_moodlebuild'] = 'Moodle Build';
$string['debuglogs_system_wwwroot'] = 'WWW Root';
$string['debuglogs_system_dataroot'] = 'Data Root';
$string['debuglogs_system_debugmode'] = 'Debug Mode';
$string['debuglogs_system_pluginstats'] = 'Plugin Statistics';
$string['debuglogs_system_totalrequests'] = 'Total Requests';
$string['debuglogs_system_completedrequests'] = 'Completed Requests';
$string['debuglogs_system_failedrequests'] = 'Failed Requests';
$string['debuglogs_system_totalquestions'] = 'Total Questions Generated';
$string['debuglogs_system_totallogs'] = 'Total Log Entries';
$string['debuglogs_system_stats_error'] = 'Error fetching stats: {$a}';
$string['debuglogs_system_tools'] = 'System Tools (for PDF extraction)';
$string['debuglogs_system_tool_pdftotext'] = 'pdftotext (poppler-utils)';
$string['debuglogs_system_tool_ghostscript'] = 'Ghostscript';
$string['debuglogs_system_tool_available'] = 'Available';
$string['debuglogs_system_tool_notfound'] = 'Not Found';
$string['debuglogs_action_clearfile_success'] = 'Log file cleared successfully';
$string['debuglogs_action_logsysteminfo_success'] = 'System info logged successfully';
$string['debuglogs_action_testlog_success'] = 'Test log entries created';
