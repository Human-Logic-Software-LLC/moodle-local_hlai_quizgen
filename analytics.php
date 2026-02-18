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
?>

<div class="hlai-quizgen-wrapper local-hlai-iksha hlai-mt-2rem">
    <!-- Page Header -->
    <div class="level mb-5">
        <div class="level-left">
            <div>
                <h1 class="title is-4 mb-1"><i class="fa fa-bar-chart hlai-icon-primary"></i> <?php echo get_string('analytics_dashboard', 'local_hlai_quizgen'); ?></h1>
                <p class="subtitle is-6 has-text-grey"><?php echo get_string('analytics_dashboard_subtitle', 'local_hlai_quizgen'); ?></p>
            </div>
        </div>
        <div class="level-right">
            <div class="buttons">
                <a href="<?php echo new moodle_url('/local/hlai_quizgen/index.php', ['courseid' => $courseid]); ?>"
                   class="button is-light">
                    <span><i class="fa fa-arrow-left hlai-icon-secondary"></i></span>
                    <span><?php echo get_string('analytics_back_to_dashboard', 'local_hlai_quizgen'); ?></span>
                </a>
            </div>
        </div>
    </div>

    <!-- Time Range Filter -->
    <div class="box mb-4">
        <div class="is-flex is-align-items-center">
            <span class="has-text-weight-semibold mr-3"><i class="fa fa-calendar hlai-icon-info"></i> <?php echo get_string('analytics_time_range', 'local_hlai_quizgen'); ?></span>
            <div class="buttons has-addons">
                <a href="?courseid=<?php echo $courseid; ?>&timerange=7"
                   class="button is-small <?php echo $timerange === '7' ? 'is-primary' : 'is-light'; ?>">
                    <?php echo get_string('analytics_last_7_days', 'local_hlai_quizgen'); ?>
                </a>
                <a href="?courseid=<?php echo $courseid; ?>&timerange=30"
                   class="button is-small <?php echo $timerange === '30' ? 'is-primary' : 'is-light'; ?>">
                    <?php echo get_string('analytics_last_30_days', 'local_hlai_quizgen'); ?>
                </a>
                <a href="?courseid=<?php echo $courseid; ?>&timerange=90"
                   class="button is-small <?php echo $timerange === '90' ? 'is-primary' : 'is-light'; ?>">
                    <?php echo get_string('analytics_last_90_days', 'local_hlai_quizgen'); ?>
                </a>
                <a href="?courseid=<?php echo $courseid; ?>&timerange=all"
                   class="button is-small <?php echo $timerange === 'all' ? 'is-primary' : 'is-light'; ?>">
                    <?php echo get_string('analytics_all_time', 'local_hlai_quizgen'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Summary Stats Row -->
    <div class="columns is-multiline mb-5">
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-file-text-o hlai-icon-primary"></i></p>
                <p class="title is-4 mb-1"><?php echo $totalrequests; ?></p>
                <p class="heading"><?php echo get_string('analytics_quiz_generations', 'local_hlai_quizgen'); ?></p>
                <p class="help has-text-grey">&nbsp;</p>
            </div>
        </div>
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-question-circle hlai-icon-info"></i></p>
                <p class="title is-4 mb-1"><?php echo $totalquestions; ?></p>
                <p class="heading"><?php echo get_string('analytics_questions_created', 'local_hlai_quizgen'); ?></p>
                <p class="help has-text-grey">&nbsp;</p>
            </div>
        </div>
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-check-circle hlai-icon-success"></i></p>
                <p class="title is-4 mb-1"><?php echo $approvedquestions; ?></p>
                <p class="heading"><?php echo get_string('approved', 'local_hlai_quizgen'); ?></p>
                <p class="help has-text-grey"><?php echo get_string('analytics_pct_acceptance', 'local_hlai_quizgen', $acceptancerate); ?></p>
            </div>
        </div>
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-times-circle hlai-icon-danger"></i></p>
                <p class="title is-4 mb-1"><?php echo $rejectedquestions; ?></p>
                <p class="heading"><?php echo get_string('rejected', 'local_hlai_quizgen'); ?></p>
                <p class="help has-text-grey">&nbsp;</p>
            </div>
        </div>
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-star hlai-icon-warning"></i></p>
                <p class="title is-4 mb-1"><?php echo $avgquality; ?></p>
                <p class="heading"><?php echo get_string('analytics_avg_quality', 'local_hlai_quizgen'); ?></p>
                <p class="help has-text-grey">&nbsp;</p>
            </div>
        </div>
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-bullseye hlai-icon-success"></i></p>
                <p class="title is-4 mb-1"><?php echo $ftar; ?>%</p>
                <p class="heading"><?php echo get_string('analytics_ftar', 'local_hlai_quizgen'); ?></p>
                <p class="help has-text-grey">&nbsp;</p>
            </div>
        </div>
    </div>

    <!-- Main Charts Row -->
    <div class="columns">
        <div class="column is-half">
            <div class="box">
                <p class="title is-6"><i class="fa fa-filter hlai-icon-info"></i> <?php echo get_string('analytics_review_funnel', 'local_hlai_quizgen'); ?></p>
                <p class="has-text-grey is-size-7"><?php echo get_string('analytics_review_funnel_desc', 'local_hlai_quizgen'); ?></p>
                <div id="funnel-chart" class="hlai-chart-h350"></div>
            </div>
        </div>

        <div class="column is-half">
            <div class="box">
                <p class="title is-6"><i class="fa fa-star hlai-icon-warning"></i> <?php echo get_string('analytics_quality_score_dist', 'local_hlai_quizgen'); ?></p>
                <p class="has-text-grey is-size-7"><?php echo get_string('analytics_quality_score_dist_desc', 'local_hlai_quizgen'); ?></p>
                <div id="quality-dist-chart" class="hlai-chart-h350"></div>
            </div>
        </div>
    </div>

    <!-- Question Type Analysis -->
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-clipboard hlai-icon-secondary"></i> <?php echo get_string('analytics_type_performance', 'local_hlai_quizgen'); ?></p>
        <p class="has-text-grey is-size-7"><?php echo get_string('analytics_type_performance_desc', 'local_hlai_quizgen'); ?></p>
        <div class="columns">
            <div class="column is-half">
                <div id="type-acceptance-chart" class="hlai-chart-h350"></div>
            </div>
            <div class="column is-half">
                <div class="table-container">
                    <table class="table is-fullwidth is-striped is-hoverable">
                        <thead>
                            <tr>
                                <th><?php echo get_string('question_type', 'local_hlai_quizgen'); ?></th>
                                <th class="has-text-right"><?php echo get_string('analytics_total', 'local_hlai_quizgen'); ?></th>
                                <th class="has-text-right"><?php echo get_string('approved', 'local_hlai_quizgen'); ?></th>
                                <th class="has-text-right"><?php echo get_string('analytics_rate', 'local_hlai_quizgen'); ?></th>
                                <th class="has-text-right"><?php echo get_string('analytics_avg_regen', 'local_hlai_quizgen'); ?></th>
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
                <p class="title is-6"><i class="fa fa-signal hlai-icon-info"></i> <?php echo get_string('analytics_difficulty_analysis', 'local_hlai_quizgen'); ?></p>
                <p class="has-text-grey is-size-7"><?php echo get_string('analytics_difficulty_analysis_desc', 'local_hlai_quizgen'); ?></p>
                <div id="difficulty-analysis-chart" class="hlai-chart-h350"></div>
            </div>
        </div>
        <div class="column is-half">
            <div class="box">
                <p class="title is-6"><i class="fa fa-lightbulb-o hlai-icon-primary"></i> <?php echo get_string('blooms_coverage', 'local_hlai_quizgen'); ?></p>
                <p class="has-text-grey is-size-7"><?php echo get_string('analytics_cognitive_level_dist', 'local_hlai_quizgen'); ?></p>
                <div id="blooms-coverage-chart" class="hlai-chart-h350"></div>
            </div>
        </div>
    </div>

    <!-- Regeneration Analysis -->
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-refresh hlai-icon-warning"></i> <?php echo get_string('analytics_regen_analysis', 'local_hlai_quizgen'); ?></p>
        <p class="has-text-grey is-size-7"><?php echo get_string('analytics_regen_analysis_desc', 'local_hlai_quizgen'); ?></p>
        <div class="columns">
            <div class="column is-half">
                <p class="has-text-weight-semibold mb-3"><?php echo get_string('analytics_regen_distribution', 'local_hlai_quizgen'); ?></p>
                <div id="regen-dist-chart" class="hlai-chart-h280"></div>
            </div>
            <div class="column is-half">
                <p class="has-text-weight-semibold mb-3"><?php echo get_string('analytics_regen_by_difficulty', 'local_hlai_quizgen'); ?></p>
                <div id="regen-by-difficulty-chart" class="hlai-chart-h280"></div>
            </div>
        </div>
    </div>

    <!-- Rejection Analysis -->
    <?php if (!empty($rejectionreasons)) : ?>
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-times-circle hlai-icon-danger"></i> <?php echo get_string('analytics_rejection_analysis', 'local_hlai_quizgen'); ?></p>
        <p class="has-text-grey is-size-7"><?php echo get_string('analytics_rejection_analysis_desc', 'local_hlai_quizgen'); ?></p>
        <div class="columns">
            <div class="column is-half">
                <div id="rejection-reasons-chart" class="hlai-chart-h300"></div>
            </div>
            <div class="column is-half">
                <div class="box">
                    <p class="title is-6 mb-3"><?php echo get_string('analytics_top_rejection_reasons', 'local_hlai_quizgen'); ?></p>
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
        <p class="title is-6"><i class="fa fa-line-chart hlai-icon-primary"></i> <?php echo get_string('analytics_trends_over_time', 'local_hlai_quizgen'); ?></p>
        <p class="has-text-grey is-size-7"><?php echo get_string('analytics_trends_over_time_desc', 'local_hlai_quizgen'); ?></p>
        <div id="trends-chart" class="hlai-chart-h350"></div>
    </div>

    <!-- Insights & Recommendations -->
    <div class="box mt-4">
        <p class="title is-6"><i class="fa fa-lightbulb-o hlai-icon-warning"></i> <?php echo get_string('analytics_insights_recommendations', 'local_hlai_quizgen'); ?></p>
        <div class="columns">
            <?php
            // Generate insights based on data.
            $insights = [];

            if ($ftar < 50) {
                $insights[] = [
                    'type' => 'warning',
                    'icon' => '<i class="fa fa-exclamation-triangle hlai-icon-warning"></i>',
                    'title' => get_string('analytics_insight_low_ftar_title', 'local_hlai_quizgen'),
                    'message' => get_string('analytics_insight_low_ftar_msg', 'local_hlai_quizgen', $ftar),
                ];
            } else if ($ftar >= 75) {
                $insights[] = [
                    'type' => 'success',
                    'icon' => '<i class="fa fa-check-circle hlai-icon-success"></i>',
                    'title' => get_string('analytics_insight_high_ftar_title', 'local_hlai_quizgen'),
                    'message' => get_string('analytics_insight_high_ftar_msg', 'local_hlai_quizgen', $ftar),
                ];
            }

            if ($avgregenerations > 2) {
                $insights[] = [
                    'type' => 'warning',
                    'icon' => '<i class="fa fa-refresh hlai-icon-info"></i>',
                    'title' => get_string('analytics_insight_high_regen_title', 'local_hlai_quizgen'),
                    'message' => get_string('analytics_insight_high_regen_msg', 'local_hlai_quizgen', $avgregenerations),
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
                    'title' => get_string('analytics_insight_best_type_title', 'local_hlai_quizgen'),
                    'message' => get_string(
                        'analytics_insight_best_type_msg',
                        'local_hlai_quizgen',
                        (object)['type' => ucfirst($besttype), 'rate' => round($bestrate, 1)]
                    ),
                ];
            }

            if (empty($insights)) {
                $insights[] = [
                    'type' => 'info',
                    'icon' => '<i class="fa fa-info-circle"></i>',
                    'title' => get_string('analytics_insight_keep_generating_title', 'local_hlai_quizgen'),
                    'message' => get_string('analytics_insight_keep_generating_msg', 'local_hlai_quizgen'),
                ];
            }

            foreach ($insights as $insight) :
            ?>
            <div class="column is-one-third">
                <div class="notification is-<?php echo $insight['type']; ?> is-light">
                    <div class="is-flex is-align-items-start">
                        <span class="mr-3 hlai-font-lg"><?php echo $insight['icon']; ?></span>
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
<div class="hlai-spacer-60"></div>

<?php
echo $OUTPUT->footer();
