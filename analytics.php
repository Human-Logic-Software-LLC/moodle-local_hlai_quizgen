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
 * Analytics page for the AI Quiz Generator.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Commenting.MissingDocblock
// phpcs:disable moodle.Commenting.FileExpectedTags

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = required_param('courseid', PARAM_INT);
$timerange = optional_param('timerange', '30', PARAM_ALPHANUM); // 7, 30, 90, all.

require_login($courseid);
$context = context_course::instance($courseid);
require_capability('local/hlai_quizgen:generatequestions', $context);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Page setup.
$PAGE->set_url(new moodle_url('/local/hlai_quizgen/analytics.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_hlai_quizgen') . ' - ' . get_string('analytics_title', 'local_hlai_quizgen'));
$PAGE->set_heading($course->fullname);

// Add Bulma CSS Framework (Native/Local - non-minified for debugging).
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');

// Add our custom CSS (loaded after Bulma to override and fix Moodle compatibility).
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

// Add ApexCharts (Local - non-minified for debugging).
$PAGE->requires->js(new moodle_url('/local/hlai_quizgen/apexcharts.js'), true);

$userid = $USER->id;

// Calculate time filter.
$timefilter = 0;
switch ($timerange) {
    case '7':
        $timefilter = time() - (7 * 24 * 60 * 60);
        break;
    case '30':
        $timefilter = time() - (30 * 24 * 60 * 60);
        break;
    case '90':
        $timefilter = time() - (90 * 24 * 60 * 60);
        break;
    default:
        $timefilter = 0; // All time.
}

/**
 * Helper function for time-filtered queries.
 *
 * @param string $basesql Base SQL query
 * @param array $baseparams Base parameters for the query
 * @param int $timefilter Time filter timestamp
 * @param string $timefield Time field name
 * @return array Array of [sql, params]
 */
function local_hlai_quizgen_get_filtered_sql($basesql, $baseparams, $timefilter, $timefield = 'timecreated') {
    if ($timefilter > 0) {
        return [$basesql . " AND {$timefield} >= ?", array_merge($baseparams, [$timefilter])];
    }
    return [$basesql, $baseparams];
}

// Summary statistics.

// Total questions generated.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ?",
    [$userid, $courseid],
    $timefilter
);
$totalquestions = $DB->count_records_sql($sql, $params);

// Approved questions.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ? AND status IN ('approved', 'deployed')",
    [$userid, $courseid],
    $timefilter
);
$approvedquestions = $DB->count_records_sql($sql, $params);

// Rejected questions.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ? AND status = 'rejected'",
    [$userid, $courseid],
    $timefilter
);
$rejectedquestions = $DB->count_records_sql($sql, $params);

// First-time acceptance.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ? " .
    "AND status IN ('approved', 'deployed') AND (regeneration_count = 0 OR regeneration_count IS NULL)",
    [$userid, $courseid],
    $timefilter
);
$firsttimeapproved = $DB->count_records_sql($sql, $params);

// Calculate rates.
$reviewed = $approvedquestions + $rejectedquestions;
$acceptancerate = $reviewed > 0 ? round(($approvedquestions / $reviewed) * 100, 1) : 0;
$ftar = $reviewed > 0 ? round(($firsttimeapproved / $reviewed) * 100, 1) : 0;

// Average quality score.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT AVG(validation_score) FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ? AND validation_score IS NOT NULL",
    [$userid, $courseid],
    $timefilter
);
$avgquality = $DB->get_field_sql($sql, $params);
$avgquality = $avgquality ? round($avgquality, 1) : 0;

// Total regenerations.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT SUM(regeneration_count) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ?",
    [$userid, $courseid],
    $timefilter
);
$totalregenerations = $DB->get_field_sql($sql, $params) ?: 0;

// Average regenerations per question.
$avgregenerations = $totalquestions > 0 ? round($totalregenerations / $totalquestions, 2) : 0;

// Total quizzes/requests.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_requests} WHERE userid = ? AND courseid = ?",
    [$userid, $courseid],
    $timefilter
);
$totalrequests = $DB->count_records_sql($sql, $params);

// Question type breakdown.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT questiontype, COUNT(*) as count,
            SUM(CASE WHEN status IN ('approved', 'deployed') THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            AVG(validation_score) as avg_quality,
            AVG(regeneration_count) as avg_regen
     FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ?",
    [$userid, $courseid],
    $timefilter
);
$typestats = $DB->get_records_sql($sql . " GROUP BY questiontype", $params);

// Difficulty breakdown.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT difficulty, COUNT(*) as count,
            SUM(CASE WHEN status IN ('approved', 'deployed') THEN 1 ELSE 0 END) as approved,
            AVG(validation_score) as avg_quality
     FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ?",
    [$userid, $courseid],
    $timefilter
);
$difficultystats = $DB->get_records_sql($sql . " GROUP BY difficulty", $params);

// Bloom's taxonomy breakdown.
[$sql, $params] = local_hlai_quizgen_get_filtered_sql(
    "SELECT blooms_level, COUNT(*) as count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            AVG(validation_score) as avg_quality
     FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ? AND blooms_level IS NOT NULL",
    [$userid, $courseid],
    $timefilter
);
$bloomsstats = $DB->get_records_sql($sql . " GROUP BY blooms_level", $params);

// Rejection reasons - column doesn't exist in database yet, using empty array.
$rejectionreasons = []; // Empty array for now.

// Add our AMD modules (after all data variables are computed).
$PAGE->requires->js_call_amd('local_hlai_quizgen/analytics', 'init', [[
    'courseid' => $courseid,
    'sesskey' => sesskey(),
    'timerange' => $timerange,
    'stats' => [
        'totalQuestions' => $totalquestions,
        'approved' => $approvedquestions,
        'rejected' => $rejectedquestions,
        'pending' => max(0, $totalquestions - $approvedquestions - $rejectedquestions),
        'ftar' => $ftar,
        'avgQuality' => $avgquality,
        'totalRegens' => $totalregenerations,
    ],
    'typeStats' => array_values($typestats),
    'difficultyStats' => array_values($difficultystats),
    'bloomsStats' => array_values($bloomsstats),
    'rejectionReasons' => array_values($rejectionreasons),
]]);

// Output starts here.
echo $OUTPUT->header();

// Render the analytics page using templates and the Output API.
$page = new \local_hlai_quizgen\output\analytics_page(
    $courseid,
    $timerange,
    [
        'totalrequests' => $totalrequests,
        'totalquestions' => $totalquestions,
        'approvedquestions' => $approvedquestions,
        'rejectedquestions' => $rejectedquestions,
        'acceptancerate' => $acceptancerate,
        'avgquality' => $avgquality,
        'ftar' => $ftar,
        'avgregenerations' => $avgregenerations,
    ],
    $typestats,
    $difficultystats,
    $bloomsstats,
    $rejectionreasons
);
echo $OUTPUT->render_from_template('local_hlai_quizgen/analytics', $page->export_for_template($OUTPUT));

echo $OUTPUT->footer();
