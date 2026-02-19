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
 * Renderable class for the analytics page.
 *
 * Prepares all data for the analytics Mustache template.
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
 * Analytics page renderable.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analytics_page implements renderable, templatable {

    /** @var int Course ID. */
    private $courseid;

    /** @var string Time range filter. */
    private $timerange;

    /** @var array Summary statistics. */
    private $stats;

    /** @var array Question type stats. */
    private $typestats;

    /** @var array Difficulty stats. */
    private $difficultystats;

    /** @var array Bloom's stats. */
    private $bloomsstats;

    /** @var array Rejection reasons. */
    private $rejectionreasons;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID.
     * @param string $timerange Time range filter.
     * @param array $stats Summary statistics.
     * @param array $typestats Question type stats.
     * @param array $difficultystats Difficulty stats.
     * @param array $bloomsstats Blooms stats.
     * @param array $rejectionreasons Rejection reasons.
     */
    public function __construct(
        int $courseid,
        string $timerange,
        array $stats,
        array $typestats,
        array $difficultystats,
        array $bloomsstats,
        array $rejectionreasons
    ) {
        $this->courseid = $courseid;
        $this->timerange = $timerange;
        $this->stats = $stats;
        $this->typestats = $typestats;
        $this->difficultystats = $difficultystats;
        $this->bloomsstats = $bloomsstats;
        $this->rejectionreasons = $rejectionreasons;
    }

    /**
     * Export data for the template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context data.
     */
    public function export_for_template(renderer_base $output): array {
        $dashboardurl = (new moodle_url('/local/hlai_quizgen/index.php', ['courseid' => $this->courseid]))->out(false);

        // Time range filter buttons.
        $timeranges = [
            ['value' => '7', 'label' => get_string('analytics_last_7_days', 'local_hlai_quizgen'),
             'active' => $this->timerange === '7',
             'url' => '?courseid=' . $this->courseid . '&timerange=7'],
            ['value' => '30', 'label' => get_string('analytics_last_30_days', 'local_hlai_quizgen'),
             'active' => $this->timerange === '30',
             'url' => '?courseid=' . $this->courseid . '&timerange=30'],
            ['value' => '90', 'label' => get_string('analytics_last_90_days', 'local_hlai_quizgen'),
             'active' => $this->timerange === '90',
             'url' => '?courseid=' . $this->courseid . '&timerange=90'],
            ['value' => 'all', 'label' => get_string('analytics_all_time', 'local_hlai_quizgen'),
             'active' => $this->timerange === 'all',
             'url' => '?courseid=' . $this->courseid . '&timerange=all'],
        ];

        // Type stats table rows.
        $typerows = [];
        foreach ($this->typestats as $type => $stats) {
            if (empty($type)) {
                continue;
            }
            $rate = $stats->count > 0 ? round(($stats->approved / $stats->count) * 100, 1) : 0;
            $rateclass = $rate >= 70 ? 'is-success' : ($rate >= 50 ? 'is-warning' : 'is-danger');
            $typerows[] = [
                'typename' => ucfirst($type),
                'count' => $stats->count,
                'approved' => $stats->approved,
                'rate' => $rate,
                'rateclass' => $rateclass,
                'avgregen' => round($stats->avg_regen, 2),
            ];
        }

        // Insights.
        $insights = $this->build_insights();

        return [
            'dashboardurl' => $dashboardurl,
            'courseid' => $this->courseid,

            // Header.
            'analytics_dashboard' => get_string('analytics_dashboard', 'local_hlai_quizgen'),
            'analytics_dashboard_subtitle' => get_string('analytics_dashboard_subtitle', 'local_hlai_quizgen'),
            'analytics_back_to_dashboard' => get_string('analytics_back_to_dashboard', 'local_hlai_quizgen'),

            // Time range filter.
            'analytics_time_range' => get_string('analytics_time_range', 'local_hlai_quizgen'),
            'timeranges' => $timeranges,

            // Summary stats.
            'totalrequests' => $this->stats['totalrequests'],
            'analytics_quiz_generations' => get_string('analytics_quiz_generations', 'local_hlai_quizgen'),
            'totalquestions' => $this->stats['totalquestions'],
            'analytics_questions_created' => get_string('analytics_questions_created', 'local_hlai_quizgen'),
            'approvedquestions' => $this->stats['approvedquestions'],
            'approved_label' => get_string('approved', 'local_hlai_quizgen'),
            'acceptancerate' => $this->stats['acceptancerate'],
            'analytics_pct_acceptance' => get_string('analytics_pct_acceptance', 'local_hlai_quizgen',
                $this->stats['acceptancerate']),
            'rejectedquestions' => $this->stats['rejectedquestions'],
            'rejected_label' => get_string('rejected', 'local_hlai_quizgen'),
            'avgquality' => $this->stats['avgquality'],
            'analytics_avg_quality' => get_string('analytics_avg_quality', 'local_hlai_quizgen'),
            'ftar' => $this->stats['ftar'],
            'analytics_ftar' => get_string('analytics_ftar', 'local_hlai_quizgen'),

            // Charts.
            'analytics_review_funnel' => get_string('analytics_review_funnel', 'local_hlai_quizgen'),
            'analytics_review_funnel_desc' => get_string('analytics_review_funnel_desc', 'local_hlai_quizgen'),
            'analytics_quality_score_dist' => get_string('analytics_quality_score_dist', 'local_hlai_quizgen'),
            'analytics_quality_score_dist_desc' => get_string('analytics_quality_score_dist_desc', 'local_hlai_quizgen'),

            // Type performance.
            'analytics_type_performance' => get_string('analytics_type_performance', 'local_hlai_quizgen'),
            'analytics_type_performance_desc' => get_string('analytics_type_performance_desc', 'local_hlai_quizgen'),
            'question_type' => get_string('question_type', 'local_hlai_quizgen'),
            'analytics_total' => get_string('analytics_total', 'local_hlai_quizgen'),
            'analytics_rate' => get_string('analytics_rate', 'local_hlai_quizgen'),
            'analytics_avg_regen' => get_string('analytics_avg_regen', 'local_hlai_quizgen'),
            'typerows' => $typerows,

            // Difficulty & Bloom's.
            'analytics_difficulty_analysis' => get_string('analytics_difficulty_analysis', 'local_hlai_quizgen'),
            'analytics_difficulty_analysis_desc' => get_string('analytics_difficulty_analysis_desc', 'local_hlai_quizgen'),
            'blooms_coverage' => get_string('blooms_coverage', 'local_hlai_quizgen'),
            'analytics_cognitive_level_dist' => get_string('analytics_cognitive_level_dist', 'local_hlai_quizgen'),

            // Regeneration.
            'analytics_regen_analysis' => get_string('analytics_regen_analysis', 'local_hlai_quizgen'),
            'analytics_regen_analysis_desc' => get_string('analytics_regen_analysis_desc', 'local_hlai_quizgen'),
            'analytics_regen_distribution' => get_string('analytics_regen_distribution', 'local_hlai_quizgen'),
            'analytics_regen_by_difficulty' => get_string('analytics_regen_by_difficulty', 'local_hlai_quizgen'),

            // Rejection.
            'hasrejectionreasons' => !empty($this->rejectionreasons),
            'analytics_rejection_analysis' => get_string('analytics_rejection_analysis', 'local_hlai_quizgen'),
            'analytics_rejection_analysis_desc' => get_string('analytics_rejection_analysis_desc', 'local_hlai_quizgen'),
            'analytics_top_rejection_reasons' => get_string('analytics_top_rejection_reasons', 'local_hlai_quizgen'),
            'rejectionreasons' => array_map(function($reason) {
                return [
                    'reason' => htmlspecialchars($reason->reason),
                    'count' => $reason->count,
                ];
            }, $this->rejectionreasons),

            // Trends.
            'analytics_trends_over_time' => get_string('analytics_trends_over_time', 'local_hlai_quizgen'),
            'analytics_trends_over_time_desc' => get_string('analytics_trends_over_time_desc', 'local_hlai_quizgen'),

            // Insights.
            'analytics_insights_recommendations' => get_string('analytics_insights_recommendations', 'local_hlai_quizgen'),
            'insights' => $insights,
        ];
    }

    /**
     * Build insights data.
     *
     * @return array Insight items for the template.
     */
    private function build_insights(): array {
        $insights = [];

        if ($this->stats['ftar'] < 50) {
            $insights[] = [
                'type' => 'warning',
                'iconclass' => 'fa-exclamation-triangle hlai-icon-warning',
                'title' => get_string('analytics_insight_low_ftar_title', 'local_hlai_quizgen'),
                'message' => get_string('analytics_insight_low_ftar_msg', 'local_hlai_quizgen', $this->stats['ftar']),
            ];
        } else if ($this->stats['ftar'] >= 75) {
            $insights[] = [
                'type' => 'success',
                'iconclass' => 'fa-check-circle hlai-icon-success',
                'title' => get_string('analytics_insight_high_ftar_title', 'local_hlai_quizgen'),
                'message' => get_string('analytics_insight_high_ftar_msg', 'local_hlai_quizgen', $this->stats['ftar']),
            ];
        }

        if ($this->stats['avgregenerations'] > 2) {
            $insights[] = [
                'type' => 'warning',
                'iconclass' => 'fa-refresh hlai-icon-info',
                'title' => get_string('analytics_insight_high_regen_title', 'local_hlai_quizgen'),
                'message' => get_string('analytics_insight_high_regen_msg', 'local_hlai_quizgen',
                    $this->stats['avgregenerations']),
            ];
        }

        // Best performing question type.
        $besttype = null;
        $bestrate = 0;
        foreach ($this->typestats as $type => $stats) {
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
                'iconclass' => 'fa-bar-chart',
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
                'iconclass' => 'fa-info-circle',
                'title' => get_string('analytics_insight_keep_generating_title', 'local_hlai_quizgen'),
                'message' => get_string('analytics_insight_keep_generating_msg', 'local_hlai_quizgen'),
            ];
        }

        return $insights;
    }
}
