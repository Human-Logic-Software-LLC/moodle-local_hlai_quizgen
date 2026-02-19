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
 * Version information for the Human Logic AI Quiz Generator plugin.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_hlai_quizgen';
$plugin->version   = 2026021900;        // YYYYMMDDXX format.
$plugin->requires  = 2022112809;        // Moodle 4.1.9+.
$plugin->supported = [401, 500];        // Moodle 4.1.9+ to 5.0.x.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.6.6';

// Note: Requires a Human Logic API key for functionality. No external plugin dependencies.
// Configure your API key in: Site Administration > Plugins > Local plugins > AI Quiz Generator.
