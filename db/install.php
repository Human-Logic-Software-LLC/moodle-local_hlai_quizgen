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
 * Post-installation script for HLAI Quiz Generator.
 * Ensures language strings and navigation are properly loaded.
 *
 * @package    local_hlai_quizgen
 * @copyright  2026 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Execute post-installation tasks.
 *
 * @return bool true on success
 */
function xmldb_local_hlai_quizgen_install() {
    // No custom installation steps required.
    // Moodle automatically purges caches after plugin installation.
    return true;
}
