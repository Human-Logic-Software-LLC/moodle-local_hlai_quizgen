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

$stepclass = 'hlai-step-';
if ($step === 'progress') {
    $stepclass .= 'progress';
} else if (is_numeric($step)) {
    $stepclass .= (int)$step;
} else {
    $stepclass .= '1';
}

echo html_writer::start_div('hlai-quizgen-wrapper hlai-quizgen-wizard local-hlai-iksha ' . $stepclass);

// Wizard header.
echo html_writer::start_div('has-text-centered mb-5');
echo html_writer::tag('h2', get_string('wizard_title', 'local_hlai_quizgen'), [
    'class' => 'has-text-weight-bold hlai-wizard-heading',
]);
echo html_writer::tag('p', get_string('wizard_subtitle', 'local_hlai_quizgen'), ['class' => 'has-text-grey']);
echo html_writer::end_div(); // Has-text-centered.

// Step indicator.
echo local_hlai_quizgen_render_step_indicator($step);

// Step content container.
echo html_writer::start_div('wizard-step-content', ['id' => 'wizard-step-content']);

// Render current step.
switch ($step) {
    case '1':
    case 1:
        echo local_hlai_quizgen_render_step1($courseid, $requestid);
        break;
    case '2':
    case 2:
        echo local_hlai_quizgen_render_step2($courseid, $requestid);
        break;
    case '3':
    case 3:
        echo local_hlai_quizgen_render_step3($courseid, $requestid);
        break;
    case 'progress':
        echo local_hlai_quizgen_render_step3_5($courseid, $requestid); // Progress monitoring.
        break;
    case '4':
    case 4:
        echo local_hlai_quizgen_render_step4($courseid, $requestid);
        break;
    case '5':
    case 5:
        echo local_hlai_quizgen_render_step5($courseid, $requestid);
        break;
    default:
        echo local_hlai_quizgen_render_step1($courseid, $requestid);
}

echo html_writer::end_div(); // Wizard-step-content.

echo html_writer::end_div(); // Hlai-quizgen-wizard.

// Add spacing before footer to prevent overlap.
echo html_writer::div('', 'hlai-spacer-60');

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
        list($insql, $inparams) = $DB->get_in_or_equal($selectedtopics, SQL_PARAMS_NAMED);
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
        'id' => 'content-upload-form',
        'enctype' => 'multipart/form-data',
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
    $html .= html_writer::tag(
        'h5',
        '<i class="fa fa-pencil hlai-icon-warning"></i> ' .
        get_string('wizard_add_your_own_content', 'local_hlai_quizgen'),
        [
        'class' => 'hlai-source-group-title',
        ]
    );
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
        'data-source' => 'manual',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-pencil hlai-icon-warning"></i>', ['class' => 'hlai-source-icon']);
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
        'data-source' => 'upload',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-folder-open hlai-icon-purple"></i>', ['class' => 'hlai-source-icon']);
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
        'data-source' => 'url',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-globe hlai-icon-info"></i>', ['class' => 'hlai-source-icon']);
    $html .= html_writer::tag(
        'strong',
        get_string('wizard_extract_from_url', 'local_hlai_quizgen'),
        ['class' => 'hlai-source-title']
    );
    $html .= html_writer::tag(
        'p',
        get_string('wizard_fetch_content_from_web', 'local_hlai_quizgen'),
        ['class' => 'hlai-source-desc']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Columns.
    $html .= html_writer::end_div(); // Hlai-source-group.

    // GROUP 2: Use Course Content.
    $html .= html_writer::start_div('hlai-source-group');
    $html .= html_writer::tag(
        'h5',
        '<i class="fa fa-book hlai-icon-success"></i> ' .
        get_string('wizard_use_course_content', 'local_hlai_quizgen'),
        [
        'class' => 'hlai-source-group-title',
        ]
    );
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
        'data-source' => 'activities',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-book hlai-icon-primary"></i>', [
        'class' => 'hlai-source-icon hlai-icon-lg',
    ]);
    $html .= html_writer::start_div('hlai-source-text');
    $html .= html_writer::tag('strong', get_string('source_activities', 'local_hlai_quizgen'), ['class' => 'hlai-source-title']);
    $html .= html_writer::tag('p', get_string('source_activities_desc', 'local_hlai_quizgen'), ['class' => 'hlai-source-desc']);
    $html .= html_writer::tag(
        'span',
        '<i class="fa fa-star hlai-icon-warning"></i> ' .
        get_string('wizard_recommended', 'local_hlai_quizgen'),
        [
        'class' => 'hlai-source-badge',
        ]
    );
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
        'data-source' => 'scan_course',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-graduation-cap hlai-icon-success"></i>', [
        'class' => 'hlai-source-icon',
    ]);
    $html .= html_writer::tag(
        'strong',
        get_string('wizard_scan_entire_course', 'local_hlai_quizgen'),
        ['class' => 'hlai-source-title']
    );
    $html .= html_writer::tag(
        'p',
        get_string('wizard_scan_entire_course_desc', 'local_hlai_quizgen'),
        ['class' => 'hlai-source-desc']
    );
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
        'data-source' => 'scan_resources',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-book hlai-icon-purple"></i>', ['class' => 'hlai-source-icon']);
    $html .= html_writer::tag(
        'strong',
        get_string('wizard_scan_all_resources', 'local_hlai_quizgen'),
        ['class' => 'hlai-source-title']
    );
    $html .= html_writer::tag(
        'p',
        get_string('wizard_scan_all_resources_desc', 'local_hlai_quizgen'),
        ['class' => 'hlai-source-desc']
    );
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
        'data-source' => 'scan_activities',
    ]);
    $html .= html_writer::start_div('hlai-source-content');
    $html .= html_writer::tag('span', '<i class="fa fa-edit hlai-icon-danger"></i>', ['class' => 'hlai-source-icon']);
    $html .= html_writer::tag(
        'strong',
        get_string('wizard_scan_all_activities', 'local_hlai_quizgen'),
        ['class' => 'hlai-source-title']
    );
    $html .= html_writer::tag(
        'p',
        get_string('wizard_scan_all_activities_desc', 'local_hlai_quizgen'),
        ['class' => 'hlai-source-desc']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Columns.
    $html .= html_writer::end_div(); // Hlai-source-group.

    // Display selected sources.
    $html .= html_writer::start_div('notification is-info is-light mt-4 hlai-hidden', [
        'id' => 'selected-sources-display',
    ]);
    $html .= html_writer::tag('strong', get_string('selected_sources', 'local_hlai_quizgen') . ': ');
    $html .= html_writer::tag('span', '', ['id' => 'selected-sources-list', 'class' => 'tag is-primary is-medium ml-2']);
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Field.

    // Bulk scan sections (hidden - no UI needed, just for JavaScript compatibility).
    $html .= html_writer::div('', 'hlai-hidden', ['id' => 'section-scan_course']);
    $html .= html_writer::div('', 'hlai-hidden', ['id' => 'section-scan_resources']);
    $html .= html_writer::div('', 'hlai-hidden', ['id' => 'section-scan_activities']);

    // Manual text entry section (initially hidden).
    $html .= html_writer::start_div('field mb-5 hlai-hidden', ['id' => 'section-manual']);
    $html .= html_writer::tag(
        'label',
        '<i class="fa fa-pencil hlai-icon-warning"></i> ' . get_string('manual_text_entry', 'local_hlai_quizgen'),
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
    $html .= html_writer::start_div('field mb-5 hlai-hidden', ['id' => 'section-upload']);
    $html .= html_writer::tag(
        'label',
        '<i class="fa fa-folder-open hlai-icon-purple"></i> ' . get_string('upload_files', 'local_hlai_quizgen'),
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
    $html .= html_writer::tag('span', get_string('wizard_no_files_selected', 'local_hlai_quizgen'), ['class' => 'file-name']);
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::tag('p', get_string('supported_formats', 'local_hlai_quizgen'), ['class' => 'help mt-2']);

    // Display PHP upload limits for debugging.
    $uploadmaxfilesize = ini_get('upload_max_filesize');
    $postmaxsize = ini_get('post_max_size');
    $maxfilesize = get_config('local_hlai_quizgen', 'max_file_size_mb') ?: 50;

    $phplimits = new stdClass();
    $phplimits->uploadmax = $uploadmaxfilesize;
    $phplimits->postmax = $postmaxsize;
    $phplimits->pluginmax = $maxfilesize . 'MB';
    $html .= html_writer::div(
        html_writer::tag(
            'small',
            get_string('wizard_php_limits', 'local_hlai_quizgen', $phplimits),
            ['class' => 'has-text-grey is-size-7']
        ),
        'mt-2'
    );

    $html .= html_writer::div('', 'mt-2', ['id' => 'uploaded-files-list']);
    $html .= html_writer::end_div(); // File-upload-section.

    // URL extraction section (initially hidden).
    $html .= html_writer::start_div('field mb-5 hlai-hidden', ['id' => 'section-url']);
    $html .= html_writer::tag(
        'label',
        '<i class="fa fa-globe hlai-icon-info"></i> ' .
        get_string('wizard_extract_from_url', 'local_hlai_quizgen'),
        [
        'class' => 'label',
        ]
    );
    $html .= html_writer::tag('p', get_string('wizard_enter_urls_help', 'local_hlai_quizgen'), ['class' => 'help mb-3']);

    $html .= html_writer::start_div('control');
    $html .= html_writer::tag('textarea', '', [
        'name' => 'url_list',
        'id' => 'url_list',
        'rows' => 4,
        'class' => 'textarea',
        'placeholder' => get_string('wizard_url_placeholder', 'local_hlai_quizgen'),
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::tag('p', get_string('wizard_url_per_line_help', 'local_hlai_quizgen'), ['class' => 'help mt-2']);
    $html .= html_writer::end_div(); // Url-extraction-section.

    // Activity selection section (initially hidden).
    $html .= html_writer::start_div('hlai-activities-section hlai-hidden', ['id' => 'section-activities']);

    // Section header with icon.
    $html .= html_writer::start_div('hlai-activities-header');
    $html .= html_writer::tag('span', '<i class="fa fa-book hlai-icon-info"></i>', ['class' => 'hlai-activities-icon']);
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
        $html .= html_writer::tag('button', '<i class="fa fa-check"></i> ' . get_string('select_all', 'local_hlai_quizgen'), [
            'type' => 'button',
            'id' => 'select-all-activities',
            'class' => 'hlai-action-btn hlai-action-select',
        ]);
        $html .= html_writer::tag(
            'button',
            '<i class="fa fa-times"></i> ' .
            get_string('deselect_all_topics', 'local_hlai_quizgen'),
            [
            'type' => 'button',
            'id' => 'deselect-all-activities',
            'class' => 'hlai-action-btn hlai-action-deselect',
            ]
        );
        $html .= html_writer::end_div();
        $html .= html_writer::tag('span', get_string('wizard_activities_available', 'local_hlai_quizgen', count($activities)), [
            'class' => 'hlai-activities-count',
        ]);
        $html .= html_writer::end_div();

        // Activities list with cards.
        $html .= html_writer::start_div('hlai-activities-list');

        foreach ($activities as $cm) {
            $activityname = format_string($cm->name, true, ['context' => context_module::instance($cm->id)]);
            $activitytype = get_string('modulename', $cm->modname);

            // Get emoji for activity type.
            $activityemoji = '<i class="fa fa-file hlai-icon-info"></i>';
            switch ($cm->modname) {
                case 'page':
                    $activityemoji = '<i class="fa fa-file-text-o hlai-icon-purple"></i>';
                    break;
                case 'book':
                    $activityemoji = '<i class="fa fa-book hlai-icon-primary"></i>';
                    break;
                case 'lesson':
                    $activityemoji = '<i class="fa fa-graduation-cap hlai-icon-info"></i>';
                    break;
                case 'resource':
                    $activityemoji = '<i class="fa fa-paperclip hlai-icon-purple"></i>';
                    break;
                case 'url':
                    $activityemoji = '<i class="fa fa-link hlai-icon-info"></i>';
                    break;
                case 'folder':
                    $activityemoji = '<i class="fa fa-folder hlai-icon-warning"></i>';
                    break;
                case 'scorm':
                    $activityemoji = '<i class="fa fa-cube hlai-icon-primary"></i>';
                    break;
                case 'forum':
                    $activityemoji = '<i class="fa fa-comments hlai-icon-info"></i>';
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
        $html .= html_writer::tag('span', get_string('wizard_n_activities_selected', 'local_hlai_quizgen', 0), [
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
    $html .= html_writer::start_div('level is-mobile mt-6 pt-4 hlai-border-top');
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

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $html;
}

/**
 * Render step 2: Topic configuration.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function local_hlai_quizgen_render_step2(int $courseid, int $requestid): string {
    global $DB, $PAGE, $CFG;

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
                get_string('wizard_could_not_update_status', 'local_hlai_quizgen', $e->getMessage()),
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
                        $html .= html_writer::div(
                            get_string('wizard_scan_course_failed', 'local_hlai_quizgen', $e->getMessage()),
                            'notification is-danger is-light'
                        );
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
                        $html .= html_writer::div(
                            get_string('wizard_scan_resources_failed', 'local_hlai_quizgen', $e->getMessage()),
                            'notification is-danger is-light'
                        );
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
                        $html .= html_writer::div(
                            get_string('wizard_bulk_scan_failed', 'local_hlai_quizgen', $e->getMessage()),
                            'notification is-danger is-light'
                        );
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
        $html .= html_writer::tag('span', '<i class="fa fa-book hlai-icon-success"></i>', ['class' => 'hlai-topics-icon']);
        $html .= html_writer::start_div('hlai-topics-header-text');
        $html .= html_writer::tag('h3', get_string('topics_found', 'local_hlai_quizgen'), ['class' => 'hlai-topics-title']);
        $html .= html_writer::tag('p', get_string('topics_select_help', 'local_hlai_quizgen'), ['class' => 'hlai-topics-subtitle']);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();

        // Action bar with buttons and count.
        $html .= html_writer::start_div('hlai-topics-actions');
        $html .= html_writer::start_div('hlai-topics-buttons');
        $html .= html_writer::tag(
            'button',
            '<i class="fa fa-check"></i> ' .
            get_string('select_all_topics', 'local_hlai_quizgen'),
            [
            'type' => 'button',
            'id' => 'select-all-topics',
            'class' => 'hlai-action-btn hlai-action-select',
            ]
        );
        $html .= html_writer::tag(
            'button',
            '<i class="fa fa-times"></i> ' .
            get_string('deselect_all_topics', 'local_hlai_quizgen'),
            [
            'type' => 'button',
            'id' => 'deselect-all-topics',
            'class' => 'hlai-action-btn hlai-action-deselect',
            ]
        );
        $html .= html_writer::end_div();
        $html .= html_writer::tag('span', get_string('wizard_topics_discovered', 'local_hlai_quizgen', count($topics)), [
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
            $html .= html_writer::tag('span', '<i class="fa fa-book hlai-icon-primary"></i>', [
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
        $html .= html_writer::tag(
            'span',
            get_string('wizard_n_topics_selected', 'local_hlai_quizgen', 0),
            ['id' => 'selected-topics-count', 'class' => 'hlai-selected-text']
        );
        $html .= html_writer::end_div();

        $html .= html_writer::end_div(); // Hlai-topics-section.

        // Navigation.
        $html .= html_writer::start_div('level is-mobile mt-6 pt-4 hlai-border-top');
        $html .= html_writer::start_div('level-left');
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 1]),
            '<i class="fa fa-arrow-left hlai-icon-info"></i> ' . get_string('previous'),
            ['class' => 'button is-light']
        );
        $html .= html_writer::end_div();
        $html .= html_writer::start_div('level-right');
        $html .= html_writer::tag('button', get_string('next') . ' <i class="fa fa-arrow-right hlai-icon-white"></i>', [
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
            html_writer::tag('span', '<i class="fa fa-hourglass-2 hlai-icon-warning"></i>', [
                'class' => 'hlai-font-lg-mr',
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
    $html .= html_writer::div('', 'hlai-spacer-60');

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
function local_hlai_quizgen_render_step3(int $courseid, int $requestid): string {
    global $DB, $CFG;

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
            get_string('wizard_no_topics_go_back', 'local_hlai_quizgen'),
            'notification is-warning'
        );
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 2]),
            '<i class="fa fa-arrow-left hlai-icon-info"></i> ' . get_string('wizard_back_to_step2', 'local_hlai_quizgen'),
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
        $topicspreview .= ' +' . get_string('wizard_n_more', 'local_hlai_quizgen', (count($topicnames) - 3));
    }
    $html .= html_writer::div(
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

    $html .= html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'id' => 'question-config-form',
    ]);

    $html .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);

    $html .= html_writer::start_div('hlai-config-grid');

    // TOTAL QUESTIONS - Compact card.
    $html .= html_writer::start_div('hlai-config-card');
    $html .= html_writer::tag(
        'label',
        get_string('total_questions', 'local_hlai_quizgen'),
        ['class' => 'hlai-config-label', 'for' => 'total-questions']
    );
    $html .= html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'total_questions',
        'id' => 'total-questions',
        'class' => 'hlai-config-input',
        'min' => '1',
        'max' => '100',
        'value' => '10',
        'required' => 'required',
    ]);
    $html .= html_writer::end_div();

    // Difficulty - Compact card.
    $html .= html_writer::start_div('hlai-config-card');
    $html .= html_writer::tag('label', get_string('question_difficulty', 'local_hlai_quizgen'), ['class' => 'hlai-config-label']);
    $html .= html_writer::start_div('hlai-diff-buttons');
    $diffoptions = [
        'easy_only' => ['label' => get_string('diff_easy', 'local_hlai_quizgen'), 'class' => 'is-easy'],
        'balanced' => ['label' => get_string('wizard_balanced', 'local_hlai_quizgen'), 'class' => 'is-balanced'],
        'hard_only' => ['label' => get_string('diff_hard', 'local_hlai_quizgen'), 'class' => 'is-hard'],
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
    $html .= html_writer::tag('span', '<i class="fa fa-edit hlai-icon-primary"></i>', ['class' => 'hlai-section-icon']);
    $html .= html_writer::start_div('hlai-section-title-wrap');
    $html .= html_writer::tag('h4', get_string('question_types', 'local_hlai_quizgen'), ['class' => 'hlai-section-label']);
    $html .= html_writer::tag('p', get_string('wizard_qtype_count_hint', 'local_hlai_quizgen'), ['class' => 'hlai-section-hint']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

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

    $html .= html_writer::start_div('hlai-qtype-list');
    foreach ($questiontypes as $type => $info) {
        $html .= html_writer::start_div('hlai-qtype-row', ['data-type' => $type]);
        $html .= html_writer::start_div('hlai-qtype-left hlai-flex-center');
        $iconstyle = 'background: ' . $info['color'] . '15; color: ' . $info['color'] . ';';
        $html .= html_writer::tag(
            'span',
            $info['icon'],
            ['class' => 'hlai-qtype-icon hlai-flex-center-all', 'style' => $iconstyle]
        );
        $html .= html_writer::tag(
            'span',
            $info['label'],
            ['class' => 'hlai-qtype-name hlai-flex-center']
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
        ]);
        $html .= html_writer::end_div();
    }
    $html .= html_writer::start_div('hlai-qtype-row hlai-qtype-total');
    $html .= html_writer::tag('span', get_string('wizard_total', 'local_hlai_quizgen'), ['class' => 'hlai-qtype-name']);
    $html .= html_writer::tag('span', '0 / 10', ['id' => 'qtype-total-display', 'class' => 'hlai-qtype-total-val']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div(); // Hlai-qtype-list.
    $html .= html_writer::end_div(); // Hlai-section.

    // Bloom's Taxonomy - Visual with colored levels.
    $html .= html_writer::start_div('hlai-section hlai-section-blooms mt-5');
    $html .= html_writer::start_div('hlai-section-header');
    $html .= html_writer::tag('span', '<i class="fa fa-lightbulb-o hlai-icon-primary"></i>', [
        'class' => 'hlai-section-icon',
    ]);
    $html .= html_writer::start_div('hlai-section-title-wrap');
    $html .= html_writer::tag('h4', get_string('blooms_taxonomy', 'local_hlai_quizgen'), ['class' => 'hlai-section-label']);
    $html .= html_writer::tag('p', get_string('wizard_blooms_hint', 'local_hlai_quizgen'), ['class' => 'hlai-section-hint']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $bloomslevels = [
        'remember' => [
            'label' => get_string('bloom_remember', 'local_hlai_quizgen'),
            'default' => 20,
            'color' => '#EF4444',
            'desc' => get_string('wizard_bloom_recall_facts', 'local_hlai_quizgen'),
        ],
        'understand' => [
            'label' => get_string('bloom_understand', 'local_hlai_quizgen'),
            'default' => 25,
            'color' => '#F59E0B',
            'desc' => get_string('wizard_bloom_explain_concepts', 'local_hlai_quizgen'),
        ],
        'apply' => [
            'label' => get_string('bloom_apply', 'local_hlai_quizgen'),
            'default' => 25,
            'color' => '#10B981',
            'desc' => get_string('wizard_bloom_use_knowledge', 'local_hlai_quizgen'),
        ],
        'analyze' => [
            'label' => get_string('bloom_analyze', 'local_hlai_quizgen'),
            'default' => 15,
            'color' => '#3B82F6',
            'desc' => get_string('wizard_bloom_break_down', 'local_hlai_quizgen'),
        ],
        'evaluate' => [
            'label' => get_string('bloom_evaluate', 'local_hlai_quizgen'),
            'default' => 10,
            'color' => '#8B5CF6',
            'desc' => get_string('wizard_bloom_make_judgments', 'local_hlai_quizgen'),
        ],
        'create' => [
            'label' => get_string('bloom_create', 'local_hlai_quizgen'),
            'default' => 5,
            'color' => '#EC4899',
            'desc' => get_string('wizard_bloom_produce_new', 'local_hlai_quizgen'),
        ],
    ];

    $html .= html_writer::start_div('hlai-blooms-list');
    foreach ($bloomslevels as $level => $info) {
        $sliderfill = $info['default'];
        $html .= html_writer::start_div('hlai-blooms-item', ['data-level' => $level]);
        $html .= html_writer::start_div('hlai-blooms-label-wrap hlai-flex-center');
        $html .= html_writer::tag('span', '', [
            'class' => 'hlai-blooms-dot hlai-flex-center',
            'style' => 'background: ' . $info['color'] . ';',
        ]);
        $html .= html_writer::tag('span', $info['label'], [
            'class' => 'hlai-blooms-name hlai-flex-center',
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
    $html .= html_writer::tag('span', get_string('wizard_total', 'local_hlai_quizgen'), ['class' => 'hlai-blooms-name']);
    $html .= html_writer::tag('span', '100%', ['id' => 'blooms_total', 'class' => 'hlai-blooms-total-val']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div(); // Hlai-blooms-list.
    $html .= html_writer::end_div(); // Hlai-section.

    // Processing mode removed (global setting).
    // Cost preview section hidden per user request.

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    // Navigation.
    $html .= html_writer::start_div('level is-mobile mt-5');
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::link(
        new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 2]),
        '<i class="fa fa-arrow-left hlai-icon-info"></i> ' . get_string('previous'),
        ['class' => 'button is-light']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('level-right');
    $html .= html_writer::tag('button', '<i class="fa fa-rocket hlai-icon-white"></i> ' .
        get_string('generate_questions', 'local_hlai_quizgen'), [
        'type' => 'submit',
        'class' => 'button is-primary',
        'id' => 'generate-btn',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_tag('form');

    // Loading overlay.
    $html .= html_writer::start_div('hlai-generating-overlay hlai-hidden', [
        'id' => 'loading-overlay',
    ]);
    $html .= html_writer::start_div('hlai-generating-modal');
    $html .= html_writer::div('', 'loader');
    $html .= html_writer::tag(
        'h3',
        '<i class="fa fa-lightbulb-o hlai-icon-primary"></i> ' .
        get_string('wizard_generating_questions_ellipsis', 'local_hlai_quizgen'),
        [
        'class' => 'generating-title',
        ]
    );
    $html .= html_writer::tag('p', get_string('wizard_generating_please_wait', 'local_hlai_quizgen'), [
        'class' => 'generating-subtitle',
    ]);
    $html .= html_writer::tag('p', get_string('wizard_do_not_close', 'local_hlai_quizgen'), ['class' => 'generating-warning']);
    $html .= html_writer::end_div(); // Hlai-generating-modal.
    $html .= html_writer::end_div(); // Hlai-generating-overlay.

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $html;
}

/**
 * Render step 3.5: Progress monitoring.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function local_hlai_quizgen_render_step3_5(int $courseid, int $requestid): string {
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
            get_string('generation_failed', 'local_hlai_quizgen') . ': ' .
            s($request->error_message ?? get_string('error:unknown', 'local_hlai_quizgen')),
            'notification is-danger is-light mt-4'
        );
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'step' => 1]),
            '<i class="fa fa-refresh hlai-icon-info"></i> ' . get_string('wizard_start_over', 'local_hlai_quizgen'),
            ['class' => 'button is-primary mt-3']
        );
        return $html;
    }

    // Calculate progress from database.
    $progress = (int)($request->progress ?? 0);
    $statusmessage = $request->status_message ?? get_string('wizard_processing', 'local_hlai_quizgen');

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

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $html;
}

/**
 * Render step 4: Review & edit.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID
 * @return string HTML
 */
function local_hlai_quizgen_render_step4(int $courseid, int $requestid): string {
    global $DB, $PAGE;

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

    $html = html_writer::start_div('hlai-step-content');

    // Header.
    $html .= html_writer::tag(
        'h2',
        '<i class="fa fa-clipboard hlai-icon-primary"></i> ' .
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

        $html .= html_writer::tag(
            'h3',
            '<i class="fa fa-lightbulb-o hlai-icon-primary"></i> ' .
            get_string('wizard_generating_questions_ellipsis', 'local_hlai_quizgen'),
            [
            'class' => 'title is-5 mb-2',
            ]
        );
        $html .= html_writer::tag('p', get_string('wizard_generating_please_wait', 'local_hlai_quizgen'), [
            'class' => 'has-text-grey is-size-6 mb-4',
        ]);
        $html .= html_writer::tag('p', get_string('wizard_do_not_close', 'local_hlai_quizgen'), [
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
    $html .= html_writer::span(
        get_string('wizard_total_upper', 'local_hlai_quizgen'),
        'heading has-text-grey-light is-size-7 has-text-weight-bold'
    );
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Approved Card.
    $html .= html_writer::start_div('column is-3-desktop is-6-mobile');
    $html .= html_writer::start_div('box has-text-centered is-success-light');
    $html .= html_writer::span($approvedcount, 'title is-3 is-block mb-1 has-text-success');
    $html .= html_writer::span(
        get_string('approved', 'local_hlai_quizgen'),
        'heading has-text-success-dark is-size-7 has-text-weight-bold'
    );
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Pending Card.
    $html .= html_writer::start_div('column is-3-desktop is-6-mobile');
    $html .= html_writer::start_div('box has-text-centered is-warning-light');
    $html .= html_writer::span($pendingcount, 'title is-3 is-block mb-1 has-text-warning-dark');
    $html .= html_writer::span(
        get_string('pending', 'local_hlai_quizgen'),
        'heading has-text-warning-dark is-size-7 has-text-weight-bold'
    );
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Rejected Card.
    $html .= html_writer::start_div('column is-3-desktop is-6-mobile');
    $html .= html_writer::start_div('box has-text-centered is-danger-light');
    $html .= html_writer::span($rejectedcount, 'title is-3 is-block mb-1 has-text-danger');
    $html .= html_writer::span(
        get_string('rejected', 'local_hlai_quizgen'),
        'heading has-text-danger-dark is-size-7 has-text-weight-bold'
    );
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_div(); // Columns.

    // Wrap the review area in a clean container, removing the outer box.
    $html .= html_writer::start_div('questions-review-wrapper');

    // Clean toolbar using Bulma level.
    $html .= html_writer::start_div('level is-mobile mb-5 py-2');

    // Left: Select all + Bulk action.
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_tag('label', ['class' => 'checkbox']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'id' => 'select-all-questions',
        'class' => 'mr-1',
    ]);
    $html .= ' ' . get_string('select_all', 'local_hlai_quizgen');
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_div('field has-addons');
    $html .= html_writer::start_div('control');
    $html .= html_writer::start_div('select is-small');
    $html .= html_writer::start_tag('select', ['id' => 'bulk-action-select']);
    $html .= html_writer::tag('option', get_string('bulk_action', 'local_hlai_quizgen'), ['value' => '']);
    $html .= html_writer::tag('option', get_string('approve_selected', 'local_hlai_quizgen'), ['value' => 'approve']);
    $html .= html_writer::tag('option', get_string('reject_selected', 'local_hlai_quizgen'), ['value' => 'reject']);
    $html .= html_writer::tag('option', get_string('delete_selected', 'local_hlai_quizgen'), ['value' => 'delete']);
    $html .= html_writer::end_tag('select');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('control');
    $html .= html_writer::tag('button', get_string('apply', 'local_hlai_quizgen'), [
        'type' => 'button',
        'class' => 'button is-primary is-small',
        'id' => 'bulk-action-btn',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Add Quick Approve All button.
    $html .= html_writer::start_div('level-item');
    $approveallurl = new moodle_url('/local/hlai_quizgen/wizard.php', [
        'courseid' => $courseid,
        'requestid' => $requestid,
        'action' => 'approve_all_questions',
        'sesskey' => sesskey(),
    ]);
    $html .= html_writer::link(
        $approveallurl,
        '<i class="fa fa-check-circle"></i> ' . get_string('wizard_approve_all_questions', 'local_hlai_quizgen'),
        ['class' => 'button is-success is-small']
    );
    $html .= html_writer::end_div();

    $html .= html_writer::end_div();

    // Right: Filters.
    $html .= html_writer::start_div('level-right');

    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_div('select is-small');
    $html .= html_writer::start_tag('select', [
        'id' => 'filter-status',
    ]);
    $html .= html_writer::tag('option', get_string('wizard_all_status', 'local_hlai_quizgen'), ['value' => 'all']);
    $html .= html_writer::tag('option', get_string('approved', 'local_hlai_quizgen'), ['value' => 'approved']);
    $html .= html_writer::tag('option', get_string('pending', 'local_hlai_quizgen'), ['value' => 'pending']);
    $html .= html_writer::tag('option', get_string('rejected', 'local_hlai_quizgen'), ['value' => 'rejected']);
    $html .= html_writer::end_tag('select');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_div('select is-small');
    $html .= html_writer::start_tag('select', [
        'id' => 'filter-type',
    ]);
    $html .= html_writer::tag('option', get_string('wizard_all_types', 'local_hlai_quizgen'), ['value' => 'all']);
    $html .= html_writer::tag('option', get_string('qtype_multichoice', 'local_hlai_quizgen'), ['value' => 'multichoice']);
    $html .= html_writer::tag('option', get_string('qtype_truefalse', 'local_hlai_quizgen'), ['value' => 'truefalse']);
    $html .= html_writer::tag('option', get_string('qtype_shortanswer', 'local_hlai_quizgen'), ['value' => 'shortanswer']);
    $html .= html_writer::tag('option', get_string('qtype_essay', 'local_hlai_quizgen'), ['value' => 'essay']);
    $html .= html_writer::end_tag('select');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('level-item');
    $html .= html_writer::start_div('select is-small');
    $html .= html_writer::start_tag('select', [
        'id' => 'filter-difficulty',
    ]);
    $html .= html_writer::tag('option', get_string('wizard_all_difficulty', 'local_hlai_quizgen'), ['value' => 'all']);
    $html .= html_writer::tag('option', get_string('diff_easy', 'local_hlai_quizgen'), ['value' => 'easy']);
    $html .= html_writer::tag('option', get_string('diff_medium', 'local_hlai_quizgen'), ['value' => 'medium']);
    $html .= html_writer::tag('option', get_string('diff_hard', 'local_hlai_quizgen'), ['value' => 'hard']);
    $html .= html_writer::end_tag('select');
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_div();
    $html .= html_writer::end_div(); // Level toolbar.

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
        $html .= html_writer::start_div('card-header is-shadowless border-bottom-0 hlai-card-header-clean');

        $html .= html_writer::start_div('card-header-title is-align-items-center py-2');

        // Group Checkbox and ID for perfect alignment.
        $html .= html_writer::start_div('is-flex is-align-items-center mr-4');

        // Checkbox with clean spacing.
        $html .= html_writer::start_tag('label', ['class' => 'checkbox mr-3 hlai-checkbox-flex']);
        $html .= html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'class' => 'question-checkbox hlai-checkbox-scale',
            'id' => 'question-checkbox-' . $question->id,
            'data-question-id' => $question->id,
        ]);
        $html .= html_writer::end_tag('label');

        // Question number text, clean and professional.
        $html .= html_writer::tag('span', get_string('wizard_question_number', 'local_hlai_quizgen', $questionnumber), [
            'class' => 'has-text-weight-bold has-text-grey-darker is-size-6',
        ]);
        $html .= html_writer::end_div();

        // Tags.
        $typelabel = str_replace('multichoice', 'MCQ', $question->questiontype ?? 'mcq');
        $html .= html_writer::tag('span', strtoupper($typelabel), ['class' => 'tag is-light mr-2']);

        $diffclass = $question->difficulty === 'easy' ? 'is-success' :
            ($question->difficulty === 'hard' ? 'is-danger' : 'is-warning');
        $diffstringmap = [
            'easy' => get_string('diff_easy', 'local_hlai_quizgen'),
            'medium' => get_string('diff_medium', 'local_hlai_quizgen'),
            'hard' => get_string('diff_hard', 'local_hlai_quizgen'),
        ];
        $difflabel = $diffstringmap[$question->difficulty] ?? ucfirst($question->difficulty);
        $html .= html_writer::tag('span', $difflabel, ['class' => 'tag is-light ' . $diffclass]);
        $html .= html_writer::end_div(); // Card-header-title.

        // Status Icon.
        $html .= html_writer::start_div('card-header-icon');
        $statusstringmap = [
            'approved' => get_string('approved', 'local_hlai_quizgen'),
            'rejected' => get_string('rejected', 'local_hlai_quizgen'),
            'pending' => get_string('pending', 'local_hlai_quizgen'),
        ];
        $statustext = $statusstringmap[$question->status] ?? ucfirst($question->status);
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
                    html_writer::tag(
                        'strong',
                        '<i class="fa fa-lightbulb-o mr-2"></i>' .
                        get_string('wizard_model_answer_criteria', 'local_hlai_quizgen')
                    ),
                    ['class' => 'has-text-grey-dark mb-2']
                );
                $html .= html_writer::div(format_text($modelanswer, FORMAT_HTML), 'model-answer hlai-model-answer');
                $html .= html_writer::end_div();
            } else {
                $html .= html_writer::start_div('mt-3');
                $html .= html_writer::tag(
                    'p',
                    '<i class="fa fa-info-circle mr-2"></i>' . get_string('wizard_no_model_answer', 'local_hlai_quizgen'),
                    ['class' => 'has-text-grey-light is-italic']
                );
                $html .= html_writer::end_div();
            }
        } else {
            // For MCQ, TF, Short Answer, Matching - show answer options.
            $answers = $allanswers[$question->id] ?? [];
            if (!empty($answers)) {
                $html .= html_writer::start_div('mt-3');
                $letterlabel = 'A';
                foreach ($answers as $answer) {
                    $iscorrect = $answer->fraction > 0;
                    $answerclass = 'answer-row hlai-answer-row';
                    if ($iscorrect) {
                        $answerclass .= ' hlai-answer-correct';
                    } else {
                        $answerclass .= ' hlai-answer-neutral';
                    }

                    $html .= html_writer::start_div($answerclass);

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
            $html .= html_writer::link($approveurl, get_string('approve_question', 'local_hlai_quizgen'), [
                'class' => 'card-footer-item has-text-success has-text-weight-bold',
            ]);
        } else {
            // Already approved indicator.
            $html .= html_writer::span(get_string('approved', 'local_hlai_quizgen'), 'card-footer-item has-text-grey-light');
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
            $html .= html_writer::link(
                $rejecturl,
                get_string('wizard_reject', 'local_hlai_quizgen'),
                ['class' => 'card-footer-item has-text-danger']
            );
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
            $html .= html_writer::link(
                $regenurl,
                get_string('regenerate_question', 'local_hlai_quizgen') .
                ' (' . $remainingregens . ')',
                [
                'class' => 'card-footer-item has-text-info',
                ]
            );
        }

        $html .= html_writer::end_div(); // Card-footer.

        $html .= html_writer::end_div(); // Card question-card.
    }

    $html .= html_writer::end_div(); // Questions-review.

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    // Navigation.
    $html .= html_writer::start_div('level is-mobile mt-5');
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::link(
        new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 3]),
        '<i class="fa fa-arrow-left hlai-icon-secondary"></i> ' . get_string('previous'),
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
function local_hlai_quizgen_render_step5(int $courseid, int $requestid): string {
    global $DB;

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

    $html = html_writer::start_div('hlai-step-content');

    // Header.
    $html .= html_writer::tag(
        'h2',
        '<i class="fa fa-rocket hlai-icon-primary"></i> ' .
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
            get_string('wizard_no_questions_go_back_step3', 'local_hlai_quizgen'),
            'notification is-warning'
        );
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 3]),
            '<i class="fa fa-arrow-left hlai-icon-white"></i> ' . get_string('wizard_back_to_step3', 'local_hlai_quizgen'),
            ['class' => 'button is-primary mt-4']
        );
        $html .= html_writer::end_div(); // Box.
        return $html;
    }

    if ($approvedcount == 0) {
        $html .= html_writer::div(
            get_string('wizard_none_approved_yet', 'local_hlai_quizgen', $totalcount),
            'notification is-warning'
        );
        $html .= html_writer::link(
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 4]),
            '<i class="fa fa-arrow-left hlai-icon-white"></i> ' . get_string('wizard_back_to_step4', 'local_hlai_quizgen'),
            ['class' => 'button is-primary mt-4']
        );
        $html .= html_writer::end_div(); // Box.
        return $html;
    }

    $readyinfo = new stdClass();
    $readyinfo->approved = $approvedcount;
    $readyinfo->total = $totalcount;
    $html .= html_writer::div(
        html_writer::tag(
            'strong',
            "<i class='fa fa-check-circle'></i> " .
            get_string('wizard_approved_ready_deploy', 'local_hlai_quizgen', $readyinfo)
        ),
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
        '<i class="fa fa-cube hlai-icon-primary"></i> ' .
        get_string('deployment_options', 'local_hlai_quizgen'),
        ['class' => 'title is-5 mb-4']
    );

    // Option 1: Create new quiz.
    $html .= html_writer::start_div('field mb-4');
    $html .= html_writer::start_tag('label', [
        'class' => 'radio hlai-deploy-label',
    ]);
    $html .= html_writer::empty_tag('input', [
        'type' => 'radio',
        'name' => 'deploy_type',
        'value' => 'new_quiz',
        'id' => 'deploy_new_quiz',
        'class' => 'deploy-type-radio hlai-radio-mt',
        'checked' => 'checked',
    ]);
    $html .= html_writer::start_div('');
    $html .= html_writer::tag('strong', get_string('create_new_quiz', 'local_hlai_quizgen'));
    $html .= html_writer::tag('p', get_string('wizard_create_quiz_desc', 'local_hlai_quizgen'), ['class' => 'help mb-0']);
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
        'value' => get_string('wizard_default_quiz_name', 'local_hlai_quizgen') . ' - ' . date('Y-m-d'),
        'class' => 'input',
        'required' => 'required',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Option 2: Question bank only.
    $html .= html_writer::start_div('field mb-4');
    $html .= html_writer::start_tag('label', [
        'class' => 'radio hlai-deploy-label',
    ]);
    $html .= html_writer::empty_tag('input', [
        'type' => 'radio',
        'name' => 'deploy_type',
        'value' => 'question_bank',
        'id' => 'deploy_qbank',
        'class' => 'deploy-type-radio hlai-radio-mt',
    ]);
    $html .= html_writer::start_div('');
    $html .= html_writer::tag('strong', get_string('export_to_question_bank', 'local_hlai_quizgen'));
    $html .= html_writer::tag('p', get_string('wizard_qbank_desc', 'local_hlai_quizgen'), ['class' => 'help mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('label');
    $html .= html_writer::end_div();

    $html .= html_writer::start_div('ml-5 mb-3 hlai-hidden', ['id' => 'qbank_options']);
    $html .= html_writer::start_div('field');
    $html .= html_writer::tag('label', get_string('category_name', 'local_hlai_quizgen'), [
        'for' => 'category_name',
        'class' => 'label is-small',
    ]);
    $html .= html_writer::start_div('control');
    // Use request's category_name if available, otherwise default to 'AI Generated Questions'.
    $defaultcategoryname = (!empty($request) && !empty($request->category_name))
        ? $request->category_name
        : get_string('wizard_ai_generated_questions', 'local_hlai_quizgen');
    $html .= html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'category_name',
        'id' => 'category_name',
        'value' => $defaultcategoryname,
        'class' => 'input',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::tag('p', get_string('wizard_category_help', 'local_hlai_quizgen'), [
        'class' => 'help',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Navigation.
    $html .= html_writer::start_div('level is-mobile mt-5');
    $html .= html_writer::start_div('level-left');
    $html .= html_writer::link(
        new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid, 'requestid' => $requestid, 'step' => 4]),
        '<i class="fa fa-arrow-left hlai-icon-secondary"></i> ' . get_string('previous'),
        ['class' => 'button is-light']
    );
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('level-right');
    $html .= html_writer::tag('button', '<i class="fa fa-rocket hlai-icon-white"></i> ' .
        get_string('deploy_questions', 'local_hlai_quizgen'), [
        'type' => 'submit',
        'class' => 'button is-success',
    ]);
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= html_writer::end_tag('form');

    $html .= html_writer::end_div(); // Box.

    // JavaScript handled by AMD module local_hlai_quizgen/wizard.

    return $html;
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
