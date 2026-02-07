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

// ================= SITE-WIDE DATA COLLECTION =================.

// 1. Site-Wide Overview Statistics.
$totalquestionsgenerated = $DB->count_records('hlai_quizgen_questions');
$totalquizzescreated = $DB->count_records('hlai_quizgen_requests', ['status' => 'completed']);

$activeteachers = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT userid) FROM {hlai_quizgen_requests}",
    []
);

$activecourses = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT courseid) FROM {hlai_quizgen_requests}",
    []
);

$avgqualityscore = $DB->get_field_sql(
    "SELECT AVG(validation_score) FROM {hlai_quizgen_questions}
     WHERE validation_score IS NOT NULL AND validation_score > 0",
    []
);
$avgqualityscore = $avgqualityscore ? round($avgqualityscore, 1) : 'N/A';

// Site-wide FTAR calculation.
$totalapproved = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {hlai_quizgen_questions}
     WHERE status IN ('approved', 'deployed')",
    []
);

$totalreviewed = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {hlai_quizgen_questions}
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
    "SELECT timecreated FROM {hlai_quizgen_questions} WHERE timecreated >= ?",
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
     FROM {hlai_quizgen_questions}
     WHERE status IN ('approved', 'deployed')
     GROUP BY questiontype
     ORDER BY count DESC",
    []
);

// 5. Difficulty Distribution (Site-Wide).
$difficultystats = $DB->get_records_sql(
    "SELECT difficulty, COUNT(*) as count
     FROM {hlai_quizgen_questions}
     WHERE status IN ('approved', 'deployed')
     GROUP BY difficulty",
    []
);

// 6. Bloom's Taxonomy Distribution (Site-Wide).
$bloomsstats = $DB->get_records_sql(
    "SELECT blooms_level, COUNT(*) as count
     FROM {hlai_quizgen_questions}
     WHERE status IN ('approved', 'deployed') AND blooms_level IS NOT NULL
     GROUP BY blooms_level",
    []
);

// 7. Top Performers - Courses.
$topcourses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, COUNT(q.id) as question_count
     FROM {hlai_quizgen_questions} q
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
     FROM {hlai_quizgen_questions} q
     JOIN {user} u ON q.userid = u.id
     WHERE q.status IN ('approved', 'rejected', 'deployed')
     GROUP BY u.id, u.firstname, u.lastname
     HAVING COUNT(*) >= 10
     ORDER BY acceptance_rate DESC, approved_questions DESC
     LIMIT 10",
    []
);

// 9. System Health Checks.
$pendinggenerations = $DB->count_records('hlai_quizgen_requests', ['status' => 'pending']);
$failedgenerations = $DB->count_records('hlai_quizgen_requests', ['status' => 'failed']);

// Recent errors (last 7 days).
$sevendaysago = time() - (7 * 24 * 60 * 60);
$recenterrors = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {hlai_quizgen_requests}
     WHERE status = ? AND timecreated >= ?",
    ['failed', $sevendaysago]
);

// Check AI provider configuration.
$aiproviderconfigured = !empty(get_config('local_hlai_quizgen', 'azure_endpoint'))
                         && !empty(get_config('local_hlai_quizgen', 'azure_api_key'));

// ================= OUTPUT HTML =================.

echo $OUTPUT->header();

?>

<style>
/* Iksha-inspired Clean Design System */
:root {
    --admin-primary: #3B82F6;
    --admin-primary-light: #EFF6FF;
    --admin-success: #10B981;
    --admin-success-light: #ECFDF5;
    --admin-warning: #F59E0B;
    --admin-warning-light: #FFFBEB;
    --admin-danger: #EF4444;
    --admin-danger-light: #FEF2F2;
    --admin-info: #06B6D4;
    --admin-info-light: #ECFEFF;
    --admin-gray-50: #F8FAFC;
    --admin-gray-100: #F1F5F9;
    --admin-gray-200: #E2E8F0;
    --admin-gray-700: #334155;
    --admin-gray-800: #1E293B;
}

.admin-stat-card {
    background: #fff;
    border: 1px solid var(--admin-gray-200);
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    transition: all 0.2s ease;
    height: 100%;
}

.admin-stat-card:hover {
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    border-color: var(--admin-gray-200);
}

.stat-icon-container {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 8px;
    margin-bottom: 0.75rem;
}

.stat-icon-container.is-primary {
    background: var(--admin-primary-light);
    color: var(--admin-primary);
}

.stat-icon-container.is-success {
    background: var(--admin-success-light);
    color: var(--admin-success);
}

.stat-icon-container.is-warning {
    background: var(--admin-warning-light);
    color: var(--admin-warning);
}

.stat-icon-container.is-info {
    background: var(--admin-info-light);
    color: var(--admin-info);
}

.stat-icon-container.is-danger {
    background: var(--admin-danger-light);
    color: var(--admin-danger);
}

.stat-icon-large {
    font-size: 1.5rem;
}

.stat-label {
    font-size: 0.8125rem;
    color: var(--admin-gray-700);
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--admin-gray-800);
    line-height: 1.2;
}

.stat-subtext {
    font-size: 0.75rem;
    color: var(--admin-gray-700);
    margin-top: 0.25rem;
}

.section-header {
    border-left: 3px solid var(--admin-primary);
    padding-left: 1rem;
    margin: 2.5rem 0 1.5rem 0;
}

.section-header h2 {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--admin-gray-800);
    margin: 0;
}

.chart-container {
    background: white;
    border: 1px solid var(--admin-gray-200);
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    margin-bottom: 1.5rem;
}

.chart-container:hover {
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
}

.chart-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--admin-gray-800);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.chart-title i {
    color: var(--admin-primary);
}

.health-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.8125rem;
}

.health-indicator.is-healthy {
    background: var(--admin-success-light);
    color: var(--admin-success);
}

.health-indicator.is-error {
    background: var(--admin-danger-light);
    color: var(--admin-danger);
}

.top-performer-row {
    padding: 0.875rem;
    border-bottom: 1px solid var(--admin-gray-100);
    transition: background 0.15s ease;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.top-performer-row:last-child {
    border-bottom: none;
}

.top-performer-row:hover {
    background: var(--admin-gray-50);
}

.performer-rank {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: var(--admin-gray-100);
    color: var(--admin-gray-700);
    font-weight: 700;
    font-size: 0.8125rem;
}

.performer-name {
    font-weight: 600;
    color: var(--admin-gray-800);
    font-size: 0.875rem;
}

.performer-details {
    font-size: 0.75rem;
    color: var(--admin-gray-700);
    margin-top: 0.125rem;
}

.performer-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-size: 0.8125rem;
    font-weight: 600;
    background: var(--admin-primary-light);
    color: var(--admin-primary);
}

.page-title-section {
    margin-top: 2rem;
    margin-bottom: 2.5rem;
}

.page-title-section h1 {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--admin-gray-800);
    margin-bottom: 0.5rem;
}

.page-subtitle {
    font-size: 0.9375rem;
    color: var(--admin-gray-700);
}

.system-health-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.health-metric {
    padding: 1rem;
    background: var(--admin-gray-50);
    border-radius: 6px;
}

.health-metric strong {
    display: block;
    font-size: 0.8125rem;
    color: var(--admin-gray-700);
    margin-bottom: 0.5rem;
}

.health-value {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.875rem;
}

.health-value.is-warning {
    background: var(--admin-warning-light);
    color: var(--admin-warning);
}

.health-value.is-success {
    background: var(--admin-success-light);
    color: var(--admin-success);
}

.health-value.is-danger {
    background: var(--admin-danger-light);
    color: var(--admin-danger);
}

.health-value.is-dark {
    background: var(--admin-gray-200);
    color: var(--admin-gray-700);
}

.config-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.config-button {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1rem;
    border: 1px solid var(--admin-gray-200);
    border-radius: 6px;
    background: white;
    color: var(--admin-gray-700);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.8125rem;
    transition: all 0.15s ease;
}

.config-button:hover {
    background: var(--admin-gray-50);
    border-color: var(--admin-primary);
    color: var(--admin-primary);
    text-decoration: none;
}

.config-button i {
    font-size: 0.875rem;
}
</style>

<div class="container is-fluid">

    <!-- Page Title -->
    <div class="page-title-section">
        <h1>
            <i class="fa fa-line-chart" style="color: var(--admin-primary);"></i> Site-Wide Quiz Generation Analytics
        </h1>
        <p class="page-subtitle">
            Comprehensive overview of AI quiz generation across your entire Moodle site
        </p>
    </div>

    <!-- Site-Wide Overview Cards -->
    <div class="section-header">
        <h2><i class="fa fa-bar-chart"></i> Site-Wide Overview</h2>
    </div>

    <div class="columns is-multiline">
        <!-- Total Questions Generated -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-primary">
                    <i class="fa fa-question-circle stat-icon-large"></i>
                </div>
                <div class="stat-label">Total Questions Generated</div>
                <div class="stat-value"><?php echo number_format($totalquestionsgenerated); ?></div>
            </div>
        </div>

        <!-- Total Quizzes Created -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-info">
                    <i class="fa fa-file-text-o stat-icon-large"></i>
                </div>
                <div class="stat-label">Total Quizzes Created</div>
                <div class="stat-value"><?php echo number_format($totalquizzescreated); ?></div>
            </div>
        </div>

        <!-- Active Teachers -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-success">
                    <i class="fa fa-users stat-icon-large"></i>
                </div>
                <div class="stat-label">Active Teachers</div>
                <div class="stat-value"><?php echo number_format($activeteachers); ?></div>
                <div class="stat-subtext"><?php echo $adoptionrate; ?>% adoption rate</div>
            </div>
        </div>

        <!-- Active Courses -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-warning">
                    <i class="fa fa-graduation-cap stat-icon-large"></i>
                </div>
                <div class="stat-label">Courses Using Plugin</div>
                <div class="stat-value"><?php echo number_format($activecourses); ?></div>
                <div class="stat-subtext"><?php echo $coursecoverage; ?>% course coverage</div>
            </div>
        </div>

        <!-- Average Quality Score -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-warning">
                    <i class="fa fa-star stat-icon-large"></i>
                </div>
                <div class="stat-label">Avg Quality Score</div>
                <div class="stat-value"><?php echo $avgqualityscore; ?></div>
            </div>
        </div>

        <!-- Site-Wide FTAR -->
        <div class="column is-4">
            <div class="admin-stat-card">
                <div class="stat-icon-container is-danger">
                    <i class="fa fa-bullseye stat-icon-large"></i>
                </div>
                <div class="stat-label">Site-Wide FTAR</div>
                <div class="stat-value"><?php echo $siteftar; ?>%</div>
                <div class="stat-subtext">First-Time Acceptance Rate</div>
            </div>
        </div>
    </div>

    <!-- Adoption & Usage Metrics -->
    <div class="section-header">
        <h2><i class="fa fa-line-chart"></i> Adoption & Usage Metrics</h2>
    </div>

    <div class="columns">
        <div class="column is-8">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-area-chart"></i> Usage Trend (Last 30 Days)
                </h3>
                <div id="usageTrendChart"></div>
            </div>
        </div>
        <div class="column is-4">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-pie-chart"></i> Adoption Overview
                </h3>
                <div id="adoptionChart"></div>
            </div>
        </div>
    </div>

    <!-- Quality Overview (Site-Wide) -->
    <div class="section-header">
        <h2><i class="fa fa-star"></i> Quality Overview</h2>
    </div>

    <div class="columns is-multiline">
        <div class="column is-6">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-th-list"></i> Bloom's Taxonomy Distribution
                </h3>
                <div id="bloomsDistributionChart"></div>
            </div>
        </div>
        <div class="column is-6">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-tasks"></i> Question Type Popularity
                </h3>
                <div id="questionTypeChart"></div>
            </div>
        </div>
        <div class="column is-12">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-signal"></i> Difficulty Distribution
                </h3>
                <div id="difficultyChart"></div>
            </div>
        </div>
    </div>

    <!-- Top Performers -->
    <div class="section-header">
        <h2><i class="fa fa-trophy"></i> Top Performers</h2>
    </div>

    <div class="columns">
        <div class="column is-6">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-trophy"></i> Top 10 Courses by Questions Generated
                </h3>
                <?php if (!empty($topcourses)) : ?>
                    <?php $rank = 1; foreach ($topcourses as $course) : ?>
                        <div class="top-performer-row">
                            <div class="is-flex is-align-items-center" style="gap: 0.75rem;">
                                <span class="performer-rank">#<?php echo $rank++; ?></span>
                                <strong class="performer-name"><?php echo format_string($course->fullname); ?></strong>
                            </div>
                            <span class="performer-badge">
                                <?php echo number_format($course->question_count); ?> questions
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p class="has-text-grey">No course data available yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="column is-6">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fa fa-trophy"></i> Top 10 Teachers by Acceptance Rate
                </h3>
                <?php if (!empty($topteachers)) : ?>
                    <?php $rank = 1; foreach ($topteachers as $teacher) : ?>
                        <div class="top-performer-row">
                            <div class="is-flex is-align-items-center" style="gap: 0.75rem;">
                                <span class="performer-rank">#<?php echo $rank++; ?></span>
                                <div>
                                    <strong class="performer-name"><?php echo fullname($teacher); ?></strong>
                                    <div class="performer-details">
                                        <?php echo $teacher->approved_questions; ?>/<?php echo $teacher->total_questions; ?> approved
                                    </div>
                                </div>
                            </div>
                            <span class="performer-badge">
                                <?php echo $teacher->acceptance_rate; ?>%
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p class="has-text-grey">No teacher data available yet (minimum 10 questions required).</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- System Health -->
    <div class="section-header">
        <h2><i class="fa fa-wrench"></i> System Health</h2>
    </div>

    <div class="columns">
        <div class="column is-12">
            <div class="chart-container">
                <div class="system-health-grid">
                    <div class="health-metric">
                        <strong>AI Provider Status</strong>
                        <span class="health-indicator <?php echo $aiproviderconfigured ? 'is-healthy' : 'is-error'; ?>">
                            <i class="fa <?php echo $aiproviderconfigured ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                            <?php echo $aiproviderconfigured ? 'Connected' : 'Not Configured'; ?>
                        </span>
                    </div>
                    <div class="health-metric">
                        <strong>Pending Generations</strong>
                        <span class="health-value is-warning">
                            <i class="fa fa-clock"></i> <?php echo $pendinggenerations; ?>
                        </span>
                    </div>
                    <div class="health-metric">
                        <strong>Recent Errors (7 days)</strong>
                        <span class="health-value <?php echo $recenterrors > 0 ? 'is-danger' : 'is-success'; ?>">
                            <i class="fa fa-exclamation-triangle"></i> <?php echo $recenterrors; ?>
                        </span>
                    </div>
                    <div class="health-metric">
                        <strong>Total Failed</strong>
                        <span class="health-value is-dark">
                            <i class="fa fa-ban"></i> <?php echo $failedgenerations; ?>
                        </span>
                    </div>
                </div>

                <hr style="border-color: var(--admin-gray-200); margin: 1.5rem 0;">

                <h3 class="chart-title">
                    <i class="fa fa-cog"></i> Quick Configuration Links
                </h3>
                <div class="config-buttons">
                    <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'local_hlai_quizgen']); ?>"
                       class="config-button">
                        <i class="fa fa-wrench"></i> Plugin Settings
                    </a>
                    <a href="<?php echo new moodle_url('/admin/roles/check.php', ['capability' => 'local/hlai_quizgen:generatequestions']); ?>"
                       class="config-button">
                        <i class="fa fa-users"></i> User Capabilities
                    </a>
                    <a href="<?php echo new moodle_url('/local/hlai_quizgen/view_logs.php'); ?>"
                       class="config-button">
                        <i class="fa fa-file-text-o"></i> View Error Logs
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// ================= APEXCHARTS VISUALIZATIONS =================.

// 1. Usage Trend Chart (Last 30 Days).
<?php
$trenddates = [];
$trendcounts = [];
foreach ($usagetrenddata as $data) {
    $trenddates[] = $data->date;
    $trendcounts[] = $data->count;
}
?>
var usageTrendOptions = {
    series: [{
        name: 'Questions Generated',
        data: <?php echo json_encode($trendcounts); ?>
    }],
    chart: {
        type: 'area',
        height: 250,
        toolbar: { show: false },
        fontFamily: 'inherit'
    },
    colors: ['#3B82F6'],
    dataLabels: { enabled: false },
    stroke: {
        curve: 'smooth',
        width: 2
    },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.4,
            opacityTo: 0.1
        }
    },
    xaxis: {
        categories: <?php echo json_encode($trenddates); ?>,
        labels: { show: true }
    },
    tooltip: {
        x: { format: 'dd MMM yyyy' }
    }
};
var usageTrendChart = new ApexCharts(document.querySelector("#usageTrendChart"), usageTrendOptions);
usageTrendChart.render();

// 2. Adoption Donut Chart.
var adoptionOptions = {
    series: [<?php echo $activeteachers; ?>, <?php echo max(0, $totaluserswithcapability - $activeteachers); ?>],
    chart: {
        type: 'donut',
        height: 250,
        fontFamily: 'inherit'
    },
    labels: ['Active Teachers', 'Inactive Teachers'],
    colors: ['#10B981', '#BFDBFE'],
    legend: {
        position: 'bottom',
        fontSize: '13px'
    },
    dataLabels: {
        enabled: true,
        style: {
            fontSize: '14px',
            fontWeight: 600,
            colors: ['#1E293B']
        },
        dropShadow: {
            enabled: true,
            top: 1,
            left: 1,
            blur: 2,
            color: '#fff',
            opacity: 0.8
        }
    },
    plotOptions: {
        pie: {
            donut: {
                labels: {
                    show: true,
                    total: {
                        show: true,
                        label: 'Total Users',
                        fontSize: '14px',
                        fontWeight: 600,
                        color: '#334155'
                    },
                    value: {
                        fontSize: '20px',
                        fontWeight: 700,
                        color: '#1E293B'
                    }
                }
            }
        }
    },
    states: {
        hover: {
            filter: {
                type: 'lighten',
                value: 0.05
            }
        },
        active: {
            filter: {
                type: 'none'
            }
        }
    },
    tooltip: {
        y: {
            formatter: function(value) {
                return value + ' teachers'
            }
        }
    }
};
var adoptionChart = new ApexCharts(document.querySelector("#adoptionChart"), adoptionOptions);
adoptionChart.render();

// 3. Bloom's Taxonomy Distribution (Radar Chart).
<?php
$bloomslabels = [];
$bloomsvalues = [];
foreach ($bloomsstats as $stat) {
    $bloomslabels[] = $stat->blooms_level;
    $bloomsvalues[] = (int)$stat->count;
}
?>
var bloomsOptions = {
    series: [{
        name: 'Questions',
        data: <?php echo json_encode($bloomsvalues); ?>
    }],
    chart: {
        type: 'radar',
        height: 350,
        fontFamily: 'inherit'
    },
    colors: ['#3B82F6'],
    fill: {
        opacity: 0.15
    },
    markers: {
        size: 4,
        colors: ['#3B82F6'],
        strokeWidth: 2,
        strokeColors: '#fff'
    },
    xaxis: {
        categories: <?php echo json_encode($bloomslabels); ?>
    },
    yaxis: {
        show: false
    },
    grid: {
        show: false
    }
};
var bloomsChart = new ApexCharts(document.querySelector("#bloomsDistributionChart"), bloomsOptions);
bloomsChart.render();

// 4. Question Type Bar Chart.
<?php
$typelabels = [];
$typevalues = [];
foreach ($questiontypestats as $stat) {
    $typelabels[] = ucfirst(str_replace('_', ' ', $stat->questiontype ?? ''));
    $typevalues[] = (int)$stat->count;
}
?>
var questionTypeOptions = {
    series: [{
        name: 'Questions',
        data: <?php echo json_encode($typevalues); ?>
    }],
    chart: {
        type: 'bar',
        height: 350,
        toolbar: { show: false },
        fontFamily: 'inherit'
    },
    plotOptions: {
        bar: {
            horizontal: false,
            borderRadius: 8,
            columnWidth: '60%'
        }
    },
    colors: ['#06B6D4'],
    dataLabels: { enabled: false },
    xaxis: {
        categories: <?php echo json_encode($typelabels); ?>
    }
};
var questionTypeChart = new ApexCharts(document.querySelector("#questionTypeChart"), questionTypeOptions);
questionTypeChart.render();

// 5. Difficulty Distribution Chart.
<?php
$difficultylabels = [];
$difficultyvalues = [];
foreach ($difficultystats as $stat) {
    $difficultylabels[] = ucfirst($stat->difficulty ?? '');
    $difficultyvalues[] = (int)$stat->count;
}
?>
var difficultyOptions = {
    series: [{
        name: 'Questions',
        data: <?php echo json_encode($difficultyvalues); ?>
    }],
    chart: {
        type: 'bar',
        height: 300,
        toolbar: { show: false },
        fontFamily: 'inherit'
    },
    plotOptions: {
        bar: {
            horizontal: true,
            borderRadius: 8
        }
    },
    colors: ['#F59E0B'],
    dataLabels: { enabled: true },
    xaxis: {
        categories: <?php echo json_encode($difficultylabels); ?>
    }
};
var difficultyChart = new ApexCharts(document.querySelector("#difficultyChart"), difficultyOptions);
difficultyChart.render();
</script>

<?php

echo $OUTPUT->footer();
