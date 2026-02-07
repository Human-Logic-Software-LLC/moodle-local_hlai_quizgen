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
 * Library functions for the Human Logic AI Quiz Generator plugin.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add AI Quiz Generator link to course navigation.
 *
 * @param navigation_node $navigation Navigation node
 * @param stdClass $course Course object
 * @param context_course $context Course context
 * @return void
 */
function local_hlai_quizgen_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    if (has_capability('local/hlai_quizgen:generatequestions', $context)) {
        // AI Quiz Generator (Wizard).
        $navigation->add(
            get_string('navtitle', 'local_hlai_quizgen'),
            new moodle_url('/local/hlai_quizgen/wizard.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_hlai_quizgen_wizard'
        );

        // AI Quiz Dashboard.
        $navigation->add(
            get_string('dashboard', 'local_hlai_quizgen'),
            new moodle_url('/local/hlai_quizgen/index.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_hlai_quizgen_dashboard'
        );
    }
}

/**
 * Ensure Font Awesome is available for all plugin pages (icons rely on it).
 *
 * @return void
 */
function local_hlai_quizgen_before_http_headers() {
    global $PAGE;

    // From Moodle 4.1+ the renderer exposes fontawesome(); guard for safety on custom builds.
    if (!empty($PAGE) && method_exists($PAGE->requires, 'fontawesome')) {
        $PAGE->requires->fontawesome();
    }
}

/**
 * Serve plugin files.
 *
 * @param stdClass $course Course object
 * @param stdClass $cm Course module object
 * @param context $context Context
 * @param string $filearea File area
 * @param array $args Extra arguments
 * @param bool $forcedownload Force download
 * @param array $options Additional options
 * @return bool False if file not found, does not return if found
 */
function local_hlai_quizgen_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    // Check context is course context.
    if ($context->contextlevel != CONTEXT_COURSE) {
        return false;
    }

    // Check user has capability.
    require_capability('local/hlai_quizgen:generatequestions', $context);

    // Only support 'content' file area (uploaded course content).
    if ($filearea !== 'content') {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/local_hlai_quizgen/$filearea/$relativepath";
    $file = $fs->get_file_by_hash(sha1($fullpath));

    if (!$file || $file->is_directory()) {
        return false;
    }

    // Send the file.
    send_stored_file($file, 86400, 0, $forcedownload, $options);
}
