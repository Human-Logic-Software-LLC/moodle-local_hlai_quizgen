<?php
/**
 * Gateway client test page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 STARTER
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// This file is part of Moodle - http://moodle.org/.
//
// Moodle is free software: you can redistribute it and/or modify.
// It under the terms of the GNU General Public License as published by.
// The Free Software Foundation, either version 3 of the License, or.
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,.
// But WITHOUT ANY WARRANTY; without even the implied warranty of.
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the.
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License.
// Along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for gateway_client class.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Test class for gateway_client.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_hlai_quizgen\gateway_client
 */
class gateway_client_test extends \advanced_testcase {

    /**
     * Test that get_gateway_url returns a string.
     */
    public function test_get_gateway_url_returns_string() {
        $this->resetAfterTest(true);

        // Set a test gateway URL.
        set_config('gateway_url', 'http://localhost:8000', 'local_hlai_quizgen');

        $url = gateway_client::get_gateway_url();
        $this->assertIsString($url);
        $this->assertEquals('http://localhost:8000', $url);
    }

    /**
     * Test that get_gateway_key returns a string.
     */
    public function test_get_gateway_key_returns_string() {
        $this->resetAfterTest(true);

        // Set a test gateway key.
        set_config('gateway_api_key', 'test_key_12345', 'local_hlai_quizgen');

        $key = gateway_client::get_gateway_key();
        $this->assertIsString($key);
        $this->assertEquals('test_key_12345', $key);
    }

    /**
     * Test that is_ready returns false when gateway not configured.
     */
    public function test_is_ready_returns_false_when_not_configured() {
        $this->resetAfterTest(true);

        // Clear config.
        set_config('gateway_url', '', 'local_hlai_quizgen');
        set_config('gateway_api_key', '', 'local_hlai_quizgen');

        $ready = gateway_client::is_ready();
        $this->assertFalse($ready);
    }

    /**
     * Test that is_ready returns true when gateway is configured.
     */
    public function test_is_ready_returns_true_when_configured() {
        $this->resetAfterTest(true);

        // Set config.
        set_config('gateway_url', 'http://localhost:8000', 'local_hlai_quizgen');
        set_config('gateway_api_key', 'test_key_12345', 'local_hlai_quizgen');

        $ready = gateway_client::is_ready();
        $this->assertTrue($ready);
    }

    /**
     * Test that get_endpoint_for_operation returns correct endpoints.
     */
    public function test_get_endpoint_for_operation() {
        $this->resetAfterTest(true);

        // Test analyze_topics endpoint.
        $endpoint = gateway_client::get_endpoint_for_operation('analyze_topics');
        $this->assertEquals('/analyze_topics', $endpoint);

        // Test generate_questions endpoint.
        $endpoint = gateway_client::get_endpoint_for_operation('generate_questions');
        $this->assertEquals('/generate_questions', $endpoint);

        // Test refine_question endpoint.
        $endpoint = gateway_client::get_endpoint_for_operation('refine_question');
        $this->assertEquals('/refine_question', $endpoint);

        // Test generate_distractors endpoint.
        $endpoint = gateway_client::get_endpoint_for_operation('generate_distractors');
        $this->assertEquals('/generate_distractors', $endpoint);

        // Test health endpoint.
        $endpoint = gateway_client::get_endpoint_for_operation('health');
        $this->assertEquals('/health', $endpoint);
    }
}
