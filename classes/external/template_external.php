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
 * External API functions for template management.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\external;

/**
 * External API class for template management in the AI Quiz Generator.
 *
 * Provides web service functions for saving, retrieving, and deleting user templates.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_external extends \external_api {
    // Save template methods.

    /**
     * Describes the parameters for save_template.
     *
     * @return \external_function_parameters
     */
    public static function save_template_parameters() {
        return new \external_function_parameters([
            'name' => new \external_value(PARAM_TEXT, 'The name of the template'),
            // PARAM_RAW required for JSON object input; validated via json_decode() in method body.
            'config' => new \external_value(PARAM_RAW, 'The template configuration as a JSON string'),
            'courseid' => new \external_value(PARAM_INT, 'The course ID (0 for user-level)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Save the current configuration as a template.
     *
     * @param string $name The template name.
     * @param string $config The template configuration as a JSON string.
     * @param int $courseid The course ID (0 for user-level).
     * @return array The saved template name.
     */
    public static function save_template($name, $config, $courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::save_template_parameters(), [
            'name' => $name,
            'config' => $config,
            'courseid' => $courseid,
        ]);
        $name = $params['name'];
        $config = $params['config'];
        $courseid = $params['courseid'];

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Validate JSON configuration.
        $configdata = json_decode($config, true);
        if (!$configdata) {
            throw new \moodle_exception('ajax_invalid_configuration', 'local_hlai_quizgen');
        }

        // Save as user setting.
        $DB->insert_record('local_hlai_quizgen_settings', [
            'userid' => $USER->id,
            'courseid' => $courseid ?: null,
            'setting_name' => 'template_' . time(),
            'setting_value' => json_encode([
                'name' => $name,
                'config' => $configdata,
                'created' => time(),
            ]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return ['saved' => $name];
    }

    /**
     * Describes the return value for save_template.
     *
     * @return \external_single_structure
     */
    public static function save_template_returns() {
        return new \external_single_structure([
            'saved' => new \external_value(PARAM_TEXT, 'The name of the saved template'),
        ]);
    }

    // Get templates methods.

    /**
     * Describes the parameters for get_templates.
     *
     * @return \external_function_parameters
     */
    public static function get_templates_parameters() {
        return new \external_function_parameters([]);
    }

    /**
     * Get the current user's saved templates.
     *
     * @return array The list of templates.
     */
    public static function get_templates() {
        global $DB, $USER;

        // Validate parameters.
        self::validate_parameters(self::get_templates_parameters(), []);

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Get user's saved templates.
        $templates = $DB->get_records_sql(
            "SELECT id, setting_name, setting_value
             FROM {local_hlai_quizgen_settings}
             WHERE userid = ? AND setting_name LIKE 'template_%'
             ORDER BY timecreated DESC",
            [$USER->id]
        );

        $items = [];
        foreach ($templates as $t) {
            $data = json_decode($t->setting_value, true);
            if ($data) {
                $items[] = [
                    'id' => (int) $t->id,
                    'name' => $data['name'],
                    'config' => json_encode($data['config']),
                    'created' => isset($data['created']) ? userdate($data['created']) : '',
                ];
            }
        }

        return ['templates' => $items];
    }

    /**
     * Describes the return value for get_templates.
     *
     * @return \external_single_structure
     */
    public static function get_templates_returns() {
        return new \external_single_structure([
            'templates' => new \external_multiple_structure(
                new \external_single_structure([
                    'id' => new \external_value(PARAM_INT, 'The template record ID'),
                    'name' => new \external_value(PARAM_TEXT, 'The template name'),
                    'config' => new \external_value(PARAM_RAW, 'The template configuration as a JSON string'),
                    'created' => new \external_value(PARAM_TEXT, 'The human-readable creation date'),
                ])
            ),
        ]);
    }

    // Delete template methods.

    /**
     * Describes the parameters for delete_template.
     *
     * @return \external_function_parameters
     */
    public static function delete_template_parameters() {
        return new \external_function_parameters([
            'templateid' => new \external_value(PARAM_INT, 'The ID of the template to delete'),
        ]);
    }

    /**
     * Delete a user's saved template.
     *
     * @param int $templateid The template record ID.
     * @return array The ID of the deleted template.
     */
    public static function delete_template($templateid) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::delete_template_parameters(), [
            'templateid' => $templateid,
        ]);
        $templateid = $params['templateid'];

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Validate user owns the template.
        $template = $DB->get_record('local_hlai_quizgen_settings', ['id' => $templateid]);
        if (!$template || $template->userid != $USER->id) {
            throw new \moodle_exception('ajax_template_not_found', 'local_hlai_quizgen');
        }

        // Delete the template.
        $DB->delete_records('local_hlai_quizgen_settings', ['id' => $templateid]);

        return ['deleted' => $templateid];
    }

    /**
     * Describes the return value for delete_template.
     *
     * @return \external_single_structure
     */
    public static function delete_template_returns() {
        return new \external_single_structure([
            'deleted' => new \external_value(PARAM_INT, 'The ID of the deleted template'),
        ]);
    }
}
