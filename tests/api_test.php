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
 * Unit tests for API class.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

/**
 * Test class for API functions.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_hlai_quizgen\api
 */
final class api_test extends \advanced_testcase {
    /**
     * Test creating a generation request.
     *
     * @return void
     */
    public function test_create_request(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Create a test course.
        $course = $this->getDataGenerator()->create_course();

        // Create a request.
        $requestdata = [
            'courseid' => $course->id,
            'total_questions' => 10,
            'processing_mode' => 'balanced',
        ];

        $requestid = api::create_request($requestdata);

        // Verify request was created.
        $this->assertNotEmpty($requestid);
        $this->assertGreaterThan(0, $requestid);

        // Verify request exists in database.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid]);
        $this->assertNotEmpty($request);
        $this->assertEquals($course->id, $request->courseid);
        $this->assertEquals(10, $request->total_questions);
        $this->assertEquals('balanced', $request->processing_mode);
        $this->assertEquals('draft', $request->status);
    }

    /**
     * Test updating request status.
     *
     * @return void
     */
    public function test_update_request_status(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Create a test course.
        $course = $this->getDataGenerator()->create_course();

        // Create a request.
        $requestdata = [
            'courseid' => $course->id,
            'total_questions' => 5,
        ];

        $requestid = api::create_request($requestdata);

        // Update status to processing.
        api::update_request_status($requestid, 'processing');

        // Verify status was updated.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid]);
        $this->assertEquals('processing', $request->status);

        // Update status to completed.
        api::update_request_status($requestid, 'completed', 'Generation complete');

        // Verify status and message were updated.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid]);
        $this->assertEquals('completed', $request->status);
        $this->assertEquals('Generation complete', $request->error_message);
    }

    /**
     * Test getting requests for a course.
     *
     * @return void
     */
    public function test_get_requests_for_course(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Create test courses.
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        // Create requests for course1.
        $request1 = api::create_request(['courseid' => $course1->id, 'total_questions' => 5]);
        $request2 = api::create_request(['courseid' => $course1->id, 'total_questions' => 10]);

        // Create request for course2.
        $request3 = api::create_request(['courseid' => $course2->id, 'total_questions' => 3]);

        // Get requests for course1.
        $requests = api::get_requests_for_course($course1->id);

        // Verify we got only course1's requests.
        $this->assertCount(2, $requests);
        $requestids = array_column($requests, 'id');
        $this->assertContains($request1, $requestids);
        $this->assertContains($request2, $requestids);
        $this->assertNotContains($request3, $requestids);
    }

    /**
     * Test getting request by ID.
     *
     * @return void
     */
    public function test_get_request(): void {
        $this->resetAfterTest(true);

        // Create a test course.
        $course = $this->getDataGenerator()->create_course();

        // Create a request.
        $requestid = api::create_request([
            'courseid' => $course->id,
            'total_questions' => 15,
            'processing_mode' => 'quality',
        ]);

        // Get the request.
        $request = api::get_request($requestid);

        // Verify request details.
        $this->assertNotEmpty($request);
        $this->assertEquals($requestid, $request->id);
        $this->assertEquals($course->id, $request->courseid);
        $this->assertEquals(15, $request->total_questions);
        $this->assertEquals('quality', $request->processing_mode);
    }
}
