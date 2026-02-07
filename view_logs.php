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
 * View error logs for debugging.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/hlai_quizgen/view_logs.php');
$PAGE->set_title(get_string('pluginname', 'local_hlai_quizgen') . ' - Debug Logs');
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

echo $OUTPUT->header();
echo $OUTPUT->heading('Recent HLAI QuizGen Debug Logs');
echo html_writer::start_div('hlai-quizgen-wrapper local-hlai-iksha');

$logfile = ini_get('error_log');

if (file_exists($logfile)) {
    $lines = file($logfile);
    $quizgenlines = array_filter($lines, function ($line) {
        return strpos($line, 'HLAI QuizGen') !== false;
    });

    $recent = array_slice($quizgenlines, -50); // Last 50 lines.

    if (empty($recent)) {
        echo '<div class="notification is-info is-light">No HLAI QuizGen log entries found yet. Try submitting the form.</div>';
    } else {
        echo '<div class="box">';
        echo '<pre class="hlai-log-details">';
        echo htmlspecialchars(implode('', $recent));
        echo '</pre>';
        echo '</div>';
    }

    echo '<div class="buttons mt-3">';
    echo '<a href="javascript:location.reload();" class="button is-primary">Refresh Logs</a>';
    echo '<a href="wizard.php?courseid=3" class="button is-light">Back to Wizard</a>';
    echo '</div>';
} else {
    echo '<div class="notification is-warning is-light">Log file not found at: ' . htmlspecialchars($logfile) . '</div>';
    echo '<p>Trying alternate location...</p>';

    $altlog = ini_get('error_log');
    echo '<p>PHP error_log setting: ' . htmlspecialchars($altlog) . '</p>';
}

echo html_writer::end_div();
echo $OUTPUT->footer();
