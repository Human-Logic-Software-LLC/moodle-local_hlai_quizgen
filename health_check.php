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
 * Health check endpoint for AI Quiz Generator.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Allow this endpoint to be called without login for monitoring systems.
// But require a secret token for security.
$token = optional_param('token', '', PARAM_ALPHANUMEXT);
$expectedtoken = get_config('local_hlai_quizgen', 'health_check_token');

// If no token configured, require login.
if (empty($expectedtoken)) {
    require_login();
    require_capability('moodle/site:config', context_system::instance());
} else if (!hash_equals($expectedtoken, $token)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    die();
}

// Set JSON header.
header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => time(),
    'checks' => [],
];

try {
    // Check 1: Database connectivity.
    global $DB;
    $health['checks']['database'] = [
        'status' => 'ok',
        'message' => 'Database connection successful',
    ];

    // Check 2: AI provider availability (hlai_hub or hlai_hubproxy).
    $gatewayurl = \local_hlai_quizgen\gateway_client::get_gateway_url();
    $providerready = \local_hlai_quizgen\gateway_client::is_ready();

    if ($providerready) {
        $health['checks']['gateway'] = [
            'status' => 'ok',
            'message' => 'Gateway configured and ready',
            'details' => [
                'gateway_url' => $gatewayurl,
            ],
        ];
    } else {
        $health['checks']['gateway'] = [
            'status' => 'error',
            'message' => 'Gateway not configured. Please configure AI Service URL and API Key in plugin settings.',
            'details' => [
                'gateway_url' => $gatewayurl,
            ],
        ];
        $health['status'] = 'unhealthy';
    }

    // Check 3: Database tables exist.
    $requiredtables = [
        'local_hlai_quizgen_requests',
        'local_hlai_quizgen_topics',
        'local_hlai_quizgen_questions',
        'local_hlai_quizgen_answers',
        'local_hlai_quizgen_error_log',
        'local_hlai_quizgen_cache',
    ];

    $missingtables = [];
    foreach ($requiredtables as $table) {
        if (!$DB->get_manager()->table_exists($table)) {
            $missingtables[] = $table;
        }
    }

    if (empty($missingtables)) {
        $health['checks']['database_schema'] = [
            'status' => 'ok',
            'message' => 'All required tables exist',
        ];
    } else {
        $health['checks']['database_schema'] = [
            'status' => 'error',
            'message' => 'Missing tables: ' . implode(', ', $missingtables),
        ];
        $health['status'] = 'unhealthy';
    }

    // Check 4: Recent generation activity.
    $recentrequests = $DB->count_records_select(
        'local_hlai_quizgen_requests',
        'timecreated > ?',
        [time() - (24 * 3600)]
    );

    $health['checks']['recent_activity'] = [
        'status' => 'ok',
        'requests_24h' => $recentrequests,
    ];

    // Check 5: Error rate.
    $totalrecent = $DB->count_records_select(
        'local_hlai_quizgen_requests',
        'timecreated > ?',
        [time() - (24 * 3600)]
    );

    $failedrecent = $DB->count_records_select(
        'local_hlai_quizgen_requests',
        'status = ? AND timecreated > ?',
        ['failed', time() - (24 * 3600)]
    );

    if ($totalrecent > 0) {
        $errorrate = ($failedrecent / $totalrecent) * 100;

        if ($errorrate > 50) {
            $health['checks']['error_rate'] = [
                'status' => 'error',
                'error_rate' => round($errorrate, 2) . '%',
                'message' => 'High error rate detected',
            ];
            $health['status'] = 'unhealthy';
        } else if ($errorrate > 20) {
            $health['checks']['error_rate'] = [
                'status' => 'warning',
                'error_rate' => round($errorrate, 2) . '%',
                'message' => 'Elevated error rate',
            ];
            if ($health['status'] === 'healthy') {
                $health['status'] = 'degraded';
            }
        } else {
            $health['checks']['error_rate'] = [
                'status' => 'ok',
                'error_rate' => round($errorrate, 2) . '%',
            ];
        }
    } else {
        $health['checks']['error_rate'] = [
            'status' => 'ok',
            'error_rate' => '0%',
            'message' => 'No recent requests',
        ];
    }

    // Check 6: Cache performance.
    if (\local_hlai_quizgen\cache_manager::is_caching_enabled()) {
        $cachestats = \local_hlai_quizgen\cache_manager::get_cache_statistics();

        $health['checks']['cache'] = [
            'status' => 'ok',
            'total_entries' => $cachestats['total_entries'],
            'total_hits' => $cachestats['total_hits'],
            'hit_rate' => $cachestats['overall_hit_rate'],
            'storage_mb' => $cachestats['storage_mb'],
        ];

        // Warn if cache is getting large.
        if ($cachestats['storage_mb'] > 100) {
            $health['checks']['cache']['status'] = 'warning';
            $health['checks']['cache']['message'] = 'Cache size exceeds 100MB';
        }
    } else {
        $health['checks']['cache'] = [
            'status' => 'disabled',
            'message' => 'Caching is disabled',
        ];
    }

    // Check 7: Scheduled tasks.
    $tasks = [
        'local_hlai_quizgen\task\cleanup_old_requests',
        'local_hlai_quizgen\task\process_generation_queue',
    ];

    $taskstatuses = [];
    foreach ($tasks as $taskclass) {
        $task = \core\task\manager::get_scheduled_task($taskclass);
        if ($task) {
            $lastrun = $task->get_last_run_time();
            $taskstatuses[basename(str_replace('\\', '/', $taskclass))] = [
                'last_run' => $lastrun ? userdate($lastrun) : 'Never',
                'disabled' => $task->get_disabled(),
            ];
        }
    }

    $health['checks']['scheduled_tasks'] = [
        'status' => 'ok',
        'tasks' => $taskstatuses,
    ];

    // Check 8: File permissions.
    $writabledirs = [
        $CFG->dataroot . '/temp',
        $CFG->dataroot . '/local_hlai_quizgen',
    ];

    $permissionissues = [];
    foreach ($writabledirs as $dir) {
        if (!is_writable($dir)) {
            $permissionissues[] = $dir;
        }
    }

    if (empty($permissionissues)) {
        $health['checks']['file_permissions'] = [
            'status' => 'ok',
            'message' => 'All directories writable',
        ];
    } else {
        $health['checks']['file_permissions'] = [
            'status' => 'warning',
            'message' => 'Some directories not writable',
            'directories' => $permissionissues,
        ];
        if ($health['status'] === 'healthy') {
            $health['status'] = 'degraded';
        }
    }

    // Set appropriate HTTP status code.
    if ($health['status'] === 'unhealthy') {
        http_response_code(503);
    } else if ($health['status'] === 'degraded') {
        http_response_code(200); // Still operational.
    } else {
        http_response_code(200);
    }
} catch (\Exception $e) {
    $health['status'] = 'error';
    $health['message'] = 'Health check failed: ' . $e->getMessage();
    http_response_code(500);
}

echo json_encode($health, JSON_PRETTY_PRINT);
