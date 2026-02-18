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
 * Renderable class for the debug logs page.
 *
 * Prepares all data for the debug_logs Mustache templates.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;
use moodle_url;
use local_hlai_quizgen\debug_logger;

/**
 * Debug logs page renderable.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debug_logs_page implements renderable, templatable {

    /** @var string Active tab. */
    private $tab;

    /** @var int|null Request ID filter. */
    private $requestid;

    /** @var string Log level filter. */
    private $level;

    /** @var int Limit. */
    private $limit;

    /**
     * Constructor.
     *
     * @param string $tab Active tab name.
     * @param int|null $requestid Request ID filter.
     * @param string $level Level filter.
     * @param int $limit Result limit.
     */
    public function __construct(string $tab, ?int $requestid, string $level, int $limit) {
        $this->tab = $tab;
        $this->requestid = $requestid;
        $this->level = $level;
        $this->limit = $limit;
    }

    /**
     * Export data for the template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context data.
     */
    public function export_for_template(renderer_base $output): array {
        $data = [
            'provider' => $this->get_provider_data(),
            'tabs' => $this->get_tabs_data(),
            'actions' => $this->get_actions_data(),
            'show_database' => ($this->tab === 'database'),
            'show_file' => ($this->tab === 'file'),
            'show_requests' => ($this->tab === 'requests'),
            'show_system' => ($this->tab === 'system'),
        ];

        // Add tab-specific data.
        switch ($this->tab) {
            case 'database':
                $data['databaselogs'] = $this->get_database_logs_data();
                break;
            case 'file':
                $data['filelogs'] = $this->get_file_logs_data();
                break;
            case 'requests':
                $data['requests'] = $this->get_requests_data();
                break;
            case 'system':
                $data['systeminfo'] = $this->get_system_info_data();
                break;
        }

        return $data;
    }

    /**
     * Get AI provider status data.
     *
     * @return array Provider data for template.
     */
    private function get_provider_data(): array {
        $data = [
            'heading' => get_string('debuglogs_aiprovider_heading', 'local_hlai_quizgen'),
            'activelabel' => get_string('debuglogs_activeprovider', 'local_hlai_quizgen'),
            'gatewaylabel' => get_string('debuglogs_gatewayurl', 'local_hlai_quizgen'),
            'available' => false,
            'error' => false,
        ];

        try {
            $gatewayurl = \local_hlai_quizgen\gateway_client::get_gateway_url();
            $gatewayready = \local_hlai_quizgen\gateway_client::is_ready();

            $data['available'] = true;
            $data['statusclass'] = $gatewayready ? 'success' : 'danger';
            $data['statuslabel'] = $gatewayready
                ? get_string('debuglogs_yes', 'local_hlai_quizgen')
                : get_string('debuglogs_no', 'local_hlai_quizgen');
            $data['gatewayurl'] = $gatewayurl;
            $data['showwarning'] = !$gatewayready;
            if (!$gatewayready) {
                $data['warningmessage'] = get_string('debuglogs_noprovider_warning', 'local_hlai_quizgen');
            }
        } catch (\Exception $e) {
            $data['error'] = true;
            $data['errormessage'] = get_string(
                'debuglogs_provider_error',
                'local_hlai_quizgen',
                htmlspecialchars($e->getMessage())
            );
        }

        return $data;
    }

    /**
     * Get tab navigation data.
     *
     * @return array Tabs data for template.
     */
    private function get_tabs_data(): array {
        $tabdefs = [
            'database' => get_string('debuglogs_tab_database', 'local_hlai_quizgen'),
            'file' => get_string('debuglogs_tab_file', 'local_hlai_quizgen'),
            'requests' => get_string('debuglogs_tab_requests', 'local_hlai_quizgen'),
            'system' => get_string('debuglogs_tab_system', 'local_hlai_quizgen'),
        ];

        $tabs = [];
        foreach ($tabdefs as $key => $label) {
            $url = new moodle_url('/local/hlai_quizgen/debug_logs.php', ['tab' => $key]);
            $tabs[] = [
                'key' => $key,
                'label' => $label,
                'active' => ($this->tab === $key),
                'url' => $url->out(false),
            ];
        }

        return $tabs;
    }

    /**
     * Get action buttons data.
     *
     * @return array Actions data for template.
     */
    private function get_actions_data(): array {
        $systeminfourl = new moodle_url('/local/hlai_quizgen/debug_logs.php', [
            'tab' => $this->tab,
            'action' => 'logsysteminfo',
            'sesskey' => sesskey(),
        ]);
        $testlogurl = new moodle_url('/local/hlai_quizgen/debug_logs.php', [
            'tab' => $this->tab,
            'action' => 'testlog',
            'sesskey' => sesskey(),
        ]);
        $refreshurl = new moodle_url('/local/hlai_quizgen/debug_logs.php', ['tab' => $this->tab]);

        return [
            'systeminfourl' => $systeminfourl->out(false),
            'systeminfolabel' => get_string('debuglogs_btn_logsysteminfo', 'local_hlai_quizgen'),
            'testlogurl' => $testlogurl->out(false),
            'testloglabel' => get_string('debuglogs_btn_createtestlog', 'local_hlai_quizgen'),
            'refreshurl' => $refreshurl->out(false),
            'refreshlabel' => get_string('debuglogs_btn_refresh', 'local_hlai_quizgen'),
        ];
    }

    /**
     * Get database logs tab data.
     *
     * @return array Database logs data for template.
     */
    private function get_database_logs_data(): array {
        $leveloptions = [
            ['value' => 'error', 'label' => get_string('debuglogs_filter_level_error', 'local_hlai_quizgen'),
                'selected' => ($this->level === 'error')],
            ['value' => 'warning', 'label' => get_string('debuglogs_filter_level_warning', 'local_hlai_quizgen'),
                'selected' => ($this->level === 'warning')],
            ['value' => 'info', 'label' => get_string('debuglogs_filter_level_info', 'local_hlai_quizgen'),
                'selected' => ($this->level === 'info')],
            ['value' => 'debug', 'label' => get_string('debuglogs_filter_level_debug', 'local_hlai_quizgen'),
                'selected' => ($this->level === 'debug')],
        ];

        $data = [
            'filters' => [
                'heading' => get_string('debuglogs_filters_heading', 'local_hlai_quizgen'),
                'requestidlabel' => get_string('debuglogs_filter_requestid', 'local_hlai_quizgen'),
                'requestid' => $this->requestid ?? '',
                'alllabel' => get_string('debuglogs_filter_all', 'local_hlai_quizgen'),
                'levellabel' => get_string('debuglogs_filter_level', 'local_hlai_quizgen'),
                'leveloptions' => $leveloptions,
                'limitlabel' => get_string('debuglogs_filter_limit', 'local_hlai_quizgen'),
                'limit' => $this->limit,
                'filterbtn' => get_string('debuglogs_filter_btn', 'local_hlai_quizgen'),
            ],
            'headers' => [
                'time' => get_string('debuglogs_table_time', 'local_hlai_quizgen'),
                'level' => get_string('debuglogs_table_level', 'local_hlai_quizgen'),
                'request' => get_string('debuglogs_table_request', 'local_hlai_quizgen'),
                'user' => get_string('debuglogs_table_user', 'local_hlai_quizgen'),
                'component' => get_string('debuglogs_table_component', 'local_hlai_quizgen'),
                'message' => get_string('debuglogs_table_message', 'local_hlai_quizgen'),
                'details' => get_string('debuglogs_table_details', 'local_hlai_quizgen'),
            ],
            'haslogs' => false,
            'logs' => [],
            'nologsmessage' => get_string('debuglogs_nologs', 'local_hlai_quizgen'),
        ];

        $logs = debug_logger::getrecentdatabaselogs($this->limit, $this->requestid, $this->level ?: null);

        if (!empty($logs)) {
            $data['haslogs'] = true;
            foreach ($logs as $log) {
                $logitem = [
                    'id' => $log->id,
                    'time' => userdate($log->timecreated, '%Y-%m-%d %H:%M:%S'),
                    'levelclass' => self::get_level_class($log->status),
                    'levelupper' => strtoupper($log->status),
                    'requestid' => $log->requestid ?: '-',
                    'userid' => $log->userid,
                    'component' => htmlspecialchars($log->component ?? '-'),
                    'message' => htmlspecialchars(substr($log->error_message ?? '', 0, 100)),
                    'hasdetails' => false,
                    'detailsbtn' => get_string('debuglogs_btn_viewdetails', 'local_hlai_quizgen'),
                ];

                if (!empty($log->details)) {
                    $details = json_decode($log->details, true);
                    if ($details) {
                        $logitem['hasdetails'] = true;
                        $logitem['detailsformatted'] = htmlspecialchars(
                            json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        );
                    }
                }

                $data['logs'][] = $logitem;
            }
        }

        return $data;
    }

    /**
     * Get file logs tab data.
     *
     * @return array File logs data for template.
     */
    private function get_file_logs_data(): array {
        $logfile = debug_logger::getlogfilepath();

        $clearurl = new moodle_url('/local/hlai_quizgen/debug_logs.php', [
            'tab' => 'file',
            'action' => 'clearfile',
            'sesskey' => sesskey(),
        ]);

        $data = [
            'heading' => get_string('debuglogs_logfile_heading', 'local_hlai_quizgen'),
            'clearurl' => $clearurl->out(false),
            'clearconfirm' => get_string('debuglogs_clearfile_confirm', 'local_hlai_quizgen'),
            'clearbtn' => get_string('debuglogs_btn_clearlogfile', 'local_hlai_quizgen'),
            'pathlabel' => get_string('debuglogs_logfile_path', 'local_hlai_quizgen'),
            'sizelabel' => get_string('debuglogs_logfile_size', 'local_hlai_quizgen'),
            'haslogfile' => false,
            'fileexists' => false,
            'hasentries' => false,
            'emptymessage' => get_string('debuglogs_logfile_empty', 'local_hlai_quizgen'),
            'notexistmessage' => get_string('debuglogs_logfile_notexist', 'local_hlai_quizgen'),
            'notfoundmessage' => get_string('debuglogs_logfile_notfound', 'local_hlai_quizgen'),
        ];

        if ($logfile) {
            $data['haslogfile'] = true;
            $data['logfilepath'] = $logfile;

            if (file_exists($logfile)) {
                $data['fileexists'] = true;
                $data['filesize'] = self::format_bytes(filesize($logfile));

                $entries = debug_logger::getrecentfilelogs($this->limit);

                if (!empty($entries)) {
                    $data['hasentries'] = true;
                    $data['showingcount'] = get_string(
                        'debuglogs_logfile_showing',
                        'local_hlai_quizgen',
                        count($entries)
                    );

                    // Format entries with color coding.
                    $formatted = '';
                    foreach (array_reverse($entries) as $entry) {
                        $entry = htmlspecialchars(trim($entry));
                        $entry = preg_replace(
                            '/\[ERROR\]/',
                            '<span class="hlai-log-error">[ERROR]</span>',
                            $entry
                        );
                        $entry = preg_replace(
                            '/\[WARNING\]/',
                            '<span class="hlai-log-warning">[WARNING]</span>',
                            $entry
                        );
                        $entry = preg_replace(
                            '/\[CRITICAL\]/',
                            '<span class="hlai-log-critical">[CRITICAL]</span>',
                            $entry
                        );
                        $entry = preg_replace(
                            '/\[INFO\]/',
                            '<span class="hlai-log-info">[INFO]</span>',
                            $entry
                        );
                        $entry = preg_replace(
                            '/\[DEBUG\]/',
                            '<span class="hlai-log-debug">[DEBUG]</span>',
                            $entry
                        );
                        $formatted .= $entry . "\n" . str_repeat('-', 80) . "\n";
                    }
                    $data['formattedentries'] = $formatted;
                }
            }
        }

        return $data;
    }

    /**
     * Get recent requests tab data.
     *
     * @return array Requests data for template.
     */
    private function get_requests_data(): array {
        global $DB;

        $data = [
            'hasrequests' => false,
            'nofoundmessage' => get_string('debuglogs_requests_nofound', 'local_hlai_quizgen'),
            'headers' => [
                'id' => get_string('debuglogs_requests_table_id', 'local_hlai_quizgen'),
                'course' => get_string('debuglogs_requests_table_course', 'local_hlai_quizgen'),
                'user' => get_string('debuglogs_requests_table_user', 'local_hlai_quizgen'),
                'status' => get_string('debuglogs_requests_table_status', 'local_hlai_quizgen'),
                'questions' => get_string('debuglogs_requests_table_questions', 'local_hlai_quizgen'),
                'tokens' => get_string('debuglogs_requests_table_tokens', 'local_hlai_quizgen'),
                'created' => get_string('debuglogs_requests_table_created', 'local_hlai_quizgen'),
                'error' => get_string('debuglogs_requests_table_error', 'local_hlai_quizgen'),
                'actions' => get_string('debuglogs_requests_table_actions', 'local_hlai_quizgen'),
            ],
            'items' => [],
        ];

        $requests = $DB->get_records('local_hlai_quizgen_requests', [], 'timecreated DESC', '*', 0, $this->limit);

        if (!empty($requests)) {
            $data['hasrequests'] = true;
            $viewlogsbtn = get_string('debuglogs_requests_btn_viewlogs', 'local_hlai_quizgen');

            foreach ($requests as $req) {
                $logsurl = new moodle_url('/local/hlai_quizgen/debug_logs.php', [
                    'tab' => 'database',
                    'requestid' => $req->id,
                ]);

                $haserror = !empty($req->error_message);
                $hasprogress = !$haserror && !empty($req->progress_message);

                $data['items'][] = [
                    'id' => $req->id,
                    'courseid' => $req->courseid,
                    'userid' => $req->userid,
                    'statusclass' => self::get_status_class($req->status),
                    'statusupper' => strtoupper($req->status),
                    'questionscount' => $req->questions_generated ?? $req->total_questions ?? 0,
                    'tokenscount' => $req->total_tokens ?? 0,
                    'time' => userdate($req->timecreated, '%Y-%m-%d %H:%M:%S'),
                    'haserror' => $haserror,
                    'errorfull' => $haserror ? htmlspecialchars($req->error_message) : '',
                    'errortruncated' => $haserror ? htmlspecialchars(substr($req->error_message, 0, 50)) : '',
                    'hasprogress' => $hasprogress,
                    'progresstruncated' => $hasprogress ?
                        htmlspecialchars(substr($req->progress_message, 0, 50)) : '',
                    'noerror' => !$haserror && !$hasprogress,
                    'logsurl' => $logsurl->out(false),
                    'viewlogsbtn' => $viewlogsbtn,
                ];
            }
        }

        return $data;
    }

    /**
     * Get system info tab data.
     *
     * @return array System info data for template.
     */
    private function get_system_info_data(): array {
        global $CFG, $DB;

        $unknownstr = get_string('debuglogs_unknown', 'local_hlai_quizgen');

        // PHP Configuration.
        $errorlog = ini_get('error_log') ?: get_string('debuglogs_system_notset', 'local_hlai_quizgen');
        $php = [
            'heading' => get_string('debuglogs_system_phpconfig', 'local_hlai_quizgen'),
            'items' => [
                ['label' => get_string('debuglogs_system_phpversion', 'local_hlai_quizgen'), 'value' => PHP_VERSION],
                ['label' => get_string('debuglogs_system_memorylimit', 'local_hlai_quizgen'),
                    'value' => ini_get('memory_limit')],
                ['label' => get_string('debuglogs_system_maxexectime', 'local_hlai_quizgen'),
                    'value' => ini_get('max_execution_time') . 's'],
                ['label' => get_string('debuglogs_system_postmaxsize', 'local_hlai_quizgen'),
                    'value' => ini_get('post_max_size')],
                ['label' => get_string('debuglogs_system_uploadmaxfilesize', 'local_hlai_quizgen'),
                    'value' => ini_get('upload_max_filesize')],
                ['label' => get_string('debuglogs_system_errorlog', 'local_hlai_quizgen'), 'value' => $errorlog],
            ],
        ];

        // PHP Extensions.
        $extnames = ['curl', 'json', 'zip', 'simplexml', 'openssl', 'zlib', 'fileinfo'];
        $extensions = [
            'heading' => get_string('debuglogs_system_extensions', 'local_hlai_quizgen'),
            'items' => [],
        ];
        foreach ($extnames as $ext) {
            $loaded = extension_loaded($ext);
            $extensions['items'][] = [
                'name' => $ext,
                'statusclass' => $loaded ? 'success' : 'danger',
                'statuslabel' => $loaded
                    ? get_string('debuglogs_system_ext_loaded', 'local_hlai_quizgen')
                    : get_string('debuglogs_system_ext_missing', 'local_hlai_quizgen'),
            ];
        }

        // Moodle Configuration.
        $moodle = [
            'heading' => get_string('debuglogs_system_moodleconfig', 'local_hlai_quizgen'),
            'items' => [
                ['label' => get_string('debuglogs_system_moodleversion', 'local_hlai_quizgen'),
                    'value' => $CFG->release ?? $unknownstr],
                ['label' => get_string('debuglogs_system_moodlebuild', 'local_hlai_quizgen'),
                    'value' => $CFG->version ?? $unknownstr],
                ['label' => get_string('debuglogs_system_wwwroot', 'local_hlai_quizgen'),
                    'value' => $CFG->wwwroot],
                ['label' => get_string('debuglogs_system_dataroot', 'local_hlai_quizgen'),
                    'value' => $CFG->dataroot],
                ['label' => get_string('debuglogs_system_debugmode', 'local_hlai_quizgen'),
                    'value' => (string) ($CFG->debug ?? 0)],
            ],
        ];

        // Plugin Statistics.
        $plugin = [
            'heading' => get_string('debuglogs_system_pluginstats', 'local_hlai_quizgen'),
            'error' => false,
            'items' => [],
        ];

        try {
            $totalrequests = $DB->count_records('local_hlai_quizgen_requests');
            $failedrequests = $DB->count_records('local_hlai_quizgen_requests', ['status' => 'failed']);
            $completedrequests = $DB->count_records('local_hlai_quizgen_requests', ['status' => 'completed']);
            $totalquestions = $DB->count_records('local_hlai_quizgen_questions');
            $totallogs = $DB->count_records('local_hlai_quizgen_logs');

            $plugin['items'] = [
                ['label' => get_string('debuglogs_system_totalrequests', 'local_hlai_quizgen'),
                    'value' => (string) $totalrequests, 'hastag' => false],
                ['label' => get_string('debuglogs_system_completedrequests', 'local_hlai_quizgen'),
                    'value' => (string) $completedrequests, 'hastag' => true, 'tagclass' => 'success'],
                ['label' => get_string('debuglogs_system_failedrequests', 'local_hlai_quizgen'),
                    'value' => (string) $failedrequests, 'hastag' => true, 'tagclass' => 'danger'],
                ['label' => get_string('debuglogs_system_totalquestions', 'local_hlai_quizgen'),
                    'value' => (string) $totalquestions, 'hastag' => false],
                ['label' => get_string('debuglogs_system_totallogs', 'local_hlai_quizgen'),
                    'value' => (string) $totallogs, 'hastag' => false],
            ];
        } catch (\Exception $e) {
            $plugin['error'] = true;
            $plugin['errormessage'] = get_string(
                'debuglogs_system_stats_error',
                'local_hlai_quizgen',
                htmlspecialchars($e->getMessage())
            );
        }

        // System Tools.
        $tools = [
            'heading' => get_string('debuglogs_system_tools', 'local_hlai_quizgen'),
            'items' => [],
        ];

        $pathdirs = explode(PATH_SEPARATOR, getenv('PATH') ?: '');

        // Check pdftotext.
        $pdftotextfound = false;
        foreach ($pathdirs as $dir) {
            if (
                is_executable($dir . DIRECTORY_SEPARATOR . 'pdftotext') ||
                is_executable($dir . DIRECTORY_SEPARATOR . 'pdftotext.exe')
            ) {
                $pdftotextfound = true;
                break;
            }
        }
        $tools['items'][] = [
            'label' => get_string('debuglogs_system_tool_pdftotext', 'local_hlai_quizgen'),
            'statusclass' => $pdftotextfound ? 'success' : 'warning',
            'statuslabel' => $pdftotextfound
                ? get_string('debuglogs_system_tool_available', 'local_hlai_quizgen')
                : get_string('debuglogs_system_tool_notfound', 'local_hlai_quizgen'),
        ];

        // Check ghostscript.
        $gsfound = false;
        $gsnames = ['gs', 'gs.exe', 'gswin64c.exe', 'gswin32c.exe'];
        foreach ($pathdirs as $dir) {
            foreach ($gsnames as $gsname) {
                if (is_executable($dir . DIRECTORY_SEPARATOR . $gsname)) {
                    $gsfound = true;
                    break 2;
                }
            }
        }
        $tools['items'][] = [
            'label' => get_string('debuglogs_system_tool_ghostscript', 'local_hlai_quizgen'),
            'statusclass' => $gsfound ? 'success' : 'warning',
            'statuslabel' => $gsfound
                ? get_string('debuglogs_system_tool_available', 'local_hlai_quizgen')
                : get_string('debuglogs_system_tool_notfound', 'local_hlai_quizgen'),
        ];

        return [
            'php' => $php,
            'extensions' => $extensions,
            'moodle' => $moodle,
            'plugin' => $plugin,
            'tools' => $tools,
        ];
    }

    /**
     * Get Bulma tag color class for log level.
     *
     * @param string $level The log level.
     * @return string The CSS class name.
     */
    private static function get_level_class(string $level): string {
        switch (strtolower($level)) {
            case 'critical':
            case 'error':
                return 'danger';
            case 'warning':
                return 'warning';
            case 'info':
                return 'info';
            case 'debug':
                return 'dark';
            default:
                return 'light';
        }
    }

    /**
     * Get Bulma tag color class for request status.
     *
     * @param string $status The request status.
     * @return string The CSS class name.
     */
    private static function get_status_class(string $status): string {
        switch (strtolower($status)) {
            case 'completed':
                return 'success';
            case 'failed':
                return 'danger';
            case 'processing':
                return 'warning';
            case 'pending':
                return 'info';
            default:
                return 'light';
        }
    }

    /**
     * Format bytes to human readable string.
     *
     * @param int $bytes Number of bytes.
     * @return string Human-readable file size.
     */
    private static function format_bytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
