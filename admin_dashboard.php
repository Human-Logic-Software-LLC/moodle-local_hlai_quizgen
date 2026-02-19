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

?>

<div class="hlai-quizgen-wrapper local-hlai-iksha">
<div class="container is-fluid">

    <!-- Page Title -->
    <div class="page-title-section">
        <h1>
            <i class="fa fa-line-chart hlai-icon-admin-primary"></i>
            <?php echo get_string('admin_site_analytics_title', 'local_hlai_quizgen'); ?>
        </h1>
        <p class="page-subtitle">
            <?php echo get_string('admin_site_analytics_subtitle', 'local_hlai_quizgen'); ?>
        </p>
    </div>

    <!-- Site-Wide Overview Cards -->
    <div class="section-header">
        <h2><i class="fa fa-bar-chart"></i> <?php echo get_string('admin_site_overview', 'local_hlai_quizgen'); ?></h2>
    </div>

    <div class="columns is-multiline">
        <!-- Total Questions Generated -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-primary">
                    <i class="fa fa-question-circle stat-icon-large"></i>
                </div>
                <div class="stat-label"><?php echo get_string('admin_total_questions_generated', 'local_hlai_quizgen'); ?></div>
                <div class="stat-value"><?php echo number_format($totalquestionsgenerated); ?></div>
            </div>
        </div>

        <!-- Total Quizzes Created -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-info">
                    <i class="fa fa-file-text-o stat-icon-large"></i>
                </div>
                <div class="stat-label"><?php echo get_string('admin_total_quizzes_created', 'local_hlai_quizgen'); ?></div>
                <div class="stat-value"><?php echo number_format($totalquizzescreated); ?></div>
            </div>
        </div>

        <!-- Active Teachers -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-success">
                    <i class="fa fa-users stat-icon-large"></i>
                </div>
                <div class="stat-label"><?php echo get_string('admin_active_teachers', 'local_hlai_quizgen'); ?></div>
                <div class="stat-value"><?php echo number_format($activeteachers); ?></div>
                <div class="stat-subtext">
                    <?php echo get_string('admin_adoption_rate', 'local_hlai_quizgen', $adoptionrate); ?>
                </div>
            </div>
        </div>

        <!-- Active Courses -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-warning">
                    <i class="fa fa-graduation-cap stat-icon-large"></i>
                </div>
                <div class="stat-label"><?php echo get_string('admin_courses_using_plugin', 'local_hlai_quizgen'); ?></div>
                <div class="stat-value"><?php echo number_format($activecourses); ?></div>
                <div class="stat-subtext">
                    <?php echo get_string('admin_course_coverage', 'local_hlai_quizgen', $coursecoverage); ?>
                </div>
            </div>
        </div>

        <!-- Average Quality Score -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-warning">
                    <i class="fa fa-star stat-icon-large"></i>
                </div>
                <div class="stat-label"><?php echo get_string('avg_quality_score', 'local_hlai_quizgen'); ?></div>
                <div class="stat-value"><?php echo $avgqualityscore; ?></div>
            </div>
        </div>

        <!-- Site-Wide FTAR -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-danger">
                    <i class="fa fa-bullseye stat-icon-large"></i>
                </div>
                <div class="stat-label"><?php echo get_string('admin_site_wide_ftar', 'local_hlai_quizgen'); ?></div>
                <div class="stat-value"><?php echo $siteftar; ?>%</div>
                <div class="stat-subtext"><?php echo get_string('first_time_acceptance_rate', 'local_hlai_quizgen'); ?></div>
            </div>
        </div>
    </div>

    <!-- Adoption & Usage Metrics -->
    <div class="section-header">
        <h2><i class="fa fa-line-chart"></i> <?php echo get_string('admin_adoption_usage', 'local_hlai_quizgen'); ?></h2>
    </div>

    <div class="columns">
        <div class="column is-8">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-area-chart"></i> <?php echo get_string('admin_usage_trend', 'local_hlai_quizgen'); ?>
                </h3>
                <div id="usageTrendChart"></div>
            </div>
        </div>
        <div class="column is-4">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-pie-chart"></i> <?php echo get_string('admin_adoption_overview', 'local_hlai_quizgen'); ?>
                </h3>
                <div id="adoptionChart"></div>
            </div>
        </div>
    </div>

    <!-- Quality Overview (Site-Wide) -->
    <div class="section-header">
        <h2><i class="fa fa-star"></i> <?php echo get_string('admin_quality_overview', 'local_hlai_quizgen'); ?></h2>
    </div>

    <div class="columns is-multiline">
        <div class="column is-6">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-th-list"></i> <?php echo get_string('blooms_taxonomy', 'local_hlai_quizgen'); ?>
                </h3>
                <div id="bloomsDistributionChart"></div>
            </div>
        </div>
        <div class="column is-6">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-tasks"></i> <?php echo get_string('admin_question_type_popularity', 'local_hlai_quizgen'); ?>
                </h3>
                <div id="questionTypeChart"></div>
            </div>
        </div>
        <div class="column is-12">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-signal"></i> <?php echo get_string('difficulty_distribution', 'local_hlai_quizgen'); ?>
                </h3>
                <div id="difficultyChart"></div>
            </div>
        </div>
    </div>

    <!-- Top Performers -->
    <div class="section-header">
        <h2><i class="fa fa-trophy"></i> <?php echo get_string('admin_top_performers', 'local_hlai_quizgen'); ?></h2>
    </div>

    <div class="columns">
        <div class="column is-6">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-trophy"></i> <?php echo get_string('admin_top_courses', 'local_hlai_quizgen'); ?>
                </h3>
                <?php if (!empty($topcourses)) : ?>
                    <?php $rank = 1; foreach ($topcourses as $course) : ?>
                        <div class="top-performer-row">
                            <div class="is-flex is-align-items-center hlai-gap-075">
                                <span class="performer-rank">#<?php echo $rank++; ?></span>
                                <strong class="performer-name"><?php echo format_string($course->fullname); ?></strong>
                            </div>
                            <span class="performer-badge">
                                <?php
                                echo get_string(
                                    'admin_questions_count',
                                    'local_hlai_quizgen',
                                    number_format($course->question_count)
                                );
                                ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p class="has-text-grey"><?php echo get_string('admin_no_course_data', 'local_hlai_quizgen'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="column is-6">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-trophy"></i> <?php echo get_string('admin_top_teachers', 'local_hlai_quizgen'); ?>
                </h3>
                <?php if (!empty($topteachers)) : ?>
                    <?php $rank = 1; foreach ($topteachers as $teacher) : ?>
                        <div class="top-performer-row">
                            <div class="is-flex is-align-items-center hlai-gap-075">
                                <span class="performer-rank">#<?php echo $rank++; ?></span>
                                <div>
                                    <strong class="performer-name"><?php echo fullname($teacher); ?></strong>
                                    <div class="performer-details">
                                        <?php
                                        echo get_string(
                                            'admin_approved_fraction',
                                            'local_hlai_quizgen',
                                            (object)[
                                                'approved' => $teacher->approved_questions,
                                                'total' => $teacher->total_questions,
                                            ]
                                        );
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <span class="performer-badge">
                                <?php echo $teacher->acceptance_rate; ?>%
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p class="has-text-grey"><?php echo get_string('admin_no_teacher_data', 'local_hlai_quizgen'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- System Health -->
    <div class="section-header">
        <h2><i class="fa fa-wrench"></i> <?php echo get_string('admin_system_health', 'local_hlai_quizgen'); ?></h2>
    </div>

    <div class="columns">
        <div class="column is-12">
            <div class="chart-container">
                <div class="system-health-grid">
                    <div class="health-metric">
                        <strong><?php echo get_string('admin_ai_provider_status', 'local_hlai_quizgen'); ?></strong>
                        <span class="health-indicator <?php echo $aiproviderconfigured ? 'is-healthy' : 'is-error'; ?>">
                            <i class="fa <?php echo $aiproviderconfigured ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                            <?php
                            echo $aiproviderconfigured
                                ? get_string('admin_connected', 'local_hlai_quizgen')
                                : get_string('admin_not_configured', 'local_hlai_quizgen');
                            ?>
                        </span>
                    </div>
                    <div class="health-metric">
                        <strong><?php echo get_string('admin_pending_generations', 'local_hlai_quizgen'); ?></strong>
                        <span class="health-value is-warning">
                            <i class="fa fa-clock"></i> <?php echo $pendinggenerations; ?>
                        </span>
                    </div>
                    <div class="health-metric">
                        <strong><?php echo get_string('admin_recent_errors', 'local_hlai_quizgen'); ?></strong>
                        <span class="health-value <?php echo $recenterrors > 0 ? 'is-danger' : 'is-success'; ?>">
                            <i class="fa fa-exclamation-triangle"></i> <?php echo $recenterrors; ?>
                        </span>
                    </div>
                    <div class="health-metric">
                        <strong><?php echo get_string('admin_total_failed', 'local_hlai_quizgen'); ?></strong>
                        <span class="health-value is-dark">
                            <i class="fa fa-ban"></i> <?php echo $failedgenerations; ?>
                        </span>
                    </div>
                </div>

                <hr class="hlai-admin-hr">

                <h3 class="chart-title">
                    <i class="fa fa-cog"></i> <?php echo get_string('admin_quick_config_links', 'local_hlai_quizgen'); ?>
                </h3>
                <div class="config-buttons">
                    <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'local_hlai_quizgen']); ?>"
                       class="config-button">
                        <i class="fa fa-wrench"></i> <?php echo get_string('admin_plugin_settings', 'local_hlai_quizgen'); ?>
                    </a>
                    <?php
                    $capurl = new moodle_url(
                        '/admin/roles/check.php',
                        ['capability' => 'local/hlai_quizgen:generatequestions']
                    );
                    ?>
                    <a href="<?php echo $capurl; ?>"
                       class="config-button">
                        <i class="fa fa-users"></i> <?php echo get_string('admin_user_capabilities', 'local_hlai_quizgen'); ?>
                    </a>
                    <a href="<?php echo new moodle_url('/local/hlai_quizgen/debug_logs.php'); ?>"
                       class="config-button">
                        <i class="fa fa-file-text-o"></i> <?php echo get_string('admin_view_error_logs', 'local_hlai_quizgen'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<?php
// Charts handled by AMD module local_hlai_quizgen/admindashboard.

echo $OUTPUT->footer();
