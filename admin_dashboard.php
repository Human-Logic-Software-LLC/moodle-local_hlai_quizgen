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
 * Admin dashboard page for the AI Quiz Generator.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Commenting.MissingDocblock

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Require admin login.
admin_externalpage_setup('local_hlai_quizgen_admin');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Page setup.
$PAGE->set_url(new moodle_url('/local/hlai_quizgen/admin_dashboard.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('admin_dashboard_title', 'local_hlai_quizgen'));
$PAGE->set_heading(get_string('admin_dashboard_heading', 'local_hlai_quizgen'));

// Add Bulma CSS Framework.
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

// Add ApexCharts.
$PAGE->requires->js(new moodle_url('/local/hlai_quizgen/apexcharts.js'), true);

// Site-wide data collection.

// 1. Site-Wide Overview Statistics.
$totalquestionsgenerated = $DB->count_records('local_hlai_quizgen_questions');
$totalquizzescreated = $DB->count_records('local_hlai_quizgen_requests', ['status' => 'completed']);

$activeteachers = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT userid) FROM {local_hlai_quizgen_requests}",
    []
);

$activecourses = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT courseid) FROM {local_hlai_quizgen_requests}",
    []
);

$avgqualityscore = $DB->get_field_sql(
    "SELECT AVG(validation_score) FROM {local_hlai_quizgen_questions}
     WHERE validation_score IS NOT NULL AND validation_score > 0",
    []
);
$avgqualityscore = $avgqualityscore ? round($avgqualityscore, 1) : 'N/A';

// Site-wide FTAR calculation.
$totalapproved = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
     WHERE status IN ('approved', 'deployed')",
    []
);

$totalreviewed = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions}
     WHERE status IN ('approved', 'rejected', 'deployed')",
    []
);

$siteftar = $totalreviewed > 0 ? round(($totalapproved / $totalreviewed) * 100, 1) : 0;

// 2. Adoption Metrics.
$totaluserswithcapability = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT ra.userid)
     FROM {role_assignments} ra
     JOIN {role_capabilities} rc ON rc.roleid = ra.roleid
     WHERE rc.capability = ?
     AND rc.permission = 1",
    ['local/hlai_quizgen:generatequestions']
);

$adoptionrate = $totaluserswithcapability > 0
    ? round(($activeteachers / $totaluserswithcapability) * 100, 1)
    : 0;

// Count all courses except site course (id = 1).
$totalcourses = $DB->count_records_select('course', 'id > ?', [1]);
$coursecoverage = $totalcourses > 0
    ? round(($activecourses / $totalcourses) * 100, 1)
    : 0;

// 3. Usage Trends (Last 30 days) - Use PHP for date grouping for database compatibility.
$thirtydaysago = time() - (30 * 24 * 60 * 60);
$rawusagedata = $DB->get_records_sql(
    "SELECT timecreated FROM {local_hlai_quizgen_questions} WHERE timecreated >= ?",
    [$thirtydaysago]
);

// Group by date in PHP for database compatibility.
$usagebydate = [];
foreach ($rawusagedata as $row) {
    $date = date('Y-m-d', $row->timecreated);
    if (!isset($usagebydate[$date])) {
        $usagebydate[$date] = 0;
    }
    $usagebydate[$date]++;
}
ksort($usagebydate);

// Convert to object array format.
$usagetrenddata = [];
foreach ($usagebydate as $date => $count) {
    $obj = new stdClass();
    $obj->date = $date;
    $obj->count = $count;
    $usagetrenddata[] = $obj;
}

// 4. Question Type Distribution (Site-Wide).
$questiontypestats = $DB->get_records_sql(
    "SELECT questiontype, COUNT(*) as count
     FROM {local_hlai_quizgen_questions}
     WHERE status IN ('approved', 'deployed')
     GROUP BY questiontype
     ORDER BY count DESC",
    []
);

// 5. Difficulty Distribution (Site-Wide).
$difficultystats = $DB->get_records_sql(
    "SELECT difficulty, COUNT(*) as count
     FROM {local_hlai_quizgen_questions}
     WHERE status IN ('approved', 'deployed')
     GROUP BY difficulty",
    []
);

// 6. Bloom's Taxonomy Distribution (Site-Wide).
$bloomsstats = $DB->get_records_sql(
    "SELECT blooms_level, COUNT(*) as count
     FROM {local_hlai_quizgen_questions}
     WHERE status IN ('approved', 'deployed') AND blooms_level IS NOT NULL
     GROUP BY blooms_level",
    []
);

// 7. Top Performers - Courses.
$topcourses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, COUNT(q.id) as question_count
     FROM {local_hlai_quizgen_questions} q
     JOIN {course} c ON q.courseid = c.id
     WHERE q.status IN ('approved', 'deployed')
     GROUP BY c.id, c.fullname
     ORDER BY question_count DESC
     LIMIT 10",
    []
);

// 8. Top Performers - Teachers.
$topteachers = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname,
            COUNT(*) as total_questions,
            SUM(CASE WHEN q.status IN ('approved', 'deployed') THEN 1 ELSE 0 END) as approved_questions,
            ROUND((SUM(CASE WHEN q.status IN ('approved', 'deployed') THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as acceptance_rate
     FROM {local_hlai_quizgen_questions} q
     JOIN {user} u ON q.userid = u.id
     WHERE q.status IN ('approved', 'rejected', 'deployed')
     GROUP BY u.id, u.firstname, u.lastname
     HAVING COUNT(*) >= 10
     ORDER BY acceptance_rate DESC, approved_questions DESC
     LIMIT 10",
    []
);

// 9. System Health Checks.
$pendinggenerations = $DB->count_records('local_hlai_quizgen_requests', ['status' => 'pending']);
$failedgenerations = $DB->count_records('local_hlai_quizgen_requests', ['status' => 'failed']);

// Recent errors (last 7 days).
$sevendaysago = time() - (7 * 24 * 60 * 60);
$recenterrors = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_requests}
     WHERE status = ? AND timecreated >= ?",
    ['failed', $sevendaysago]
);

// Check AI provider configuration.
$aiproviderconfigured = \local_hlai_quizgen\gateway_client::is_ready();

// Prepare chart data for AMD module.
$trenddates = [];
$trendcounts = [];
foreach ($usagetrenddata as $data) {
    $trenddates[] = $data->date;
    $trendcounts[] = $data->count;
}
$bloomslabels = [];
$bloomsvalues = [];
foreach ($bloomsstats as $stat) {
    $bloomslabels[] = $stat->blooms_level;
    $bloomsvalues[] = (int)$stat->count;
}
$typelabels = [];
$typevalues = [];
foreach ($questiontypestats as $stat) {
    $typelabels[] = ucfirst(str_replace('_', ' ', $stat->questiontype ?? ''));
    $typevalues[] = (int)$stat->count;
}
$difficultylabels = [];
$difficultyvalues = [];
foreach ($difficultystats as $stat) {
    $difficultylabels[] = ucfirst($stat->difficulty ?? '');
    $difficultyvalues[] = (int)$stat->count;
}

// Add AMD module for admin dashboard charts.
$PAGE->requires->js_call_amd('local_hlai_quizgen/admindashboard', 'init', [[
    'trendDates' => $trenddates,
    'trendCounts' => $trendcounts,
    'activeTeachers' => (int)$activeteachers,
    'inactiveTeachers' => max(0, (int)$totaluserswithcapability - (int)$activeteachers),
    'bloomsLabels' => $bloomslabels,
    'bloomsValues' => $bloomsvalues,
    'typeLabels' => $typelabels,
    'typeValues' => $typevalues,
    'difficultyLabels' => $difficultylabels,
    'difficultyValues' => $difficultyvalues,
]]);

// Output HTML.

echo $OUTPUT->header();

// Render the admin dashboard page using templates and the Output API.
$page = new \local_hlai_quizgen\output\admin_dashboard_page(
    [
        'totalquestionsgenerated' => $totalquestionsgenerated,
        'totalquizzescreated' => $totalquizzescreated,
        'activeteachers' => $activeteachers,
        'adoptionrate' => $adoptionrate,
        'activecourses' => $activecourses,
        'coursecoverage' => $coursecoverage,
        'avgqualityscore' => $avgqualityscore,
        'siteftar' => $siteftar,
    ],
    $topcourses,
    $topteachers,
    [
        'aiproviderconfigured' => $aiproviderconfigured,
        'pendinggenerations' => $pendinggenerations,
        'recenterrors' => $recenterrors,
        'failedgenerations' => $failedgenerations,
    ]
);
echo $OUTPUT->render_from_template('local_hlai_quizgen/admin_dashboard', $page->export_for_template($OUTPUT));

// Charts handled by AMD module local_hlai_quizgen/admindashboard.

echo $OUTPUT->footer();
