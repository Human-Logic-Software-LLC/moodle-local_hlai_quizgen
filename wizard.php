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
require_once(__DIR__ . '/lib.php');

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
$dependencyerrors = local_hlai_quizgen_check_plugin_dependencies();
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
            '<i class="fa fa-arrow-left hlai-icon-secondary"></i> ' . get_string('back'),
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
    local_hlai_quizgen_handle_content_upload($courseid, $context);
}

if ($action === 'create_request' && confirm_sesskey()) {
    local_hlai_quizgen_handle_create_request($courseid);
}

if ($action === 'save_topic_selection' && confirm_sesskey()) {
    local_hlai_quizgen_handle_save_topic_selection($requestid);
}

if ($action === 'save_question_distribution' && confirm_sesskey()) {
    local_hlai_quizgen_handle_save_question_distribution($requestid);
}

if ($action === 'generate_questions' && confirm_sesskey()) {
    local_hlai_quizgen_handle_generate_questions($requestid);
}

if ($action === 'deploy_questions' && confirm_sesskey()) {
    local_hlai_quizgen_handle_deploy_questions($requestid, $courseid);
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

if ($action === 'approve_all_questions' && confirm_sesskey()) {
    // Approve all pending questions for this request in a single bulk update.
    $approvedcount = $DB->count_records_select(
        'local_hlai_quizgen_questions',
        'requestid = ? AND status != ?',
        [$requestid, 'approved']
    );
    $DB->set_field_select(
        'local_hlai_quizgen_questions',
        'status',
        'approved',
        'requestid = ? AND status != ?',
        [$requestid, 'approved']
    );

    redirect(
        new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'step' => 4,
        ]),
        get_string('bulk_approved', 'local_hlai_quizgen', $approvedcount),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
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
            get_string('wizard_question_regenerated', 'local_hlai_quizgen'),
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
    local_hlai_quizgen_handle_bulk_action($bulkaction, $requestid);
}

// Add Bulma CSS Framework (Native/Local - non-minified for debugging).
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');

// Add our custom CSS (loaded after Bulma to override and fix Moodle compatibility).
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

// Add AMD module for wizard functionality.
$jsconfig = [
    'courseid' => $courseid,
    'requestid' => $requestid,
    'step' => $step,
];
// Add step-specific config.
if ($step === '3.5') {
    $jsconfig['refreshUrl'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'step' => '3.5',
    ]))->out(false);
}
if ($step == 1) {
    $jsconfig['strings'] = [
        'choose_files' => get_string('choose_files', 'local_hlai_quizgen'),
    ];
}
$PAGE->requires->js_call_amd('local_hlai_quizgen/wizard', 'init', [$jsconfig]);

echo $OUTPUT->header();

// Render current step content via existing step-rendering functions.
switch ($step) {
    case '1':
    case 1:
        $stephtml = local_hlai_quizgen_render_step1($courseid, $requestid);
        break;
    case '2':
    case 2:
        $stephtml = local_hlai_quizgen_render_step2($courseid, $requestid);
        break;
    case '3':
    case 3:
        $stephtml = local_hlai_quizgen_render_step3($courseid, $requestid);
        break;
    case 'progress':
        $stephtml = local_hlai_quizgen_render_step3_5($courseid, $requestid);
        break;
    case '4':
    case 4:
        $stephtml = local_hlai_quizgen_render_step4($courseid, $requestid);
        break;
    case '5':
    case 5:
        $stephtml = local_hlai_quizgen_render_step5($courseid, $requestid);
        break;
    default:
        $stephtml = local_hlai_quizgen_render_step1($courseid, $requestid);
}

// Render the wizard page using templates and the Output API.
$wizardpage = new \local_hlai_quizgen\output\wizard_page($step, $stephtml);
echo $OUTPUT->render_from_template('local_hlai_quizgen/wizard', $wizardpage->export_for_template($OUTPUT));

echo $OUTPUT->footer();

/**
 * Handle content upload.
 *
 * @param int $courseid Course ID
 * @param context $context Context
 * @return void
 */
function local_hlai_quizgen_handle_content_upload(int $courseid, context $context) {
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
    $manualtext = optional_param('manual_text', '', PARAM_CLEANHTML);
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

    // Get uploaded files via helper (avoids direct $_FILES access).
    $uploadedfiledata = local_hlai_quizgen_get_uploaded_files('contentfiles');

    // Validate file sizes before processing.
    if ($uploadedfiledata && !empty($uploadedfiledata['name'][0])) {
        $maxfilesize = get_config('local_hlai_quizgen', 'max_file_size_mb') ?: 50;
        $maxbytes = $maxfilesize * 1024 * 1024;

        foreach ($uploadedfiledata['size'] as $key => $filesize) {
            if ($filesize > $maxbytes) {
                $filename = $uploadedfiledata['name'][$key];
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
    $hasfiles = $uploadedfiledata && !empty($uploadedfiledata['name'][0]);
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
        foreach ($uploadedfiledata['name'] as $key => $filename) {
            if (empty($filename)) {
                continue;
            }
            $filesize = $uploadedfiledata['size'][$key] ?? 0;
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

            // Batch-insert the deduplicated topics.
            $now = time();
            $inserttopics = [];
            foreach ($uniquetopics as $topic) {
                $newtopic = clone $topic;
                unset($newtopic->id);
                $newtopic->requestid = $requestid;
                $newtopic->timecreated = $now;
                $inserttopics[] = $newtopic;
            }
            $DB->insert_records('local_hlai_quizgen_topics', $inserttopics);

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

        foreach ($uploadedfiledata['name'] as $key => $filename) {
            if (empty($filename)) {
                continue;
            }

            $fileerror = $uploadedfiledata['error'][$key];
            if ($fileerror != UPLOAD_ERR_OK) {
                $errormsg = get_string('wizard_upload_err_unknown', 'local_hlai_quizgen');
                switch ($fileerror) {
                    case UPLOAD_ERR_INI_SIZE:
                        $errormsg = get_string('wizard_upload_err_ini_size', 'local_hlai_quizgen');
                        break;
                    case UPLOAD_ERR_FORM_SIZE:
                        $errormsg = get_string('wizard_upload_err_form_size', 'local_hlai_quizgen');
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errormsg = get_string('wizard_upload_err_partial', 'local_hlai_quizgen');
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errormsg = get_string('wizard_upload_err_no_file', 'local_hlai_quizgen');
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $errormsg = get_string('wizard_upload_err_no_tmp_dir', 'local_hlai_quizgen');
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $errormsg = get_string('wizard_upload_err_cant_write', 'local_hlai_quizgen');
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $errormsg = get_string('wizard_upload_err_extension', 'local_hlai_quizgen');
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

            $tmpfile = $uploadedfiledata['tmp_name'][$key];
            $filesize = $uploadedfiledata['size'][$key];

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
                        'error' => get_string('wizard_tmp_file_not_found', 'local_hlai_quizgen'),
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
function local_hlai_quizgen_handle_create_request(int $courseid) {
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
function local_hlai_quizgen_handle_save_question_distribution(int $requestid) {
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
function local_hlai_quizgen_handle_save_topic_selection(int $requestid) {
    global $DB;

    $courseid = required_param('courseid', PARAM_INT);
    $selectedtopics = optional_param_array('topics', [], PARAM_INT);

    // Update all topics to deselected first.
    $DB->set_field('local_hlai_quizgen_topics', 'selected', 0, ['requestid' => $requestid]);

    // Batch-update selected topics to avoid N+1 queries.
    if (!empty($selectedtopics)) {
        [$insql, $inparams] = $DB->get_in_or_equal($selectedtopics, SQL_PARAMS_NAMED);
        $DB->set_field_select('local_hlai_quizgen_topics', 'selected', 1, "id $insql", $inparams);
        $DB->set_field_select('local_hlai_quizgen_topics', 'num_questions', 5, "id $insql", $inparams);
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
function local_hlai_quizgen_handle_generate_questions(int $requestid) {
    global $DB;

    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

    // Get total questions from form input (new approach).
    $totalquestions = required_param('total_questions', PARAM_INT);

    // Validate.
    if ($totalquestions < 1 || $totalquestions > 100) {
        throw new \moodle_exception('wizard_invalid_total_questions', 'local_hlai_quizgen');
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
        $mismatchinfo = new stdClass();
        $mismatchinfo->typecount = $typecount;
        $mismatchinfo->total = $totalquestions;
        throw new \moodle_exception('wizard_qtype_count_mismatch', 'local_hlai_quizgen', '', $mismatchinfo);
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
function local_hlai_quizgen_handle_bulk_action(string $action, int $requestid) {
    global $DB;

    $questionids = optional_param_array('question_ids', [], PARAM_INT);

    if (empty($questionids)) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $DB->get_field('local_hlai_quizgen_requests', 'courseid', ['id' => $requestid]),
                'requestid' => $requestid,
                'step' => 4,
            ]),
            get_string('error:noquestionsselected', 'local_hlai_quizgen'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
        return;
    }

    $courseid = $DB->get_field('local_hlai_quizgen_requests', 'courseid', ['id' => $requestid]);

    // Batch-fetch all questions to avoid N+1 query pattern.
    [$insql, $inparams] = $DB->get_in_or_equal($questionids);
    $params = array_merge($inparams, [$requestid]);
    $questions = $DB->get_records_select(
        'local_hlai_quizgen_questions',
        "id $insql AND requestid = ?",
        $params
    );

    $count = 0;
    foreach ($questionids as $qid) {
        if (!isset($questions[$qid])) {
            continue;
        }
        $question = $questions[$qid];

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
        'approve' => get_string('bulk_approved', 'local_hlai_quizgen', $count),
        'reject' => get_string('bulk_rejected', 'local_hlai_quizgen', $count),
        'delete' => get_string('bulk_deleted', 'local_hlai_quizgen', $count),
    ];

    redirect(
        new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'step' => 4,
        ]),
        $messages[$action] ?? get_string('wizard_action_completed', 'local_hlai_quizgen'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

/**
 * Post-deployment auto-recovery: verify tracking and automatically link any untracked questions.
 *
 * For each question: if moodle_questionid is set, mark as deployed. If not, search for a matching
 * Moodle question by text content and auto-link it. Only reset to 'approved' as a last resort.
 *
 * @param array $questionids Plugin question IDs that were deployed
 * @param int $courseid Course ID
 * @param \moodle_database $DB Database instance
 * @return array ['tracked' => int, 'recovered' => int, 'untracked' => int, 'message' => string]
 */
function local_hlai_quizgen_auto_recover_tracking(array $questionids, int $courseid, $DB): array {
    $tracked = 0;
    $recovered = 0;
    $untracked = 0;

    // Build list of context IDs to search: course context + all quiz/qbank module contexts.
    // This works on both Moodle 4.x (course context) and 5.x (module context).
    $coursecontext = context_course::instance($courseid);
    $contextids = [$coursecontext->id];

    // Also gather all module contexts for this course (quiz and qbank modules).
    // This ensures we find questions in quiz-specific banks (Moodle 5.x).
    try {
        $modulecontexts = $DB->get_records_sql(
            "SELECT ctx.id
             FROM {context} ctx
             JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = ?
             JOIN {modules} m ON m.id = cm.module
             WHERE cm.course = ? AND m.name IN ('quiz', 'qbank')",
            [CONTEXT_MODULE, $courseid]
        );
        foreach ($modulecontexts as $mctx) {
            $contextids[] = $mctx->id;
        }
    } catch (\Exception $e) {
        debugging("auto_recover_tracking: Could not get module contexts: " . $e->getMessage(), DEBUG_DEVELOPER);
    }

    debugging("auto_recover_tracking: Searching in " .
        count($contextids) . " contexts for course $courseid", DEBUG_DEVELOPER);

    // Build placeholders for IN clause.
    [$insql, $inparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_QM);

    // Bulk-fetch all plugin questions in one query to avoid N+1 SELECT per question ID.
    [$qinsql, $qinparams] = $DB->get_in_or_equal($questionids);
    $questions = $DB->get_records_select('local_hlai_quizgen_questions', "id $qinsql", $qinparams);

    foreach ($questions as $q) {
        $qid = $q->id;

        // Already tracked — just ensure status is correct.
        if (!empty($q->moodle_questionid)) {
            $DB->set_field('local_hlai_quizgen_questions', 'status', 'deployed', ['id' => $qid]);
            $tracked++;
            continue;
        }

        // NOT tracked — attempt auto-recovery by searching ALL relevant question banks.
        $questiontext = $q->questiontext ?? '';
        $qtype = ($q->questiontype === 'scenario') ? 'essay' : $q->questiontype;

        if (!empty($questiontext)) {
            // Search course context + all module contexts (Moodle 4.x + 5.x compatible).
            $params = array_merge($inparams, [$questiontext, $qtype]);
            $match = $DB->get_record_sql(
                "SELECT q.id
                 FROM {question} q
                 JOIN {question_versions} qv ON qv.questionid = q.id
                 JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qc.contextid $insql
                 AND q.questiontext = ?
                 AND q.qtype = ?
                 ORDER BY q.id DESC
                 LIMIT 1",
                $params
            );

            if ($match) {
                // Auto-link the found Moodle question.
                $updateobj = new stdClass();
                $updateobj->id = $qid;
                $updateobj->moodle_questionid = $match->id;
                $updateobj->status = 'deployed';
                $updateobj->timedeployed = time();
                $DB->update_record('local_hlai_quizgen_questions', $updateobj);
                $recovered++;
                debugging("auto_recover_tracking: Auto-linked plugin question " .
                    "$qid to Moodle question {$match->id}", DEBUG_DEVELOPER);
                continue;
            }
        }

        // Could not auto-recover — reset to approved so user can re-deploy.
        $DB->set_field('local_hlai_quizgen_questions', 'status', 'approved', ['id' => $qid]);
        $untracked++;
    }

    $message = '';
    if ($recovered > 0) {
        $message .= get_string('wizard_auto_recovered', 'local_hlai_quizgen', $recovered) . ' ';
    }
    if ($untracked > 0) {
        $message .= get_string('wizard_untracked_warning', 'local_hlai_quizgen', $untracked);
    }

    return [
        'tracked' => $tracked,
        'recovered' => $recovered,
        'untracked' => $untracked,
        'message' => $message,
    ];
}

/**
 * Handle deploy questions from step 5.
 *
 * @param int $requestid Request ID
 * @param int $courseid Course ID
 * @return void
 * @throws moodle_exception
 */
function local_hlai_quizgen_handle_deploy_questions(int $requestid, int $courseid) {
    global $DB;

    debugging("handle_deploy_questions: Starting deployment for request $requestid, course $courseid", DEBUG_DEVELOPER);

    $deploytype = required_param('deploy_type', PARAM_TEXT);
    $quizname = optional_param('quiz_name', '', PARAM_TEXT);
    $categoryname = optional_param('category_name', '', PARAM_TEXT);

    debugging("handle_deploy_questions: deploy_type=$deploytype, quiz_name=$quizname", DEBUG_DEVELOPER);

    // Get only approved questions for this request.
    $questions = $DB->get_records('local_hlai_quizgen_questions', [
        'requestid' => $requestid,
        'status' => 'approved',
    ], '', 'id, questiontype');
    $questionids = array_keys($questions);

    debugging("handle_deploy_questions: Found " . count($questionids) . " approved questions", DEBUG_DEVELOPER);

    if (empty($questionids)) {
        // Check if there are ANY questions for this request.
        $allquestions = $DB->count_records('local_hlai_quizgen_questions', ['requestid' => $requestid]);
        if ($allquestions > 0) {
            // Questions exist but none are approved.
            redirect(
                new moodle_url('/local/hlai_quizgen/wizard.php', [
                    'courseid' => $courseid,
                    'requestid' => $requestid,
                    'step' => 4,
                ]),
                get_string('wizard_cannot_deploy_none_approved', 'local_hlai_quizgen', $allquestions),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        } else {
            throw new \moodle_exception('error:noquestionstodeploy', 'local_hlai_quizgen');
        }
    }

    // Log the question types we're about to deploy.
    $qtypes = [];
    foreach ($questions as $q) {
        $qtypes[] = $q->questiontype ?? 'unknown';
    }
    debugging("handle_deploy_questions: Question types to deploy: " . implode(', ', $qtypes), DEBUG_DEVELOPER);

    try {
        $deployer = new \local_hlai_quizgen\quiz_deployer();

        if ($deploytype === 'new_quiz') {
            debugging("handle_deploy_questions: Creating new quiz...", DEBUG_DEVELOPER);
            $cmid = $deployer->create_quiz($questionids, $courseid, $quizname);

            // Post-deployment verification with auto-recovery.
            $result = local_hlai_quizgen_auto_recover_tracking($questionids, $courseid, $DB);

            $successmsg = get_string('success:quizcreated', 'local_hlai_quizgen');

            if ($result['untracked'] > 0) {
                redirect(
                    new moodle_url('/mod/quiz/view.php', ['id' => $cmid]),
                    $successmsg . "\n\n" . $result['message'],
                    null,
                    \core\output\notification::NOTIFY_WARNING
                );
            } else {
                redirect(
                    new moodle_url('/mod/quiz/view.php', ['id' => $cmid]),
                    $successmsg . ' ' . get_string('wizard_all_questions_tracked', 'local_hlai_quizgen', $result['tracked']),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            }
        } else {
            debugging("handle_deploy_questions: Deploying to question bank...", DEBUG_DEVELOPER);
            $moodlequestionids = $deployer->deploy_to_question_bank($questionids, $courseid, $categoryname);

            // Save category name to request record for future reference.
            if (!empty($categoryname)) {
                try {
                    $DB->set_field(
                        'local_hlai_quizgen_requests',
                        'category_name',
                        $categoryname,
                        ['id' => $requestid]
                    );
                } catch (\Exception $e) {
                    debugging(
                        'handle_deploy_questions: Could not save category_name: '
                        . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }

            // Post-deployment verification with auto-recovery.
            $result = local_hlai_quizgen_auto_recover_tracking($questionids, $courseid, $DB);

            $deploymsg = new stdClass();
            $deploymsg->count = count($moodlequestionids);
            $deploymsg->category = $categoryname ?: get_string('wizard_default', 'local_hlai_quizgen');
            $successmsg = get_string('success:questionsdeployed', 'local_hlai_quizgen') .
                         ' ' . get_string('wizard_questions_to_category', 'local_hlai_quizgen', $deploymsg);

            if ($result['untracked'] > 0) {
                redirect(
                    new moodle_url('/question/edit.php', ['courseid' => $courseid]),
                    $successmsg . "\n\n" . $result['message'],
                    null,
                    \core\output\notification::NOTIFY_WARNING
                );
            } else {
                redirect(
                    new moodle_url('/question/edit.php', ['courseid' => $courseid]),
                    $successmsg . ' ' . get_string('wizard_all_questions_tracked', 'local_hlai_quizgen', $result['tracked']),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            }
        }
    } catch (\Throwable $e) {
        // Catch both Exception and Error types.
        $fullerror = get_class($e) . ': ' . $e->getMessage();
        $debuginfo = " [File: " . $e->getFile() . ":" . $e->getLine() . "]";

        debugging("handle_deploy_questions: DEPLOYMENT FAILED - $fullerror $debuginfo", DEBUG_DEVELOPER);
        debugging("handle_deploy_questions: Stack trace: " . $e->getTraceAsString(), DEBUG_DEVELOPER);

        // Try to log the error.
        try {
            \local_hlai_quizgen\error_handler::handle_exception(
                $e,
                $requestid,
                'deployment',
                \local_hlai_quizgen\error_handler::SEVERITY_ERROR
            );
        } catch (\Throwable $logerror) {
            debugging("handle_deploy_questions: Failed to log error: " . $logerror->getMessage(), DEBUG_DEVELOPER);
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
function local_hlai_quizgen_render_step_indicator($currentstep): string {
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
function local_hlai_quizgen_render_step1(int $courseid, int $requestid): string {
    global $OUTPUT, $DB, $PAGE;

    $formurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'action' => 'upload_content',
    ]);

    // PHP upload limits.
    $uploadmaxfilesize = ini_get('upload_max_filesize');
    $postmaxsize = ini_get('post_max_size');
    $maxfilesize = get_config('local_hlai_quizgen', 'max_file_size_mb') ?: 50;
    $phplimits = new stdClass();
    $phplimits->uploadmax = $uploadmaxfilesize;
    $phplimits->postmax = $postmaxsize;
    $phplimits->pluginmax = $maxfilesize . 'MB';

    // Get course activities.
    $modinfo = get_fast_modinfo($courseid);
    $allowedmodules = ['page', 'book', 'lesson', 'resource', 'url', 'folder', 'scorm', 'forum'];

    $activitydata = [];
    foreach ($modinfo->get_cms() as $cm) {
        if (in_array($cm->modname, $allowedmodules) && $cm->uservisible) {
            $activityname = format_string($cm->name, true, ['context' => context_module::instance($cm->id)]);
            $activitytype = get_string('modulename', $cm->modname);

            // Get icon for activity type.
            $emojimap = [
                'page' => '<i class="fa fa-file-text-o hlai-icon-purple"></i>',
                'book' => '<i class="fa fa-book hlai-icon-primary"></i>',
                'lesson' => '<i class="fa fa-graduation-cap hlai-icon-info"></i>',
                'resource' => '<i class="fa fa-paperclip hlai-icon-purple"></i>',
                'url' => '<i class="fa fa-link hlai-icon-info"></i>',
                'folder' => '<i class="fa fa-folder hlai-icon-warning"></i>',
                'scorm' => '<i class="fa fa-cube hlai-icon-primary"></i>',
                'forum' => '<i class="fa fa-comments hlai-icon-info"></i>',
            ];
            $emoji = $emojimap[$cm->modname] ?? '<i class="fa fa-file hlai-icon-info"></i>';

            $activitydata[] = [
                'id' => $cm->id,
                'name' => $activityname,
                'type' => $activitytype,
                'emoji_html' => $emoji,
            ];
        }
    }

    // Prepare template context.
    $context = [
        'step1_title' => get_string('step1_title', 'local_hlai_quizgen'),
        'step1_description' => get_string('step1_description', 'local_hlai_quizgen'),
        'form_url' => $formurl->out(false),
        'sesskey' => sesskey(),
        'courseid' => $courseid,
        'select_content_sources' => get_string('select_content_sources', 'local_hlai_quizgen'),
        'select_content_sources_help' => get_string('select_content_sources_help', 'local_hlai_quizgen'),
        'group1_title' => get_string('wizard_add_your_own_content', 'local_hlai_quizgen'),
        'source_manual' => get_string('source_manual', 'local_hlai_quizgen'),
        'source_manual_desc' => get_string('source_manual_desc', 'local_hlai_quizgen'),
        'source_upload' => get_string('source_upload', 'local_hlai_quizgen'),
        'source_upload_desc' => get_string('source_upload_desc', 'local_hlai_quizgen'),
        'source_url_title' => get_string('wizard_extract_from_url', 'local_hlai_quizgen'),
        'source_url_desc' => get_string('wizard_fetch_content_from_web', 'local_hlai_quizgen'),
        'group2_title' => get_string('wizard_use_course_content', 'local_hlai_quizgen'),
        'source_activities' => get_string('source_activities', 'local_hlai_quizgen'),
        'source_activities_desc' => get_string('source_activities_desc', 'local_hlai_quizgen'),
        'wizard_recommended' => get_string('wizard_recommended', 'local_hlai_quizgen'),
        'source_scan_course' => get_string('wizard_scan_entire_course', 'local_hlai_quizgen'),
        'source_scan_course_desc' => get_string('wizard_scan_entire_course_desc', 'local_hlai_quizgen'),
        'source_scan_resources' => get_string('wizard_scan_all_resources', 'local_hlai_quizgen'),
        'source_scan_resources_desc' => get_string('wizard_scan_all_resources_desc', 'local_hlai_quizgen'),
        'source_scan_activities' => get_string('wizard_scan_all_activities', 'local_hlai_quizgen'),
        'source_scan_activities_desc' => get_string('wizard_scan_all_activities_desc', 'local_hlai_quizgen'),
        'selected_sources' => get_string('selected_sources', 'local_hlai_quizgen'),
        'manual_text_entry' => get_string('manual_text_entry', 'local_hlai_quizgen'),
        'manual_text_entry_help' => get_string('manual_text_entry_help', 'local_hlai_quizgen'),
        'manual_text_placeholder' => get_string('manual_text_placeholder', 'local_hlai_quizgen'),
        'upload_files' => get_string('upload_files', 'local_hlai_quizgen'),
        'upload_files_help' => get_string('upload_files_help', 'local_hlai_quizgen'),
        'choose_files' => get_string('choose_files', 'local_hlai_quizgen'),
        'no_files_selected' => get_string('wizard_no_files_selected', 'local_hlai_quizgen'),
        'supported_formats' => get_string('supported_formats', 'local_hlai_quizgen'),
        'php_limits' => get_string('wizard_php_limits', 'local_hlai_quizgen', $phplimits),
        'url_label' => get_string('wizard_extract_from_url', 'local_hlai_quizgen'),
        'url_help' => get_string('wizard_enter_urls_help', 'local_hlai_quizgen'),
        'url_placeholder' => get_string('wizard_url_placeholder', 'local_hlai_quizgen'),
        'url_per_line_help' => get_string('wizard_url_per_line_help', 'local_hlai_quizgen'),
        'select_activities' => get_string('select_activities', 'local_hlai_quizgen'),
        'select_activities_help' => get_string('select_activities_help', 'local_hlai_quizgen'),
        'has_activities' => !empty($activitydata),
        'activities' => $activitydata,
        'activities_count_text' => get_string('wizard_activities_available', 'local_hlai_quizgen', count($activitydata)),
        'select_all' => get_string('select_all', 'local_hlai_quizgen'),
        'deselect_all' => get_string('deselect_all_topics', 'local_hlai_quizgen'),
        'n_activities_selected' => get_string('wizard_n_activities_selected', 'local_hlai_quizgen', 0),
        'noactivities' => get_string('noactivities', 'local_hlai_quizgen'),
        'cancel_url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
        'cancel' => get_string('cancel'),
        'next' => get_string('next'),
    ];

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step1', $context);
}

/**
 * Render step 2: Topic configuration.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function local_hlai_quizgen_render_step2(int $courseid, int $requestid): string {
    global $DB, $PAGE, $CFG, $OUTPUT;

    // Validate request ID - redirect to Step 1 if invalid.
    if ($requestid === 0) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'step' => 1,
            ]),
            get_string('wizard_please_start_step1', 'local_hlai_quizgen'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
        return '';
    }

    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);
    $errors = [];

    // If the request is still "pending" (e.g., after a reset), bump it into "analyzing".
    // So the synchronous analysis path below can run on this page load.
    if ($request->status === 'pending') {
        try {
            \local_hlai_quizgen\api::update_request_status($requestid, 'analyzing');
            $request->status = 'analyzing';
        } catch (\Throwable $e) {
            $errors[] = get_string('wizard_could_not_update_status', 'local_hlai_quizgen', $e->getMessage());
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
        $coursecontext = context_course::instance($courseid);
        $files = $fs->get_area_files(
            $coursecontext->id,
            'local_hlai_quizgen',
            'content',
            $requestid,
            'filename',
            false
        );

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
                        $errors[] = get_string('wizard_scan_course_failed', 'local_hlai_quizgen', $e->getMessage());
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
                        $errors[] = get_string('wizard_scan_resources_failed', 'local_hlai_quizgen', $e->getMessage());
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
                        $errors[] = get_string('wizard_bulk_scan_failed', 'local_hlai_quizgen', $e->getMessage());
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
                $errors[] = get_string('error:analysisfailed', 'local_hlai_quizgen') . ': ' . $e->getMessage();
            }
        } else {
            $errors[] = get_string('error:nocontent', 'local_hlai_quizgen');
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

    $hastopics = !empty($topics);

    // Prepare template context.
    $context = [
        'step2_title' => get_string('step2_title', 'local_hlai_quizgen'),
        'step2_description' => get_string('step2_description', 'local_hlai_quizgen'),
        'errors' => array_map(function ($msg) {
            return ['message' => $msg];
        }, $errors),
        'has_topics' => $hastopics,
        'analyzing' => !$hastopics,
        'analyzing_content' => get_string('analyzing_content', 'local_hlai_quizgen'),
    ];

    if ($hastopics) {
        $formurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'action' => 'save_topic_selection',
        ]);
        $context['form_url'] = $formurl->out(false);
        $context['sesskey'] = sesskey();
        $context['topics_found'] = get_string('topics_found', 'local_hlai_quizgen');
        $context['topics_select_help'] = get_string('topics_select_help', 'local_hlai_quizgen');
        $context['select_all_topics'] = get_string('select_all_topics', 'local_hlai_quizgen');
        $context['deselect_all_topics'] = get_string('deselect_all_topics', 'local_hlai_quizgen');
        $context['topics_discovered'] = get_string('wizard_topics_discovered', 'local_hlai_quizgen', count($topics));
        $context['n_topics_selected'] = get_string('wizard_n_topics_selected', 'local_hlai_quizgen', 0);
        $context['prev_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid, 'requestid' => $requestid, 'step' => 1,
        ]))->out(false);
        $context['previous'] = get_string('previous');
        $context['next'] = get_string('next');

        $topicsdata = [];
        foreach ($topics as $topic) {
            $topicsdata[] = [
                'id' => $topic->id,
                'title' => format_string($topic->title),
                'has_description' => !empty($topic->description),
                'description' => !empty($topic->description) ? format_text($topic->description) : '',
            ];
        }
        $context['topics'] = $topicsdata;

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
        // Auto-refresh after 3 seconds.
        $PAGE->requires->js_amd_inline("
            setTimeout(function() {
                window.location.reload();
            }, 3000);
        ");
    }

    return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step2', $context);
}

/**
 * Render step 3: Question parameters.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function local_hlai_quizgen_render_step3(int $courseid, int $requestid): string {
    global $DB, $OUTPUT;

    // Validate request ID - redirect to Step 1 if invalid.
    if ($requestid === 0) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'step' => 1,
            ]),
            get_string('wizard_please_start_step1', 'local_hlai_quizgen'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
        return '';
    }

    // Count selected topics.
    $selectedtopics = $DB->get_records('local_hlai_quizgen_topics', ['requestid' => $requestid, 'selected' => 1]);

    // Prepare template context.
    $context = [
        'step3_title' => get_string('step3_title', 'local_hlai_quizgen'),
        'step3_description' => get_string('step3_description', 'local_hlai_quizgen'),
        'no_topics' => empty($selectedtopics),
    ];

    if (empty($selectedtopics)) {
        $context['no_topics_message'] = get_string('wizard_no_topics_go_back', 'local_hlai_quizgen');
        $context['back_to_step2_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid, 'requestid' => $requestid, 'step' => 2,
        ]))->out(false);
        $context['back_to_step2'] = get_string('wizard_back_to_step2', 'local_hlai_quizgen');

        return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step3', $context);
    }

    // Minimal topics info bar (built as HTML since it's simple formatting).
    $topicnames = array_map(function ($t) {
        return $t->title;
    }, $selectedtopics);
    $topicspreview = implode(', ', array_slice($topicnames, 0, 3));
    if (count($topicnames) > 3) {
        $topicspreview .= ' +' . get_string('wizard_n_more', 'local_hlai_quizgen', (count($topicnames) - 3));
    }
    $context['topics_info_html'] = html_writer::div(
        html_writer::tag(
            'span',
            '<i class="fa fa-info-circle hlai-icon-info"></i> ',
            ['class' => 'hlai-info-icon']
        ) .
        html_writer::tag(
            'span',
            get_string('wizard_n_topics_selected_colon', 'local_hlai_quizgen', count($selectedtopics)),
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
    $context['form_url'] = $formurl->out(false);
    $context['sesskey'] = sesskey();

    // Total questions label.
    $context['total_questions_label'] = get_string('total_questions', 'local_hlai_quizgen');

    // Difficulty options.
    $context['question_difficulty_label'] = get_string('question_difficulty', 'local_hlai_quizgen');
    $context['difficulty_options'] = [
        [
            'value' => 'easy_only',
            'label' => get_string('diff_easy', 'local_hlai_quizgen'),
            'css_class' => 'is-easy',
            'is_checked' => false,
        ],
        [
            'value' => 'balanced',
            'label' => get_string('wizard_balanced', 'local_hlai_quizgen'),
            'css_class' => 'is-balanced',
            'is_checked' => true,
        ],
        [
            'value' => 'hard_only',
            'label' => get_string('diff_hard', 'local_hlai_quizgen'),
            'css_class' => 'is-hard',
            'is_checked' => false,
        ],
    ];

    // Question types.
    $context['question_types_label'] = get_string('question_types', 'local_hlai_quizgen');
    $context['qtype_count_hint'] = get_string('wizard_qtype_count_hint', 'local_hlai_quizgen');
    $questiontypes = [
        'multichoice' => [
            'label' => get_string('qtype_multichoice', 'local_hlai_quizgen'),
            'icon' => '<i class="fa fa-dot-circle-o"></i>', 'color' => '#3B82F6',
        ],
        'truefalse' => [
            'label' => get_string('qtype_truefalse', 'local_hlai_quizgen'),
            'icon' => '<i class="fa fa-check"></i>', 'color' => '#10B981',
        ],
        'shortanswer' => [
            'label' => get_string('qtype_shortanswer', 'local_hlai_quizgen'),
            'icon' => '<i class="fa fa-pencil"></i>', 'color' => '#F59E0B',
        ],
        'essay' => [
            'label' => get_string('qtype_essay', 'local_hlai_quizgen'),
            'icon' => '<i class="fa fa-file-text-o"></i>', 'color' => '#64748B',
        ],
        'scenario' => [
            'label' => get_string('wizard_qtype_scenario', 'local_hlai_quizgen'),
            'icon' => '<i class="fa fa-bullseye"></i>', 'color' => '#EF4444',
        ],
    ];
    $qtypedata = [];
    foreach ($questiontypes as $type => $info) {
        $iconstyle = 'background: ' . $info['color'] . '15; color: ' . $info['color'] . ';';
        $qtypedata[] = [
            'type' => $type,
            'label' => $info['label'],
            'icon_html' => $info['icon'],
            'color' => $info['color'],
            'icon_style' => $iconstyle,
        ];
    }
    $context['question_types'] = $qtypedata;

    // Total label (shared by qtypes and blooms).
    $context['total_label'] = get_string('wizard_total', 'local_hlai_quizgen');

    // Bloom's Taxonomy.
    $context['blooms_taxonomy_label'] = get_string('blooms_taxonomy', 'local_hlai_quizgen');
    $context['blooms_hint'] = get_string('wizard_blooms_hint', 'local_hlai_quizgen');
    $bloomslevels = [
        'remember' => ['label' => get_string('bloom_remember', 'local_hlai_quizgen'), 'default' => 20, 'color' => '#EF4444'],
        'understand' => ['label' => get_string('bloom_understand', 'local_hlai_quizgen'), 'default' => 25, 'color' => '#F59E0B'],
        'apply' => ['label' => get_string('bloom_apply', 'local_hlai_quizgen'), 'default' => 25, 'color' => '#10B981'],
        'analyze' => ['label' => get_string('bloom_analyze', 'local_hlai_quizgen'), 'default' => 15, 'color' => '#3B82F6'],
        'evaluate' => ['label' => get_string('bloom_evaluate', 'local_hlai_quizgen'), 'default' => 10, 'color' => '#8B5CF6'],
        'create' => ['label' => get_string('bloom_create', 'local_hlai_quizgen'), 'default' => 5, 'color' => '#EC4899'],
    ];
    $bloomsdata = [];
    foreach ($bloomslevels as $level => $info) {
        $sliderfill = $info['default'];
        $bloomsdata[] = [
            'level' => $level,
            'label' => $info['label'],
            'default_val' => $info['default'],
            'color' => $info['color'],
            'dot_style' => 'background: ' . $info['color'] . ';',
            'slider_style' => '--slider-color: ' . $info['color'] . '; background: linear-gradient(to right, ' .
                $info['color'] . ' 0%, ' . $info['color'] . ' ' . $sliderfill . '%, ' .
                '#e2e8f0 ' . $sliderfill . '%, #e2e8f0 100%);',
            'value_style' => 'color: ' . $info['color'],
        ];
    }
    $context['blooms_levels'] = $bloomsdata;

    // Navigation.
    $context['prev_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid, 'requestid' => $requestid, 'step' => 2,
    ]))->out(false);
    $context['previous'] = get_string('previous');
    $context['generate_questions'] = get_string('generate_questions', 'local_hlai_quizgen');

    // Loading overlay strings.
    $context['generating_title'] = get_string('wizard_generating_questions_ellipsis', 'local_hlai_quizgen');
    $context['generating_subtitle'] = get_string('wizard_generating_please_wait', 'local_hlai_quizgen');
    $context['generating_warning'] = get_string('wizard_do_not_close', 'local_hlai_quizgen');

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step3', $context);
}

/**
 * Render step 3.5: Progress monitoring.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function local_hlai_quizgen_render_step3_5(int $courseid, int $requestid): string {
    global $DB, $OUTPUT;

    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

    // Check if already completed - redirect to step 4.
    if ($request->status === 'completed') {
        redirect(new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'requestid' => $requestid,
            'step' => 4,
        ]));
    }

    // Prepare template context.
    $context = [
        'is_failed' => ($request->status === 'failed'),
        'generating_questions' => get_string('generating_questions', 'local_hlai_quizgen'),
    ];

    if ($request->status === 'failed') {
        $context['failed_message'] = get_string('generation_failed', 'local_hlai_quizgen') . ': ' .
            s($request->error_message ?? get_string('error:unknown', 'local_hlai_quizgen'));
        $context['start_over_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid, 'step' => 1,
        ]))->out(false);
        $context['wizard_start_over'] = get_string('wizard_start_over', 'local_hlai_quizgen');
    } else {
        $context['generating_questions_desc'] = get_string('generating_questions_desc', 'local_hlai_quizgen');
        $context['progress'] = (int)($request->progress ?? 0);
        $context['statusmessage'] = s($request->status_message ?? get_string('wizard_processing', 'local_hlai_quizgen'));
    }

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step3_5', $context);
}

/**
 * Render step 4: Review & edit.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function local_hlai_quizgen_render_step4(int $courseid, int $requestid): string {
    global $DB, $PAGE, $OUTPUT;

    // Validate request ID - redirect to Step 1 if invalid.
    if ($requestid === 0) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'step' => 1,
            ]),
            get_string('wizard_please_start_step1', 'local_hlai_quizgen'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
        return '';
    }

    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], '*', MUST_EXIST);

    // Prepare template context.
    $context = [
        'step4_title' => '<i class="fa fa-clipboard hlai-icon-primary"></i> ' .
            get_string('step4_title', 'local_hlai_quizgen'),
        'step4_description' => get_string('step4_description', 'local_hlai_quizgen'),
        'is_pending' => false,
        'is_failed' => false,
        'no_questions' => false,
        'has_questions' => false,
    ];

    // Check request status — pending (still generating).
    if ($request->status === 'pending') {
        $context['is_pending'] = true;
        $context['generating_title'] = get_string('wizard_generating_questions_ellipsis', 'local_hlai_quizgen');
        $context['generating_subtitle'] = get_string('wizard_generating_please_wait', 'local_hlai_quizgen');
        $context['generating_warning'] = get_string('wizard_do_not_close', 'local_hlai_quizgen');

        // Auto-refresh after 5 seconds.
        $PAGE->requires->js_amd_inline("
            setTimeout(function() {
                window.location.reload();
            }, 5000);
        ");

        return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step4', $context);
    }

    // Failed status.
    if ($request->status === 'failed') {
        $context['is_failed'] = true;
        $context['failed_message'] = get_string('generation_failed', 'local_hlai_quizgen') . ': ' . $request->error_message;
        $context['retry_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid, 'requestid' => $requestid, 'step' => 3,
        ]))->out(false);
        $context['retry'] = get_string('retry', 'local_hlai_quizgen');

        return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step4', $context);
    }

    // Get generated questions.
    $questions = $DB->get_records('local_hlai_quizgen_questions', ['requestid' => $requestid], 'id ASC');

    if (empty($questions)) {
        $context['no_questions'] = true;
        $context['no_questions_message'] = get_string('no_questions_generated', 'local_hlai_quizgen');

        return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step4', $context);
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

    $context['has_questions'] = true;
    $context['total_count'] = count($questions);
    $context['approved_count'] = $approvedcount;
    $context['pending_count'] = $pendingcount;
    $context['rejected_count'] = $rejectedcount;

    // Labels.
    $context['total_upper'] = get_string('wizard_total_upper', 'local_hlai_quizgen');
    $context['approved_label'] = get_string('approved', 'local_hlai_quizgen');
    $context['pending_label'] = get_string('pending', 'local_hlai_quizgen');
    $context['rejected_label'] = get_string('rejected', 'local_hlai_quizgen');
    $context['select_all_label'] = get_string('select_all', 'local_hlai_quizgen');
    $context['bulk_action_label'] = get_string('bulk_action', 'local_hlai_quizgen');
    $context['approve_selected'] = get_string('approve_selected', 'local_hlai_quizgen');
    $context['reject_selected'] = get_string('reject_selected', 'local_hlai_quizgen');
    $context['delete_selected'] = get_string('delete_selected', 'local_hlai_quizgen');
    $context['apply_label'] = get_string('apply', 'local_hlai_quizgen');

    // Approve all button.
    $approveallurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'action' => 'approve_all_questions',
        'sesskey' => sesskey(),
    ]);
    $context['approve_all_url'] = $approveallurl->out(false);
    $context['approve_all_label'] = get_string('wizard_approve_all_questions', 'local_hlai_quizgen');

    // Filter labels.
    $context['filter_all_status'] = get_string('wizard_all_status', 'local_hlai_quizgen');
    $context['filter_all_types'] = get_string('wizard_all_types', 'local_hlai_quizgen');
    $context['filter_all_difficulty'] = get_string('wizard_all_difficulty', 'local_hlai_quizgen');
    $context['qtype_multichoice'] = get_string('qtype_multichoice', 'local_hlai_quizgen');
    $context['qtype_truefalse'] = get_string('qtype_truefalse', 'local_hlai_quizgen');
    $context['qtype_shortanswer'] = get_string('qtype_shortanswer', 'local_hlai_quizgen');
    $context['qtype_essay'] = get_string('qtype_essay', 'local_hlai_quizgen');
    $context['diff_easy'] = get_string('diff_easy', 'local_hlai_quizgen');
    $context['diff_medium'] = get_string('diff_medium', 'local_hlai_quizgen');
    $context['diff_hard'] = get_string('diff_hard', 'local_hlai_quizgen');

    // Pre-load all answers for these questions to avoid N+1 queries.
    $questionids = array_keys($questions);
    $allanswers = [];
    if (!empty($questionids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        $answersql = "SELECT * FROM {local_hlai_quizgen_answers}
                       WHERE questionid $insql
                    ORDER BY questionid, sortorder ASC";
        $answersraw = $DB->get_records_sql($answersql, $inparams);
        foreach ($answersraw as $ans) {
            $allanswers[$ans->questionid][] = $ans;
        }
    }

    // Difficulty string map.
    $diffstringmap = [
        'easy' => get_string('diff_easy', 'local_hlai_quizgen'),
        'medium' => get_string('diff_medium', 'local_hlai_quizgen'),
        'hard' => get_string('diff_hard', 'local_hlai_quizgen'),
    ];

    // Status string map.
    $statusstringmap = [
        'approved' => get_string('approved', 'local_hlai_quizgen'),
        'rejected' => get_string('rejected', 'local_hlai_quizgen'),
        'pending' => get_string('pending', 'local_hlai_quizgen'),
    ];

    $maxregens = get_config('local_hlai_quizgen', 'max_regenerations') ?: 5;
    $questionnumber = 0;
    $questionsdata = [];

    foreach ($questions as $question) {
        $questionnumber++;
        $questiontype = $question->questiontype ?? 'multichoice';

        $cardclass = 'card mb-4 question-card';
        if ($question->status === 'rejected') {
            $cardclass .= ' has-background-white-ter';
        }

        $typelabel = str_replace('multichoice', 'MCQ', $questiontype);
        $diffclass = $question->difficulty === 'easy' ? 'is-success' :
            ($question->difficulty === 'hard' ? 'is-danger' : 'is-warning');
        $difflabel = $diffstringmap[$question->difficulty] ?? ucfirst($question->difficulty);

        $statustext = $statusstringmap[$question->status] ?? ucfirst($question->status);
        $statustagclass = $question->status === 'approved' ? 'is-success' :
                         ($question->status === 'rejected' ? 'is-danger' : 'is-warning');

        $qdata = [
            'id' => $question->id,
            'number' => $questionnumber,
            'question_number_text' => get_string('wizard_question_number', 'local_hlai_quizgen', $questionnumber),
            'questiontext_html' => format_text($question->questiontext),
            'type_label' => strtoupper($typelabel),
            'difficulty_label' => $difflabel,
            'diff_tag_class' => $diffclass,
            'status' => $question->status ?? 'pending',
            'status_label' => $statustext,
            'status_tag_class' => $statustagclass,
            'card_class' => $cardclass,
            'data_type' => $questiontype,
            'data_difficulty' => $question->difficulty,
            'is_essay_or_scenario' => ($questiontype === 'essay' || $questiontype === 'scenario'),
            'not_approved' => ($question->status !== 'approved'),
            'not_rejected' => ($question->status !== 'rejected'),
            'approved_label' => get_string('approved', 'local_hlai_quizgen'),
        ];

        // Essay/scenario: model answer.
        if ($qdata['is_essay_or_scenario']) {
            $modelanswer = $question->generalfeedback ?? '';
            $qdata['has_model_answer'] = !empty($modelanswer);
            if (!empty($modelanswer)) {
                $qdata['model_answer_label'] = '<i class="fa fa-lightbulb-o mr-2"></i>' .
                    get_string('wizard_model_answer_criteria', 'local_hlai_quizgen');
                $qdata['model_answer_html'] = format_text($modelanswer, FORMAT_HTML);
            } else {
                $qdata['no_model_answer'] = '<i class="fa fa-info-circle mr-2"></i>' .
                    get_string('wizard_no_model_answer', 'local_hlai_quizgen');
            }
        } else {
            // MCQ, TF, Short Answer - show answer options.
            $answers = $allanswers[$question->id] ?? [];
            $qdata['has_answers'] = !empty($answers);
            if (!empty($answers)) {
                $letterlabel = 'A';
                $answersdata = [];
                foreach ($answers as $answer) {
                    $iscorrect = $answer->fraction > 0;
                    $answerclass = 'answer-row hlai-answer-row';
                    $answerclass .= $iscorrect ? ' hlai-answer-correct' : ' hlai-answer-neutral';

                    $answersdata[] = [
                        'letter' => $letterlabel,
                        'answer_html' => format_text($answer->answer ?? ''),
                        'is_correct' => $iscorrect,
                        'row_class' => $answerclass,
                    ];
                    $letterlabel++;
                }
                $qdata['answers'] = $answersdata;
            }
        }

        // Footer actions.
        if ($qdata['not_approved']) {
            $qdata['approve_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $courseid,
                'requestid' => $requestid,
                'action' => 'approve_question',
                'questionid' => $question->id,
                'sesskey' => sesskey(),
                'step' => 4,
            ]))->out(false);
            $qdata['approve_label'] = get_string('approve_question', 'local_hlai_quizgen');
        }

        if ($qdata['not_rejected']) {
            $qdata['reject_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $courseid,
                'requestid' => $requestid,
                'action' => 'reject_question',
                'questionid' => $question->id,
                'sesskey' => sesskey(),
                'step' => 4,
            ]))->out(false);
            $qdata['reject_label'] = get_string('wizard_reject', 'local_hlai_quizgen');
        }

        $remainingregens = $maxregens - ($question->regeneration_count ?? 0);
        $qdata['has_regenerations'] = ($remainingregens > 0);
        if ($remainingregens > 0) {
            $qdata['regen_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
                'courseid' => $courseid,
                'requestid' => $requestid,
                'action' => 'regenerate_question',
                'questionid' => $question->id,
                'sesskey' => sesskey(),
                'step' => 4,
            ]))->out(false);
            $qdata['regen_label'] = get_string('regenerate_question', 'local_hlai_quizgen') .
                ' (' . $remainingregens . ')';
        }

        $questionsdata[] = $qdata;
    }

    $context['questions'] = $questionsdata;

    // Navigation.
    $context['prev_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid, 'requestid' => $requestid, 'step' => 3,
    ]))->out(false);
    $context['next_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid, 'requestid' => $requestid, 'step' => 5,
    ]))->out(false);
    $context['previous'] = get_string('previous');
    $context['next'] = get_string('next');

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step4', $context);
}

/**
 * Render step 5: Deployment.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function local_hlai_quizgen_render_step5(int $courseid, int $requestid): string {
    global $DB, $OUTPUT;

    // Validate request ID - redirect to Step 1 if invalid.
    if ($requestid === 0) {
        redirect(
            new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid,
            'step' => 1,
            ]),
            get_string('wizard_please_start_step1', 'local_hlai_quizgen'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
        return '';
    }

    // Get request record for category_name (fetch full record for compatibility).
    $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid]);

    // Count approved questions only.
    $approvedcount = $DB->count_records('local_hlai_quizgen_questions', [
        'requestid' => $requestid,
        'status' => 'approved',
    ]);
    $totalcount = $DB->count_records('local_hlai_quizgen_questions', ['requestid' => $requestid]);

    // Prepare template context.
    $context = [
        'step5_title' => get_string('step5_title', 'local_hlai_quizgen'),
        'step5_description' => get_string('step5_description', 'local_hlai_quizgen'),
        'no_questions' => ($totalcount == 0),
        'no_approved' => ($totalcount > 0 && $approvedcount == 0),
        'has_approved' => ($approvedcount > 0),
    ];

    if ($totalcount == 0) {
        $context['no_questions_message'] = get_string('wizard_no_questions_go_back_step3', 'local_hlai_quizgen');
        $context['back_to_step3_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid, 'requestid' => $requestid, 'step' => 3,
        ]))->out(false);
        $context['back_to_step3'] = get_string('wizard_back_to_step3', 'local_hlai_quizgen');
    } else if ($approvedcount == 0) {
        $context['no_approved_message'] = get_string('wizard_none_approved_yet', 'local_hlai_quizgen', $totalcount);
        $context['back_to_step4_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid, 'requestid' => $requestid, 'step' => 4,
        ]))->out(false);
        $context['back_to_step4'] = get_string('wizard_back_to_step4', 'local_hlai_quizgen');
    } else {
        $readyinfo = new stdClass();
        $readyinfo->approved = $approvedcount;
        $readyinfo->total = $totalcount;
        $context['ready_deploy_message'] = get_string('wizard_approved_ready_deploy', 'local_hlai_quizgen', $readyinfo);
        $context['form_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid, 'requestid' => $requestid, 'action' => 'deploy_questions',
        ]))->out(false);
        $context['sesskey'] = sesskey();
        $context['deployment_options'] = get_string('deployment_options', 'local_hlai_quizgen');
        $context['create_new_quiz'] = get_string('create_new_quiz', 'local_hlai_quizgen');
        $context['create_quiz_desc'] = get_string('wizard_create_quiz_desc', 'local_hlai_quizgen');
        $context['quiz_name_label'] = get_string('quiz_name', 'local_hlai_quizgen');
        $context['default_quiz_name'] = get_string('wizard_default_quiz_name', 'local_hlai_quizgen') . ' - ' . date('Y-m-d');
        $context['export_to_qbank'] = get_string('export_to_question_bank', 'local_hlai_quizgen');
        $context['qbank_desc'] = get_string('wizard_qbank_desc', 'local_hlai_quizgen');
        $context['category_name_label'] = get_string('category_name', 'local_hlai_quizgen');
        $defaultcategoryname = (!empty($request) && !empty($request->category_name))
            ? $request->category_name
            : get_string('wizard_ai_generated_questions', 'local_hlai_quizgen');
        $context['default_category_name'] = $defaultcategoryname;
        $context['category_help'] = get_string('wizard_category_help', 'local_hlai_quizgen');
        $context['prev_url'] = (new moodle_url('/local/hlai_quizgen/wizard.php', [
            'courseid' => $courseid, 'requestid' => $requestid, 'step' => 4,
        ]))->out(false);
        $context['previous'] = get_string('previous');
        $context['deploy_questions'] = get_string('deploy_questions', 'local_hlai_quizgen');
    }

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $OUTPUT->render_from_template('local_hlai_quizgen/wizard_step5', $context);
}

/**
 * Check plugin dependencies and requirements.
 *
 * @return array Array of error messages (empty if all OK)
 */
function local_hlai_quizgen_check_plugin_dependencies(): array {
    $errors = [];

    // Check Gateway availability.
    try {
        if (!\local_hlai_quizgen\gateway_client::is_ready()) {
            $errors[] = get_string('wizard_gateway_not_configured', 'local_hlai_quizgen');
        }
    } catch (\Throwable $e) {
        $errors[] = get_string('wizard_gateway_check_failed', 'local_hlai_quizgen', $e->getMessage());
    }

    // Note: External libraries (Smalot, PHPWord, PHPPresentation) are no longer required.
    // File extraction now uses native PHP (ZipArchive) and system tools (Ghostscript).

    return $errors;
}
