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
 * AI Quiz Generator wizard main page.
 *
 * 5-step wizard interface for question generation.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

// Load Composer autoloader for document parsing libraries.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}

// Use wizard helper class for better code organization.
use local_hlai_quizgen\wizard_helper;
use local_hlai_quizgen\debug_logger;

$courseid = required_param('courseid', PARAM_INT);
$requestid = optional_param('requestid', 0, PARAM_INT);
$step = optional_param('step', '1', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// Verify login and context.
require_login($courseid);
$context = context_course::instance($courseid);
require_capability('local/hlai_quizgen:generatequestions', $context);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Set up page BEFORE any output (fixes debugging warning).
$PAGE->set_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'step' => $step]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('wizard_title', 'local_hlai_quizgen'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

// Add step-specific body class so we can target footer behavior per step.
$PAGE->add_body_class('hlai-step-' . (is_numeric($step) ? (int)$step : $step));

// Pre-flight checks - validate dependencies before starting wizard.
$dependencyerrors = check_plugin_dependencies();
if (!empty($dependencyerrors)) {
    // Check if AI provider is critically missing.
    $criticalerror = !\local_hlai_quizgen\gateway_client::is_ready();

    echo $OUTPUT->header();

    if ($criticalerror) {
        echo $OUTPUT->notification(
            get_string('error:noaiprovider', 'local_hlai_quizgen'),
            \core\output\notification::NOTIFY_ERROR
        );
    } else {
        // AI provider exists but may have issues - show as warning.
        foreach ($dependencyerrors as $error) {
            echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_WARNING);
        }
    }

    echo html_writer::div(
        html_writer::link(
            new moodle_url('/course/view.php', ['id' => $courseid]),
            '<i class="fa fa-arrow-left" style="color: #64748B;"></i> ' . get_string('back'),
            ['class' => 'button is-primary mt-3']
        ),
        'mt-3'
    );
    echo $OUTPUT->footer();
    die();
}

// Wizard state persistence disabled - users must complete wizard in one session.
// (State restoration feature removed for simpler user experience).

// Handle form submissions.
if ($action === 'upload_content' && confirm_sesskey()) {
    handle_content_upload($courseid, $context);
}

if ($action === 'create_request' && confirm_sesskey()) {
    handle_create_request($courseid);
}

if ($action === 'save_topic_selection' && confirm_sesskey()) {
    handle_save_topic_selection($requestid);
}

if ($action === 'save_question_distribution' && confirm_sesskey()) {
    handle_save_question_distribution($requestid);
}

if ($action === 'generate_questions' && confirm_sesskey()) {
    handle_generate_questions($requestid);
}

if ($action === 'deploy_questions' && confirm_sesskey()) {
    handle_deploy_questions($requestid, $courseid);
}

// Handle individual question actions (Step 4).
if ($action === 'approve_question' && confirm_sesskey()) {
    $questionid = required_param('questionid', PARAM_INT);
    $DB->set_field('local_hlai_quizgen_questions', 'status', 'approved', ['id' => $questionid]);
    redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'step' => 4,
    ]));
}

if ($action === 'reject_question' && confirm_sesskey()) {
    $questionid = required_param('questionid', PARAM_INT);
    $DB->set_field('local_hlai_quizgen_questions', 'status', 'rejected', ['id' => $questionid]);
    redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'step' => 4,
    ]));
}

if ($action === 'regenerate_question' && confirm_sesskey()) {
    $questionid = required_param('questionid', PARAM_INT);

    try {
        // Call API regenerate method which enforces limits.
        $newquestion = \local_hlai_quizgen\api::regenerate_question($questionid);

        // FIX: If requestid wasn't in URL, get it from the question record.
        if (empty($requestid) || $requestid == 0) {
            $requestid = $newquestion->requestid;
        }

        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'step' => 4,
            ]),
            'Question regenerated successfully',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Exception $e) {
        // FIX: Get requestid from question if not available.
        if (empty($requestid) || $requestid == 0) {
            $question = $DB->get_record('local_hlai_quizgen_questions', ['id' => $questionid], 'requestid');
            if ($question) {
                $requestid = $question->requestid;
            }
        }

        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'step' => 4,
            ]),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// Handle bulk actions on questions (Step 4).
$bulkaction = optional_param('bulk_action', '', PARAM_TEXT);
if (!empty($bulkaction) && confirm_sesskey()) {
    handle_bulk_action($bulkaction, $requestid);
}

// Add Bulma CSS Framework (Native/Local - non-minified for debugging).
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');

// Add our custom CSS (loaded after Bulma to override and fix Moodle compatibility).
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

// Add AMD module for wizard functionality.
$PAGE->requires->js_call_amd('local_hlai_quizgen/wizard', 'init', [$courseid, $requestid, $step]);

echo $OUTPUT->header();

$stepclass = 'hlai-step-';
if ($step === 'progress') {
    $stepclass .= 'progress';
} else if (is_numeric($step)) {
    $stepclass .= (int)$step;
} else {
    $stepclass .= '1';
}

echo html_writer::start_div('hlai-quizgen-wizard local-hlai-iksha ' . $stepclass);

// Wizard header.
echo html_writer::start_div('has-text-centered mb-5');
echo html_writer::tag('h2', get_string('wizard_title', 'local_hlai_quizgen'), [
    'class' => 'has-text-weight-bold',
    'style' => 'font-size: 1.75rem; color: var(--hlai-gray-800);',
]);
echo html_writer::tag('p', get_string('wizard_subtitle', 'local_hlai_quizgen'), ['class' => 'has-text-grey']);
echo html_writer::end_div(); // Has-text-centered.

// Step indicator.
echo render_step_indicator($step);

// Step content container.
echo html_writer::start_div('wizard-step-content', ['id' => 'wizard-step-content']);

// Render current step.
switch ($step) {
    case '1':
    case 1:
        echo render_step1($courseid, $requestid);
        break;
    case '2':
    case 2:
        echo render_step2($courseid, $requestid);
        break;
    case '3':
    case 3:
        echo render_step3($courseid, $requestid);
        break;
    case 'progress':
        echo render_step3_5($courseid, $requestid); // Progress monitoring.
        break;
    case '4':
    case 4:
        echo render_step4($courseid, $requestid);
        break;
    case '5':
    case 5:
        echo render_step5($courseid, $requestid);
        break;
    default:
        echo render_step1($courseid, $requestid);
}

echo html_writer::end_div(); // Wizard-step-content.

echo html_writer::end_div(); // Hlai-quizgen-wizard.

// Add spacing before footer to prevent overlap.
echo html_writer::div('', '', ['style' => 'height: 60px;']);

echo $OUTPUT->footer();

/**
 * Handle content upload.
 *
 * @param int $courseid Course ID
 * @param context $context Context
 * @return void
 */
function handle_content_upload(int $courseid, context $context) {
    global $DB, $USER, $CFG;

    // Check rate limit BEFORE processing uploads to avoid wasting resources.
    if (
        \local_hlai_quizgen\rate_limiter::is_rate_limiting_enabled() &&
        !\local_hlai_quizgen\rate_limiter::is_user_exempt($USER->id)
    ) {
        $ratelimitcheck = \local_hlai_quizgen\rate_limiter::check_rate_limit($USER->id, $courseid);

        if (!$ratelimitcheck['allowed']) {
            // Record violation.
            \local_hlai_quizgen\rate_limiter::record_violation(
                $USER->id,
                $ratelimitcheck['limit_type'] ?? 'unknown',
                $ratelimitcheck
            );

            redirect(
                new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'step' => 1]),
                get_string('error:rate_limit_exceeded', 'local_hlai_quizgen', $ratelimitcheck['reason']),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
            return;
        }
    }

    // Get form data.
    $manualtext = optional_param('manual_text', '', PARAM_RAW);
    $activityids = optional_param_array('activityids', [], PARAM_INT);
    $contentsources = optional_param_array('content_sources', [], PARAM_TEXT);
    $urllist = optional_param('url_list', '', PARAM_TEXT);

    // Check for bulk scanning options.
    $bulkscanentire = in_array('scan_course', $contentsources);
    $bulkscanresources = in_array('scan_resources', $contentsources);
    $bulkscanactivities = in_array('scan_activities', $contentsources);

    // Parse URL list.
    $urls = [];
    if (!empty(trim($urllist))) {
        $lines = explode("\n", $urllist);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && filter_var($line, FILTER_VALIDATE_URL)) {
                $urls[] = $line;
            }
        }
    }

    // Validate file sizes before processing.
    if (!empty($_FILES['contentfiles']['name'][0])) {
        $maxfilesize = get_config('local_hlai_quizgen', 'max_file_size_mb') ?: 50;
        $maxbytes = $maxfilesize * 1024 * 1024;

        foreach ($_FILES['contentfiles']['size'] as $key => $filesize) {
            if ($filesize > $maxbytes) {
                $filename = $_FILES['contentfiles']['name'][$key];
                redirect(
                    new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'step' => 1]),
                    get_string(
                        'error:filetoobig',
                        'local_hlai_quizgen',
                        ['filename' => $filename, 'maxsize' => $maxfilesize . 'MB']
                    ),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
                return;
            }
        }
    }

    // Validate that at least one content source is provided.
    $hasmanualtext = !empty(trim($manualtext));
    $hasfiles = !empty($_FILES['contentfiles']['name'][0]);
    $hasactivities = !empty($activityids);
    $hasurls = !empty($urls);
    $hasbulkscan = $bulkscanentire || $bulkscanresources || $bulkscanactivities;

    if (!$hasmanualtext && !$hasfiles && !$hasurls && !$hasactivities && !$hasbulkscan) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'step' => 1]),
            get_string('error:nocontent', 'local_hlai_quizgen'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
        return;
    }

    // CONTENT DEDUPLICATION: Collect all content to calculate hash.
    $allcontent = '';

    // Add manual text.
    if ($hasmanualtext) {
        $allcontent .= trim($manualtext) . "\n\n";
    }

    // Add activity IDs (deterministic representation).
    if ($hasactivities) {
        sort($activityids);  // Sort for consistent hashing.
        $allcontent .= 'ACTIVITIES:' . implode(',', $activityids) . "\n\n";
    }

    // Add bulk scan flags.
    if ($bulkscanentire) {
        $allcontent .= 'BULK_SCAN:ENTIRE_COURSE' . "\n\n";
    }
    if ($bulkscanresources) {
        $allcontent .= 'BULK_SCAN:ALL_RESOURCES' . "\n\n";
    }
    if ($bulkscanactivities) {
        $allcontent .= 'BULK_SCAN:ALL_ACTIVITIES' . "\n\n";
    }

    // Add file content (we'll hash filenames and sizes for now, actual content during processing).
    if ($hasfiles) {
        $filedata = [];
        foreach ($_FILES['contentfiles']['name'] as $key => $filename) {
            if (empty($filename)) {
                continue;
            }
            $filesize = $_FILES['contentfiles']['size'][$key] ?? 0;
            $filedata[] = $filename . ':' . $filesize;
        }
        sort($filedata);  // Sort for consistent hashing.
        $allcontent .= 'FILES:' . implode('|', $filedata) . "\n\n";
    }

    // Add URLs.
    if ($hasurls) {
        sort($urls);  // Sort for consistent hashing.
        $allcontent .= 'URLS:' . implode('|', $urls) . "\n\n";
    }

    // Calculate SHA-256 hash of content.
    $contenthash = hash('sha256', $allcontent);

    // Check for duplicate content if deduplication is enabled.
    $deduplicationenabled = get_config('local_hlai_quizgen', 'enable_content_deduplication') !== '0';
    $existingrequest = null;

    if ($deduplicationenabled) {
        // Check for duplicate content in this course from the last 30 days.
        $existingrequest = $DB->get_record_sql(
            "SELECT * FROM {local_hlai_quizgen_requests}
             WHERE courseid = ? AND content_hash = ? AND timecreated > ?
             ORDER BY timecreated DESC
             LIMIT 1",
            [$courseid, $contenthash, time() - (30 * 24 * 60 * 60)]
        );
    }

    if ($existingrequest && $existingrequest->status === 'completed') {
        // Found duplicate! Check if topics exist.
        $existingtopics = $DB->get_records('local_hlai_quizgen_topics', ['requestid' => $existingrequest->id]);

        if (!empty($existingtopics)) {
            // Create new request and clone topics from existing.
            $record = new stdClass();
            $record->courseid = $courseid;
            $record->userid = $USER->id;
            $record->status = 'pending';  // Will be updated to analyzing after insert.
            $record->content_hash = $contenthash;
            $record->timecreated = time();
            $record->timemodified = time();

            // Store content sources info.
            $contentsourceinfo = [];
            if ($hasmanualtext) {
                $contentsourceinfo[] = 'manual_text';
            }
            if ($hasfiles) {
                $contentsourceinfo[] = 'uploaded_files';
            }
            if ($hasurls) {
                $contentsourceinfo[] = 'urls:' . implode(',', $urls);
            }
            if ($hasactivities) {
                $contentsourceinfo[] = 'course_activities:' . implode(',', $activityids);
            }
            if ($bulkscanentire) {
                $contentsourceinfo[] = 'bulk_scan:entire_course';
            }
            if ($bulkscanresources) {
                $contentsourceinfo[] = 'bulk_scan:all_resources';
            }
            if ($bulkscanactivities) {
                $contentsourceinfo[] = 'bulk_scan:all_activities';
            }
            $record->content_sources = json_encode($contentsourceinfo);

            if ($hasmanualtext) {
                $record->custom_instructions = $manualtext;
            }

            $requestid = $DB->insert_record('local_hlai_quizgen_requests', $record);

            // Clone topics from existing request, with deduplication.
            // First, deduplicate the topics by normalized title.
            $seentitles = [];
            $uniquetopics = [];
            foreach ($existingtopics as $topic) {
                // Clean and normalize the title for comparison.
                $cleanedtitle = \local_hlai_quizgen\topic_analyzer::clean_topic_title_public($topic->title);
                $normalizedtitle = strtolower(trim($cleanedtitle));

                // Skip if we've already seen this title.
                if (isset($seentitles[$normalizedtitle])) {
                    continue;
                }

                $seentitles[$normalizedtitle] = $topic->title;
                $topic->title = $cleanedtitle; // Use cleaned title.
                $uniquetopics[] = $topic;
            }

            // Now insert the deduplicated topics.
            foreach ($uniquetopics as $topic) {
                $newtopic = clone $topic;
                unset($newtopic->id);
                $newtopic->requestid = $requestid;
                $newtopic->timecreated = time();
                $DB->insert_record('local_hlai_quizgen_topics', $newtopic);
            }

            // Skip to step 2 (topic selection) since we already have topics.
            redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $courseid,
                'requestid' => $requestid,
                'step' => 2,
            ]));
            return;
        }
    }

    // No duplicate or no topics - create new request normally.
    $record = new stdClass();
    $record->courseid = $courseid;
    $record->userid = $USER->id;
    $record->status = 'pending';  // Will be updated to analyzing.
    $record->content_hash = $contenthash;  // Store hash.
    $record->timecreated = time();
    $record->timemodified = time();

    // Store content sources info.
    $contentsourceinfo = [];
    if ($hasmanualtext) {
        $contentsourceinfo[] = 'manual_text';
    }
    if ($hasfiles) {
        $contentsourceinfo[] = 'uploaded_files';
    }
    if ($hasurls) {
        $contentsourceinfo[] = 'urls:' . implode(',', $urls);
    }
    if ($hasactivities) {
        $contentsourceinfo[] = 'course_activities:' . implode(',', $activityids);
    }
    if ($bulkscanentire) {
        $contentsourceinfo[] = 'bulk_scan:entire_course';
    }
    if ($bulkscanresources) {
        $contentsourceinfo[] = 'bulk_scan:all_resources';
    }
    if ($bulkscanactivities) {
        $contentsourceinfo[] = 'bulk_scan:all_activities';
    }
    $record->content_sources = json_encode($contentsourceinfo);

    // Store manual text in custom_instructions field.
    if ($hasmanualtext) {
        $record->custom_instructions = $manualtext;
    }

    $requestid = $DB->insert_record('local_hlai_quizgen_requests', $record);

    // Update to analyzing status.
    \local_hlai_quizgen\api::update_request_status($requestid, 'analyzing');

    // Handle file uploads.
    if ($hasfiles) {
        require_once($CFG->libdir . '/filelib.php');

        $fs = get_file_storage();
        $uploadedfiles = [];

        foreach ($_FILES['contentfiles']['name'] as $key => $filename) {
            if (empty($filename)) {
                continue;
            }

            $fileerror = $_FILES['contentfiles']['error'][$key];
            if ($fileerror != UPLOAD_ERR_OK) {
                $errormsg = 'Unknown error';
                switch ($fileerror) {
                    case UPLOAD_ERR_INI_SIZE:
                        $errormsg = 'File exceeds upload_max_filesize in php.ini';
                        break;
                    case UPLOAD_ERR_FORM_SIZE:
                        $errormsg = 'File exceeds MAX_FILE_SIZE in HTML form';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errormsg = 'File was only partially uploaded';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errormsg = 'No file was uploaded';
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $errormsg = 'Missing temporary folder';
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $errormsg = 'Failed to write file to disk';
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $errormsg = 'File upload stopped by extension';
                        break;
                }
                redirect(
                    new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'step' => 1]),
                    get_string('error:fileupload', 'local_hlai_quizgen', ['filename' => $filename, 'error' => $errormsg]),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
                return;
            }

            $tmpfile = $_FILES['contentfiles']['tmp_name'][$key];
            $filesize = $_FILES['contentfiles']['size'][$key];

            // Validate file size (50MB max).
            $maxsize = 50 * 1024 * 1024;
            if ($filesize > $maxsize) {
                continue;
            }

            // Check if temp file exists.
            if (!file_exists($tmpfile)) {
                redirect(
                    new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'step' => 1]),
                    get_string('error:fileupload', 'local_hlai_quizgen', [
                        'filename' => $filename,
                        'error' => 'Temporary file not found',
                    ]),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
                return;
            }

            // Prepare file record.
            $fileinfo = [
                'contextid' => $context->id,
                'component' => 'local_hlai_quizgen',
                'filearea' => 'content',
                'itemid' => $requestid,
                'filepath' => '/',
                'filename' => clean_filename($filename),
                'userid' => $USER->id,
            ];

            // Store file.
            try {
                $storedfile = $fs->create_file_from_pathname($fileinfo, $tmpfile);
                $uploadedfiles[] = $storedfile->get_filename();
            } catch (Exception $e) {
                // Silently skip files that fail to upload.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    // Handle URL extraction.
    if ($hasurls) {
        require_once($CFG->dirroot . '/local/hlai_quizgen/classes/content_extractor.php');

        foreach ($urls as $url) {
            try {
                $urlcontent = \local_hlai_quizgen\content_extractor::extract_from_url($url);

                // Store URL content in database for later processing.
                $urlrecord = new stdClass();
                $urlrecord->requestid = $requestid;
                $urlrecord->url = $url;
                $urlrecord->content = $urlcontent['text'];
                $urlrecord->title = $urlcontent['title'];
                $urlrecord->word_count = $urlcontent['word_count'];
                $urlrecord->timecreated = time();

                $DB->insert_record('local_hlai_quizgen_url_content', $urlrecord);
            } catch (Exception $e) {
                // Continue with other URLs even if one fails.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    // Save wizard state.
    // Redirect to step 2.
    redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'step' => 2,
    ]));
}

/**
 * Handle create request from step 1.
 *
 * Creates a new quiz generation request for the given course.
 *
 * @param int $courseid Course ID
 * @return void
 */
function handle_create_request(int $courseid) {
    global $DB, $USER;

    $requestid = optional_param('requestid', 0, PARAM_INT);

    if ($requestid) {
        // Request already exists, redirect to step 2.
        redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'step' => 2,
        ]));
    }

    // Create a new request.
    $record = new \stdClass();
    $record->courseid = $courseid;
    $record->userid = $USER->id;
    $record->status = 'pending';
    $record->timecreated = time();
    $record->timemodified = time();
    $requestid = $DB->insert_record('local_hlai_quizgen_requests', $record);

    redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'step' => 2,
    ]));
}

/**
 * Handle save question distribution (Step 2 - question distribution).
 *
 * @param int $requestid Request ID
 * @return void
 */
function handle_save_question_distribution(int $requestid) {
    global $DB;

    $courseid = required_param('courseid', PARAM_INT);
    $topicquestions = optional_param_array('topic_questions', [], PARAM_INT);

    // Update question counts for each topic.
    foreach ($topicquestions as $topicid => $numqs) {
        $numqs = (int)$numqs;
        if ($numqs < 0) {
            $numqs = 0;
        }
        if ($numqs > 50) {
            $numqs = 50;
        }

        $DB->set_field('local_hlai_quizgen_topics', 'num_questions', $numqs, ['id' => $topicid]);
    }

    // Update total question count on request.
    $totalquestions = array_sum($topicquestions);
    $DB->set_field('local_hlai_quizgen_requests', 'total_questions', $totalquestions, ['id' => $requestid]);

    // Redirect to step 3 to show configuration (don't auto-generate).
    redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'step' => 3,
    ]));
}

/**
 * Handle save topic selection (Step 2 - topic selection).
 *
 * @param int $requestid Request ID
 * @return void
 */
function handle_save_topic_selection(int $requestid) {
    global $DB;

    $courseid = required_param('courseid', PARAM_INT);
    $selectedtopics = optional_param_array('topics', [], PARAM_INT);

    // Update all topics to deselected first.
    $DB->set_field('local_hlai_quizgen_topics', 'selected', 0, ['requestid' => $requestid]);

    // Set default num_questions for selected topics.
    foreach ($selectedtopics as $topicid) {
        $DB->set_field('local_hlai_quizgen_topics', 'selected', 1, ['id' => $topicid]);
        $DB->set_field('local_hlai_quizgen_topics', 'num_questions', 5, ['id' => $topicid]); // Default 5 questions.
    }

    // Redirect to step 3.
    redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'step' => 3,
    ]));
}

/**
 * Handle generate questions from step 3.
 *
 * @param int $requestid Request ID
 * @return void
 * @throws moodle_exception
 */
function handle_generate_questions(int $requestid) {
    global $DB;

    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

    // Get total questions from form input (new approach).
    $totalquestions = required_param('total_questions', PARAM_INT);

    // Validate.
    if ($totalquestions < 1 || $totalquestions > 100) {
        throw new \moodle_exception('Invalid total questions. Must be between 1 and 100.');
    }

    // Get question type counts from the new quantity-based form.
    $qtypecounts = optional_param_array('qtype_count', [], PARAM_INT);

    // Build distribution map instead of repeated array for better performance.
    $questiontypedist = [];
    $typecount = 0;
    foreach ($qtypecounts as $type => $count) {
        if ($count > 0) {
            $questiontypedist[$type] = $count;
            $typecount += $count;
        }
    }

    // Validate that question type counts match total.
    if (!empty($questiontypedist) && $typecount != $totalquestions) {
        throw new \moodle_exception(
            'Question type counts (' . $typecount . ') must equal total questions (' . $totalquestions . '). ' .
            'Please ensure the question type quantities add up to the total.'
        );
    }

    // Fallback if no types specified.
    if (empty($questiontypedist)) {
        $questiontypedist = ['multichoice' => $totalquestions];
    }

    $difficulty = optional_param('difficulty', 'balanced', PARAM_TEXT);
    $processingmode = get_config('local_hlai_quizgen', 'default_quality_mode') ?? 'balanced';
    // Processing mode now comes from global plugin config.
    $processingmode = get_config('local_hlai_quizgen', 'default_quality_mode') ?? 'balanced';

    // Capture Bloom's Taxonomy distribution.
    $bloomsdist = [
        'remember' => optional_param('blooms_remember', 20, PARAM_INT),
        'understand' => optional_param('blooms_understand', 25, PARAM_INT),
        'apply' => optional_param('blooms_apply', 25, PARAM_INT),
        'analyze' => optional_param('blooms_analyze', 15, PARAM_INT),
        'evaluate' => optional_param('blooms_evaluate', 10, PARAM_INT),
        'create' => optional_param('blooms_create', 5, PARAM_INT),
    ];

    // Convert difficulty preset to distribution array.
    $difficultydist = ['easy' => 20, 'medium' => 60, 'hard' => 20];  // Default balanced.
    if ($difficulty === 'easy') {
        $difficultydist = ['easy' => 50, 'medium' => 40, 'hard' => 10];
    } else if ($difficulty === 'challenging') {
        $difficultydist = ['easy' => 10, 'medium' => 40, 'hard' => 50];
    }

    // Update request with parameters.
    $request->total_questions = $totalquestions;
    // CRITICAL FIX: Store DISTRIBUTION not expanded array.
    // This allows each topic to generate its proportional share of each type.
    $request->question_types = json_encode($questiontypedist); // Store distribution map for type allocation.
    $request->difficulty_distribution = json_encode($difficultydist);
    $request->blooms_distribution = json_encode($bloomsdist);  // Store Bloom's distribution.
    $request->processing_mode = $processingmode;
    $request->timemodified = time();

    $DB->update_record('local_hlai_quizgen_requests', $request);

    // CRITICAL FIX: Distribute total questions across ALL selected topics.
    $selectedtopics = $DB->get_records('local_hlai_quizgen_topics', ['requestid' => $requestid, 'selected' => 1]);
    $topiccount = count($selectedtopics);

    if ($topiccount > 0) {
        // Distribute questions evenly, with remainder going to first topics.
        $questionspertopic = floor($totalquestions / $topiccount);
        $remainder = $totalquestions % $topiccount;

        $index = 0;
        foreach ($selectedtopics as $topic) {
            $topicquestions = $questionspertopic + ($index < $remainder ? 1 : 0);
            $DB->set_field('local_hlai_quizgen_topics', 'num_questions', $topicquestions, ['id' => $topic->id]);

            // FIXED: Don't store distribution per topic - let it inherit from request level.
            // This prevents double-counting where each topic gets the full distribution.
            // The adhoc task will calculate proportions based on topic->num_questions.
            $index++;
        }
    }

    // Async code disabled for better UX - requires cron to be running.

    // SYNCHRONOUS GENERATION - Generate questions immediately for better UX.
    // Log the start of question generation.
    debug_logger::wizard_step(3, 'generate_questions_start', $requestid, [
        'total_questions' => $totalquestions,
        'question_types' => $questiontypedist,
        'difficulty' => $difficulty,
        'processing_mode' => $processingmode,
        'topics_count' => $topiccount ?? 0,
    ]);

    // Log system info at the start of generation for debugging.
    debug_logger::logsysteminfo($requestid);

    try {
        // Increase time limit for large requests.
        $oldtimelimit = ini_get('max_execution_time');
        if ($totalquestions > 20) {
            set_time_limit(300); // 5 minutes for large requests.
        }

        debug_logger::debug('Executing question generation task', [
            'time_limit' => $totalquestions > 20 ? 300 : $oldtimelimit,
        ], $requestid);

        // Execute the adhoc task directly (synchronous).
        $task = new \local_hlai_quizgen\task\generate_questions_adhoc();
        $task->set_custom_data((object)[
            'request_id' => $requestid,
        ]);
        $task->execute();

        // Restore time limit.
        if ($totalquestions > 20) {
            set_time_limit($oldtimelimit);
        }

        // Refresh request status after generation.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

        // Log successful completion.
        $questionsgenerated = $DB->count_records('local_hlai_quizgen_questions', ['requestid' => $requestid]);
        debug_logger::wizard_step(3, 'generate_questions_complete', $requestid, [
            'status' => $request->status,
            'questions_generated' => $questionsgenerated,
            'total_tokens' => $request->total_tokens ?? 0,
        ]);
    } catch (\Exception $e) {
        // Log the exception with full details.
        debug_logger::exception($e, 'wizard_generate_questions', $requestid);

        // Use centralized error handling.
        \local_hlai_quizgen\error_handler::handle_exception(
            $e,
            $requestid,
            'question_generation',
            \local_hlai_quizgen\error_handler::SEVERITY_ERROR
        );
        \local_hlai_quizgen\api::update_request_status($requestid, 'failed', $e->getMessage());
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

        // Log the failure.
        debug_logger::wizard_step(3, 'generate_questions_failed', $requestid, [
            'error' => $e->getMessage(),
            'status' => 'failed',
        ]);
    }

    // Redirect to step 4.
    redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $request->courseid,
        'requestid' => $requestid,
        'step' => 4,
    ]));
}

/**
 * Handle bulk actions on questions (approve, reject, delete).
 *
 * @param string $action Action to perform (approve, reject, delete)
 * @param int $requestid Request ID
 * @return void
 */
function handle_bulk_action(string $action, int $requestid) {
    global $DB;

    $questionids = optional_param_array('question_ids', [], PARAM_INT);

    if (empty($questionids)) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $DB->get_field('local_hlai_quizgen_requests', 'courseid', ['id' => $requestid]),
                'requestid' => $requestid,
                'step' => 4,
            ]),
            get_string('error:noquestionsselected', 'local_hlai_quizgen') ?: 'No questions selected',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
        return;
    }

    $courseid = $DB->get_field('local_hlai_quizgen_requests', 'courseid', ['id' => $requestid]);

    $count = 0;
    foreach ($questionids as $qid) {
        $question = $DB->get_record('local_hlai_quizgen_questions', [
            'id' => $qid,
            'requestid' => $requestid,
        ]);

        if (!$question) {
            continue;
        }

        switch ($action) {
            case 'approve':
                $question->status = 'approved';
                $DB->update_record('local_hlai_quizgen_questions', $question);
                $count++;
                break;

            case 'reject':
                $question->status = 'rejected';
                $DB->update_record('local_hlai_quizgen_questions', $question);
                $count++;
                break;

            case 'delete':
                // Delete answers first.
                $DB->delete_records('local_hlai_quizgen_answers', ['questionid' => $qid]);
                // Delete question.
                $DB->delete_records('local_hlai_quizgen_questions', ['id' => $qid]);
                $count++;
                break;
        }
    }

    $messages = [
        'approve' => get_string('bulk_approved', 'local_hlai_quizgen') ?: 'Approved {$a} question(s)',
        'reject' => get_string('bulk_rejected', 'local_hlai_quizgen') ?: 'Rejected {$a} question(s)',
        'delete' => get_string('bulk_deleted', 'local_hlai_quizgen') ?: 'Deleted {$a} question(s)',
    ];

    redirect(
        new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'step' => 4,
        ]),
        str_replace('{$a}', $count, $messages[$action] ?? 'Action completed'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

/**
 * Handle deploy questions from step 5.
 *
 * @param int $requestid Request ID
 * @param int $courseid Course ID
 * @return void
 * @throws moodle_exception
 */
function handle_deploy_questions(int $requestid, int $courseid) {
    global $DB;

    debugging("DEBUG handle_deploy_questions: Starting deployment for request $requestid, course $courseid", DEBUG_DEVELOPER);

    $deploytype = required_param('deploy_type', PARAM_TEXT);
    $quizname = optional_param('quiz_name', '', PARAM_TEXT);
    $categoryname = optional_param('category_name', '', PARAM_TEXT);

    debugging("DEBUG handle_deploy_questions: deploy_type=$deploytype, quiz_name=$quizname", DEBUG_DEVELOPER);

    // Get only approved questions for this request.
    $questions = $DB->get_records('local_hlai_quizgen_questions', [
        'requestid' => $requestid,
        'status' => 'approved',
    ], '', 'id, questiontype');
    $questionids = array_keys($questions);

    debugging("DEBUG handle_deploy_questions: Found " . count($questionids) . " approved questions", DEBUG_DEVELOPER);

    if (empty($questionids)) {
        throw new \moodle_exception('error:noquestionstodeploy', 'local_hlai_quizgen');
    }

    // Log the question types we're about to deploy.
    $qtypes = [];
    foreach ($questions as $q) {
        $qtypes[] = $q->questiontype ?? 'unknown';
    }
    debugging("DEBUG handle_deploy_questions: Question types to deploy: " . implode(', ', $qtypes), DEBUG_DEVELOPER);

    try {
        $deployer = new \local_hlai_quizgen\quiz_deployer();

        if ($deploytype === 'new_quiz') {
            debugging("DEBUG handle_deploy_questions: Creating new quiz...", DEBUG_DEVELOPER);
            $cmid = $deployer->create_quiz($questionids, $courseid, $quizname);

            // Mark questions as deployed on success.
            foreach ($questionids as $qid) {
                $DB->set_field('local_hlai_quizgen_questions', 'status', 'deployed', ['id' => $qid]);
            }

            redirect(
                new moodle_url('/mod/quiz/view.php', ['id' => $cmid]),
                get_string('success:quizcreated', 'local_hlai_quizgen'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } else {
            debugging("DEBUG handle_deploy_questions: Deploying to question bank...", DEBUG_DEVELOPER);
            $moodlequestionids = $deployer->deploy_to_question_bank($questionids, $courseid, $categoryname);

            // Mark questions as deployed on success (already done in deploy_to_question_bank, but keeping for safety).
            foreach ($questionids as $qid) {
                $DB->set_field('local_hlai_quizgen_questions', 'status', 'deployed', ['id' => $qid]);
            }

            redirect(
                new moodle_url('/question/edit.php', ['courseid' => $courseid]),
                get_string('success:questionsdeployed', 'local_hlai_quizgen') . ' (' . count($moodlequestionids) . ' questions)',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    } catch (\Throwable $e) {
        // Catch both Exception and Error types.
        $fullerror = get_class($e) . ': ' . $e->getMessage();
        $debuginfo = " [File: " . $e->getFile() . ":" . $e->getLine() . "]";

        debugging("DEBUG handle_deploy_questions: DEPLOYMENT FAILED - $fullerror $debuginfo", DEBUG_DEVELOPER);
        debugging("DEBUG handle_deploy_questions: Stack trace: " . $e->getTraceAsString(), DEBUG_DEVELOPER);

        // Try to log the error.
        try {
            \local_hlai_quizgen\error_handler::handle_exception(
                $e,
                $requestid,
                'deployment',
                \local_hlai_quizgen\error_handler::SEVERITY_ERROR
            );
        } catch (\Throwable $logerror) {
            debugging("DEBUG handle_deploy_questions: Failed to log error: " . $logerror->getMessage(), DEBUG_DEVELOPER);
        }

        // Show the full error message to the user for debugging.
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $courseid,
                'requestid' => $requestid,
                'step' => 5,
            ]),
            get_string('error:deploymentfailed', 'local_hlai_quizgen') . ': ' . $fullerror,
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

/**
 * Render step indicator.
 *
 * @param mixed $currentstep Current step number or identifier
 * @return string HTML
 */
function render_step_indicator($currentstep): string {
    // Normalize step to integer for display (progress = step 3.5, show as step 3).
    if ($currentstep === 'progress') {
        $currentstep = 3;
    }
    $currentstep = (int)$currentstep;

    $steps = [
        1 => get_string('step1_title', 'local_hlai_quizgen'),
        2 => get_string('step2_title', 'local_hlai_quizgen'),
        3 => get_string('step3_title', 'local_hlai_quizgen'),
        4 => get_string('step4_title', 'local_hlai_quizgen'),
        5 => get_string('step5_title', 'local_hlai_quizgen'),
    ];

    $html = html_writer::start_div('hlai-steps mb-5');

    foreach ($steps as $stepnum => $steptitle) {
        $classes = ['hlai-step-item'];
        if ($stepnum == $currentstep) {
            $classes[] = 'is-active';
        } else if ($stepnum < $currentstep) {
            $classes[] = 'is-completed';
        }

        $html .= html_writer::start_div(implode(' ', $classes));
        $html .= html_writer::tag('div', $stepnum, ['class' => 'hlai-step-marker']);
        $html .= html_writer::start_div('hlai-step-details');
        $html .= html_writer::tag('div', $steptitle, ['class' => 'hlai-step-title']);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
    }

    $html .= html_writer::end_div();

    return $html;
}

/**
 * Render step 1: Content selection.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function render_step1(int $courseid, int $requestid): string {
    global $OUTPUT, $DB, $PAGE;

    $html = html_writer::start_div('hlai-step-content');
    $html .= html_writer::tag('h2', get_string('step1_title', 'local_hlai_quizgen'), ['class' => 'title is-4 mb-0']);
    $html .= html_writer::tag('p', get_string('step1_description', 'local_hlai_quizgen'), [
        'class' => 'subtitle is-6 has-text-grey mt-1 mb-4',
    ]);
    $html .= html_writer::tag('hr', '', ['class' => 'mt-0 mb-5']);

    // Start form.
    $formurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'action' => 'upload_content',
    ]);

    $html .= html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'id' => 'content-selection-form',
        'enctype' => 'multipart/form-data',
        'onsubmit' => 'return validateForm();',
    ]);

    $html .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);

    $html .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'action',
        'value' => 'upload_content',
    ]);

    $html .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'courseid',
        'value' => $courseid,
    ]);

    // Content source selection with checkboxes.
    $html .= html_writer::start_div('field mb-5');
    $html .= html_writer::tag('label', get_string('select_content_sources', 'local_hlai_quizgen'), ['class' => 'label is-medium']);
    $html .= html_writer::tag('p', get_string('select_content_sources_help', 'local_hlai_quizgen'), ['class' => 'help mb-4']);

    // GROUP 1: Add Your Own Content.
    $html .= html_writer::start_div('hlai-source-group mb-5');
    $html .= html_writer::tag('h5', '<i class="fa fa-pencil" style="color: #F59E0B;"></i> Add Your Own Content', [
        'class' => 'hlai-source-group-title',
    ]);
    $html .= html_writer::start_div('columns is-multiline');

    // Option 1: Manual Text Entry.
    $html .= html_writer::start_div('column is-4');
    $html .= html_writer::start_tag('label', ['class' => 'hlai-source-card', 'for' => 'source_manual']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'content_sources[]',
        'value' => 'manual',
        'id' => 'source_manual',
        'class' => 'content-source-checkbox hlai-source-check',
        'onchange' => 'toggleContentSection("manual")',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-pencil" style="color: #F59E0B;"></i>', ['class' => 'hlai-source-icon']);
    $html .= html_writer::tag('strong', get_string('source_manual', 'local_hlai_quizgen'), ['class' => 'hlai-source-title']);
    $html .= html_writer::tag('p', get_string('source_manual_desc', 'local_hlai_quizgen'), ['class' => 'hlai-source-desc']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    // Option 2: File Upload.
    $html .= html_writer::start_div('column is-4');
    $html .= html_writer::start_tag('label', ['class' => 'hlai-source-card', 'for' => 'source_upload']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'content_sources[]',
        'value' => 'upload',
        'id' => 'source_upload',
        'class' => 'content-source-checkbox hlai-source-check',
        'onchange' => 'toggleContentSection("upload")',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-folder-open" style="color: #8B5CF6;"></i>', ['class' => 'hlai-source-icon']);
    $html .= html_writer::tag('strong', get_string('source_upload', 'local_hlai_quizgen'), ['class' => 'hlai-source-title']);
    $html .= html_writer::tag('p', get_string('source_upload_desc', 'local_hlai_quizgen'), ['class' => 'hlai-source-desc']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    // Option 3: URL Extraction.
    $html .= html_writer::start_div('column is-4');
    $html .= html_writer::start_tag('label', ['class' => 'hlai-source-card', 'for' => 'source_url']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'content_sources[]',
        'value' => 'url',
        'id' => 'source_url',
        'class' => 'content-source-checkbox hlai-source-check',
        'onchange' => 'toggleContentSection("url")',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-globe" style="color: #06B6D4;"></i>', ['class' => 'hlai-source-icon']);
    $html .= html_writer::tag('strong', 'Extract from URL', ['class' => 'hlai-source-title']);
    $html .= html_writer::tag('p', 'Fetch content from web pages', ['class' => 'hlai-source-desc']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Columns.
    $html .= html_writer::end_div(); // Hlai-source-group.

    // GROUP 2: Use Course Content.
    $html .= html_writer::start_div('hlai-source-group');
    $html .= html_writer::tag('h5', '<i class="fa fa-book" style="color: #10B981;"></i> Use Course Content', [
        'class' => 'hlai-source-group-title',
    ]);
    $html .= html_writer::start_div('columns is-multiline');

    // Option 4: Course Activities (Browse & Select).
    $html .= html_writer::start_div('column is-6');
    $html .= html_writer::start_tag('label', ['class' => 'hlai-source-card hlai-source-featured', 'for' => 'source_activities']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'content_sources[]',
        'value' => 'activities',
        'id' => 'source_activities',
        'class' => 'content-source-checkbox hlai-source-check',
        'onchange' => 'toggleContentSection("activities")',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-book" style="color: #3B82F6;"></i>', [
        'class' => 'hlai-source-icon hlai-icon-lg',
    ]);
    $html .= html_writer::start_div('hlai-source-text');
    $html .= html_writer::tag('strong', get_string('source_activities', 'local_hlai_quizgen'), ['class' => 'hlai-source-title']);
    $html .= html_writer::tag('p', get_string('source_activities_desc', 'local_hlai_quizgen'), ['class' => 'hlai-source-desc']);
    $html .= html_writer::tag('span', '<i class="fa fa-star" style="color: #F59E0B;"></i> Recommended', [
        'class' => 'hlai-source-badge',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    // Option 5: Scan Entire Course.
    $html .= html_writer::start_div('column is-6');
    $html .= html_writer::start_tag('label', ['class' => 'hlai-source-card', 'for' => 'source_scan_course']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'content_sources[]',
        'value' => 'scan_course',
        'id' => 'source_scan_course',
        'class' => 'content-source-checkbox hlai-source-check',
        'onchange' => 'toggleContentSection("scan_course")',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-graduation-cap" style="color: #10B981;"></i>', [
        'class' => 'hlai-source-icon',
    ]);
    $html .= html_writer::tag('strong', 'Scan Entire Course', ['class' => 'hlai-source-title']);
    $html .= html_writer::tag('p', 'Course summary + all sections', ['class' => 'hlai-source-desc']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    // Option 6: Scan All Resources.
    $html .= html_writer::start_div('column is-6');
    $html .= html_writer::start_tag('label', ['class' => 'hlai-source-card', 'for' => 'source_scan_resources']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'content_sources[]',
        'value' => 'scan_resources',
        'id' => 'source_scan_resources',
        'class' => 'content-source-checkbox hlai-source-check',
        'onchange' => 'toggleContentSection("scan_resources")',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-book" style="color: #8B5CF6;"></i>', ['class' => 'hlai-source-icon']);
    $html .= html_writer::tag('strong', 'Scan All Resources', ['class' => 'hlai-source-title']);
    $html .= html_writer::tag('p', 'All pages, books, files, URLs', ['class' => 'hlai-source-desc']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    // Option 7: Scan All Activities.
    $html .= html_writer::start_div('column is-6');
    $html .= html_writer::start_tag('label', ['class' => 'hlai-source-card', 'for' => 'source_scan_activities']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'content_sources[]',
        'value' => 'scan_activities',
        'id' => 'source_scan_activities',
        'class' => 'content-source-checkbox hlai-source-check',
        'onchange' => 'toggleContentSection("scan_activities")',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-edit" style="color: #EF4444;"></i>', ['class' => 'hlai-source-icon']);
    $html .= html_writer::tag('strong', 'Scan All Activities', ['class' => 'hlai-source-title']);
    $html .= html_writer::tag('p', 'All lessons, SCORM, forums', ['class' => 'hlai-source-desc']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Columns.
    $html .= html_writer::end_div(); // Hlai-source-group.

    // Display selected sources.
    $html .= html_writer::start_div('notification is-info is-light mt-4', [
        'id' => 'selected-sources-display',
        'style' => 'display:none;',
    ]);
    $html .= html_writer::tag('strong', get_string('selected_sources', 'local_hlai_quizgen') . ': ');
    $html .= html_writer::tag('span', '', ['id' => 'selected-sources-list', 'class' => 'tag is-primary is-medium ml-2']);
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Field.

    // Bulk scan sections (hidden - no UI needed, just for JavaScript compatibility).
    $html .= html_writer::div('', '', ['id' => 'section-scan_course', 'style' => 'display:none;']);
    $html .= html_writer::div('', '', ['id' => 'section-scan_resources', 'style' => 'display:none;']);
    $html .= html_writer::div('', '', ['id' => 'section-scan_activities', 'style' => 'display:none;']);

    // Manual text entry section (initially hidden).
    $html .= html_writer::start_div('field mb-5', ['id' => 'section-manual', 'style' => 'display:none;']);
    $html .= html_writer::tag(
        'label',
        '<i class="fa fa-pencil" style="color: #F59E0B;"></i> ' . get_string('manual_text_entry', 'local_hlai_quizgen'),
        ['class' => 'label']
    );
    $html .= html_writer::tag('p', get_string('manual_text_entry_help', 'local_hlai_quizgen'), ['class' => 'help mb-3']);
    $html .= html_writer::start_div('control');
    $html .= html_writer::tag('textarea', '', [
        'name' => 'manual_text',
        'id' => 'manual_text',
        'rows' => 8,
        'class' => 'textarea',
        'placeholder' => get_string('manual_text_placeholder', 'local_hlai_quizgen'),
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // File upload section (initially hidden).
    $html .= html_writer::start_div('field mb-5', ['id' => 'section-upload', 'style' => 'display:none;']);
    $html .= html_writer::tag(
        'label',
        '<i class="fa fa-folder-open" style="color: #8B5CF6;"></i> ' . get_string('upload_files', 'local_hlai_quizgen'),
        ['class' => 'label']
    );
    $html .= html_writer::tag('p', get_string('upload_files_help', 'local_hlai_quizgen'), ['class' => 'help mb-3']);

    $html .= html_writer::start_div('file has-name is-boxed is-fullwidth');
    $html .= html_writer::start_tag('label', ['class' => 'file-label']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'file',
        'name' => 'contentfiles[]',
        'id' => 'content-files',
        'class' => 'file-input',
        'multiple' => 'multiple',
        'accept' => '.pdf,.doc,.docx,.ppt,.pptx,.txt',
    ]);
    $html .= html_writer::start_tag('span', ['class' => 'file-cta']);
    $html .= html_writer::tag('span', '📂', ['class' => 'file-icon is-size-4']);
    $html .= html_writer::tag('span', get_string('choose_files', 'local_hlai_quizgen'), ['class' => 'file-label']);
    $html .= html_writer::end_tag('span');
    $html .= html_writer::tag('span', 'No files selected', ['class' => 'file-name']);
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::tag('p', get_string('supported_formats', 'local_hlai_quizgen'), ['class' => 'help mt-2']);

    // Display PHP upload limits for debugging.
    $uploadmaxfilesize = ini_get('upload_max_filesize');
    $postmaxsize = ini_get('post_max_size');
    $maxfilesize = get_config('local_hlai_quizgen', 'max_file_size_mb') ?: 50;

    $html .= html_writer::div(
        html_writer::tag(
            'small',
            'PHP limits: upload_max_filesize=' . $uploadmaxfilesize .
            ', post_max_size=' . $postmaxsize .
            ', plugin_max=' . $maxfilesize . 'MB',
            ['class' => 'has-text-grey is-size-7']
        ),
        'mt-2'
    );

    $html .= html_writer::div('', 'mt-2', ['id' => 'uploaded-files-list']);
    $html .= html_writer::end_div(); // File-upload-section.

    // URL extraction section (initially hidden).
    $html .= html_writer::start_div('field mb-5', ['id' => 'section-url', 'style' => 'display:none;']);
    $html .= html_writer::tag('label', '<i class="fa fa-globe" style="color: #06B6D4;"></i> Extract from URL', [
        'class' => 'label',
    ]);
    $html .= html_writer::tag('p', 'Enter one or more URLs to extract content from web pages.', ['class' => 'help mb-3']);

    $html .= html_writer::start_div('control');
    $html .= html_writer::tag('textarea', '', [
        'name' => 'url_list',
        'id' => 'url_list',
        'rows' => 4,
        'class' => 'textarea',
        'placeholder' => "https://example.com/article\nhttps://example.com/another-page\n(one URL per line)",
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::tag('p', 'Enter one URL per line. Content will be extracted from each page.', ['class' => 'help mt-2']);
    $html .= html_writer::end_div(); // Url-extraction-section.

    // Activity selection section (initially hidden).
    $html .= html_writer::start_div('hlai-activities-section', ['id' => 'section-activities', 'style' => 'display:none;']);

    // Section header with icon.
    $html .= html_writer::start_div('hlai-activities-header');
    $html .= html_writer::tag('span', '<i class="fa fa-book" style="color: #06B6D4;"></i>', ['class' => 'hlai-activities-icon']);
    $html .= html_writer::start_div('hlai-activities-header-text');
    $html .= html_writer::tag('h3', get_string('select_activities', 'local_hlai_quizgen'), ['class' => 'hlai-activities-title']);
    $html .= html_writer::tag('p', get_string('select_activities_help', 'local_hlai_quizgen'), [
        'class' => 'hlai-activities-subtitle',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Get course activities.
    $modinfo = get_fast_modinfo($courseid);
    $activities = [];

    // Only include module types that contain static learning content.
    // EXCLUDED: quiz (questions, not content), wiki (user discussions),.
    // assignment (submissions), choice/survey (polls), glossary (definitions database).
    // INCLUDED: page, book, lesson (structured content), resource (files),.
    // url (links), folder (file collections), scorm (packaged content), forum (discussions).
    $allowedmodules = ['page', 'book', 'lesson', 'resource', 'url', 'folder', 'scorm', 'forum'];

    foreach ($modinfo->get_cms() as $cm) {
        // Only include activity types we can extract content from.
        if (in_array($cm->modname, $allowedmodules)) {
            if ($cm->uservisible) {
                $activities[] = $cm;
            }
        }
    }

    if (!empty($activities)) {
        // Action bar with buttons and count.
        $html .= html_writer::start_div('hlai-activities-actions');
        $html .= html_writer::start_div('hlai-activities-buttons');
        $html .= html_writer::tag('button', '<i class="fa fa-check"></i> Select All', [
            'type' => 'button',
            'id' => 'select-all-activities',
            'class' => 'hlai-action-btn hlai-action-select',
        ]);
        $html .= html_writer::tag('button', '<i class="fa fa-times"></i> Deselect All', [
            'type' => 'button',
            'id' => 'deselect-all-activities',
            'class' => 'hlai-action-btn hlai-action-deselect',
        ]);
        $html .= html_writer::end_div();
        $html .= html_writer::tag('span', count($activities) . ' activities available', [
            'class' => 'hlai-activities-count',
        ]);
        $html .= html_writer::end_div();

        // Activities list with cards.
        $html .= html_writer::start_div('hlai-activities-list');

        foreach ($activities as $cm) {
            $activityname = format_string($cm->name, true, ['context' => context_module::instance($cm->id)]);
            $activitytype = get_string('modulename', $cm->modname);

            // Get emoji for activity type.
            $activityemoji = '<i class="fa fa-file" style="color: #06B6D4;"></i>';
            switch ($cm->modname) {
                case 'page':
                    $activityemoji = '<i class="fa fa-file-text-o" style="color: #8B5CF6;"></i>';
                    break;
                case 'book':
                    $activityemoji = '<i class="fa fa-book" style="color: #3B82F6;"></i>';
                    break;
                case 'lesson':
                    $activityemoji = '<i class="fa fa-graduation-cap" style="color: #06B6D4;"></i>';
                    break;
                case 'resource':
                    $activityemoji = '<i class="fa fa-paperclip" style="color: #8B5CF6;"></i>';
                    break;
                case 'url':
                    $activityemoji = '<i class="fa fa-link" style="color: #06B6D4;"></i>';
                    break;
                case 'folder':
                    $activityemoji = '<i class="fa fa-folder" style="color: #F59E0B;"></i>';
                    break;
                case 'scorm':
                    $activityemoji = '<i class="fa fa-cube" style="color: #3B82F6;"></i>';
                    break;
                case 'forum':
                    $activityemoji = '<i class="fa fa-comments" style="color: #06B6D4;"></i>';
                    break;
            }

            $html .= html_writer::start_tag('label', ['class' => 'hlai-activity-card']);
            $html .= html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'activityids[]',
                'value' => $cm->id,
                'class' => 'hlai-activity-checkbox',
            ]);
            $html .= html_writer::tag('span', '', ['class' => 'hlai-activity-checkmark']);
            $html .= html_writer::start_div('hlai-activity-content');
            $html .= html_writer::tag('span', $activityemoji, ['class' => 'hlai-activity-emoji']);
            $html .= html_writer::start_div('hlai-activity-text');
            $html .= html_writer::tag('span', $activityname, ['class' => 'hlai-activity-name']);
            $html .= html_writer::tag('span', $activitytype, ['class' => 'hlai-activity-type']);
            $html .= html_writer::end_div();
            $html .= html_writer::end_div();
            $html .= html_writer::end_tag('label');
        }

        $html .= html_writer::end_div(); // Hlai-activities-list.

        // Selected count indicator.
        $html .= html_writer::start_div('hlai-activities-selected-bar');
        $html .= html_writer::tag('span', '<i class="fa fa-check-circle"></i>', ['class' => 'hlai-selected-icon']);
        $html .= html_writer::tag('span', '0 activities selected', [
            'id' => 'selected-activities-count',
            'class' => 'hlai-selected-text',
        ]);
        $html .= html_writer::end_div();
    } else {
        $html .= html_writer::div(
            get_string('noactivities', 'local_hlai_quizgen'),
            'notification is-info is-light'
        );
    }

    $html .= html_writer::end_div(); // Activity-selection-section.

    // Navigation buttons.
    $html .= html_writer::start_div('level mt-6 pt-4', ['style' => 'border-top: 1px solid #dbdbdb;']);
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::link(
        new moodle_url('/course/view.php', ['id' => $courseid]),
        get_string('cancel'),
        ['class' => 'button is-light']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('level-right');
    $html .= html_writer::tag('button', get_string('next') . ' <i class="fa fa-arrow-right"></i>', [
        'type' => 'submit',
        'class' => 'button is-primary',
        'id' => 'step1-next',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Box.

    $html .= html_writer::end_tag('form');

    // Add JavaScript.
    $html .= html_writer::script("
        console.log('Wizard Step 1 JavaScript loaded');

        /**
         * Validate form before submission.
         * @return {boolean} True if valid, false otherwise
         */
        function validateForm() {
            var el = document.getElementById('source_manual');
            var manualChecked = el ? el.checked : false;
            el = document.getElementById('source_upload');
            var uploadChecked = el ? el.checked : false;
            el = document.getElementById('source_url');
            var urlChecked = el ? el.checked : false;
            el = document.getElementById('source_activities');
            var activitiesChecked = el ? el.checked : false;
            el = document.getElementById('source_scan_course');
            var scanCourseChecked = el ? el.checked : false;
            el = document.getElementById('source_scan_resources');
            var scanResourcesChecked = el ? el.checked : false;
            el = document.getElementById('source_scan_activities');
            var scanActivitiesChecked = el ? el.checked : false;

            console.log('Validating form...');
            console.log('Manual:', manualChecked, 'Upload:', uploadChecked, 'URL:', urlChecked, 'Activities:', activitiesChecked);
            console.log(
             'Bulk Scans - Course:',
             scanCourseChecked,
             'Resources:',
             scanResourcesChecked,
             'Activities:',
             scanActivitiesChecked
            );

            // Check if at least one content source is selected.
            if (!manualChecked && !uploadChecked && !urlChecked && !activitiesChecked &&
                !scanCourseChecked && !scanResourcesChecked && !scanActivitiesChecked) {
                alert('Please select at least one content source!');
                return false;
            }

            // Check content exists for selected sources.
            if (manualChecked) {
                var manualText = document.getElementById('manual_text').value.trim();
                if (manualText === '') {
                    alert('Please enter some manual text or uncheck the Manual Entry option.');
                    return false;
                }
            }

            if (uploadChecked) {
                var files = document.getElementById('content-files').files;
                if (files.length === 0) {
                    alert('Please select files to upload or uncheck the Upload Files option.');
                    return false;
                }

                // Check file sizes.
                var maxFileSizeMB = 50;
                var maxFileSizeBytes = maxFileSizeMB * 1024 * 1024;
                var oversizedFiles = [];

                Array.from(files).forEach(function(file) {
                    if (file.size > maxFileSizeBytes) {
                        oversizedFiles.push(file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)');
                    }
                });

                if (oversizedFiles.length > 0) {
                    alert('The following files exceed the maximum size of ' + maxFileSizeMB + ' MB:\\n\\n' +
                        oversizedFiles.join('\\n') + '\\n\\nPlease remove these files and try again.');
                    return false;
                }
            }

            if (urlChecked) {
                var urlList = document.getElementById('url_list').value.trim();
                if (urlList === '') {
                    alert('Please enter at least one URL or uncheck the URL option.');
                    return false;
                }
            }

            if (activitiesChecked) {
                var selectedActivities = document.querySelectorAll('.hlai-activity-checkbox:checked');
                if (selectedActivities.length === 0) {
                    alert('Please select at least one activity or uncheck the Course Activities option.');
                    return false;
                }
            }

            console.log('Validation passed!');
            return true;
        }

        /**
         * Toggle content sections based on checkbox selection.
         * @param {string} source Source identifier
         */
        function toggleContentSection(source) {
            var checkbox = document.getElementById('source_' + source);
            var section = document.getElementById('section-' + source);

            // Only toggle section visibility if section exists (bulk scans don't have UI sections).
            if (section && checkbox) {
                if (checkbox.checked) {
                    section.style.display = 'block';
                } else {
                    section.style.display = 'none';
                }
            }

            updateSelectedSources();
        }



        /**
         * Update selected sources display.
         */
        function updateSelectedSources() {
            var checkboxes = document.querySelectorAll('.content-source-checkbox:checked');
            var display = document.getElementById('selected-sources-display');
            var list = document.getElementById('selected-sources-list');

            if (checkboxes.length > 0) {
                var sources = [];
                checkboxes.forEach(function(cb) {
                    var label = document.querySelector('label[for=\"' + cb.id + '\"] strong').textContent;
                    sources.push(label);
                });
                list.textContent = sources.join(' + ');
                display.style.display = 'block';
            } else {
                display.style.display = 'none';
            }
        }

        // File input label update.
        document.getElementById('content-files').addEventListener('change', function(e) {
            var fileCount = e.target.files.length;
            var label = e.target.nextElementSibling;
            var fileList = document.getElementById('uploaded-files-list');

            // Get max file size from PHP config (50MB default).
            var maxFileSizeMB = 50;
            var maxFileSizeBytes = maxFileSizeMB * 1024 * 1024;

            var hasOversizedFiles = false;
            var oversizedFileNames = [];

            if (fileCount > 0) {
                label.innerText = fileCount + ' file(s) selected';

                var fileNames = '<div class=\"mt-2\"><strong>Selected files:</strong><ul class=\"mb-0\">';
                Array.from(e.target.files).forEach(function(file) {
                    var safeFileName = document.createElement('div');
                    safeFileName.textContent = file.name;
                    var fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                    var sizeClass = file.size > maxFileSizeBytes ? 'text-danger' : 'text-muted';

                    if (file.size > maxFileSizeBytes) {
                        hasOversizedFiles = true;
                        oversizedFileNames.push(file.name + ' (' + fileSizeMB + ' MB)');
                        fileNames += '<li class=\"text-danger\">' + safeFileName.innerHTML +
                            ' <span class=\"' + sizeClass + '\">(' + fileSizeMB +
                            ' MB) - TOO LARGE!</span></li>';
                    } else {
                        fileNames += '<li>' + safeFileName.innerHTML +
                            ' <span class=\"' + sizeClass + '\">(' + fileSizeMB + ' MB)</span></li>';
                    }
                });
                fileNames += '</ul></div>';

                if (hasOversizedFiles) {
                    fileNames += '<div class=\"notification is-danger is-light mt-2\"><strong>Error:</strong> ' +
                        'The following files exceed the maximum size of ' + maxFileSizeMB + ' MB:<ul>';
                    oversizedFileNames.forEach(function(fname) {
                        fileNames += '<li>' + fname + '</li>';
                    });
                    fileNames += '</ul>Please remove these files before submitting.</div>';
                }

                fileList.innerHTML = fileNames;
            } else {
                label.innerText = '" . get_string('choose_files', 'local_hlai_quizgen') . "';
                fileList.innerHTML = '';
            }
        });

        /**
         * Update activity count.
         */
        function updateActivityCount() {
            var checked = document.querySelectorAll('.hlai-activity-checkbox:checked').length;
            var total = document.querySelectorAll('.hlai-activity-checkbox').length;
            var countDisplay = document.getElementById('selected-activities-count');
            var selectedBar = document.querySelector('.hlai-activities-selected-bar');

            if (countDisplay) {
                var text = checked === 1 ? '1 activity selected' : checked + ' activities selected';
                countDisplay.textContent = text;
            }

            // Update the selected bar visibility/style.
            if (selectedBar) {
                if (checked > 0) {
                    selectedBar.classList.add('has-selection');
                } else {
                    selectedBar.classList.remove('has-selection');
                }
            }

            // Update card selected states.
            document.querySelectorAll('.hlai-activity-checkbox').forEach(function(cb) {
                var card = cb.closest('.hlai-activity-card');
                if (card) {
                    if (cb.checked) {
                        card.classList.add('is-selected');
                    } else {
                        card.classList.remove('is-selected');
                    }
                }
            });
        }

        // Select All button handler.
        var selectAllBtn = document.getElementById('select-all-activities');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                document.querySelectorAll('.hlai-activity-checkbox').forEach(function(cb) {
                    cb.checked = true;
                });
                updateActivityCount();
            });
        }

        // Deselect All button handler.
        var deselectAllBtn = document.getElementById('deselect-all-activities');
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function() {
                document.querySelectorAll('.hlai-activity-checkbox').forEach(function(cb) {
                    cb.checked = false;
                });
                updateActivityCount();
            });
        }

        // Add activity count listener.
        document.querySelectorAll('.hlai-activity-checkbox').forEach(function(cb) {
            cb.addEventListener('change', updateActivityCount);
        });

        // Initial count.
        updateActivityCount();
    ");

    return $html;
}

/**
 * Render step 2: Topic configuration.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function render_step2(int $courseid, int $requestid): string {
    global $DB, $PAGE, $CFG;

    // Validate request ID - redirect to Step 1 if invalid.
    if ($requestid === 0) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'step' => 1,
            ]),
            'Please start by selecting content in Step 1.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
        return '';
    }

    $html = html_writer::start_div('hlai-step-content');
    $html .= html_writer::tag('h2', get_string('step2_title', 'local_hlai_quizgen'), ['class' => 'title is-4 mb-0']);
    $html .= html_writer::tag('p', get_string('step2_description', 'local_hlai_quizgen'), [
        'class' => 'subtitle is-6 has-text-grey mt-1 mb-4',
    ]);
    $html .= html_writer::tag('hr', '', ['class' => 'mt-0 mb-5']);

    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

    // If the request is still "pending" (e.g., after a reset), bump it into "analyzing".
    // So the synchronous analysis path below can run on this page load.
    if ($request->status === 'pending') {
        try {
            \local_hlai_quizgen\api::update_request_status($requestid, 'analyzing');
            $request->status = 'analyzing';
        } catch (\Throwable $e) {
            $html .= html_writer::div(
                'Could not update status to analyzing: ' . $e->getMessage(),
                'notification is-danger'
            );
        }
    }

    // Check if we need to analyze content.
    if ($request->status === 'analyzing') {
        $allcontent = '';

        // Get manual text from custom_instructions field.
        if (!empty($request->custom_instructions)) {
            $allcontent .= $request->custom_instructions . "\n\n";
        }

        // Get uploaded files from file storage.
        $fs = get_file_storage();
        $context = context_course::instance($courseid);
        $files = $fs->get_area_files($context->id, 'local_hlai_quizgen', 'content', $requestid, 'filename', false);

        if (!empty($files)) {
            foreach ($files as $file) {
                $filepath = $file->copy_content_to_temp();
                $filename = $file->get_filename();

                try {
                    // Pass original filename to extract_from_file so it can detect the correct extension.
                    $result = \local_hlai_quizgen\content_extractor::extract_from_file($filepath, $filename);
                    $filecontent = $result['text'];

                    if (!empty($filecontent)) {
                        $wordcount = $result['word_count'];
                        $allcontent .= "Content from $filename ($wordcount words):\n" . $filecontent . "\n\n";
                    }
                } catch (Exception $e) {
                    // Silently skip files that fail to extract.
                    debugging($e->getMessage(), DEBUG_DEVELOPER);
                } finally {
                    // Clean up temp file.
                    if (file_exists($filepath)) {
                        @unlink($filepath);
                    }
                }
            }
        }

        // Get content from selected activities or bulk scans.
        if (!empty($request->content_sources)) {
            $sources = json_decode($request->content_sources, true);
            foreach ($sources as $source) {
                // Handle individual activity selection.
                if (strpos($source, 'course_activities:') === 0) {
                    $activityidsstr = substr($source, strlen('course_activities:'));
                    $activityids = array_map('intval', explode(',', $activityidsstr));

                    if (!empty($activityids)) {
                        try {
                            $activitycontent = \local_hlai_quizgen\content_extractor::extract_from_activities(
                                $courseid,
                                $activityids
                            );
                            if (!empty(trim($activitycontent))) {
                                $allcontent .= $activitycontent . "\n\n";
                            }
                        } catch (Exception $e) {
                            // Silently skip failed activity extraction.
                            debugging($e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                    // Handle bulk scan entire course.
                } else if ($source === 'bulk_scan:entire_course') {
                    try {
                        $scanner = new \local_hlai_quizgen\course_scanner();
                        $scanresult = $scanner::scan_entire_course($courseid);

                        if (!empty(trim($scanresult['text']))) {
                            $allcontent .= $scanresult['text'] . "\n\n";
                        }
                    } catch (Exception $e) {
                        $html .= '<div class="notification is-danger is-light">Scan Entire Course failed: ' .
                            htmlspecialchars($e->getMessage()) .
                            '</div>';
                    }
                    // Handle bulk scan all resources.
                } else if ($source === 'bulk_scan:all_resources') {
                    try {
                        $scanner = new \local_hlai_quizgen\course_scanner();
                        $scanresult = $scanner::scan_all_resources($courseid);

                        if (!empty(trim($scanresult['text']))) {
                            $allcontent .= $scanresult['text'] . "\n\n";
                        }
                    } catch (Exception $e) {
                        $html .= '<div class="notification is-danger is-light">Scan All Resources failed: ' .
                            htmlspecialchars($e->getMessage()) .
                            '</div>';
                    }
                    // Handle bulk scan all activities.
                } else if ($source === 'bulk_scan:all_activities') {
                    try {
                        $scanner = new \local_hlai_quizgen\course_scanner();
                        $scanresult = $scanner::scan_all_activities($courseid);

                        if (!empty(trim($scanresult['text']))) {
                            $allcontent .= $scanresult['text'] . "\n\n";
                        }
                    } catch (Exception $e) {
                        $html .= '<div class="notification is-danger is-light">Bulk scan failed: ' .
                            htmlspecialchars($e->getMessage()) .
                            '</div>';
                    }
                }
            }
        }

        // Analyze all content if we have any.
        if (!empty(trim($allcontent))) {
            // Memory safety: Limit content size to prevent memory exhaustion.
            $maxcontentsize = 10 * 1024 * 1024; // 10MB maximum.
            $contentsize = strlen($allcontent);
            $wastruncated = false;
            if ($contentsize > $maxcontentsize) {
                $allcontent = substr($allcontent, 0, $maxcontentsize);
                $wastruncated = true;
            }

            try {
                $analyzer = new \local_hlai_quizgen\topic_analyzer();
                $topics = $analyzer->analyze_content($allcontent, $requestid);

                // Update request status to topics_ready.
                \local_hlai_quizgen\api::update_request_status($requestid, 'topics_ready');
            } catch (Exception $e) {
                $html .= html_writer::div(
                    get_string('error:analysisfailed', 'local_hlai_quizgen') . ': ' . $e->getMessage(),
                    'notification is-danger'
                );
            }
        } else {
            $html .= html_writer::div(
                get_string('error:nocontent', 'local_hlai_quizgen'),
                'notification is-danger'
            );
        }
    }

    // Display topics if available.
    $topics = $DB->get_records('local_hlai_quizgen_topics', ['requestid' => $requestid], 'level ASC, id ASC');

    // DEDUPLICATION: Remove duplicate topics for display (and fix titles).
    if (!empty($topics)) {
        $seentitles = [];
        $uniquetopics = [];
        $duplicateids = [];

        foreach ($topics as $topic) {
            // Clean the title.
            $cleanedtitle = \local_hlai_quizgen\topic_analyzer::clean_topic_title_public($topic->title);
            $normalizedtitle = strtolower(trim($cleanedtitle));

            if (isset($seentitles[$normalizedtitle])) {
                // This is a duplicate - mark for removal.
                $duplicateids[] = $topic->id;
                continue;
            }

            // Update the title to cleaned version.
            if ($topic->title !== $cleanedtitle) {
                $topic->title = $cleanedtitle;
                $DB->set_field('local_hlai_quizgen_topics', 'title', $cleanedtitle, ['id' => $topic->id]);
            }

            $seentitles[$normalizedtitle] = $cleanedtitle;
            $uniquetopics[$topic->id] = $topic;
        }

        // Delete duplicate topics from database (cleanup).
        if (!empty($duplicateids)) {
            foreach ($duplicateids as $dupid) {
                $DB->delete_records('local_hlai_quizgen_topics', ['id' => $dupid]);
            }
        }

        $topics = $uniquetopics;
    }

    if (!empty($topics)) {
        $formurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'action' => 'save_topic_selection',
        ]);

        $html .= html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $formurl->out(false),
            'id' => 'topic-selection-form',
        ]);

        $html .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);

        // Topics section with polished design.
        $html .= html_writer::start_div('hlai-topics-section');

        // Section header with icon.
        $html .= html_writer::start_div('hlai-topics-header');
        $html .= html_writer::tag('span', '<i class="fa fa-book" style="color: #10B981;"></i>', ['class' => 'hlai-topics-icon']);
        $html .= html_writer::start_div('hlai-topics-header-text');
        $html .= html_writer::tag('h3', get_string('topics_found', 'local_hlai_quizgen'), ['class' => 'hlai-topics-title']);
        $html .= html_writer::tag('p', get_string('topics_select_help', 'local_hlai_quizgen'), ['class' => 'hlai-topics-subtitle']);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();

        // Action bar with buttons and count.
        $html .= html_writer::start_div('hlai-topics-actions');
        $html .= html_writer::start_div('hlai-topics-buttons');
        $html .= html_writer::tag('button', '<i class="fa fa-check"></i> Select All', [
            'type' => 'button',
            'id' => 'select-all-topics',
            'class' => 'hlai-action-btn hlai-action-select',
        ]);
        $html .= html_writer::tag('button', '<i class="fa fa-times"></i> Deselect All', [
            'type' => 'button',
            'id' => 'deselect-all-topics',
            'class' => 'hlai-action-btn hlai-action-deselect',
        ]);
        $html .= html_writer::end_div();
        $html .= html_writer::tag('span', count($topics) . ' topics discovered', [
            'class' => 'hlai-topics-count',
            'id' => 'topics-count-display',
        ]);
        $html .= html_writer::end_div();

        // Topics list with cards.
        $html .= html_writer::start_div('hlai-topics-list');
        foreach ($topics as $index => $topic) {
            $html .= html_writer::start_tag('label', ['class' => 'hlai-topic-card']);
            $html .= html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'topics[]',
                'value' => $topic->id,
                'class' => 'hlai-topic-checkbox',
            ]);
            $html .= html_writer::tag('span', '', ['class' => 'hlai-topic-checkmark']);
            $html .= html_writer::start_div('hlai-topic-content');
            $html .= html_writer::tag('span', '<i class="fa fa-book" style="color: #3B82F6;"></i>', [
                'class' => 'hlai-topic-emoji',
            ]);
            $html .= html_writer::start_div('hlai-topic-text');
            $html .= html_writer::tag('span', format_string($topic->title), ['class' => 'hlai-topic-title']);
            if (!empty($topic->description)) {
                $html .= html_writer::tag('p', format_text($topic->description), ['class' => 'hlai-topic-description']);
            }
            $html .= html_writer::end_div();
            $html .= html_writer::end_div();
            $html .= html_writer::end_tag('label');
        }
        $html .= html_writer::end_div(); // Hlai-topics-list.

        // Selected count indicator.
        $html .= html_writer::start_div('hlai-topics-selected-bar');
        $html .= html_writer::tag('span', '<i class="fa fa-check-circle"></i>', ['class' => 'hlai-selected-icon']);
        $html .= html_writer::tag('span', '0 topics selected', ['id' => 'selected-topics-count', 'class' => 'hlai-selected-text']);
        $html .= html_writer::end_div();

        $html .= html_writer::end_div(); // Hlai-topics-section.

        // Navigation.
        $html .= html_writer::start_div('level mt-6 pt-4', ['style' => 'border-top: 1px solid #dbdbdb;']);
        $html .= html_writer::start_div('level-left');
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 1]),
            '<i class="fa fa-arrow-left" style="color: #06B6D4;"></i> ' . get_string('previous'),
            ['class' => 'button is-light']
        );
        $html .= html_writer::end_div();
        $html .= html_writer::start_div('level-right');
        $html .= html_writer::tag('button', get_string('next') . ' <i class="fa fa-arrow-right" style="color: #FFFFFF;"></i>', [
            'type' => 'submit',
            'class' => 'button is-primary',
        ]);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();

        $html .= html_writer::end_div(); // Hlai-card-content.
        $html .= html_writer::end_div(); // Hlai-card.

        $html .= html_writer::end_tag('form');

        // JavaScript for Select All / Deselect All buttons and selected count.
        $PAGE->requires->js_amd_inline("
            require(['jquery'], function($) {
                /**
                 * Update selected count.
                 */
                function updateSelectedCount() {
                    var count = $('input.hlai-topic-checkbox:checked').length;
                    var text = count === 1 ? '1 topic selected' : count + ' topics selected';
                    $('#selected-topics-count').text(text);

                    // Update the selected bar visibility/style.
                    if (count > 0) {
                        $('.hlai-topics-selected-bar').addClass('has-selection');
                    } else {
                        $('.hlai-topics-selected-bar').removeClass('has-selection');
                    }

                    // Update card selected states.
                    $('input.hlai-topic-checkbox').each(function() {
                        if ($(this).prop('checked')) {
                            $(this).closest('.hlai-topic-card').addClass('is-selected');
                        } else {
                            $(this).closest('.hlai-topic-card').removeClass('is-selected');
                        }
                    });
                }

                $('#select-all-topics').on('click', function() {
                    $('input.hlai-topic-checkbox').prop('checked', true);
                    updateSelectedCount();
                });
                $('#deselect-all-topics').on('click', function() {
                    $('input.hlai-topic-checkbox').prop('checked', false);
                    updateSelectedCount();
                });

                // Update on individual checkbox change.
                $(document).on('change', 'input.hlai-topic-checkbox', function() {
                    updateSelectedCount();
                });

                // Initial count on page load.
                updateSelectedCount();
            });
        ");
    } else {
        $html .= html_writer::div(
            html_writer::tag('span', '<i class="fa fa-hourglass-2" style="color: #F59E0B;"></i>', [
                'style' => 'font-size: 1.5rem; margin-right: 0.5rem;',
            ]) .
            get_string('analyzing_content', 'local_hlai_quizgen'),
            'notification is-info'
        );

        // Auto-refresh after 3 seconds.
        $PAGE->requires->js_amd_inline("
            setTimeout(function() {
                window.location.reload();
            }, 3000);
        ");
    }

    // Footer spacing to prevent overlap with Moodle footer.
    $html .= html_writer::div('', '', ['style' => 'height: 60px;']);

    $html .= html_writer::end_div(); // Box.

    return $html;
}

/**
 * Render step 3: Question parameters.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function render_step3(int $courseid, int $requestid): string {
    global $DB, $CFG;

    // Validate request ID - redirect to Step 1 if invalid.
    if ($requestid === 0) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'step' => 1,
            ]),
            'Please start by selecting content in Step 1.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
        return '';
    }

    $html = html_writer::start_div('hlai-step-content hlai-step3-minimal');
    $html .= html_writer::tag('h2', get_string('step3_title', 'local_hlai_quizgen'), [
        'class' => 'title is-4 mb-0 hlai-step3-title',
    ]);
    $html .= html_writer::tag('p', get_string('step3_description', 'local_hlai_quizgen'), [
        'class' => 'subtitle is-6 has-text-grey mt-1 mb-4 hlai-step3-subtitle',
    ]);
    $html .= html_writer::tag('hr', '', ['class' => 'mt-0 mb-5 hlai-step3-divider']);

    // Count selected topics.
    $selectedtopics = $DB->get_records('local_hlai_quizgen_topics', ['requestid' => $requestid, 'selected' => 1]);

    if (empty($selectedtopics)) {
        $html .= html_writer::div(
            'No topics selected. Please go back to Step 2 and select topics.',
            'notification is-warning'
        );
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 2]),
            '<i class="fa fa-arrow-left" style="color: #06B6D4;"></i> Back to Step 2',
            ['class' => 'button is-primary mt-4']
        );
        $html .= html_writer::end_div();
        return $html;
    }

    // Minimal topics info bar.
    $topicnames = array_map(function ($t) {
        return $t->title;
    }, $selectedtopics);
    $topicspreview = implode(', ', array_slice($topicnames, 0, 3));
    if (count($topicnames) > 3) {
        $topicspreview .= ' +' . (count($topicnames) - 3) . ' more';
    }
    $html .= html_writer::div(
        html_writer::tag(
            'span',
            '<i class="fa fa-info-circle" style="color: #06B6D4;"></i> ',
            ['class' => 'hlai-info-icon']
        ) .
        html_writer::tag(
            'span',
            count($selectedtopics) . ' topics selected: ',
            ['class' => 'hlai-info-label']
        ) .
        html_writer::tag('span', $topicspreview, ['class' => 'hlai-info-topics']),
        'hlai-topics-info-bar'
    );

    $formurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'action' => 'generate_questions',
    ]);

    $html .= html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'id' => 'question-config-form',
        'onsubmit' => 'return validateQuestionConfig();',
    ]);

    $html .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);

    $html .= html_writer::start_div('hlai-config-grid');

    // TOTAL QUESTIONS - Compact card.
    $html .= html_writer::start_div('hlai-config-card');
    $html .= html_writer::tag('label', 'Total Questions', ['class' => 'hlai-config-label', 'for' => 'total-questions']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'total_questions',
        'id' => 'total-questions',
        'class' => 'hlai-config-input',
        'min' => '1',
        'max' => '100',
        'value' => '10',
        'required' => 'required',
        'oninput' => 'updateQuestionTypeDistribution()',
    ]);
    $html .= html_writer::end_div();

    // Difficulty - Compact card.
    $html .= html_writer::start_div('hlai-config-card');
    $html .= html_writer::tag('label', 'Difficulty', ['class' => 'hlai-config-label']);
    $html .= html_writer::start_div('hlai-diff-buttons');
    $diffoptions = [
        'easy_only' => ['label' => 'Easy', 'class' => 'is-easy'],
        'balanced' => ['label' => 'Balanced', 'class' => 'is-balanced'],
        'hard_only' => ['label' => 'Hard', 'class' => 'is-hard'],
    ];
    foreach ($diffoptions as $val => $info) {
        $checked = ($val === 'balanced');
        $btnclass = 'hlai-diff-btn ' . $info['class'] . ($checked ? ' is-active' : '');
        $html .= html_writer::start_tag('label', ['class' => $btnclass]);
        $html .= html_writer::empty_tag('input', [
            'type' => 'radio',
            'name' => 'difficulty',
            'value' => $val,
            'checked' => $checked ? 'checked' : null,
            'onchange' => 'updateDiffButtons(this)',
        ]);
        $html .= html_writer::tag('span', $info['label']);
        $html .= html_writer::end_tag('label');
    }
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Hlai-config-grid.

    // Question Types - Visual card layout.
    $html .= html_writer::start_div('hlai-section hlai-section-qtypes mt-5');
    $html .= html_writer::start_div('hlai-section-header');
    $html .= html_writer::tag('span', '<i class="fa fa-edit" style="color: #3B82F6;"></i>', ['class' => 'hlai-section-icon']);
    $html .= html_writer::start_div('hlai-section-title-wrap');
    $html .= html_writer::tag('h4', 'Question Types', ['class' => 'hlai-section-label']);
    $html .= html_writer::tag('p', 'Specify count for each type (must total to questions above)', ['class' => 'hlai-section-hint']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $questiontypes = [
        'multichoice' => ['label' => 'Multiple Choice', 'icon' => '<i class="fa fa-dot-circle-o"></i>', 'color' => '#3B82F6'],
        'truefalse' => ['label' => 'True/False', 'icon' => '<i class="fa fa-check"></i>', 'color' => '#10B981'],
        'shortanswer' => ['label' => 'Short Answer', 'icon' => '<i class="fa fa-pencil"></i>', 'color' => '#F59E0B'],
        'essay' => ['label' => 'Essay', 'icon' => '<i class="fa fa-file-text-o"></i>', 'color' => '#64748B'],
        'scenario' => ['label' => 'Scenario-based', 'icon' => '<i class="fa fa-bullseye"></i>', 'color' => '#EF4444'],
    ];

    $html .= html_writer::start_div('hlai-qtype-list');
    foreach ($questiontypes as $type => $info) {
        $html .= html_writer::start_div('hlai-qtype-row', ['data-type' => $type]);
        $html .= html_writer::start_div('hlai-qtype-left', ['style' => 'display: flex; align-items: center;']);
        $iconstyle = 'background: ' . $info['color'] . '15; color: ' . $info['color'] . '; ' .
            'display: flex; align-items: center; justify-content: center;';
        $html .= html_writer::tag('span', $info['icon'], ['class' => 'hlai-qtype-icon', 'style' => $iconstyle]);
        $html .= html_writer::tag(
            'span',
            $info['label'],
            ['class' => 'hlai-qtype-name', 'style' => 'display: flex; align-items: center;']
        );
        $html .= html_writer::end_div();
        $html .= html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'qtype_count[' . $type . ']',
            'id' => 'qtype_count_' . $type,
            'class' => 'hlai-qtype-input',
            'min' => '0',
            'max' => '100',
            'value' => '0',
            'oninput' => 'updateQuestionTypeTotal()',
        ]);
        $html .= html_writer::end_div();
    }
    $html .= html_writer::start_div('hlai-qtype-row hlai-qtype-total');
    $html .= html_writer::tag('span', 'Total', ['class' => 'hlai-qtype-name']);
    $html .= html_writer::tag('span', '0 / 10', ['id' => 'qtype-total-display', 'class' => 'hlai-qtype-total-val']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div(); // Hlai-qtype-list.
    $html .= html_writer::end_div(); // Hlai-section.

    // Bloom's Taxonomy - Visual with colored levels.
    $html .= html_writer::start_div('hlai-section hlai-section-blooms mt-5');
    $html .= html_writer::start_div('hlai-section-header');
    $html .= html_writer::tag('span', '<i class="fa fa-lightbulb-o" style="color: #3B82F6;"></i>', [
        'class' => 'hlai-section-icon',
    ]);
    $html .= html_writer::start_div('hlai-section-title-wrap');
    $html .= html_writer::tag('h4', "Bloom's Taxonomy", ['class' => 'hlai-section-label']);
    $html .= html_writer::tag('p', 'Cognitive level distribution (should total 100%)', ['class' => 'hlai-section-hint']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $bloomslevels = [
        'remember' => ['label' => 'Remember', 'default' => 20, 'color' => '#EF4444', 'desc' => 'Recall facts'],
        'understand' => ['label' => 'Understand', 'default' => 25, 'color' => '#F59E0B', 'desc' => 'Explain concepts'],
        'apply' => ['label' => 'Apply', 'default' => 25, 'color' => '#10B981', 'desc' => 'Use knowledge'],
        'analyze' => ['label' => 'Analyze', 'default' => 15, 'color' => '#3B82F6', 'desc' => 'Break down'],
        'evaluate' => ['label' => 'Evaluate', 'default' => 10, 'color' => '#8B5CF6', 'desc' => 'Make judgments'],
        'create' => ['label' => 'Create', 'default' => 5, 'color' => '#EC4899', 'desc' => 'Produce new'],
    ];

    $html .= html_writer::start_div('hlai-blooms-list');
    foreach ($bloomslevels as $level => $info) {
        $sliderfill = $info['default'];
        $html .= html_writer::start_div('hlai-blooms-item', ['data-level' => $level]);
        $html .= html_writer::start_div('hlai-blooms-label-wrap', ['style' => 'display: flex; align-items: center;']);
        $html .= html_writer::tag('span', '', [
            'class' => 'hlai-blooms-dot',
            'style' => 'background: ' . $info['color'] . '; display: flex; align-items: center;',
        ]);
        $html .= html_writer::tag('span', $info['label'], [
            'class' => 'hlai-blooms-name',
            'style' => 'display: flex; align-items: center;',
        ]);
        $html .= html_writer::end_div();
        $html .= html_writer::start_div('hlai-blooms-control');
        $html .= html_writer::empty_tag('input', [
            'type' => 'range',
            'name' => 'blooms_' . $level,
            'id' => 'blooms_' . $level,
            'min' => '0',
            'max' => '100',
            'value' => $info['default'],
            'class' => 'hlai-slider',
            'data-color' => $info['color'],
            'style' => '--slider-color: ' . $info['color'] . '; background: linear-gradient(to right, ' .
                $info['color'] . ' 0%, ' . $info['color'] . ' ' . $sliderfill . '%, ' .
                '#e2e8f0 ' . $sliderfill . '%, #e2e8f0 100%);',
            'oninput' => 'updateSliderColor(this); updateBloomsTotal();',
        ]);
        $html .= html_writer::tag('span', $info['default'] . '%', [
            'id' => 'blooms_' . $level . '_value',
            'class' => 'hlai-slider-val',
            'style' => 'color: ' . $info['color'],
        ]);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
    }
    $html .= html_writer::start_div('hlai-blooms-item hlai-blooms-total-row');
    $html .= html_writer::tag('span', 'Total', ['class' => 'hlai-blooms-name']);
    $html .= html_writer::tag('span', '100%', ['id' => 'blooms_total', 'class' => 'hlai-blooms-total-val']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div(); // Hlai-blooms-list.
    $html .= html_writer::end_div(); // Hlai-section.

    // Processing mode removed (global setting).
    // Cost preview section hidden per user request.

    // Minimal JavaScript for Step 3.
    $html .= html_writer::script("
        /**
         * Update difficulty buttons.
         * @param {HTMLElement} radio Radio button element.
         */
        function updateDiffButtons(radio) {
            document.querySelectorAll('.hlai-diff-btn').forEach(function(btn) {
                btn.classList.remove('is-active');
            });
            radio.parentElement.classList.add('is-active');
        }

        /**
         * Update slider color.
         * @param {HTMLElement} slider Slider element.
         */
        function updateSliderColor(slider) {
            var value = slider.value;
            var id = slider.id;
            var color = slider.dataset.color || '#6366f1';
            var valueEl = document.getElementById(id + '_value');
            if (valueEl) valueEl.textContent = value + '%';
            slider.style.background = 'linear-gradient(to right, ' + color + ' 0%, ' + color +
                ' ' + value + '%, #e2e8f0 ' + value + '%, #e2e8f0 100%)';
        }

        /**
         * Update Blooms total.
         */
        function updateBloomsTotal() {
            var levels = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
            var total = 0;

            levels.forEach(function(level) {
                var slider = document.getElementById('blooms_' + level);
                if (slider) {
                    total += parseInt(slider.value);
                }
            });

            var totalElem = document.getElementById('blooms_total');
            if (totalElem) {
                totalElem.textContent = total + '%';

                // Update color based on total.
                if (total === 100) {
                    totalElem.style.color = '#10b981';
                } else if (total > 80 && total < 120) {
                    totalElem.style.color = '#f59e0b';
                } else {
                    totalElem.style.color = '#ef4444';
                }
            }
        }
    ");

    // Navigation.
    $html .= html_writer::start_div('level mt-5');
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::link(
        new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 2]),
        '<i class="fa fa-arrow-left" style="color: #06B6D4;"></i> ' . get_string('previous'),
        ['class' => 'button is-light']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('level-right');
    $html .= html_writer::tag('button', '<i class="fa fa-rocket" style="color: #FFFFFF;"></i> ' .
        get_string('generate_questions', 'local_hlai_quizgen'), [
        'type' => 'submit',
        'class' => 'button is-primary',
        'id' => 'generate-btn',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_tag('form');

    // Loading overlay.
    $html .= html_writer::start_div('hlai-generating-overlay', [
        'id' => 'loading-overlay',
        'style' => 'display:none;',
    ]);
    $html .= html_writer::start_div('hlai-generating-modal');
    $html .= html_writer::div('', 'loader');
    $html .= html_writer::tag('h3', '<i class="fa fa-lightbulb-o" style="color: #3B82F6;"></i> Generating Questions...', [
        'class' => 'generating-title',
    ]);
    $html .= html_writer::tag('p', 'Please wait while AI generates your questions. This may take 30-90 seconds.', [
        'class' => 'generating-subtitle',
    ]);
    $html .= html_writer::tag('p', 'Do not close this window or press the back button.', ['class' => 'generating-warning']);
    $html .= html_writer::end_div(); // Hlai-generating-modal.
    $html .= html_writer::end_div(); // Hlai-generating-overlay.

    // Add JavaScript to validate question type totals and show loading overlay.
    $html .= html_writer::script("
        console.log('Quiz Gen: Loading JavaScript initialized');

        var configForm = document.getElementById('question-config-form');
        var generateBtn = document.getElementById('generate-btn');
        var loadingOverlay = document.getElementById('loading-overlay');
        var totalInput = document.getElementById('total-questions');
        var isSubmitting = false;

        console.log('Quiz Gen: Form element:', configForm);
        console.log('Quiz Gen: Button element:', generateBtn);
        console.log('Quiz Gen: Overlay element:', loadingOverlay);

        /**
         * Update question type distribution.
         */
        function updateQuestionTypeDistribution() {
            var total = parseInt(totalInput.value) || 10;
            var displayEl = document.getElementById('qtype-total-display');
            if (displayEl) {
                var parts = displayEl.textContent.split('/');
                displayEl.textContent = (parts[0] ? parts[0].trim() : '0') + ' / ' + total;
            }

            // Update max values for all inputs.
            document.querySelectorAll('.hlai-qtype-input').forEach(function(input) {
                input.max = total;
            });

            updateQuestionTypeTotal();
        }

        /**
         * Update question type total.
         */
        function updateQuestionTypeTotal() {
            var totalRequired = parseInt(totalInput.value) || 10;
            var total = 0;
            document.querySelectorAll('.hlai-qtype-input').forEach(function(input) {
                var val = parseInt(input.value) || 0;
                total += val;
                // Add/remove has-value class for visual feedback.
                if (val > 0) {
                    input.classList.add('has-value');
                } else {
                    input.classList.remove('has-value');
                }
            });

            var displayEl = document.getElementById('qtype-total-display');
            if (displayEl) {
                displayEl.textContent = total + ' / ' + totalRequired;
                displayEl.classList.remove('is-valid', 'is-warning', 'is-error');
                if (total === totalRequired) {
                    displayEl.classList.add('is-valid');
                } else if (total > totalRequired) {
                    displayEl.classList.add('is-error');
                } else {
                    displayEl.classList.add('is-warning');
                }
            }
        }

        // Initialize total display on page load.
        updateQuestionTypeTotal();

        /**
         * Validate question configuration.
         * @return {boolean} True if valid, false otherwise.
         */
        function validateQuestionConfig() {
            var totalRequired = parseInt(document.getElementById('total-questions').value) || 0;
            var total = 0;
            document.querySelectorAll('.hlai-qtype-input').forEach(function(input) {
                total += parseInt(input.value) || 0;
            });

            if (total === 0) {
                alert('Please specify at least one question type with a quantity greater than 0.');
                return false;
            }

            if (total !== totalRequired) {
                alert('Question type total (' + total + ') must equal total questions (' +
                    totalRequired + '). Please adjust the values.');
                return false;
            }

            return true;
        }

        if (configForm) {
            console.log('Quiz Gen: Attaching submit handler');
            configForm.addEventListener('submit', function(e) {
                console.log('Quiz Gen: Form submitted!');

                var totalRequired = parseInt(totalInput.value) || 10;

                // Validate question type totals.
                var total = 0;
                document.querySelectorAll('.hlai-qtype-input').forEach(function(input) {
                    total += parseInt(input.value) || 0;
                });

                if (total === 0) {
                    e.preventDefault();
                    alert('Please specify at least one question type with a quantity greater than 0.');
                    return false;
                }

                if (total !== totalRequired) {
                    e.preventDefault();
                    alert('Question type total (' + total + ') must equal total questions (' + totalRequired + ').');
                    return false;
                }

                // Prevent double submission.
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }

                isSubmitting = true;
                console.log('Quiz Gen: Showing loading overlay...');

                // Show loading overlay.
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'flex';
                    console.log('Quiz Gen: Overlay displayed');
                } else {
                    console.error('Quiz Gen: Loading overlay element not found!');
                }

                // Disable button.
                if (generateBtn) {
                    generateBtn.disabled = true;
                    generateBtn.textContent = 'Generating...';
                }
            });
        }
    ");

    return $html;
}

/**
 * Render step 3.5: Progress monitoring.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function render_step3_5(int $courseid, int $requestid): string {
    global $DB, $PAGE;

    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

    // Check if already completed - redirect to step 4.
    if ($request->status === 'completed') {
        redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'step' => 4,
        ]));
    }

    // Check if failed - show error.
    if ($request->status === 'failed') {
        $html = html_writer::tag('h3', get_string('generating_questions', 'local_hlai_quizgen'));
        $html .= html_writer::div(
            'Generation failed: ' . s($request->error_message ?? 'Unknown error'),
            'notification is-danger is-light mt-4'
        );
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'step' => 1]),
            '<i class="fa fa-refresh" style="color: #06B6D4;"></i> Start Over',
            ['class' => 'button is-primary mt-3']
        );
        return $html;
    }

    // Calculate progress from database.
    $progress = (int)($request->progress ?? 0);
    $statusmessage = $request->status_message ?? 'Processing...';

    $html = html_writer::tag('h3', get_string('generating_questions', 'local_hlai_quizgen'), ['class' => 'title is-5']);
    $html .= html_writer::tag('p', get_string('generating_questions_desc', 'local_hlai_quizgen'), ['class' => 'has-text-grey']);

    // Progress bar container.
    $html .= html_writer::start_div('progress-monitor mt-4');

    // Progress bar.
    $html .= html_writer::tag('progress', $progress . '%', [
        'id' => 'progress-bar',
        'class' => 'progress is-primary',
        'value' => $progress,
        'max' => '100',
        'aria-valuenow' => $progress,
        'aria-valuemin' => '0',
        'aria-valuemax' => '100',
    ]);

    // Progress message.
    $html .= html_writer::tag('p', s($statusmessage), [
        'id' => 'progress-message',
        'class' => 'has-text-centered mt-3',
    ]);

    $html .= html_writer::end_div();

    // Auto-refresh using JavaScript (simpler than AJAX, no external endpoint needed).
    $refreshurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'step' => '3.5',
    ]);
    $html .= html_writer::script("
        setTimeout(function() {
            window.location.href = '" . $refreshurl->out(false) . "';
        }, 2000);
    ");

    return $html;
}

/**
 * Render step 4: Review & edit.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function render_step4(int $courseid, int $requestid): string {
    global $DB, $PAGE;

    // Validate request ID - redirect to Step 1 if invalid.
    if ($requestid === 0) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'step' => 1,
            ]),
            'Please start by selecting content in Step 1.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
        return '';
    }

    $html = html_writer::start_div('hlai-step-content');

    // Header.
    $html .= html_writer::tag(
        'h2',
        '<i class="fa fa-clipboard" style="color: #3B82F6;"></i> ' .
        get_string('step4_title', 'local_hlai_quizgen'),
        ['class' => 'title is-4 mb-2']
    );
    $html .= html_writer::tag(
        'p',
        get_string('step4_description', 'local_hlai_quizgen'),
        ['class' => 'subtitle is-6 has-text-grey mb-4']
    );
    $html .= html_writer::tag('hr', '', ['class' => 'mt-0 mb-6']);

    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

    // Check request status.
    if ($request->status === 'pending') {
        // Overlay for centered loading screen.
        $html .= html_writer::start_div('hlai-generating-overlay');
        $html .= html_writer::start_div('hlai-generating-modal');

        // Spinner styled by CSS class .hlai-generating-modal .loader.
        $html .= html_writer::div('', 'loader');

        $html .= html_writer::tag('h3', '<i class="fa fa-lightbulb-o" style="color: #3B82F6;"></i> Generating Questions...', [
            'class' => 'title is-5 mb-2',
        ]);
        $html .= html_writer::tag('p', 'Please wait while AI generates your questions. This may take 30-90 seconds.', [
            'class' => 'has-text-grey is-size-6 mb-4',
        ]);
        $html .= html_writer::tag('p', 'Do not close this window or press the back button.', [
            'class' => 'has-text-warning-dark is-size-7 has-text-weight-bold',
        ]);

        $html .= html_writer::end_div(); // Hlai-generating-modal.
        $html .= html_writer::end_div(); // Hlai-generating-overlay.

        // Auto-refresh after 5 seconds.
        $PAGE->requires->js_amd_inline("
            setTimeout(function() {
                window.location.reload();
            }, 5000);
        ");

        $html .= html_writer::end_div(); // Box.
        return $html;
    }

    if ($request->status === 'failed') {
        $html .= html_writer::div(
            get_string('generation_failed', 'local_hlai_quizgen') . ': ' . $request->error_message,
            'notification is-danger'
        );

        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 3]),
            '<i class="fa fa-refresh"></i> ' . get_string('retry', 'local_hlai_quizgen'),
            ['class' => 'button is-warning mt-3']
        );

        $html .= html_writer::end_div(); // Box.
        return $html;
    }

    // Get generated questions.
    $questions = $DB->get_records('local_hlai_quizgen_questions', ['requestid' => $requestid], 'id ASC');

    if (empty($questions)) {
        $html .= html_writer::div(
            get_string('no_questions_generated', 'local_hlai_quizgen'),
            'notification is-warning'
        );
        $html .= html_writer::end_div(); // Box.
        return $html;
    }

    // Calculate status counts.
    $approvedcount = 0;
    $pendingcount = 0;
    $rejectedcount = 0;
    foreach ($questions as $q) {
        if ($q->status === 'approved') {
            $approvedcount++;
        } else if ($q->status === 'rejected') {
            $rejectedcount++;
        } else {
            $pendingcount++;
        }
    }

    // Display inline stats - Improved typography and spacing with Cards for better visual.
    $html .= html_writer::start_div('columns is-mobile is-multiline mb-6');

    // Total Card.
    $html .= html_writer::start_div('column is-3-desktop is-6-mobile');
    $html .= html_writer::start_div('box has-text-centered');
    $html .= html_writer::span($questions ? count($questions) : 0, 'title is-3 is-block mb-1 has-text-grey-dark');
    $html .= html_writer::span('TOTAL', 'heading has-text-grey-light is-size-7 has-text-weight-bold');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Approved Card.
    $html .= html_writer::start_div('column is-3-desktop is-6-mobile');
    $html .= html_writer::start_div('box has-text-centered is-success-light');
    $html .= html_writer::span($approvedcount, 'title is-3 is-block mb-1 has-text-success');
    $html .= html_writer::span('APPROVED', 'heading has-text-success-dark is-size-7 has-text-weight-bold');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Pending Card.
    $html .= html_writer::start_div('column is-3-desktop is-6-mobile');
    $html .= html_writer::start_div('box has-text-centered is-warning-light');
    $html .= html_writer::span($pendingcount, 'title is-3 is-block mb-1 has-text-warning-dark');
    $html .= html_writer::span('PENDING', 'heading has-text-warning-dark is-size-7 has-text-weight-bold');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Rejected Card.
    $html .= html_writer::start_div('column is-3-desktop is-6-mobile');
    $html .= html_writer::start_div('box has-text-centered is-danger-light');
    $html .= html_writer::span($rejectedcount, 'title is-3 is-block mb-1 has-text-danger');
    $html .= html_writer::span('REJECTED', 'heading has-text-danger-dark is-size-7 has-text-weight-bold');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Columns.

    // Wrap the review area in a clean container, removing the outer box.
    $html .= html_writer::start_div('questions-review-wrapper');

    // Clean toolbar using Bulma level.
    $html .= html_writer::start_div('level mb-5 py-2');

    // Left: Select all + Bulk action.
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_tag('label', ['class' => 'checkbox']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'id' => 'select-all-questions',
        'class' => 'mr-1',
        'onchange' => 'toggleAllQuestions(this.checked)',
    ]);
    $html .= ' Select All';
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_div('field has-addons');
    $html .= html_writer::start_div('control');
    $html .= html_writer::start_div('select is-small');
    $html .= html_writer::start_tag('select', ['id' => 'bulk-action-select']);
    $html .= html_writer::tag('option', 'Bulk Action', ['value' => '']);
    $html .= html_writer::tag('option', 'Approve Selected', ['value' => 'approve']);
    $html .= html_writer::tag('option', 'Reject Selected', ['value' => 'reject']);
    $html .= html_writer::tag('option', 'Delete Selected', ['value' => 'delete']);
    $html .= html_writer::end_tag('select');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('control');
    $html .= html_writer::tag('button', 'Apply', [
        'type' => 'button',
        'class' => 'button is-primary is-small',
        'onclick' => 'applyBulkAction()',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Right: Filters.
    $html .= html_writer::start_div('level-right');

    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_div('select is-small');
    $html .= html_writer::start_tag('select', [
        'id' => 'filter-status',
        'onchange' => 'applyFilters()',
    ]);
    $html .= html_writer::tag('option', 'All Status', ['value' => 'all']);
    $html .= html_writer::tag('option', 'Approved', ['value' => 'approved']);
    $html .= html_writer::tag('option', 'Pending', ['value' => 'pending']);
    $html .= html_writer::tag('option', 'Rejected', ['value' => 'rejected']);
    $html .= html_writer::end_tag('select');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_div('select is-small');
    $html .= html_writer::start_tag('select', [
        'id' => 'filter-type',
        'onchange' => 'applyFilters()',
    ]);
    $html .= html_writer::tag('option', 'All Types', ['value' => 'all']);
    $html .= html_writer::tag('option', 'Multiple Choice', ['value' => 'multichoice']);
    $html .= html_writer::tag('option', 'True/False', ['value' => 'truefalse']);
    $html .= html_writer::tag('option', 'Short Answer', ['value' => 'shortanswer']);
    $html .= html_writer::tag('option', 'Essay', ['value' => 'essay']);
    $html .= html_writer::end_tag('select');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_div('select is-small');
    $html .= html_writer::start_tag('select', [
        'id' => 'filter-difficulty',
        'onchange' => 'applyFilters()',
    ]);
    $html .= html_writer::tag('option', 'All Difficulty', ['value' => 'all']);
    $html .= html_writer::tag('option', 'Easy', ['value' => 'easy']);
    $html .= html_writer::tag('option', 'Medium', ['value' => 'medium']);
    $html .= html_writer::tag('option', 'Hard', ['value' => 'hard']);
    $html .= html_writer::end_tag('select');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_div();
    $html .= html_writer::end_div(); // Level toolbar.

    $questionnumber = 0;
    foreach ($questions as $question) {
        $questionnumber++;

        $cardclass = 'card mb-4 question-card';
        if ($question->status === 'rejected') {
            $cardclass .= ' has-background-white-ter'; // Grey out rejected.
        }

        $html .= html_writer::start_div($cardclass, [
            'data-question-id' => $question->id,
            'data-status' => $question->status ?? 'pending',
            'data-type' => $question->questiontype ?? 'multichoice',
            'data-difficulty' => $question->difficulty,
        ]);

        // CARD HEADER.
        $html .= html_writer::start_div('card-header is-shadowless border-bottom-0', [
            'style' => 'box-shadow: none; border-bottom: 1px solid var(--hlai-gray-100);',
        ]);

        $html .= html_writer::start_div('card-header-title is-align-items-center py-2');

        // Group Checkbox and ID for perfect alignment.
        $html .= html_writer::start_div('is-flex is-align-items-center mr-4');

        // Checkbox with clean spacing.
        $html .= html_writer::start_tag('label', ['class' => 'checkbox mr-3', 'style' => 'display:flex; align-items:center;']);
        $html .= html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'class' => 'question-checkbox',
            'id' => 'question-checkbox-' . $question->id,
            'data-question-id' => $question->id,
            'style' => 'transform: scale(1.1); cursor: pointer;',
        ]);
        $html .= html_writer::end_tag('label');

        // Question number text, clean and professional.
        $html .= html_writer::tag('span', 'Question ' . $questionnumber, [
            'class' => 'has-text-weight-bold has-text-grey-darker is-size-6',
        ]);
        $html .= html_writer::end_div();

        // Tags.
        $typelabel = str_replace('multichoice', 'MCQ', $question->questiontype ?? 'mcq');
        $html .= html_writer::tag('span', strtoupper($typelabel), ['class' => 'tag is-light mr-2']);

        $diffclass = $question->difficulty === 'easy' ? 'is-success' :
            ($question->difficulty === 'hard' ? 'is-danger' : 'is-warning');
        $html .= html_writer::tag('span', ucfirst($question->difficulty), ['class' => 'tag is-light ' . $diffclass]);
        $html .= html_writer::end_div(); // Card-header-title.

        // Status Icon.
        $html .= html_writer::start_div('card-header-icon');
        $statustext = ucfirst($question->status);
        $statustagclass = $question->status === 'approved' ? 'is-success' :
                         ($question->status === 'rejected' ? 'is-danger' : 'is-warning');
        $html .= html_writer::tag('span', $statustext, ['class' => 'tag ' . $statustagclass]);
        $html .= html_writer::end_div();

        $html .= html_writer::end_div(); // Card-header.

        // CARD CONTENT.
        $html .= html_writer::start_div('card-content');
        $html .= html_writer::start_div('content');
        $html .= html_writer::tag('p', format_text($question->questiontext), ['class' => 'is-size-5 has-text-weight-medium']);

        // Answers - handle different question types.
        $questiontype = $question->questiontype ?? 'multichoice';

        if ($questiontype === 'essay' || $questiontype === 'scenario') {
            // For essay/scenario questions, show the model answer from generalfeedback.
            $modelanswer = $question->generalfeedback ?? '';
            if (!empty($modelanswer)) {
                $html .= html_writer::start_div('mt-3');
                $html .= html_writer::tag(
                    'p',
                    html_writer::tag('strong', '<i class="fa fa-lightbulb-o mr-2"></i>Model Answer / Grading Criteria:'),
                    ['class' => 'has-text-grey-dark mb-2']
                );
                $modelstyle = 'padding: 1rem; border-radius: 6px; background-color: #f0f9ff; border-left: 4px solid #3b82f6;';
                $html .= html_writer::div(format_text($modelanswer, FORMAT_HTML), 'model-answer', ['style' => $modelstyle]);
                $html .= html_writer::end_div();
            } else {
                $html .= html_writer::start_div('mt-3');
                $html .= html_writer::tag(
                    'p',
                    '<i class="fa fa-info-circle mr-2"></i>No model answer provided for this essay question.',
                    ['class' => 'has-text-grey-light is-italic']
                );
                $html .= html_writer::end_div();
            }
        } else {
            // For MCQ, TF, Short Answer, Matching - show answer options.
            $answers = $DB->get_records('local_hlai_quizgen_answers', ['questionid' => $question->id], 'sortorder ASC');
            if (!empty($answers)) {
                $html .= html_writer::start_div('mt-3');
                $letterlabel = 'A';
                foreach ($answers as $answer) {
                    $iscorrect = $answer->fraction > 0;
                    $answerstyle = 'padding: 0.5rem; border-radius: 4px; display: flex; ' .
                        'align-items: flex-start; margin-bottom: 0.35rem;';

                    // Use background utilities instead of custom classes if we can, but clean style needs some help.
                    if ($iscorrect) {
                        $answerstyle .= ' background-color: #effaf3; color: #257942;'; // Light green.
                    } else {
                        $answerstyle .= ' background-color: #f5f5f5;'; // Light grey.
                    }

                    $html .= html_writer::start_div('answer-row', ['style' => $answerstyle]);

                    $html .= html_writer::tag('strong', $letterlabel . '.', ['class' => 'mr-3']);
                    $html .= html_writer::tag('span', format_text($answer->answer ?? ''), ['class' => 'is-flex-grow-1']);

                    if ($iscorrect) {
                        $html .= html_writer::tag('span', '✔', ['class' => 'icon has-text-success ml-2']);
                    }

                    $html .= html_writer::end_div(); // Answer-row.
                    $letterlabel++;
                }
                $html .= html_writer::end_div();
            }
        }
        $html .= html_writer::end_div(); // Content.
        $html .= html_writer::end_div(); // Card-content.

        // CARD FOOTER.
        $html .= html_writer::start_div('card-footer');

        if ($question->status !== 'approved') {
            $approveurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $courseid,
                'requestid' => $requestid,
                'action' => 'approve_question',
                'questionid' => $question->id,
                'sesskey' => sesskey(),
                'step' => 4,
            ]);
            $html .= html_writer::link($approveurl, 'Approve', [
                'class' => 'card-footer-item has-text-success has-text-weight-bold',
            ]);
        } else {
            // Already approved indicator.
            $html .= html_writer::span('Approved', 'card-footer-item has-text-grey-light');
        }

        if ($question->status !== 'rejected') {
            $rejecturl = new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $courseid,
                'requestid' => $requestid,
                'action' => 'reject_question',
                'questionid' => $question->id,
                'sesskey' => sesskey(),
                'step' => 4,
            ]);
            $html .= html_writer::link($rejecturl, 'Reject', ['class' => 'card-footer-item has-text-danger']);
        }

        $maxregens = get_config('local_hlai_quizgen', 'max_regenerations') ?: 5;
        $remainingregens = $maxregens - ($question->regeneration_count ?? 0);
        if ($remainingregens > 0) {
            $regenurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $courseid,
                'requestid' => $requestid,
                'action' => 'regenerate_question',
                'questionid' => $question->id,
                'sesskey' => sesskey(),
                'step' => 4,
            ]);
            $html .= html_writer::link($regenurl, 'Regenerate (' . $remainingregens . ')', [
                'class' => 'card-footer-item has-text-info',
            ]);
        }

        $html .= html_writer::end_div(); // Card-footer.

        $html .= html_writer::end_div(); // Card question-card.
    }

    $html .= html_writer::end_div(); // Questions-review.

    // JavaScript for bulk actions and filtering.
    $html .= html_writer::script("
        /**
         * Toggle all questions.
         * @param {boolean} checked Whether to check or uncheck.
         */
        function toggleAllQuestions(checked) {
            document.querySelectorAll('.question-checkbox').forEach(function(checkbox) {
                // Only check visible questions.
                var card = checkbox.closest('.question-card');
                if (card && card.style.display !== 'none') {
                    checkbox.checked = checked;
                }
            });
        }

        /**
         * Apply bulk action.
         */
        function applyBulkAction() {
            var action = document.getElementById('bulk-action-select').value;
            if (!action) {
                alert('Please select an action');
                return;
            }

            var selectedQuestions = [];
            document.querySelectorAll('.question-checkbox:checked').forEach(function(checkbox) {
                selectedQuestions.push(checkbox.dataset.questionId);
            });

            if (selectedQuestions.length === 0) {
                alert('Please select at least one question');
                return;
            }

            var confirmMsg = 'Are you sure you want to ' + action + ' ' + selectedQuestions.length + ' question(s)?';
            if (!confirm(confirmMsg)) {
                return;
            }

            // Create form and submit.
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;

            // Add sesskey.
            var sesskeyInput = document.createElement('input');
            sesskeyInput.type = 'hidden';
            sesskeyInput.name = 'sesskey';
            sesskeyInput.value = M.cfg.sesskey;
            form.appendChild(sesskeyInput);

            // Add action.
            var actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'bulk_action';
            actionInput.value = action;
            form.appendChild(actionInput);

            // Add question IDs.
            selectedQuestions.forEach(function(qid) {
                var qInput = document.createElement('input');
                qInput.type = 'hidden';
                qInput.name = 'question_ids[]';
                qInput.value = qid;
                form.appendChild(qInput);
            });

            document.body.appendChild(form);
            form.submit();
        }

        /**
         * Apply filters.
         */
        function applyFilters() {
            var statusFilter = document.getElementById('filter-status').value;
            var typeFilter = document.getElementById('filter-type').value;
            var difficultyFilter = document.getElementById('filter-difficulty').value;

            document.querySelectorAll('.question-card').forEach(function(card) {
                var show = true;

                // Check status filter.
                if (statusFilter !== 'all' && card.dataset.status !== statusFilter) {
                    show = false;
                }

                // Check type filter.
                if (typeFilter !== 'all' && card.dataset.type !== typeFilter) {
                    show = false;
                }

                // Check difficulty filter.
                if (difficultyFilter !== 'all' && card.dataset.difficulty !== difficultyFilter) {
                    show = false;
                }

                card.style.display = show ? '' : 'none';
            });

            // Update select-all checkbox if needed.
            document.getElementById('select-all-questions').checked = false;
        }
    ");

    // Navigation.
    $html .= html_writer::start_div('level mt-5');
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::link(
        new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 3]),
        '<i class="fa fa-arrow-left" style="color: #64748B;"></i> ' . get_string('previous'),
        ['class' => 'button is-light']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('level-right');
    $html .= html_writer::link(
        new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 5]),
        get_string('next') . ' <i class="fa fa-arrow-right"></i>',
        ['class' => 'button is-primary']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::end_div(); // Level toolbar-bottom.

    $html .= html_writer::end_div(); // Hlai-step-content.

    return $html;
}

/**
 * Render step 5: Deployment.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function render_step5(int $courseid, int $requestid): string {
    global $DB;

    // Validate request ID - redirect to Step 1 if invalid.
    if ($requestid === 0) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'step' => 1,
            ]),
            'Please start by selecting content in Step 1.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
        return '';
    }

    $html = html_writer::start_div('hlai-step-content');

    // Header.
    $html .= html_writer::tag(
        'h2',
        '<i class="fa fa-rocket" style="color: #3B82F6;"></i> ' .
        get_string('step5_title', 'local_hlai_quizgen'),
        ['class' => 'title is-4 mb-0']
    );
    $html .= html_writer::tag('p', get_string('step5_description', 'local_hlai_quizgen'), [
        'class' => 'subtitle is-6 has-text-grey mt-1 mb-4',
    ]);
    $html .= html_writer::tag('hr', '', ['class' => 'mt-0 mb-5']);

    // Count approved questions only.
    $approvedcount = $DB->count_records('local_hlai_quizgen_questions', [
        'requestid' => $requestid,
        'status' => 'approved',
    ]);

    $totalcount = $DB->count_records('local_hlai_quizgen_questions', ['requestid' => $requestid]);

    if ($totalcount == 0) {
        $html .= html_writer::div(
            'No questions have been generated yet. Please go back to Step 3.',
            'notification is-warning'
        );
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 3]),
            '<i class="fa fa-arrow-left" style="color: #FFFFFF;"></i> Back to Step 3',
            ['class' => 'button is-primary mt-4']
        );
        $html .= html_writer::end_div(); // Box.
        return $html;
    }

    if ($approvedcount == 0) {
        $html .= html_writer::div(
            "You have {$totalcount} generated questions, but none are approved yet. " .
            "Please go back to Step 4 and approve questions before deployment.",
            'notification is-warning'
        );
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 4]),
            '<i class="fa fa-arrow-left" style="color: #FFFFFF;"></i> Back to Step 4 - Review Questions',
            ['class' => 'button is-primary mt-4']
        );
        $html .= html_writer::end_div(); // Box.
        return $html;
    }

    $html .= html_writer::div(
        html_writer::tag('strong', "<i class='fa fa-check-circle'></i> {$approvedcount} approved questions") .
            " ready to deploy (out of {$totalcount} generated)",
        'notification is-success'
    );

    $formurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'action' => 'deploy_questions',
    ]);

    $html .= html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'id' => 'deployment-form',
    ]);

    $html .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);

    // Deployment type.
    $html .= html_writer::tag(
        'h4',
        '<i class="fa fa-cube" style="color: #3B82F6;"></i> ' .
        get_string('deployment_options', 'local_hlai_quizgen'),
        ['class' => 'title is-5 mb-4']
    );

    // Option 1: Create new quiz.
    $html .= html_writer::start_div('field mb-4');
    $html .= html_writer::start_tag('label', [
        'class' => 'radio',
        'style' => 'display: flex; align-items: flex-start; gap: 0.5rem;',
    ]);
    $html .= html_writer::empty_tag('input', [
        'type' => 'radio',
        'name' => 'deploy_type',
        'value' => 'new_quiz',
        'id' => 'deploy_new_quiz',
        'class' => 'deploy-type-radio',
        'checked' => 'checked',
        'style' => 'margin-top: 4px;',
    ]);
    $html .= html_writer::start_div('');
    $html .= html_writer::tag('strong', get_string('create_new_quiz', 'local_hlai_quizgen'));
    $html .= html_writer::tag('p', 'Create a new quiz activity and add all approved questions to it.', ['class' => 'help mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('ml-5 mb-4', ['id' => 'new_quiz_options']);
    $html .= html_writer::start_div('field');
    $html .= html_writer::tag('label', get_string('quiz_name', 'local_hlai_quizgen'), [
        'for' => 'quiz_name',
        'class' => 'label is-small',
    ]);
    $html .= html_writer::start_div('control');
    $html .= html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'quiz_name',
        'id' => 'quiz_name',
        'value' => 'AI Generated Quiz - ' . date('Y-m-d'),
        'class' => 'input',
        'required' => 'required',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Option 2: Question bank only.
    $html .= html_writer::start_div('field mb-4');
    $html .= html_writer::start_tag('label', [
        'class' => 'radio',
        'style' => 'display: flex; align-items: flex-start; gap: 0.5rem;',
    ]);
    $html .= html_writer::empty_tag('input', [
        'type' => 'radio',
        'name' => 'deploy_type',
        'value' => 'question_bank',
        'id' => 'deploy_qbank',
        'class' => 'deploy-type-radio',
        'style' => 'margin-top: 4px;',
    ]);
    $html .= html_writer::start_div('');
    $html .= html_writer::tag('strong', get_string('export_to_question_bank', 'local_hlai_quizgen'));
    $html .= html_writer::tag('p', 'Add questions to the question bank for later use.', ['class' => 'help mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('ml-5 mb-3', ['id' => 'qbank_options', 'style' => 'display:none;']);
    $html .= html_writer::start_div('field');
    $html .= html_writer::tag('label', get_string('category_name', 'local_hlai_quizgen'), [
        'for' => 'category_name',
        'class' => 'label is-small',
    ]);
    $html .= html_writer::start_div('control');
    $html .= html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'category_name',
        'id' => 'category_name',
        'value' => 'AI Generated Questions',
        'class' => 'input',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Navigation.
    $html .= html_writer::start_div('level mt-5');
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::link(
        new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 4]),
        '<i class="fa fa-arrow-left" style="color: #64748B;"></i> ' . get_string('previous'),
        ['class' => 'button is-light']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('level-right');
    $html .= html_writer::tag('button', '<i class="fa fa-rocket" style="color: #FFFFFF;"></i> ' .
        get_string('deploy_questions', 'local_hlai_quizgen'), [
        'type' => 'submit',
        'class' => 'button is-success',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_tag('form');

    $html .= html_writer::end_div(); // Box.

    // Add JS for showing/hiding options and preventing double submission.
    $html .= html_writer::script("
        var deploymentForm = document.getElementById('deployment-form');
        var isSubmitting = false;

        document.querySelectorAll('.deploy-type-radio').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.getElementById('new_quiz_options').style.display =
                    document.getElementById('deploy_new_quiz').checked ? 'block' : 'none';
                document.getElementById('qbank_options').style.display =
                    document.getElementById('deploy_qbank').checked ? 'block' : 'none';
            });
        });

        // Prevent double submission.
        if (deploymentForm) {
            deploymentForm.addEventListener('submit', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }
                isSubmitting = true;

                // Disable submit button.
                var submitBtn = deploymentForm.querySelector('button[type=\"submit\"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Deploying...';
                }
            });
        }
    ");

    return $html;
}

/**
 * Clean up orphaned files for a failed or cancelled request.
 *
 * @param int $requestid Request ID
 * @param int $contextid Context ID
 * @return int Number of files deleted
 */
function cleanup_request_files(int $requestid, int $contextid): int {
    $fs = get_file_storage();

    $files = $fs->get_area_files(
        $contextid,
        'local_hlai_quizgen',
        'content',
        $requestid,
        'filename',
        false // Don't include directories.
    );

    $count = 0;
    foreach ($files as $file) {
        $file->delete();
        $count++;
    }

    return $count;
}

/**
 * Check plugin dependencies and requirements.
 *
 * @return array Array of error messages (empty if all OK)
 */
function check_plugin_dependencies(): array {
    $errors = [];

    // Check Gateway availability.
    try {
        if (!\local_hlai_quizgen\gateway_client::is_ready()) {
            $errors[] = 'AI Gateway is not configured. Please configure the AI Service URL and API Key in plugin settings.';
        }
    } catch (\Throwable $e) {
        $errors[] = 'Gateway check failed: ' . $e->getMessage();
    }

    // Note: External libraries (Smalot, PHPWord, PHPPresentation) are no longer required.
    // File extraction now uses native PHP (ZipArchive) and system tools (Ghostscript).

    return $errors;
}
