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
$PAGE->set_title(get_string('pluginname', 'local_hlai_quizgen') . ' - Dashboard');
$PAGE->set_heading($course->fullname);

// Add Bulma CSS Framework (Native/Local - non-minified for debugging).
$PAGE->requires->css('/local/hlai_quizgen/bulma.css');

// Add our custom CSS (loaded after Bulma to override and fix Moodle compatibility).
$PAGE->requires->css('/local/hlai_quizgen/styles-bulma.css');

// Add ApexCharts (Local - non-minified for debugging).
$PAGE->requires->js(new moodle_url('/local/hlai_quizgen/apexcharts.js'), true);

// Add our AMD modules.
$PAGE->requires->js_call_amd('local_hlai_quizgen/dashboard', 'init', [
    $courseid,
    sesskey(),
]);

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

// DEBUG: Temporarily log FTAR calculation.
debugging(
    "FTAR Debug - User: $userid, Total Reviewed: $totalreviewed, "
    . "First-time Approved: $firsttimeapproved, Approved: $approvedquestions, FTAR: $ftar%"
);

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

// Output starts here.
echo $OUTPUT->header();
?>

<div class="hlai-quizgen-wrapper local-hlai-iksha" style="margin-top: 2rem;">
<div class="container">
    <!-- Page Header -->
    <div class="level mb-5">
        <div class="level-left">
            <div class="level-item">
                <div>
                    <h1 class="title is-3 mb-1">
                        <i class="fa fa-graduation-cap" style="color: #3B82F6;"></i>
                        <?php echo get_string('dashboard_title', 'local_hlai_quizgen'); ?>
                    </h1>
                    <p class="subtitle is-6 has-text-grey mt-2">
                        <?php echo get_string('dashboard_subtitle', 'local_hlai_quizgen'); ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="level-right">
            <div class="level-item">
                <div class="buttons">
                    <a href="<?php echo new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid]); ?>"
                       class="button is-primary">
                        <span class="icon"><i>+</i></span>
                        <span><?php echo get_string('create_new_quiz', 'local_hlai_quizgen'); ?></span>
                    </a>
                    <a href="<?php echo new moodle_url('/local/hlai_quizgen/analytics.php', ['courseid' => $courseid]); ?>"
                       class="button is-light">
                        <span><i class="fa fa-bar-chart" style="color: #3B82F6;"></i></span>
                        <span><?php echo get_string('view_analytics', 'local_hlai_quizgen'); ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI Stats Row -->
    <div class="columns mb-5">
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-file-text-o" style="color: #3B82F6;"></i></p>
                <p class="title is-3 mb-1"><?php echo $totalquizzes; ?></p>
                <p class="heading"><?php echo get_string('quizzes_created', 'local_hlai_quizgen'); ?></p>
                <?php if ($coursequizzes > 0) : ?>
                <p class="help has-text-success">
                    <?php echo get_string('in_this_course', 'local_hlai_quizgen', $coursequizzes); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-question-circle" style="color: #06B6D4;"></i></p>
                <p class="title is-3 mb-1"><?php echo $totalquestions; ?></p>
                <p class="heading"><?php echo get_string('questions_generated_heading', 'local_hlai_quizgen'); ?></p>
            </div>
        </div>
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-star" style="color: #F59E0B;"></i></p>
                <p class="title is-3 mb-1"><?php echo $avgquality > 0 ? $avgquality . '/100' : 'N/A'; ?></p>
                <p class="heading"><?php echo get_string('avg_quality_score', 'local_hlai_quizgen'); ?></p>
                <?php
                $qualityclass = $avgquality > 0
                    ? ($avgquality >= 70 ? 'has-text-success' : 'has-text-danger')
                    : 'has-text-grey';
                ?>
                <p class="help <?php echo $qualityclass; ?>">
                    <?php
                    if ($avgquality > 0) {
                        if ($avgquality >= 70) {
                            echo get_string('quality_good', 'local_hlai_quizgen');
                        } else {
                            echo get_string('quality_needs_attention', 'local_hlai_quizgen');
                        }
                    } else {
                        echo get_string('no_quality_scores', 'local_hlai_quizgen');
                    }
                    ?>
                </p>
            </div>
        </div>
        <div class="column">
            <div class="box has-text-centered">
                <p class="is-size-3"><i class="fa fa-check-circle" style="color: #10B981;"></i></p>
                <p class="title is-3 mb-1"><?php echo $acceptancerate; ?>%</p>
                <p class="heading"><?php echo get_string('acceptance_rate', 'local_hlai_quizgen'); ?></p>
                <p class="help <?php echo $acceptancerate >= 70 ? 'has-text-success' : 'has-text-grey'; ?>">
                    <?php echo get_string('ftar', 'local_hlai_quizgen', $ftar); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="columns">
        <!-- Left Column: Charts -->
        <div class="column is-two-thirds">

            <!-- First-Time Acceptance Rate Chart -->
            <div class="box mb-4">
                <p class="title is-5">
                    <i class="fa fa-bullseye" style="color: #10B981;"></i>
                    <?php echo get_string('first_time_acceptance_rate', 'local_hlai_quizgen'); ?>
                </p>
                <p class="subtitle is-6 has-text-grey">
                    <?php echo get_string('questions_approved_without_regen', 'local_hlai_quizgen'); ?>
                </p>
                <div id="ftar-gauge-chart" style="height: 280px;"></div>
                <div class="has-text-centered mt-3">
                    <?php
                    $ftarstatus = '';
                    $ftarclass = '';
                    if ($ftar >= 75) {
                        $ftarstatus = get_string('ftar_excellent', 'local_hlai_quizgen');
                        $ftarclass = 'is-success';
                    } else if ($ftar >= 60) {
                        $ftarstatus = get_string('ftar_good', 'local_hlai_quizgen');
                        $ftarclass = 'is-warning';
                    } else if ($ftar >= 45) {
                        $ftarstatus = get_string('ftar_fair', 'local_hlai_quizgen');
                        $ftarclass = 'is-warning';
                    } else {
                        $ftarstatus = get_string('ftar_needs_attention', 'local_hlai_quizgen');
                        $ftarclass = 'is-danger';
                    }
                    ?>
                    <span class="tag <?php echo $ftarclass; ?> is-medium">
                        <?php echo $ftarstatus; ?>
                    </span>
                </div>
            </div>

            <!-- Quality Trends Chart -->
            <div class="box mb-4">
                <p class="title is-5">
                    <i class="fa fa-line-chart" style="color: #3B82F6;"></i>
                    <?php echo get_string('quality_trends', 'local_hlai_quizgen'); ?>
                </p>
                <p class="subtitle is-6 has-text-grey">
                    <?php echo get_string('quality_trends_subtitle', 'local_hlai_quizgen'); ?>
                </p>
                <div id="acceptance-trend-chart" style="height: 300px;"></div>
            </div>

            <!-- Question Type & Difficulty Charts Row -->
            <div class="columns">
                <div class="column is-half">
                    <div class="box">
                        <p class="title is-5">
                            <i class="fa fa-bar-chart" style="color: #64748B;"></i>
                            <?php echo get_string('question_types', 'local_hlai_quizgen'); ?>
                        </p>
                        <div id="question-type-chart" style="height: 280px;">
                            <?php if (empty($typedistribution)) : ?>
                            <div class="has-text-centered py-6">
                                <span style="font-size: 3rem;"><i class="fa fa-bar-chart" style="color: #CBD5E1;"></i></span>
                                <p class="has-text-grey mt-3">
                                    <?php echo get_string('no_questions_yet', 'local_hlai_quizgen'); ?>
                                </p>
                                <a href="<?php echo new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid]); ?>"
                                   class="button is-primary is-outlined is-small mt-3">
                                    <?php echo get_string('create_first_quiz', 'local_hlai_quizgen'); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="column is-half">
                    <div class="box">
                        <p class="title is-5">
                            <i class="fa fa-line-chart" style="color: #06B6D4;"></i>
                            <?php echo get_string('difficulty_distribution', 'local_hlai_quizgen'); ?>
                        </p>
                        <div id="difficulty-chart" style="height: 280px;"></div>
                    </div>
                </div>
            </div>

            <!-- Bloom's Taxonomy Charts -->
            <div class="box mt-4">
                <p class="title is-5">
                    <i class="fa fa-lightbulb-o" style="color: #3B82F6;"></i>
                    <?php echo get_string('blooms_coverage', 'local_hlai_quizgen'); ?>
                </p>
                <p class="subtitle is-6 has-text-grey">
                    <?php echo get_string('blooms_coverage_subtitle', 'local_hlai_quizgen'); ?>
                </p>
                <div class="columns">
                    <div class="column is-half">
                        <div id="blooms-radar-chart" style="height: 400px;"></div>
                    </div>
                    <div class="column is-half">
                        <div id="blooms-bar-chart" style="height: 400px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Actions & Activity -->
        <div class="column is-one-third">

            <!-- Quick Actions -->
            <div class="panel mb-4">
                <p class="panel-heading">
                    <i class="fa fa-bolt" style="color: #F59E0B;"></i>
                    <?php echo get_string('quick_actions', 'local_hlai_quizgen'); ?>
                </p>
                <a href="<?php echo new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $courseid]); ?>"
                   class="panel-block">
                    <span class="panel-icon has-text-primary">+</span>
                    <?php echo get_string('generate_new_questions', 'local_hlai_quizgen'); ?>
                </a>
                <a href="<?php echo new moodle_url('/local/hlai_quizgen/analytics.php', ['courseid' => $courseid]); ?>"
                   class="panel-block">
                    <span class="panel-icon" style="color: #06B6D4;"><i class="fa fa-bar-chart"></i></span>
                    <?php echo get_string('view_analytics', 'local_hlai_quizgen'); ?>
                </a>
                <a href="<?php echo new moodle_url('/local/hlai_quizgen/view_logs.php', ['courseid' => $courseid]); ?>"
                   class="panel-block">
                    <span class="panel-icon" style="color: #64748B;"><i class="fa fa-list-alt"></i></span>
                    <?php echo get_string('view_activity_logs', 'local_hlai_quizgen'); ?>
                </a>
            </div>

            <!-- Recent Activity -->
            <div class="panel mb-4">
                <p class="panel-heading">
                    <i class="fa fa-clock" style="color: #06B6D4;"></i>
                    <?php echo get_string('recent_activity', 'local_hlai_quizgen'); ?>
                </p>
                <?php if (empty($recentrequests)) : ?>
                <div class="panel-block">
                    <div class="has-text-centered py-4" style="width: 100%;">
                        <span style="font-size: 2rem;"><i class="fa fa-clipboard" style="color: #CBD5E1;"></i></span>
                        <p class="has-text-weight-semibold mt-3">
                            <?php echo get_string('no_recent_activity', 'local_hlai_quizgen'); ?>
                        </p>
                        <p class="has-text-grey is-size-7"><?php echo get_string('start_creating', 'local_hlai_quizgen'); ?></p>
                    </div>
                </div>
                <?php else : ?>
                    <?php foreach ($recentrequests as $request) :
                        $statusicon = '';
                        $statusclass = '';
                        switch ($request->status) {
                            case 'completed':
                                $statusicon = '<i class="fa fa-check-circle" style="color: #10B981;"></i>';
                                $statusclass = 'is-success';
                                break;
                            case 'processing':
                                $statusicon = '<i class="fa fa-spinner fa-spin" style="color: #F59E0B;"></i>';
                                $statusclass = 'is-warning';
                                break;
                            case 'failed':
                                $statusicon = '<i class="fa fa-times-circle" style="color: #EF4444;"></i>';
                                $statusclass = 'is-danger';
                                break;
                            default:
                                $statusicon = '<i class="fa fa-file" style="color: #64748B;"></i>';
                                $statusclass = 'is-info';
                        }
                        $timeago = format_time(time() - $request->timecreated);
                ?>
                <div class="panel-block is-flex is-justify-content-space-between">
                    <div class="is-flex is-align-items-center">
                        <span class="mr-2"><?php echo $statusicon; ?></span>
                        <div>
                            <p class="has-text-weight-medium is-size-7"><?php echo format_string($request->coursename); ?></p>
                            <p class="has-text-grey is-size-7">
                                <?php echo $request->questions_generated; ?>/<?php echo $request->total_questions; ?> questions
                                &middot; <?php echo $timeago; ?> ago
                            </p>
                        </div>
                    </div>
                    <span class="tag <?php echo $statusclass; ?> is-light is-small">
                        <?php echo ucfirst($request->status); ?>
                    </span>
                </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Regeneration by Type -->
            <div class="box">
                <p class="title is-5">
                    <i class="fa fa-refresh" style="color: #F59E0B;"></i>
                    <?php echo get_string('regeneration_by_type', 'local_hlai_quizgen'); ?>
                </p>
                <p class="subtitle is-6 has-text-grey">
                    <?php echo get_string('regeneration_by_type_subtitle', 'local_hlai_quizgen'); ?>
                </p>
                <div id="regen-by-type-chart" style="height: 250px;"></div>
            </div>
        </div>
    </div>

    <!-- Tips Section -->
    <div class="notification is-info is-light mt-5 mb-6">
        <button class="delete" onclick="this.closest('.notification').style.display='none';"></button>
        <strong>
            <i class="fa fa-lightbulb-o" style="color: #F59E0B;"></i>
            <?php echo get_string('tips_title', 'local_hlai_quizgen'); ?>
        </strong>
        <ul class="mt-2">
            <li><?php echo get_string('tip_detailed_content', 'local_hlai_quizgen'); ?></li>
            <li><?php echo get_string('tip_specific_topics', 'local_hlai_quizgen'); ?></li>
            <li><?php echo get_string('tip_assessment_purpose', 'local_hlai_quizgen'); ?></li>
            <li><?php echo get_string('tip_question_types', 'local_hlai_quizgen'); ?></li>
        </ul>
    </div>
</div>
</div>
<div style="height: 60px;"></div>

<!-- Pass data to JavaScript -->
<script>
window.hlaiQuizgenDashboard = {
    courseid: <?php echo $courseid; ?>,
    sesskey: '<?php echo sesskey(); ?>',
    ajaxUrl: '<?php echo $CFG->wwwroot; ?>/local/hlai_quizgen/ajax.php',
    stats: {
        totalQuizzes: <?php echo $totalquizzes; ?>,
        totalQuestions: <?php echo $totalquestions; ?>,
        approvedQuestions: <?php echo $approvedquestions; ?>,
        avgQuality: <?php echo $avgquality > 0 ? $avgquality : 0; ?>,
        acceptanceRate: <?php echo $acceptancerate; ?>,
        ftar: <?php echo $ftar; ?>
    },
    typeDistribution: <?php echo json_encode(array_values($typedistribution)); ?>
};
</script>

<?php
echo $OUTPUT->footer();
