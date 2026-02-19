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
 * External API functions for file management.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\external;

/**
 * External API class for file management in the AI Quiz Generator.
 *
 * Provides web service functions for uploading and removing files
 * used as source content for question generation.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_external extends \external_api {
    // Upload file methods.

    /**
     * Describes the parameters for upload_file.
     *
     * @return \external_function_parameters
     */
    public static function upload_file_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'The course ID to upload the file for'),
            'draftitemid' => new \external_value(PARAM_INT, 'The draft area item ID containing the uploaded file'),
        ]);
    }

    /**
     * Upload a file from the draft area to the plugin's file area.
     *
     * Accepts a draft item ID (from Moodle's file picker / draft area) and moves
     * the file(s) into the local_hlai_quizgen content file area for the given course.
     *
     * @param int $courseid The course ID.
     * @param int $draftitemid The draft area item ID.
     * @return array Success status and the assigned item ID.
     */
    public static function upload_file($courseid, $draftitemid) {

        // Validate parameters.
        $params = self::validate_parameters(self::upload_file_parameters(), [
            'courseid' => $courseid,
            'draftitemid' => $draftitemid,
        ]);
        $courseid = $params['courseid'];
        $draftitemid = $params['draftitemid'];

        // Validate context and capability.
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/hlai_quizgen:generatequestions', $context);

        // Generate a unique item ID for the permanent file area.
        $itemid = time();

        // Move files from the user's draft area to the plugin's permanent file area.
        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'local_hlai_quizgen',
            'content',
            $itemid,
            [
                'maxfiles' => 1,
                'maxbytes' => 50 * 1024 * 1024, // 50MB.
                'accepted_types' => ['.pdf', '.doc', '.docx', '.ppt', '.pptx', '.txt'],
            ]
        );

        return [
            'success' => true,
            'itemid' => $itemid,
        ];
    }

    /**
     * Describes the return value for upload_file.
     *
     * @return \external_single_structure
     */
    public static function upload_file_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the file was uploaded successfully'),
            'itemid' => new \external_value(PARAM_INT, 'The item ID assigned to the stored file'),
        ]);
    }

    // Remove file methods.

    /**
     * Describes the parameters for remove_file.
     *
     * @return \external_function_parameters
     */
    public static function remove_file_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'The course ID the file belongs to'),
            'itemid' => new \external_value(PARAM_INT, 'The item ID of the file to remove'),
        ]);
    }

    /**
     * Remove an uploaded file from the plugin's file area.
     *
     * Deletes all files in the local_hlai_quizgen content area matching the
     * given course context and item ID.
     *
     * @param int $courseid The course ID.
     * @param int $itemid The item ID of the file to remove.
     * @return array The item ID of the removed file.
     */
    public static function remove_file($courseid, $itemid) {

        // Validate parameters.
        $params = self::validate_parameters(self::remove_file_parameters(), [
            'courseid' => $courseid,
            'itemid' => $itemid,
        ]);
        $courseid = $params['courseid'];
        $itemid = $params['itemid'];

        // Validate context and capability.
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/hlai_quizgen:generatequestions', $context);

        // Get file storage and delete all files for this item ID.
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_hlai_quizgen', 'content', $itemid);
        foreach ($files as $file) {
            $file->delete();
        }

        return ['removed' => $itemid];
    }

    /**
     * Describes the return value for remove_file.
     *
     * @return \external_single_structure
     */
    public static function remove_file_returns() {
        return new \external_single_structure([
            'removed' => new \external_value(PARAM_INT, 'The item ID of the removed file'),
        ]);
    }
}
