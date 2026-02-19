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

namespace local_hlai_quizgen\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;

/**
 * Renderable for the wizard page.
 *
 * Wraps the main wizard structure (heading, step indicator, step content)
 * in a template while individual steps continue to use html_writer for
 * their complex rendering logic.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_page implements renderable, templatable {

    /** @var string|int Current step identifier */
    private $currentstep;

    /** @var string Pre-rendered HTML for the current step content */
    private $stephtml;

    /**
     * Constructor.
     *
     * @param string|int $currentstep Current step (1-5 or 'progress')
     * @param string $stephtml Pre-rendered step content HTML
     */
    public function __construct($currentstep, string $stephtml) {
        $this->currentstep = $currentstep;
        $this->stephtml = $stephtml;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        // Build step class for wrapper div.
        if ($this->currentstep === 'progress') {
            $stepclass = 'hlai-step-progress';
        } else if (is_numeric($this->currentstep)) {
            $stepclass = 'hlai-step-' . (int)$this->currentstep;
        } else {
            $stepclass = 'hlai-step-1';
        }

        // Normalize step for indicator display.
        $activestep = $this->currentstep;
        if ($activestep === 'progress') {
            $activestep = 3;
        }
        $activestep = (int)$activestep;

        // Build step indicator data.
        $steptitles = [
            1 => get_string('step1_title', 'local_hlai_quizgen'),
            2 => get_string('step2_title', 'local_hlai_quizgen'),
            3 => get_string('step3_title', 'local_hlai_quizgen'),
            4 => get_string('step4_title', 'local_hlai_quizgen'),
            5 => get_string('step5_title', 'local_hlai_quizgen'),
        ];

        $steps = [];
        foreach ($steptitles as $num => $title) {
            $step = [
                'number' => $num,
                'title' => $title,
                'is_active' => ($num == $activestep),
                'is_completed' => ($num < $activestep),
            ];
            $steps[] = $step;
        }

        return [
            'stepclass' => $stepclass,
            'wizard_title' => get_string('wizard_title', 'local_hlai_quizgen'),
            'wizard_subtitle' => get_string('wizard_subtitle', 'local_hlai_quizgen'),
            'steps' => $steps,
            'stephtml' => $this->stephtml,
        ];
    }
}
