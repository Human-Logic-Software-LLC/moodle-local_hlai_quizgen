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
 * Renderable class for the teacher dashboard index page.
 *
 * Prepares all data for the index Mustache template.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\output;

use renderable;
use templatable;
use renderer_base;
use moodle_url;

/**
 * Index (teacher dashboard) page renderable.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class index_page implements renderable, templatable {
    /** @var int Course ID. */
    private $courseid;

    /** @var int Total quizzes created. */
    private $totalquizzes;

    /** @var int Course-specific quiz count. */
    private $coursequizzes;

    /** @var int Total questions generated. */
    private $totalquestions;

    /** @var float Average quality score. */
    private $avgquality;

    /** @var float Acceptance rate. */
    private $acceptancerate;

    /** @var float First-time acceptance rate. */
    private $ftar;

    /** @var array Recent request records. */
    private $recentrequests;

    /** @var bool Whether type distribution data exists. */
    private $hastypedistribution;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID.
     * @param int $totalquizzes Total quizzes created.
     * @param int $coursequizzes Course-specific quiz count.
     * @param int $totalquestions Total questions generated.
     * @param float $avgquality Average quality score.
     * @param float $acceptancerate Acceptance rate percentage.
     * @param float $ftar First-time acceptance rate.
     * @param array $recentrequests Recent request records.
     * @param bool $hastypedistribution Whether type distribution data exists.
     */
    public function __construct(
        int $courseid,
        int $totalquizzes,
        int $coursequizzes,
        int $totalquestions,
        float $avgquality,
        float $acceptancerate,
        float $ftar,
        array $recentrequests,
        bool $hastypedistribution
    ) {
        $this->courseid = $courseid;
        $this->totalquizzes = $totalquizzes;
        $this->coursequizzes = $coursequizzes;
        $this->totalquestions = $totalquestions;
        $this->avgquality = $avgquality;
        $this->acceptancerate = $acceptancerate;
        $this->ftar = $ftar;
        $this->recentrequests = $recentrequests;
        $this->hastypedistribution = $hastypedistribution;
    }

    /**
     * Export data for the template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context data.
     */
    public function export_for_template(renderer_base $output): array {
        $wizardurl = (new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $this->courseid]))->out(false);
        $analyticsurl = (new moodle_url('/local/hlai_quizgen/analytics.php', ['courseid' => $this->courseid]))->out(false);
        $debuglogsurl = (new moodle_url('/local/hlai_quizgen/debug_logs.php', ['courseid' => $this->courseid]))->out(false);

        // Quality display values.
        $qualityvalue = $this->avgquality > 0
            ? get_string('quality_score_value', 'local_hlai_quizgen', $this->avgquality)
            : get_string('not_available', 'local_hlai_quizgen');

        $qualitygood = $this->avgquality >= 70;
        $qualitybad = $this->avgquality > 0 && $this->avgquality < 70;
        $qualitynone = $this->avgquality <= 0;

        // FTAR status.
        if ($this->ftar >= 75) {
            $ftarstatus = get_string('ftar_excellent', 'local_hlai_quizgen');
            $ftarclass = 'is-success';
        } else if ($this->ftar >= 60) {
            $ftarstatus = get_string('ftar_good', 'local_hlai_quizgen');
            $ftarclass = 'is-warning';
        } else if ($this->ftar >= 45) {
            $ftarstatus = get_string('ftar_fair', 'local_hlai_quizgen');
            $ftarclass = 'is-warning';
        } else {
            $ftarstatus = get_string('ftar_needs_attention', 'local_hlai_quizgen');
            $ftarclass = 'is-danger';
        }

        // Recent requests.
        $requestsdata = [];
        foreach ($this->recentrequests as $request) {
            $statusicon = '';
            $statusclass = '';
            switch ($request->status) {
                case 'completed':
                    $statusicon = 'fa-check-circle hlai-icon-success';
                    $statusclass = 'is-success';
                    break;
                case 'processing':
                    $statusicon = 'fa-spinner fa-spin hlai-icon-warning';
                    $statusclass = 'is-warning';
                    break;
                case 'failed':
                    $statusicon = 'fa-times-circle hlai-icon-danger';
                    $statusclass = 'is-danger';
                    break;
                default:
                    $statusicon = 'fa-file hlai-icon-secondary';
                    $statusclass = 'is-info';
            }
            $timeago = format_time(time() - $request->timecreated);
            $requestsdata[] = [
                'statusicon' => $statusicon,
                'statusclass' => $statusclass,
                'coursename' => format_string($request->coursename),
                'progresstext' => get_string(
                    'questions_progress',
                    'local_hlai_quizgen',
                    (object)[
                        'generated' => $request->questions_generated,
                        'total' => $request->total_questions,
                    ]
                ),
                'timeago' => get_string('time_ago', 'local_hlai_quizgen', $timeago),
                'statuslabel' => get_string('status_' . $request->status, 'local_hlai_quizgen'),
            ];
        }

        return [
            'wizardurl' => $wizardurl,
            'analyticsurl' => $analyticsurl,
            'debuglogsurl' => $debuglogsurl,
            'dashboard_title' => get_string('dashboard_title', 'local_hlai_quizgen'),
            'dashboard_subtitle' => get_string('dashboard_subtitle', 'local_hlai_quizgen'),
            'create_new_quiz' => get_string('create_new_quiz', 'local_hlai_quizgen'),
            'view_analytics' => get_string('view_analytics', 'local_hlai_quizgen'),

            // KPI stats.
            'totalquizzes' => $this->totalquizzes,
            'coursequizzes' => $this->coursequizzes,
            'hascoursequizzes' => $this->coursequizzes > 0,
            'coursequizzestext' => $this->coursequizzes > 0
                ? get_string('in_this_course', 'local_hlai_quizgen', $this->coursequizzes)
                : '',
            'quizzes_created' => get_string('quizzes_created', 'local_hlai_quizgen'),
            'totalquestions' => $this->totalquestions,
            'questions_generated_heading' => get_string('questions_generated_heading', 'local_hlai_quizgen'),
            'qualityvalue' => $qualityvalue,
            'avg_quality_score' => get_string('avg_quality_score', 'local_hlai_quizgen'),
            'qualitygood' => $qualitygood,
            'qualitybad' => $qualitybad,
            'qualitynone' => $qualitynone,
            'qualitygoodtext' => get_string('quality_good', 'local_hlai_quizgen'),
            'qualitybadtext' => get_string('quality_needs_attention', 'local_hlai_quizgen'),
            'qualitynonetext' => get_string('no_quality_scores', 'local_hlai_quizgen'),
            'acceptancerate' => $this->acceptancerate,
            'acceptance_rate' => get_string('acceptance_rate', 'local_hlai_quizgen'),
            'ftartext' => get_string('ftar', 'local_hlai_quizgen', $this->ftar),
            'acceptancehigh' => $this->acceptancerate >= 70,

            // FTAR chart.
            'first_time_acceptance_rate' => get_string('first_time_acceptance_rate', 'local_hlai_quizgen'),
            'questions_approved_without_regen' => get_string('questions_approved_without_regen', 'local_hlai_quizgen'),
            'ftarstatus' => $ftarstatus,
            'ftarclass' => $ftarclass,

            // Chart sections.
            'quality_trends' => get_string('quality_trends', 'local_hlai_quizgen'),
            'quality_trends_subtitle' => get_string('quality_trends_subtitle', 'local_hlai_quizgen'),
            'question_types' => get_string('question_types', 'local_hlai_quizgen'),
            'notypedata' => !$this->hastypedistribution,
            'no_questions_yet' => get_string('no_questions_yet', 'local_hlai_quizgen'),
            'create_first_quiz' => get_string('create_first_quiz', 'local_hlai_quizgen'),
            'difficulty_distribution' => get_string('difficulty_distribution', 'local_hlai_quizgen'),
            'blooms_coverage' => get_string('blooms_coverage', 'local_hlai_quizgen'),
            'blooms_coverage_subtitle' => get_string('blooms_coverage_subtitle', 'local_hlai_quizgen'),

            // Quick actions panel.
            'quick_actions' => get_string('quick_actions', 'local_hlai_quizgen'),
            'generate_new_questions' => get_string('generate_new_questions', 'local_hlai_quizgen'),
            'view_activity_logs' => get_string('view_activity_logs', 'local_hlai_quizgen'),

            // Recent activity.
            'recent_activity' => get_string('recent_activity', 'local_hlai_quizgen'),
            'hasrecentrequests' => !empty($this->recentrequests),
            'recentrequests' => $requestsdata,
            'no_recent_activity' => get_string('no_recent_activity', 'local_hlai_quizgen'),
            'start_creating' => get_string('start_creating', 'local_hlai_quizgen'),

            // Regeneration chart.
            'regeneration_by_type' => get_string('regeneration_by_type', 'local_hlai_quizgen'),
            'regeneration_by_type_subtitle' => get_string('regeneration_by_type_subtitle', 'local_hlai_quizgen'),

            // Tips.
            'tips_title' => get_string('tips_title', 'local_hlai_quizgen'),
            'tip_detailed_content' => get_string('tip_detailed_content', 'local_hlai_quizgen'),
            'tip_specific_topics' => get_string('tip_specific_topics', 'local_hlai_quizgen'),
            'tip_assessment_purpose' => get_string('tip_assessment_purpose', 'local_hlai_quizgen'),
            'tip_question_types' => get_string('tip_question_types', 'local_hlai_quizgen'),
        ];
    }
}
