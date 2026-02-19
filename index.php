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
 * Teacher dashboard index page for the AI Quiz Generator.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Commenting.MissingDocblock

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', 'dashboard', PARAM_ALPHA);

require_login($courseid);
$context = context_course::instance($courseid);
require_capability('local/hlai_quizgen:generatequestions', $context);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// If action is 'wizard', redirect to wizard.
if ($action === 'wizard') {
    redirect(new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid]));
}

// Page setup.
$PAGE->set_url(new moodle_url('/local/hlai_quizgen/index.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('pluginname', 'local_hlai_quizgen') . ' - ' . get_string('dashboard', 'local_hlai_quizgen'));
$PAGE->set_heading($course->fullname);

// Add Bulma CSS Framework (Native/Local - non-minified for debugging).
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');

// Add our custom CSS (loaded after Bulma to override and fix Moodle compatibility).
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

// Add ApexCharts (Local - non-minified for debugging).
$PAGE->requires->js(new moodle_url('/local/hlai_quizgen/apexcharts.js'), true);

// Get dashboard stats from database.
$userid = $USER->id;

// Quick stats.
$totalquizzes = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT id) FROM {local_hlai_quizgen_requests}
     WHERE userid = ? AND status = 'completed'",
    [$userid]
);

// Count active quiz activities in this course.
$activequizzes = $DB->count_records_sql(
    "SELECT COUNT(cm.id)
     FROM {course_modules} cm
     JOIN {modules} m ON m.id = cm.module
     WHERE cm.course = ? AND m.name = 'quiz' AND cm.deletioninprogress = 0",
    [$courseid]
);

$totalquestions = $DB->count_records('local_hlai_quizgen_questions', ['userid' => $userid]);

$approvedquestions = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND status IN ('approved', 'deployed')",
    [$userid]
);

$pendingquestions = $DB->count_records('local_hlai_quizgen_questions', [
    'userid' => $userid,
    'status' => 'pending',
]);

$avgquality = $DB->get_field_sql(
    "SELECT AVG(validation_score) FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND validation_score IS NOT NULL",
    [$userid]
);
$avgquality = $avgquality ? round($avgquality, 1) : 0;

// Calculate acceptance rate (approved + deployed vs rejected).
$totalreviewed = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND status IN ('approved', 'deployed', 'rejected')",
    [$userid]
);
$acceptancerate = $totalreviewed > 0 ? round(($approvedquestions / $totalreviewed) * 100, 1) : 0;

// First-time acceptance rate (questions approved/deployed without regeneration out of all reviewed questions).
$firsttimeapproved = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND status IN ('approved', 'deployed') AND (regeneration_count = 0 OR regeneration_count IS NULL)",
    [$userid]
);
$ftar = $totalreviewed > 0 ? round(($firsttimeapproved / $totalreviewed) * 100, 1) : 0;

// Recent requests.
$recentrequests = $DB->get_records_sql(
    "SELECT r.id, r.courseid, r.status, r.total_questions, r.questions_generated,
            r.timecreated, c.fullname as coursename
     FROM {local_hlai_quizgen_requests} r
     JOIN {course} c ON c.id = r.courseid
     WHERE r.userid = ?
     ORDER BY r.timecreated DESC
     LIMIT 5",
    [$userid]
);

// Course stats for this course specifically.
$coursequestions = $DB->count_records('local_hlai_quizgen_questions', [
    'userid' => $userid,
    'courseid' => $courseid,
]);

$coursequizzes = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT id) FROM {local_hlai_quizgen_requests}
     WHERE userid = ? AND courseid = ? AND status = 'completed'",
    [$userid, $courseid]
);

// Question type distribution for this course.
$typedistribution = $DB->get_records_sql(
    "SELECT questiontype, COUNT(*) as count
     FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ?
     GROUP BY questiontype",
    [$userid, $courseid]
);

// Add our AMD modules (after data is computed so we can pass stats).
$PAGE->requires->js_call_amd('local_hlai_quizgen/dashboard', 'init', [[
    'courseid' => $courseid,
    'sesskey' => sesskey(),
    'stats' => [
        'totalQuizzes' => $totalquizzes,
        'totalQuestions' => $totalquestions,
        'approvedQuestions' => $approvedquestions,
        'avgQuality' => $avgquality > 0 ? $avgquality : 0,
        'acceptanceRate' => $acceptancerate,
        'ftar' => $ftar,
    ],
    'typeDistribution' => array_values($typedistribution),
]]);

// Output starts here.
echo $OUTPUT->header();

// Render the dashboard page using templates and the Output API.
$page = new \local_hlai_quizgen\output\index_page(
    $courseid,
    $totalquizzes,
    $coursequizzes,
    $totalquestions,
    (float)$avgquality,
    (float)$acceptancerate,
    (float)$ftar,
    $recentrequests,
    !empty($typedistribution)
);
echo $OUTPUT->render_from_template('local_hlai_quizgen/index', $page->export_for_template($OUTPUT));

echo $OUTPUT->footer();
