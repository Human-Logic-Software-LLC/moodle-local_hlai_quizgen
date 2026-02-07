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

echo $OUTPUT->header();
$heading = get_string('debuglogs_pagetitle', 'local_hlai_quizgen');
echo $OUTPUT->heading($heading);
echo html_writer::start_div('hlai-quizgen-wrapper local-hlai-iksha');

// Display AI Provider Status.
echo '<div class="box mb-4">';
echo '<h3 class="title is-6 mb-3">' . get_string('debuglogs_aiprovider_heading', 'local_hlai_quizgen') . '</h3>';

try {
    $gatewayurl = \local_hlai_quizgen\gateway_client::get_gateway_url();
    $gatewayready = \local_hlai_quizgen\gateway_client::is_ready();
    $statusclass = $providerinfo['active'] !== 'none' ? 'success' : 'danger';

    echo '<div class="columns is-multiline is-mobile">';
    echo '<div class="column is-one-third">';
    echo '<strong>' . get_string('debuglogs_activeprovider', 'local_hlai_quizgen') . ':</strong> ';
    echo '<span class="tag is-' . $statusclass . ' ml-2">' . strtoupper($providerinfo['active']) . '</span>';
    echo '</div>';
    echo '<div class="column is-one-third">';
    echo '<strong>' . get_string('debuglogs_hubavailable', 'local_hlai_quizgen') . ':</strong> ';
    $hubbadge = $providerinfo['hub_available']
        ? '<span class="tag is-success ml-2">' . get_string('debuglogs_yes', 'local_hlai_quizgen') . '</span>'
        : '<span class="tag is-light ml-2">' . get_string('debuglogs_no', 'local_hlai_quizgen') . '</span>';
    echo $hubbadge;
    if (isset($providerinfo['hub_provider'])) {
        echo ' (' . htmlspecialchars($providerinfo['hub_provider']) . ')';
    }
    echo '</div>';
    echo '<div class="column is-one-third">';
    echo '<strong>' . get_string('debuglogs_proxyavailable', 'local_hlai_quizgen') . ':</strong> ';
    $proxybadge = $providerinfo['proxy_available']
        ? '<span class="tag is-success ml-2">' . get_string('debuglogs_yes', 'local_hlai_quizgen') . '</span>'
        : '<span class="tag is-light ml-2">' . get_string('debuglogs_no', 'local_hlai_quizgen') . '</span>';
    echo $proxybadge;
    echo '</div>';
    echo '</div>';

    if ($providerinfo['active'] === 'none') {
        echo '<div class="notification is-danger is-light mt-3 mb-0">';
        echo '<strong>Warning:</strong> No AI provider is configured! Questions cannot be generated. ';
        echo 'Please configure <code>local_hlai_hub</code> or <code>local_hlai_hubproxy</code>.';
        echo '</div>';
    }
} catch (Exception $e) {
    echo '<div class="notification is-danger is-light mb-0">Error checking AI provider: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '</div>';

// Tab navigation.
$tabs = [
    'database' => 'Database Logs',
    'file' => 'File Logs',
    'requests' => 'Recent Requests',
    'system' => 'System Info',
];

echo '<div class="tabs is-boxed mb-4"><ul>';
foreach ($tabs as $tabkey => $tablabel) {
    $activeclass = ($tab === $tabkey) ? 'is-active' : '';
    $url = new moodle_url('/local/hlai_quizgen/debug_logs.php', ['tab' => $tabkey]);
    echo '<li class="' . $activeclass . '">';
    echo '<a href="' . $url . '">' . $tablabel . '</a>';
    echo '</li>';
}
echo '</ul></div>';

// Action buttons.
echo '<div class="buttons mb-4">';
$systeminfourl = new moodle_url('/local/hlai_quizgen/debug_logs.php', [
    'tab' => $tab,
    'action' => 'logsysteminfo',
    'sesskey' => sesskey(),
]);
echo '<a href="' . $systeminfourl->out() . '" class="button is-info">Log System Info</a>';

$testlogurl = new moodle_url('/local/hlai_quizgen/debug_logs.php', [
    'tab' => $tab,
    'action' => 'testlog',
    'sesskey' => sesskey(),
]);
echo '<a href="' . $testlogurl->out() . '" class="button is-light">Create Test Log</a>';
echo '<a href="javascript:location.reload();" class="button is-outlined is-primary">Refresh</a>';
echo '</div>';

// Display content based on tab.
switch ($tab) {
    case 'database':
        display_database_logs($requestid, $level, $limit);
        break;
    case 'file':
        display_file_logs($limit);
        break;
    case 'requests':
        display_recent_requests($limit);
        break;
    case 'system':
        display_system_info();
        break;
}

echo html_writer::end_div();
echo $OUTPUT->footer();

/**
 * Display database logs.
 *
 * @param int|null $requestid Optional request ID filter.
 * @param string $level Log level filter.
 * @param int $limit Maximum number of logs to display.
 * @return void
 */
function display_database_logs(?int $requestid, string $level, int $limit): void {
    global $DB, $OUTPUT;

    // Filter form.
    echo '<div class="box mb-4">';
    echo '<h4 class="title is-6 mb-3">Filters</h4>';
    echo '<form method="get">';
    echo '<input type="hidden" name="tab" value="database">';
    echo '<div class="field is-grouped is-grouped-multiline">';
    echo '<div class="control">';
    echo '<label class="label is-small">Request ID</label>';
    $requestidvalue = $requestid ?? '';
    echo '<input type="number" name="requestid" class="input is-small" ';
    echo 'style="width: 120px;" value="' . $requestidvalue . '" placeholder="All">';
    echo '</div>';

    echo '<div class="control">';
    echo '<label class="label is-small">Level</label>';
    echo '<div class="select is-small">';
    echo '<select name="level">';
    echo '<option value="">All</option>';
    foreach (['error', 'warning', 'info', 'debug'] as $l) {
        $selected = ($level === $l) ? 'selected' : '';
        echo '<option value="' . $l . '" ' . $selected . '>' . ucfirst($l) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';

    echo '<div class="control">';
    echo '<label class="label is-small">Limit</label>';
    echo '<input type="number" name="limit" class="input is-small" style="width: 100px;" value="' . $limit . '">';
    echo '</div>';

    echo '<div class="control">';
    echo '<label class="label is-small">&nbsp;</label>';
    echo '<button type="submit" class="button is-primary is-small">Filter</button>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    // Get logs.
    $logs = debug_logger::getrecentdatabaselogs($limit, $requestid, $level ?: null);

    if (empty($logs)) {
        echo '<div class="notification is-info is-light">No log entries found.</div>';
        return;
    }

    echo '<div class="table-container">';
    echo '<table class="table is-fullwidth is-striped is-hoverable is-narrow">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Time</th>';
    echo '<th>Level</th>';
    echo '<th>Request</th>';
    echo '<th>User</th>';
    echo '<th>Component</th>';
    echo '<th>Message</th>';
    echo '<th>Details</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($logs as $log) {
        $levelclass = get_level_class($log->status);
        $time = userdate($log->timecreated, '%Y-%m-%d %H:%M:%S');

        echo '<tr>';
        echo '<td class="hlai-nowrap"><small>' . $time . '</small></td>';
        echo '<td><span class="tag is-' . $levelclass . '">' . strtoupper($log->status) . '</span></td>';
        echo '<td>' . ($log->requestid ?: '-') . '</td>';
        echo '<td>' . $log->userid . '</td>';
        echo '<td><small>' . htmlspecialchars($log->component ?? '-') . '</small></td>';
        echo '<td>' . htmlspecialchars(substr($log->error_message ?? '', 0, 100)) . '</td>';
        echo '<td>';
        if (!empty($log->details)) {
            $details = json_decode($log->details, true);
            if ($details) {
                echo '<button class="button is-small is-light" onclick="toggleDetails(' . $log->id . ')">View</button>';
                echo '<div id="details-' . $log->id . '" style="display:none;" class="mt-2">';
                echo '<pre class="hlai-log-details">';
                echo htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                echo '</pre></div>';
            }
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    echo '<script>
    /**
     * Toggle details display.
     * @param {number} id Row ID
     */
    function toggleDetails(id) {
        var el = document.getElementById("details-" + id);
        el.style.display = el.style.display === "none" ? "block" : "none";
    }
    </script>';
}

/**
 * Display file logs.
 *
 * @param int $limit Maximum number of log lines to display.
 * @return void
 */
function display_file_logs(int $limit): void {
    $logfile = debug_logger::getlogfilepath();

    echo '<div class="box mb-4">';
    echo '<div class="level mb-3">';
    echo '<div class="level-left">';
    echo '<strong>Log File</strong>';
    echo '</div>';
    echo '<div class="level-right">';
    $clearurl = new moodle_url('/local/hlai_quizgen/debug_logs.php', [
        'tab' => 'file',
        'action' => 'clearfile',
        'sesskey' => sesskey(),
    ]);
    echo '<a href="' . $clearurl->out() . '" class="button is-danger is-small" ';
    echo 'onclick="return confirm(\'Clear log file?\')">Clear Log File</a>';
    echo '</div>';
    echo '</div>';

    if ($logfile) {
        echo '<p><strong>Log file path:</strong> <code>' . htmlspecialchars($logfile) . '</code></p>';

        if (file_exists($logfile)) {
            $size = filesize($logfile);
            echo '<p><strong>File size:</strong> ' . format_bytes($size) . '</p>';

            $entries = debug_logger::getrecentfilelogs($limit);

            if (empty($entries)) {
                echo '<div class="notification is-info is-light">Log file is empty.</div>';
            } else {
                echo '<p><strong>Showing last ' . count($entries) . ' entries:</strong></p>';
                echo '<pre class="hlai-log-file">';
                foreach (array_reverse($entries) as $entry) {
                    // Color code by level.
                    $entry = htmlspecialchars(trim($entry));
                    $entry = preg_replace(
                        '/\[ERROR\]/',
                        '<span style="color: #ff6b6b;">[ERROR]</span>',
                        $entry
                    );
                    $entry = preg_replace(
                        '/\[WARNING\]/',
                        '<span style="color: #ffd93d;">[WARNING]</span>',
                        $entry
                    );
                    $criticalspan = '<span style="color: #ff0000; font-weight: bold;">[CRITICAL]</span>';
                    $entry = preg_replace('/\[CRITICAL\]/', $criticalspan, $entry);
                    $entry = preg_replace(
                        '/\[INFO\]/',
                        '<span style="color: #6bcb77;">[INFO]</span>',
                        $entry
                    );
                    $entry = preg_replace(
                        '/\[DEBUG\]/',
                        '<span style="color: #4d96ff;">[DEBUG]</span>',
                        $entry
                    );
                    echo $entry . "\n" . str_repeat('-', 80) . "\n";
                }
                echo '</pre>';
            }
        } else {
            $warningtext = 'Log file does not exist yet. ';
            $warningtext .= 'It will be created when the first log entry is written.';
            echo '<div class="notification is-warning is-light">' . $warningtext . '</div>';
        }
    } else {
        echo '<div class="notification is-danger is-light">Could not determine log file path.</div>';
    }

    echo '</div>';
}

/**
 * Display recent requests.
 *
 * @param int $limit Maximum number of requests to display.
 * @return void
 */
function display_recent_requests(int $limit): void {
    global $DB;

    $requests = $DB->get_records('local_hlai_quizgen_requests', [], 'timecreated DESC', '*', 0, $limit);

    if (empty($requests)) {
        echo '<div class="notification is-info is-light">No requests found.</div>';
        return;
    }

    echo '<div class="table-container">';
    echo '<table class="table is-fullwidth is-striped is-hoverable">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Course</th>';
    echo '<th>User</th>';
    echo '<th>Status</th>';
    echo '<th>Questions</th>';
    echo '<th>Tokens</th>';
    echo '<th>Created</th>';
    echo '<th>Error</th>';
    echo '<th>Actions</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($requests as $req) {
        $statusclass = get_status_class($req->status);
        $time = userdate($req->timecreated, '%Y-%m-%d %H:%M:%S');

        echo '<tr>';
        echo '<td>' . $req->id . '</td>';
        echo '<td>' . $req->courseid . '</td>';
        echo '<td>' . $req->userid . '</td>';
        echo '<td><span class="tag is-' . $statusclass . '">' . strtoupper($req->status) . '</span></td>';
        echo '<td>' . ($req->questions_generated ?? $req->total_questions ?? 0) . '</td>';
        echo '<td>' . ($req->total_tokens ?? 0) . '</td>';
        echo '<td><small>' . $time . '</small></td>';
        echo '<td>';
        if (!empty($req->error_message)) {
            echo '<span class="text-danger" title="' . htmlspecialchars($req->error_message) . '">';
            echo htmlspecialchars(substr($req->error_message, 0, 50)) . '...';
            echo '</span>';
        } else if (!empty($req->progress_message)) {
            echo '<small>' . htmlspecialchars(substr($req->progress_message, 0, 50)) . '</small>';
        } else {
            echo '-';
        }
        echo '</td>';
        echo '<td>';
        $logsurl = new moodle_url('/local/hlai_quizgen/debug_logs.php', ['tab' => 'database', 'requestid' => $req->id]);
        echo '<a href="' . $logsurl . '" class="button is-small is-outlined is-primary">View Logs</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

/**
 * Display system info.
 */
function display_system_info(): void {
    global $CFG, $DB;

    echo '<div class="columns">';

    // PHP Info.
    echo '<div class="column is-half">';
    echo '<div class="box mb-4">';
    echo '<h4 class="title is-6 mb-3">PHP Configuration</h4>';
    echo '<div class="content">';
    echo '<table class="table is-fullwidth is-narrow">';
    echo '<tr><td>PHP Version</td><td><code>' . PHP_VERSION . '</code></td></tr>';
    echo '<tr><td>Memory Limit</td><td><code>' . ini_get('memory_limit') . '</code></td></tr>';
    echo '<tr><td>Max Execution Time</td><td><code>' . ini_get('max_execution_time') . 's</code></td></tr>';
    echo '<tr><td>Post Max Size</td><td><code>' . ini_get('post_max_size') . '</code></td></tr>';
    echo '<tr><td>Upload Max Filesize</td><td><code>' . ini_get('upload_max_filesize') . '</code></td></tr>';
    $errorlog = ini_get('error_log') ?: 'Not set';
    echo '<tr><td>Error Log</td><td>';
    echo '<code style="word-break: break-all;">' . $errorlog . '</code></td></tr>';
    echo '</table>';
    echo '</div></div>';
    echo '</div>';

    // Extensions.
    echo '<div class="column is-half">';
    echo '<div class="box mb-4">';
    echo '<h4 class="title is-6 mb-3">Required PHP Extensions</h4>';
    echo '<div class="content">';
    echo '<table class="table is-fullwidth is-narrow">';

    $extensions = ['curl', 'json', 'zip', 'simplexml', 'openssl', 'zlib', 'fileinfo'];
    foreach ($extensions as $ext) {
        $loaded = extension_loaded($ext);
        $badge = $loaded ? '<span class="tag is-success">Loaded</span>' : '<span class="tag is-danger">Missing</span>';
        echo '<tr><td>' . $ext . '</td><td>' . $badge . '</td></tr>';
    }

    echo '</table>';
    echo '</div></div>';
    echo '</div>';

    echo '</div>'; // End columns.

    // Moodle Info.
    echo '<div class="columns">';
    echo '<div class="column is-half">';
    echo '<div class="box mb-4">';
    echo '<h4 class="title is-6 mb-3">Moodle Configuration</h4>';
    echo '<div class="content">';
    echo '<table class="table is-fullwidth is-narrow">';
    echo '<tr><td>Moodle Version</td><td><code>' . ($CFG->release ?? 'Unknown') . '</code></td></tr>';
    echo '<tr><td>Moodle Build</td><td><code>' . ($CFG->version ?? 'Unknown') . '</code></td></tr>';
    echo '<tr><td>WWW Root</td><td><code>' . $CFG->wwwroot . '</code></td></tr>';
    echo '<tr><td>Data Root</td><td><code>' . $CFG->dataroot . '</code></td></tr>';
    echo '<tr><td>Debug Mode</td><td><code>' . ($CFG->debug ?? 0) . '</code></td></tr>';
    echo '</table>';
    echo '</div></div>';
    echo '</div>';

    // Plugin Info.
    echo '<div class="column is-half">';
    echo '<div class="box mb-4">';
    echo '<h4 class="title is-6 mb-3">Plugin Statistics</h4>';
    echo '<div class="content">';

    try {
        $totalrequests = $DB->count_records('local_hlai_quizgen_requests');
        $failedrequests = $DB->count_records('local_hlai_quizgen_requests', ['status' => 'failed']);
        $completedrequests = $DB->count_records('local_hlai_quizgen_requests', ['status' => 'completed']);
        $totalquestions = $DB->count_records('local_hlai_quizgen_questions');
        $totallogs = $DB->count_records('local_hlai_quizgen_logs');

        echo '<table class="table is-fullwidth is-narrow">';
        echo '<tr><td>Total Requests</td><td><code>' . $totalrequests . '</code></td></tr>';
        echo '<tr><td>Completed Requests</td><td><span class="tag is-success">' . $completedrequests . '</span></td></tr>';
        echo '<tr><td>Failed Requests</td><td><span class="tag is-danger">' . $failedrequests . '</span></td></tr>';
        echo '<tr><td>Total Questions Generated</td><td><code>' . $totalquestions . '</code></td></tr>';
        echo '<tr><td>Total Log Entries</td><td><code>' . $totallogs . '</code></td></tr>';
        echo '</table>';
    } catch (Exception $e) {
        echo '<div class="notification is-danger is-light">Error fetching stats: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }

    echo '</div></div>';
    echo '</div>';
    echo '</div>'; // End columns.

    // System tools check.
    echo '<div class="box mb-4">';
    echo '<h4 class="title is-6 mb-3">System Tools (for PDF extraction)</h4>';
    echo '<div class="content">';
    echo '<table class="table is-fullwidth is-narrow">';

    // Check pdftotext.
    $pdftotext = @shell_exec('which pdftotext 2>/dev/null') ?: @shell_exec('where pdftotext 2>nul');
    $pdftotextstatus = !empty(trim($pdftotext ?? ''))
        ? '<span class="tag is-success">Available</span>'
        : '<span class="tag is-warning">Not Found</span>';
    echo '<tr><td>pdftotext (poppler-utils)</td><td>' . $pdftotextstatus . '</td></tr>';

    // Check ghostscript.
    $gs = @shell_exec('which gs 2>/dev/null') ?: @shell_exec('where gswin64c 2>nul');
    $gsstatus = !empty(trim($gs ?? ''))
        ? '<span class="tag is-success">Available</span>'
        : '<span class="tag is-warning">Not Found</span>';
    echo '<tr><td>Ghostscript</td><td>' . $gsstatus . '</td></tr>';

    echo '</table>';
    echo '</div></div>';
}

/**
 * Get Bulma tag color class for log level.
 *
 * @param string $level The log level.
 * @return string The CSS class name.
 */
function get_level_class(string $level): string {
    switch (strtolower($level)) {
        case 'critical':
        case 'error':
            return 'danger';
        case 'warning':
            return 'warning';
        case 'info':
            return 'info';
        case 'debug':
            return 'dark';
        default:
            return 'light';
    }
}

/**
 * Get Bulma tag color class for request status.
 *
 * @param string $status The request status.
 * @return string The CSS class name.
 */
function get_status_class(string $status): string {
    switch (strtolower($status)) {
        case 'completed':
            return 'success';
        case 'failed':
            return 'danger';
        case 'processing':
            return 'warning';
        case 'pending':
            return 'info';
        default:
            return 'light';
    }
}

/**
 * Format bytes to human readable.
 *
 * @param int $bytes The number of bytes.
 * @return string Human readable size string.
 */
function format_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
