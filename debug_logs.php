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
 * Comprehensive debug log viewer for the AI Quiz Generator plugin.
 *
 * Displays logs from both database and file for easy debugging.
 * Requires site admin capability.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_hlai_quizgen\debug_logger;

// Require login and admin capability.
require_login();
require_capability('moodle/site:config', context_system::instance());

// Get parameters.
$tab = optional_param('tab', 'database', PARAM_ALPHA);
$requestid = optional_param('requestid', null, PARAM_INT);
$level = optional_param('level', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$limit = optional_param('limit', 100, PARAM_INT);

// Handle actions.
if ($action === 'clearfile' && confirm_sesskey()) {
    debug_logger::clearlogfile();
    $message = get_string('debuglogs_action_clearfile_success', 'local_hlai_quizgen');
    redirect(
        new moodle_url('/local/hlai_quizgen/debug_logs.php', ['tab' => 'file']),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'logsysteminfo' && confirm_sesskey()) {
    debug_logger::logsysteminfo($requestid);
    $message = get_string('debuglogs_action_logsysteminfo_success', 'local_hlai_quizgen');
    redirect(
        new moodle_url('/local/hlai_quizgen/debug_logs.php', ['tab' => $tab]),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'testlog' && confirm_sesskey()) {
    debug_logger::info('Test log entry from debug_logs.php', [
        'test' => true,
        'timestamp' => time(),
    ]);
    debug_logger::warning('Test WARNING entry', ['severity' => 'warning']);
    debug_logger::error('Test ERROR entry', ['severity' => 'error']);
    $message = get_string('debuglogs_action_testlog_success', 'local_hlai_quizgen');
    redirect(
        new moodle_url('/local/hlai_quizgen/debug_logs.php', ['tab' => $tab]),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Page setup.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/hlai_quizgen/debug_logs.php', ['tab' => $tab]);
$pagetitle = get_string('pluginname', 'local_hlai_quizgen') . ' - ' .
    get_string('debuglogs_title', 'local_hlai_quizgen');
$PAGE->set_title($pagetitle);
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

// Add AMD module for debug logs interactions.
$PAGE->requires->js_call_amd('local_hlai_quizgen/debuglogs', 'init');

echo $OUTPUT->header();
$heading = get_string('debuglogs_pagetitle', 'local_hlai_quizgen');
echo $OUTPUT->heading($heading);

// Render the debug logs page using templates and the Output API.
$page = new \local_hlai_quizgen\output\debug_logs_page($tab, $requestid, $level, $limit);
echo $OUTPUT->render_from_template('local_hlai_quizgen/debug_logs', $page->export_for_template($OUTPUT));

echo $OUTPUT->footer();
