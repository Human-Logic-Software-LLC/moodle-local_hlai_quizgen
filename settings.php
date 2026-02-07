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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Settings for the Human Logic AI Quiz Generator plugin.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Create settings page
    $settings = new admin_settingpage('local_hlai_quizgen', get_string('pluginname', 'local_hlai_quizgen'));

    // Check gateway availability.
    $gatewayready = \local_hlai_quizgen\gateway_client::is_ready();

    // Show warning if gateway is not configured.
    if (!$gatewayready) {
        $warningmsg = 'AI Service gateway not configured. Please configure the AI Service URL and API Key below to enable quiz generation.';
        $settings->add(new admin_setting_heading(
            'local_hlai_quizgen/gateway_warning',
            '',
            '<div class="alert alert-warning">' . $warningmsg . '</div>'
        ));
    }

    // Gateway Configuration Section.
    $settings->add(new admin_setting_heading(
        'local_hlai_quizgen/gateway_heading',
        'Human Logic AI Service Configuration',
        'Configure your Human Logic AI service gateway endpoint and API key. This plugin requires a commercial license to function. Contact Human Logic Software LLC for access.'
    ));

    // Gateway URL setting (locked).
    $gatewayurlsetting = new admin_setting_configtext(
        'local_hlai_quizgen/gatewayurl',
        'AI Service URL',
        'Human Logic AI gateway endpoint (requires license)',
        'https://ai.human-logic.com',
        PARAM_URL
    );
    $gatewayurlsetting->set_locked_flag_options(
        admin_setting_flag::ENABLED,
        true
    );
    $settings->add($gatewayurlsetting);

    // Gateway API Key setting.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_hlai_quizgen/gatewaykey',
        'AI Service API Key',
        'Your Human Logic API key (contact support@human-logic.com for access)',
        ''
    ));

    // Settings heading.
    $settings->add(new admin_setting_heading(
        'local_hlai_quizgen/settings_heading',
        'Plugin Settings',
        ''
    ));

    // Enable/disable question types.
    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_multichoice',
        get_string('enable_multichoice', 'local_hlai_quizgen'),
        get_string('enable_multichoice_desc', 'local_hlai_quizgen'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_truefalse',
        get_string('enable_truefalse', 'local_hlai_quizgen'),
        get_string('enable_truefalse_desc', 'local_hlai_quizgen'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_shortanswer',
        get_string('enable_shortanswer', 'local_hlai_quizgen'),
        get_string('enable_shortanswer_desc', 'local_hlai_quizgen'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_essay',
        get_string('enable_essay', 'local_hlai_quizgen'),
        get_string('enable_essay_desc', 'local_hlai_quizgen'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_matching',
        get_string('enable_matching', 'local_hlai_quizgen'),
        get_string('enable_matching_desc', 'local_hlai_quizgen'),
        0  // Disabled by default as it's more complex.
    ));

    // Maximum questions per request.
    $settings->add(new admin_setting_configtext(
        'local_hlai_quizgen/max_questions_per_request',
        get_string('max_questions_per_request', 'local_hlai_quizgen'),
        get_string('max_questions_per_request_desc', 'local_hlai_quizgen'),
        100,
        PARAM_INT
    ));

    // Maximum file upload size.
    $settings->add(new admin_setting_configtext(
        'local_hlai_quizgen/max_file_size_mb',
        get_string('max_file_size_mb', 'local_hlai_quizgen'),
        get_string('max_file_size_mb_desc', 'local_hlai_quizgen'),
        50,
        PARAM_INT
    ));

    // Default quality mode.
    $settings->add(new admin_setting_configselect(
        'local_hlai_quizgen/default_quality_mode',
        get_string('default_quality_mode', 'local_hlai_quizgen'),
        get_string('default_quality_mode_desc', 'local_hlai_quizgen'),
        'balanced',
        [
            'fast' => get_string('quality_fast', 'local_hlai_quizgen'),
            'balanced' => get_string('quality_balanced', 'local_hlai_quizgen'),
            'best' => get_string('quality_best', 'local_hlai_quizgen'),
        ]
    ));

    // Cleanup old requests.
    $settings->add(new admin_setting_configtext(
        'local_hlai_quizgen/cleanup_days',
        get_string('cleanup_days', 'local_hlai_quizgen'),
        get_string('cleanup_days_desc', 'local_hlai_quizgen'),
        90,
        PARAM_INT
    ));

    // AI provider fallback.
    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_provider_fallback',
        get_string('enable_provider_fallback', 'local_hlai_quizgen'),
        get_string('enable_provider_fallback_desc', 'local_hlai_quizgen'),
        1  // Enabled by default
    ));

    // Content hash deduplication.
    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_content_deduplication',
        get_string('enable_content_deduplication', 'local_hlai_quizgen'),
        get_string('enable_content_deduplication_desc', 'local_hlai_quizgen'),
        1  // Enabled by default
    ));

    // Question validation.
    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_question_validation',
        get_string('enable_question_validation', 'local_hlai_quizgen'),
        get_string('enable_question_validation_desc', 'local_hlai_quizgen'),
        1  // Enabled by default
    ));

    // Phase 6: Production Hardening Settings.
    $settings->add(new admin_setting_heading(
        'local_hlai_quizgen/production_heading',
        get_string('production_heading', 'local_hlai_quizgen'),
        ''
    ));

    // Enable caching.
    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_caching',
        get_string('enable_caching', 'local_hlai_quizgen'),
        get_string('enable_caching_desc', 'local_hlai_quizgen'),
        1  // Enabled by default
    ));

    // Enable rate limiting.
    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_quizgen/enable_rate_limiting',
        get_string('enable_rate_limiting', 'local_hlai_quizgen'),
        get_string('enable_rate_limiting_desc', 'local_hlai_quizgen'),
        1  // Enabled by default
    ));

    // Rate limit per hour (per user).
    $settings->add(new admin_setting_configtext(
        'local_hlai_quizgen/rate_limit_per_hour',
        get_string('rate_limit_per_hour', 'local_hlai_quizgen'),
        get_string('rate_limit_per_hour_desc', 'local_hlai_quizgen'),
        10,
        PARAM_INT
    ));

    // Rate limit per day (per user).
    $settings->add(new admin_setting_configtext(
        'local_hlai_quizgen/rate_limit_per_day',
        get_string('rate_limit_per_day', 'local_hlai_quizgen'),
        get_string('rate_limit_per_day_desc', 'local_hlai_quizgen'),
        50,
        PARAM_INT
    ));

    // Site-wide rate limit per hour.
    $settings->add(new admin_setting_configtext(
        'local_hlai_quizgen/site_rate_limit_per_hour',
        get_string('site_rate_limit_per_hour', 'local_hlai_quizgen'),
        get_string('site_rate_limit_per_hour_desc', 'local_hlai_quizgen'),
        200,
        PARAM_INT
    ));

    // Health check token.
    $settings->add(new admin_setting_configtext(
        'local_hlai_quizgen/health_check_token',
        get_string('health_check_token', 'local_hlai_quizgen'),
        get_string('health_check_token_desc', 'local_hlai_quizgen'),
        bin2hex(random_bytes(16)),  // Generate random token
        PARAM_ALPHANUMEXT
    ));

    // Maximum regenerations per question.
    $settings->add(new admin_setting_configtext(
        'local_hlai_quizgen/max_regenerations',
        get_string('max_regenerations', 'local_hlai_quizgen'),
        get_string('max_regenerations_desc', 'local_hlai_quizgen'),
        5,
        PARAM_INT
    ));

    // Add settings page directly to localplugins (like hlai_grading)
    $ADMIN->add('localplugins', $settings);

    // Add admin dashboard page directly to localplugins
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_hlai_quizgen_admin',
        get_string('admin_dashboard', 'local_hlai_quizgen'),
        new moodle_url('/local/hlai_quizgen/admin_dashboard.php'),
        'moodle/site:config'
    ));
}
