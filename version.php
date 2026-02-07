<?php
// This file is part of Moodle - http://moodle.org/.
//
// Moodle is free software: you can redistribute it and/or modify.
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,.
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the.
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License.
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Version page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 STARTER
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Version information for the Human Logic AI Quiz Generator plugin.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_hlai_quizgen';
$plugin->version   = 2026020435;        // YYYYMMDDXX format - Replace all emoji with Font Awesome icons.
$plugin->requires  = 2022112809;        // Moodle 4.1.9+.
$plugin->supported = [401, 405];        // Moodle 4.1.9+ to 4.5.x.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.6.5';

// Note: Requires an AI provider plugin for functionality, but no hard dependency enforced.
// Supports either local_hlai_hub (direct AI connection) or local_hlai_hubproxy (proxy server).
// Install at least one of these plugins for the quiz generator to work.
