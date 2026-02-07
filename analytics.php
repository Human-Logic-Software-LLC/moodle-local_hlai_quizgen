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

// Phpcs:disable moodle.Commenting.MissingDocblock.
// phpcs:disable moodle.Commenting.FileExpectedTags.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = required_param('courseid', PARAM_INT);
$timerange = optional_param('timerange', '30', PARAM_ALPHA); // 7, 30, 90, all.

require_login($courseid);
$context = context_course::instance($courseid);
require_capability('local/hlai_quizgen:generatequestions', $context);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Page setup.
$PAGE->set_url(new moodle_url('/local/hlai_quizgen/analytics.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_hlai_quizgen') . ' - Analytics');
$PAGE->set_heading($course->fullname);

// Add Bulma CSS Framework (Native/Local - non-minified for debugging).
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');

// Add our custom CSS (loaded after Bulma to override and fix Moodle compatibility).
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

// Add ApexCharts (Local - non-minified for debugging).
$PAGE->requires->js(new moodle_url('/local/hlai_quizgen/apexcharts.js'), true);

// Add our AMD modules.
$PAGE->requires->js_call_amd('local_hlai_quizgen/analytics', 'init', [
    $courseid,
    sesskey(),
    $timerange,
]);

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
 * @param int $timefilter Time filter timestamp
 * @param string $timefield Time field name
 * @return string Modified SQL
 */
function get_filtered_sql($basesql, $timefilter, $timefield = 'timecreated') {
    if ($timefilter > 0) {
        return $basesql . " AND {$timefield} >= {$timefilter}";
    }
    return $basesql;
}

// ===== SUMMARY STATISTICS =====.

// Total questions generated.
$sql = get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ?",
    $timefilter
);
$totalquestions = $DB->count_records_sql($sql, [$userid, $courseid]);

// Approved questions.
$sql = get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ? AND status IN ('approved', 'deployed')",
    $timefilter
);
$approvedquestions = $DB->count_records_sql($sql, [$userid, $courseid]);

// Rejected questions.
$sql = get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ? AND status = 'rejected'",
    $timefilter
);
$rejectedquestions = $DB->count_records_sql($sql, [$userid, $courseid]);

// First-time acceptance.
$sql = get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ? " .
    "AND status IN ('approved', 'deployed') AND (regeneration_count = 0 OR regeneration_count IS NULL)",
    $timefilter
);
$firsttimeapproved = $DB->count_records_sql($sql, [$userid, $courseid]);

// Calculate rates.
$reviewed = $approvedquestions + $rejectedquestions;
$acceptancerate = $reviewed > 0 ? round(($approvedquestions / $reviewed) * 100, 1) : 0;
$ftar = $reviewed > 0 ? round(($firsttimeapproved / $reviewed) * 100, 1) : 0;

// Average quality score.
$sql = get_filtered_sql(
    "SELECT AVG(validation_score) FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ? AND validation_score IS NOT NULL",
    $timefilter
);
$avgquality = $DB->get_field_sql($sql, [$userid, $courseid]);
$avgquality = $avgquality ? round($avgquality, 1) : 0;

// Total regenerations.
$sql = get_filtered_sql(
    "SELECT SUM(regeneration_count) FROM {local_hlai_quizgen_questions} WHERE userid = ? AND courseid = ?",
    $timefilter
);
$totalregenerations = $DB->get_field_sql($sql, [$userid, $courseid]) ?: 0;

// Average regenerations per question.
$avgregenerations = $totalquestions > 0 ? round($totalregenerations / $totalquestions, 2) : 0;

// Total quizzes/requests.
$sql = get_filtered_sql(
    "SELECT COUNT(*) FROM {local_hlai_quizgen_requests} WHERE userid = ? AND courseid = ?",
    $timefilter
);
$totalrequests = $DB->count_records_sql($sql, [$userid, $courseid]);

// ===== QUESTION TYPE BREAKDOWN =====.
$sql = get_filtered_sql(
    "SELECT questiontype, COUNT(*) as count,
            SUM(CASE WHEN status IN ('approved', 'deployed') THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            AVG(validation_score) as avg_quality,
            AVG(regeneration_count) as avg_regen
     FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ?",
    $timefilter
);
$typestats = $DB->get_records_sql($sql . " GROUP BY questiontype", [$userid, $courseid]);

// ===== DIFFICULTY BREAKDOWN =====.
$sql = get_filtered_sql(
    "SELECT difficulty, COUNT(*) as count,
            SUM(CASE WHEN status IN ('approved', 'deployed') THEN 1 ELSE 0 END) as approved,
            AVG(validation_score) as avg_quality
     FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ?",
    $timefilter
);
$difficultystats = $DB->get_records_sql($sql . " GROUP BY difficulty", [$userid, $courseid]);

// ===== BLOOM'S TAXONOMY BREAKDOWN =====.
$sql = get_filtered_sql(
    "SELECT blooms_level, COUNT(*) as count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            AVG(validation_score) as avg_quality
     FROM {local_hlai_quizgen_questions}
     WHERE userid = ? AND courseid = ? AND blooms_level IS NOT NULL",
    $timefilter
);
$bloomsstats = $DB->get_records_sql($sql . " GROUP BY blooms_level", [$userid, $courseid]);

// ===== REJECTION REASONS =====.
// Note: rejection_reason column doesn't exist in database yet.
// Commenting out until schema is updated.
// $sql = get_filtered_sql(.
// "SELECT COALESCE(rejection_reason, 'Not specified') as reason, COUNT(*) as count.
// FROM {local_hlai_quizgen_questions}.
// WHERE userid = ? AND courseid = ? AND status = 'rejected'",.
// $timefilter.
// );.
// $rejection_reasons = $DB->get_records_sql(
//     $sql . " GROUP BY rejection_reason ORDER BY count DESC LIMIT 10",
//     [$userid, $courseid]
// );
$rejectionreasons = []; // Empty array for now.

// Output starts here.
echo $OUTPUT->header();
?>

<div class="hlai-quizgen-wrapper local-hlai-iksha" style="margin-top: 2rem;">
    <!-- Page Header -->
    <div class="level mb-5">
        <div class="level-left">
            <div>
                <h1 class="title is-4 mb-1"><i class="fa fa-bar-chart" style="color: #3B82F6;"></i> Analytics Dashboard</h1>
                <p class="subtitle is-6 has-text-grey">Detailed insights into your question generation quality and trends</p>
            </div>
        </div>
        <div class="level-right">
            <div class="buttons">
                <a href="<?php echo new moodle_url('/local/hlai_quizgen/index.php', ['courseid' => $courseid]); ?>"
                   class="button is-light">
                    <span><i class="fa fa-arrow-left" style="color: #64748B;"></i></span>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Time Range Filter -->
    <div class="box mb-4">
        <div class="is-flex is-align-items-center">
            <span class="has-text-weight-semibold mr-3"><i class="fa fa-calendar" style="color: #06B6D4;"></i> Time Range:</span>
            <div class="buttons has-addons">
                <a href="?courseid=<?php echo $courseid; ?>&timerange=7"
                   class="button is-small <?php echo $timerange === '7' ? 'is-primary' : 'is-light'; ?>">
                    Last 7 Days
                </a>
                <a href="?courseid=<?php echo $courseid; ?>&timerange=30"
                   class="button is-small <?php echo $timerange === '30' ? 'is-primary' : 'is-light'; ?>">
                    Last 30 Days
                </a>
                <a href="?courseid=<?php echo $courseid; ?>&timerange=90"
                   class="button is-small <?php echo $timerange === '90' ? 'is-primary' : 'is-light'; ?>">
                    Last 90 Days
                </a>
                <a href="?courseid=<?php echo $courseid; ?>&timerange=all"
                   class="button is-small <?php echo $timerange === 'all' ? 'is-primary' : 'is-light'; ?>">
                    All Time
                </a>
            </div>
        </div>
    </div>

    <!-- Summary Stats Row -->
    <div class="columns is-multiline mb-5">
        <div class="column is-2">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-file-text-o" style="color: #3B82F6;"></i></p>
                <p class="title is-4 mb-1"><?php echo $totalrequests; ?></p>
                <p class="heading">Quiz Generations</p>
            </div>
        </div>
        <div class="column is-2">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-question-circle" style="color: #06B6D4;"></i></p>
                <p class="title is-4 mb-1"><?php echo $totalquestions; ?></p>
                <p class="heading">Questions Created</p>
            </div>
        </div>
        <div class="column is-2">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-check-circle" style="color: #10B981;"></i></p>
                <p class="title is-4 mb-1"><?php echo $approvedquestions; ?></p>
                <p class="heading">Approved</p>
                <p class="help has-text-grey"><?php echo $acceptancerate; ?>% acceptance</p>
            </div>
        </div>
        <div class="column is-2">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-times-circle" style="color: #EF4444;"></i></p>
                <p class="title is-4 mb-1"><?php echo $rejectedquestions; ?></p>
                <p class="heading">Rejected</p>
            </div>
        </div>
        <div class="column is-2">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-star" style="color: #F59E0B;"></i></p>
                <p class="title is-4 mb-1"><?php echo $avgquality; ?></p>
                <p class="heading">Avg Quality</p>
            </div>
        </div>
        <div class="column is-2">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-bullseye" style="color: #10B981;"></i></p>
                <p class="title is-4 mb-1"><?php echo $ftar; ?>%</p>
                <p class="heading">FTAR</p>
            </div>
        </div>
    </div>

    <!-- Main Charts Row -->
    <div class="columns">
        <div class="column is-half">
            <div class="box">
                <p class="title is-6"><i class="fa fa-filter" style="color: #06B6D4;"></i> Question Review Funnel</p>
                <p class="has-text-grey is-size-7">How questions flow from generation to deployment</p>
                <div id="funnel-chart" style="height: 350px;"></div>
            </div>
        </div>

        <div class="column is-half">
            <div class="box">
                <p class="title is-6"><i class="fa fa-star" style="color: #F59E0B;"></i> Quality Score Distribution</p>
                <p class="has-text-grey is-size-7">Breakdown of validation scores across all questions</p>
                <div id="quality-dist-chart" style="height: 350px;"></div>
            </div>
        </div>
    </div>

    <!-- Question Type Analysis -->
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-clipboard" style="color: #64748B;"></i> Question Type Performance</p>
        <p class="has-text-grey is-size-7">Acceptance rates and quality by question type</p>
        <div class="columns">
            <div class="column is-half">
                <div id="type-acceptance-chart" style="height: 350px;"></div>
            </div>
            <div class="column is-half">
                <div class="table-container">
                    <table class="table is-fullwidth is-striped is-hoverable">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th class="has-text-right">Total</th>
                                <th class="has-text-right">Approved</th>
                                <th class="has-text-right">Rate</th>
                                <th class="has-text-right">Avg Regen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($typestats as $type => $stats) :
                                if (empty($type)) {
                                    continue;
                                }
                                $rate = $stats->count > 0 ? round(($stats->approved / $stats->count) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="tag is-info is-light"><?php echo ucfirst($type); ?></span>
                                </td>
                                <td class="has-text-right"><?php echo $stats->count; ?></td>
                                <td class="has-text-right"><?php echo $stats->approved; ?></td>
                                <td class="has-text-right">
                                    <?php
                                    $rateclass = $rate >= 70 ? 'is-success' : ($rate >= 50 ? 'is-warning' : 'is-danger');
                                    ?>
                                    <span class="tag <?php echo $rateclass; ?> is-light">
                                        <?php echo $rate; ?>%
                                    </span>
                                </td>
                                <td class="has-text-right"><?php echo round($stats->avg_regen, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Difficulty & Bloom's Analysis -->
    <div class="columns mt-4">
        <div class="column is-half">
            <div class="box">
                <p class="title is-6"><i class="fa fa-signal" style="color: #06B6D4;"></i> Difficulty Level Analysis</p>
                <p class="has-text-grey is-size-7">Distribution and acceptance by difficulty</p>
                <div id="difficulty-analysis-chart" style="height: 350px;"></div>
            </div>
        </div>
        <div class="column is-half">
            <div class="box">
                <p class="title is-6"><i class="fa fa-lightbulb-o" style="color: #3B82F6;"></i> Bloom's Taxonomy Coverage</p>
                <p class="has-text-grey is-size-7">Cognitive level distribution</p>
                <div id="blooms-coverage-chart" style="height: 350px;"></div>
            </div>
        </div>
    </div>

    <!-- Regeneration Analysis -->
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-refresh" style="color: #F59E0B;"></i> Regeneration Analysis</p>
        <p class="has-text-grey is-size-7">Understanding which questions need refinement</p>
        <div class="columns">
            <div class="column is-half">
                <p class="has-text-weight-semibold mb-3">Regeneration Distribution</p>
                <div id="regen-dist-chart" style="height: 280px;"></div>
            </div>
            <div class="column is-half">
                <p class="has-text-weight-semibold mb-3">Regeneration by Difficulty</p>
                <div id="regen-by-difficulty-chart" style="height: 280px;"></div>
            </div>
        </div>
    </div>

    <!-- Rejection Analysis -->
    <?php if (!empty($rejectionreasons)) : ?>
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-times-circle" style="color: #EF4444;"></i> Rejection Analysis</p>
        <p class="has-text-grey is-size-7">Common reasons for question rejection</p>
        <div class="columns">
            <div class="column is-half">
                <div id="rejection-reasons-chart" style="height: 300px;"></div>
            </div>
            <div class="column is-half">
                <div class="box">
                    <p class="title is-6 mb-3">Top Rejection Reasons</p>
                    <ul class="hlai-simple-list">
                        <?php foreach ($rejectionreasons as $reason) : ?>
                        <li class="is-flex is-justify-content-space-between is-align-items-center">
                            <span><?php echo htmlspecialchars($reason->reason); ?></span>
                            <span class="tag is-danger is-light"><?php echo $reason->count; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Trends Over Time -->
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-line-chart" style="color: #3B82F6;"></i> Trends Over Time</p>
        <p class="has-text-grey is-size-7">Question generation and quality trends</p>
        <div id="trends-chart" style="height: 350px;"></div>
    </div>

    <!-- Insights & Recommendations -->
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-lightbulb-o" style="color: #F59E0B;"></i> Insights & Recommendations</p>
        <div class="columns">
            <?php
            // Generate insights based on data.
            $insights = [];

            if ($ftar < 50) {
                $insights[] = [
                    'type' => 'warning',
                    'icon' => '<i class="fa fa-exclamation-triangle" style="color: #F59E0B;"></i>',
                    'title' => 'Low First-Time Acceptance Rate',
                    'message' => 'Your FTAR is ' .
                        $ftar .
                        '%. Consider providing more detailed content or selecting more specific topics to improve AI accuracy.',
                ];
            } else if ($ftar >= 75) {
                $insights[] = [
                    'type' => 'success',
                    'icon' => '<i class="fa fa-check-circle" style="color: #10B981;"></i>',
                    'title' => 'Excellent First-Time Acceptance',
                    'message' => 'Your FTAR of ' .
                        $ftar .
                        '% is excellent! The AI is generating high-quality questions that match your expectations.',
                ];
            }

            if ($avgregenerations > 2) {
                $insights[] = [
                    'type' => 'warning',
                    'icon' => '<i class="fa fa-refresh" style="color: #06B6D4;"></i>',
                    'title' => 'High Regeneration Rate',
                    'message' => 'On average, questions need ' .
                        $avgregenerations .
                        ' regenerations. Try using more structured content or clearer learning objectives.',
                ];
            }

            // Find best performing question type.
            $besttype = null;
            $bestrate = 0;
            foreach ($typestats as $type => $stats) {
                if (!empty($type) && $stats->count >= 5) {
                    $rate = $stats->count > 0 ? ($stats->approved / $stats->count) * 100 : 0;
                    if ($rate > $bestrate) {
                        $bestrate = $rate;
                        $besttype = $type;
                    }
                }
            }
            if ($besttype) {
                $insights[] = [
                    'type' => 'info',
                    'icon' => '<i class="fa fa-bar-chart"></i>',
                    'title' => 'Best Performing Type',
                    'message' => ucfirst($besttype) .
                        ' questions have the highest acceptance rate at ' .
                        round($bestrate, 1) .
                        '%. Consider using more of this type.',
                ];
            }

            if (empty($insights)) {
                $insights[] = [
                    'type' => 'info',
                    'icon' => '<i class="fa fa-info-circle"></i>',
                    'title' => 'Keep Generating!',
                    'message' => 'Generate more questions to see detailed insights'
                        . ' and recommendations based on your usage patterns.',
                ];
            }

            foreach ($insights as $insight) :
            ?>
            <div class="column is-one-third">
                <div class="notification is-<?php echo $insight['type']; ?> is-light">
                    <div class="is-flex is-align-items-start">
                        <span class="mr-3" style="font-size: 1.5rem;"><?php echo $insight['icon']; ?></span>
                        <div>
                            <strong><?php echo $insight['title']; ?></strong>
                            <p class="mt-2"><?php echo $insight['message']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<div style="height: 60px;"></div>

<!-- Pass data to JavaScript -->
<script>
window.hlaiQuizgenAnalytics = {
    courseid: <?php echo $courseid; ?>,
    sesskey: '<?php echo sesskey(); ?>',
    ajaxUrl: '<?php echo $CFG->wwwroot; ?>/local/hlai_quizgen/ajax.php',
    timerange: '<?php echo $timerange; ?>',
    stats: {
        totalQuestions: <?php echo $totalquestions; ?>,
        approved: <?php echo $approvedquestions; ?>,
        rejected: <?php echo $rejectedquestions; ?>,
        pending: <?php echo max(0, $totalquestions - $approvedquestions - $rejectedquestions); ?>,
        ftar: <?php echo $ftar; ?>,
        avgQuality: <?php echo $avgquality; ?>,
        totalRegens: <?php echo $totalregenerations; ?>
    },
    typeStats: <?php echo json_encode(array_values($typestats)); ?>,
    difficultyStats: <?php echo json_encode(array_values($difficultystats)); ?>,
    bloomsStats: <?php echo json_encode(array_values($bloomsstats)); ?>,
    rejectionReasons: <?php echo json_encode(array_values($rejectionreasons)); ?>
};
</script>

<?php
echo $OUTPUT->footer();
