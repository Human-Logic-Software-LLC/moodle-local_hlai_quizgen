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
 * Gateway client for commercial Human Logic AI routing.
 *
 * This is a thin HTTP client that communicates with the Human Logic AI gateway.
 * NO AI prompts or logic are stored here - everything happens server-side.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

/**
 * Thin gateway client for commercial Human Logic AI routing.
 */
class gateway_client {
    /** @var string Hardcoded gateway endpoint. */
    private const GATEWAY_URL = 'https://ai.human-logic.com/ai';

    /**
     * Return the gateway base URL.
     *
     * @return string
     */
    public static function get_gateway_url(): string {
        return self::GATEWAY_URL;
    }

    /**
     * Return configured gateway key.
     *
     * @return string
     */
    public static function get_gateway_key(): string {
        return trim((string)get_config('local_hlai_quizgen', 'gatewaykey'));
    }

    /**
     * Whether the gateway client is configured for processing.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return self::get_gateway_key() !== '';
    }

    /**
     * Send a topic analysis request to the gateway.
     *
     * @param array $payload Request payload
     * @param string $quality Quality mode (fast|balanced|best)
     * @return array Response with 'topics' array
     * @throws \moodle_exception
     */
    public static function analyze_topics(array $payload, string $quality = 'balanced'): array {
        return self::call_gateway('analyze_topics', $payload, $quality);
    }

    /**
     * Send a question generation request to the gateway.
     *
     * @param array $payload Request payload
     * @param string $quality Quality mode (fast|balanced|best)
     * @return array Response with 'questions' array and 'tokens' object
     * @throws \moodle_exception
     */
    public static function generate_questions(array $payload, string $quality = 'balanced'): array {
        return self::call_gateway('generate_questions', $payload, $quality);
    }

    /**
     * Send a question refinement request to the gateway.
     *
     * @param array $payload Request payload
     * @param string $quality Quality mode (fast|balanced|best)
     * @return array Response with refined 'question' object
     * @throws \moodle_exception
     */
    public static function refine_question(array $payload, string $quality = 'balanced'): array {
        return self::call_gateway('refine_question', $payload, $quality);
    }

    /**
     * Send a distractor generation request to the gateway.
     *
     * @param array $payload Request payload
     * @param string $quality Quality mode (fast|balanced|best)
     * @return array Response with 'distractors' array
     * @throws \moodle_exception
     */
    public static function generate_distractors(array $payload, string $quality = 'balanced'): array {
        return self::call_gateway('generate_distractors', $payload, $quality);
    }

    /**
     * Internal method to call the gateway API.
     *
     * @param string $operation Operation name
     * @param array $payload Request payload
     * @param string $quality Quality mode
     * @return array Response content
     * @throws \moodle_exception
     */
    private static function call_gateway(string $operation, array $payload, string $quality): array {
        global $CFG;

        if (!self::is_ready()) {
            throw new \moodle_exception(
                'error:noaiprovider',
                'local_hlai_quizgen',
                '',
                null,
                'Gateway not configured. Please configure the AI Service URL and API Key in plugin settings.'
            );
        }

        $request = [
            'operation' => $operation,
            'quality' => $quality,
            'payload' => $payload,
            'plugin' => 'local_hlai_quizgen',
        ];

        require_once($CFG->libdir . '/filelib.php');

        // Determine endpoint based on operation.
        $endpoint = self::get_endpoint_for_operation($operation);
        $url = rtrim(self::get_gateway_url(), '/') . $endpoint;
        $key = self::get_gateway_key();

        // Create curl with ignoresecurity flag to allow localhost connections.
        // This is required because Moodle's security helper blocks localhost by default.
        $curl = new \curl(['ignoresecurity' => true]);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
            'X-HL-Plugin: local_hlai_quizgen',
        ];

        debug_logger::debug("Gateway API Call", [
            'operation' => $operation,
            'url' => $url,
            'quality' => $quality,
            'payload_size' => strlen(json_encode($payload)),
        ]);

        try {
            $curl->setHeader($headers);
            $response = $curl->post($url, json_encode($request));
        } catch (\Throwable $e) {
            debug_logger::error('Gateway request failed', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
            throw new \moodle_exception(
                'error:noaiprovider',
                'local_hlai_quizgen',
                '',
                null,
                'Gateway request failed: ' . $e->getMessage()
            );
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            debug_logger::error('Gateway response not valid JSON', [
                'operation' => $operation,
                'response' => substr($response, 0, 500),
            ]);
            throw new \moodle_exception(
                'error:noaiprovider',
                'local_hlai_quizgen',
                '',
                null,
                'Gateway response was not valid JSON'
            );
        }

        if (!empty($decoded['error'])) {
            debug_logger::error('Gateway rejected request', [
                'operation' => $operation,
                'error' => $decoded['error'],
            ]);
            throw new \moodle_exception(
                'error:noaiprovider',
                'local_hlai_quizgen',
                '',
                null,
                'Gateway error: ' . $decoded['error']
            );
        }

        debug_logger::info('Gateway API Success', [
            'operation' => $operation,
            'provider' => $decoded['provider'] ?? 'unknown',
        ]);

        // Return the content from the response.
        return $decoded['content'] ?? $decoded;
    }

    /**
     * Get the endpoint path for a given operation.
     *
     * @param string $operation Operation name
     * @return string Endpoint path
     */
    private static function get_endpoint_for_operation(string $operation): string {
        // All quiz generation operations use their own endpoints.
        switch ($operation) {
            case 'analyze_topics':
                return '/analyze_topics';
            case 'generate_questions':
                return '/generate_questions';
            case 'refine_question':
                return '/refine_question';
            case 'generate_distractors':
                return '/generate_distractors';
            default:
                return '/generate'; // Fallback generic endpoint.
        }
    }
}
