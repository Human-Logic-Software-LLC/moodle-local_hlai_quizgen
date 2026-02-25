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
 * Wizard helper class for AI Quiz Generator.
 *
 * Provides utility methods for the wizard interface following Moodle coding standards.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;


/**
 * Helper class for wizard functionality.
 *
 * This class provides static methods to support the wizard interface,
 * following Moodle coding standards by keeping functions in a class
 * rather than as top-level functions.
 */
class wizard_helper {
    /**
     * Check plugin dependencies before wizard starts.
     *
     * @return array Array of error messages, empty if all dependencies met
     */
    public static function check_plugin_dependencies(): array {
        $errors = [];

        // Check if Gateway is configured.
        if (!gateway_client::is_ready()) {
            $errors[] = get_string('error:noaiprovider', 'local_hlai_quizgen');
        }

        return $errors;
    }

    /**
     * Validate form data before processing.
     *
     * @param array $data Form data to validate
     * @return array Array of validation errors, empty if valid
     */
    public static function validate_form_data(array $data): array {
        $errors = [];

        // Add validation logic as needed.

        return $errors;
    }

    /**
     * Get enabled question types from settings.
     *
     * @return array Array of enabled question type names
     */
    public static function get_enabled_question_types(): array {
        $enabled = [];

        if (get_config('local_hlai_quizgen', 'enable_multichoice')) {
            $enabled[] = 'multichoice';
        }
        if (get_config('local_hlai_quizgen', 'enable_truefalse')) {
            $enabled[] = 'truefalse';
        }
        if (get_config('local_hlai_quizgen', 'enable_shortanswer')) {
            $enabled[] = 'shortanswer';
        }
        if (get_config('local_hlai_quizgen', 'enable_essay')) {
            $enabled[] = 'essay';
        }
        if (get_config('local_hlai_quizgen', 'enable_matching')) {
            $enabled[] = 'matching';
        }

        // Default to multichoice if none enabled.
        if (empty($enabled)) {
            $enabled = ['multichoice'];
        }

        return $enabled;
    }
}
