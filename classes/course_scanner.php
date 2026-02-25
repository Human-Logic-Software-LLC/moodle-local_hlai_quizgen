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
 * Course scanner for bulk content extraction.
 *
 * Provides course-wide scanning capabilities:
 * - Scan entire course (summary + sections + activities)
 * - Scan all resources (pages, books, files, etc.)
 * - Scan all activities (lessons, SCORM, etc.)
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;


/**
 * Course scanner class.
 */
class course_scanner {
    /** @var int Maximum content size in bytes (10MB) */
    const MAX_CONTENT_SIZE = 10485760;

    /** @var array Resource module types that contain static learning content */
    const RESOURCE_MODULES = ['page', 'book', 'resource', 'url', 'folder', 'label'];

    /** @var array Activity module types that contain structured learning content */
    const ACTIVITY_MODULES = ['lesson', 'scorm', 'forum'];

    /** @var array Section names to exclude (non-learning administrative sections) */
    const EXCLUDED_SECTION_NAMES = [
        'assignment', 'assignments', 'assessment', 'assessments',
        'certificate', 'certificates', 'completion certificate', 'course completion',
    ];

    /**
     * Scan entire course including summary, sections, and all content.
     *
     * @param int $courseid Course ID
     * @return array ['text' => string, 'word_count' => int, 'sources' => array]
     * @throws \moodle_exception If scanning fails
     */
    public static function scan_entire_course(int $courseid): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $allcontent = '';
        $sources = [];

        // 1. Course Summary.
        $allcontent .= "=== COURSE: " . $course->fullname . " ===\n\n";
        if (!empty($course->summary)) {
            $allcontent .= strip_tags($course->summary) . "\n\n";
        }
        $sources[] = [
            'type' => 'course_summary',
            'name' => $course->fullname,
            'word_count' => str_word_count($course->summary ?? ''),
        ];

        // 2. Section Summaries (filter out administrative sections).
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        foreach ($sections as $section) {
            // Skip administrative sections (assignment, assessment, certificate).
            $sectionname = strtolower(trim($section->name ?? ''));
            if (in_array($sectionname, self::EXCLUDED_SECTION_NAMES)) {
                continue;
            }

            if (!empty($section->name) || !empty($section->summary)) {
                $allcontent .= "=== SECTION: " . ($section->name ?: "Section $section->section") . " ===\n\n";
                if (!empty($section->summary)) {
                    $allcontent .= strip_tags($section->summary) . "\n\n";
                }
                $sources[] = [
                    'type' => 'section_summary',
                    'name' => $section->name ?: "Section $section->section",
                    'word_count' => str_word_count($section->summary ?? ''),
                ];
            }

            // Check content size limit.
            if (strlen($allcontent) > self::MAX_CONTENT_SIZE) {
                break;
            }
        }

        // 3. Scan all resources (pages, books, etc.) - the actual learning content.
        $resourcescan = self::scan_all_resources($courseid);
        $allcontent .= $resourcescan['text'];
        $sources = array_merge($sources, $resourcescan['sources']);

        // 4. Scan all activities (lessons, SCORM) - structured learning content.
        $activityscan = self::scan_all_activities($courseid);
        $allcontent .= $activityscan['text'];
        $sources = array_merge($sources, $activityscan['sources']);

        $wordcount = str_word_count($allcontent);

        return [
            'text' => $allcontent,
            'word_count' => $wordcount,
            'sources' => $sources,
        ];
    }

    /**
     * Scan all resource modules in a course.
     *
     * @param int $courseid Course ID
     * @return array ['text' => string, 'word_count' => int, 'sources' => array]
     * @throws \moodle_exception If scanning fails
     */
    public static function scan_all_resources(int $courseid): array {
        global $DB;

        $allcontent = '';
        $sources = [];
        $modinfo = get_fast_modinfo($courseid);

        // Track seen content to avoid duplicates.
        $seennames = [];

        foreach ($modinfo->get_cms() as $cm) {
            // Only process resource modules.
            if (!in_array($cm->modname, self::RESOURCE_MODULES)) {
                continue;
            }

            // Skip hidden modules.
            if (!$cm->uservisible) {
                continue;
            }

            // Check content size limit.
            if (strlen($allcontent) > self::MAX_CONTENT_SIZE) {
                break;
            }

            try {
                $extractor = new content_extractor();
                $result = $extractor->extract_from_activity($cm->id, $cm->modname, $courseid);

                // Skip duplicates (same name = same content).
                $namekey = strtolower(trim($result['name']));
                if (isset($seennames[$namekey])) {
                    continue;
                }
                $seennames[$namekey] = true;

                // Use TOPIC marker format for AI topic extraction.
                $modulelabel = ucfirst($cm->modname);
                $allcontent .= "\n\n=== TOPIC: " . $result['name'] . " (" . $modulelabel . ") ===\n";
                $allcontent .= "Activity Name: " . $result['name'] . "\n";
                $allcontent .= "Activity Type: " . $modulelabel . "\n";
                $allcontent .= "---\n";
                $allcontent .= $result['text'];
                $allcontent .= "\n=== END TOPIC ===\n";

                $sources[] = [
                    'type' => $cm->modname,
                    'name' => $result['name'],
                    'word_count' => $result['word_count'],
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        $wordcount = str_word_count($allcontent);

        return [
            'text' => $allcontent,
            'word_count' => $wordcount,
            'sources' => $sources,
        ];
    }

    /**
     * Scan all activity modules in a course.
     *
     * @param int $courseid Course ID
     * @return array ['text' => string, 'word_count' => int, 'sources' => array]
     * @throws \moodle_exception If scanning fails
     */
    public static function scan_all_activities(int $courseid): array {
        global $DB;

        $allcontent = '';
        $sources = [];
        $modinfo = get_fast_modinfo($courseid);
        $allcms = $modinfo->get_cms();

        // Track seen content to avoid duplicates.
        $seennames = [];

        foreach ($allcms as $cm) {
            // Only process activity modules.
            if (!in_array($cm->modname, self::ACTIVITY_MODULES)) {
                continue;
            }

            // Skip hidden modules.
            if (!$cm->uservisible) {
                continue;
            }

            // Skip Announcements forum (type='news' in Moodle).
            if ($cm->modname === 'forum') {
                $forum = $DB->get_record('forum', ['id' => $cm->instance], 'type');
                if ($forum && $forum->type === 'news') {
                    continue;
                }
            }

            // Check content size limit.
            if (strlen($allcontent) > self::MAX_CONTENT_SIZE) {
                break;
            }

            try {
                $extractor = new content_extractor();
                $result = $extractor->extract_from_activity($cm->id, $cm->modname, $courseid);

                // Skip duplicates (same name = same content).
                $namekey = strtolower(trim($result['name']));
                if (isset($seennames[$namekey])) {
                    continue;
                }
                $seennames[$namekey] = true;

                // Use TOPIC marker format for AI topic extraction.
                $modulelabel = ucfirst($cm->modname);
                $allcontent .= "\n\n=== TOPIC: " . $result['name'] . " (" . $modulelabel . ") ===\n";
                $allcontent .= "Activity Name: " . $result['name'] . "\n";
                $allcontent .= "Activity Type: " . $modulelabel . "\n";
                $allcontent .= "---\n";
                $allcontent .= $result['text'];
                $allcontent .= "\n=== END TOPIC ===\n";

                $sources[] = [
                    'type' => $cm->modname,
                    'name' => $result['name'],
                    'word_count' => $result['word_count'],
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        $wordcount = str_word_count($allcontent);

        return [
            'text' => $allcontent,
            'word_count' => $wordcount,
            'sources' => $sources,
        ];
    }

    /**
     * Scan all content in a course (resources + activities).
     *
     * @param int $courseid Course ID
     * @return array ['text' => string, 'word_count' => int, 'sources' => array]
     * @throws \moodle_exception If scanning fails
     */
    public static function scan_all_content(int $courseid): array {
        $allcontent = '';
        $sources = [];

        // Scan resources.
        $resourceresult = self::scan_all_resources($courseid);
        $allcontent .= $resourceresult['text'];
        $sources = array_merge($sources, $resourceresult['sources']);

        // Check size limit.
        if (strlen($allcontent) < self::MAX_CONTENT_SIZE) {
            // Scan activities.
            $activityresult = self::scan_all_activities($courseid);
            $allcontent .= $activityresult['text'];
            $sources = array_merge($sources, $activityresult['sources']);
        }

        $wordcount = str_word_count($allcontent);

        return [
            'text' => $allcontent,
            'word_count' => $wordcount,
            'sources' => $sources,
        ];
    }

    /**
     * Get scannable modules in a course.
     *
     * @param int $courseid Course ID
     * @return array Module statistics
     */
    public static function get_scannable_modules(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $stats = [
            'resources' => [],
            'activities' => [],
            'total_resources' => 0,
            'total_activities' => 0,
        ];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }

            if (in_array($cm->modname, self::RESOURCE_MODULES)) {
                if (!isset($stats['resources'][$cm->modname])) {
                    $stats['resources'][$cm->modname] = 0;
                }
                $stats['resources'][$cm->modname]++;
                $stats['total_resources']++;
            } else if (in_array($cm->modname, self::ACTIVITY_MODULES)) {
                if (!isset($stats['activities'][$cm->modname])) {
                    $stats['activities'][$cm->modname] = 0;
                }
                $stats['activities'][$cm->modname]++;
                $stats['total_activities']++;
            }
        }

        return $stats;
    }

    /**
     * Validate scan request to prevent excessive content.
     *
     * @param int $courseid Course ID
     * @param string $scantype Type of scan (entire, resources, activities)
     * @return array ['valid' => bool, 'message' => string, 'estimated_size' => int]
     */
    public static function validate_scan_request(int $courseid, string $scantype): array {
        $stats = self::get_scannable_modules($courseid);
        $estimatedsize = 0;

        // Rough estimate: 1000 words per page, 500 bytes per word.
        switch ($scantype) {
            case 'entire':
                $estimatedsize = ($stats['total_resources'] + $stats['total_activities']) * 1000 * 500;
                break;
            case 'resources':
                $estimatedsize = $stats['total_resources'] * 1000 * 500;
                break;
            case 'activities':
                $estimatedsize = $stats['total_activities'] * 1000 * 500;
                break;
        }

        if ($estimatedsize > self::MAX_CONTENT_SIZE) {
            return [
                'valid' => false,
                'message' => get_string('error:contenttoobig', 'local_hlai_quizgen'),
                'estimated_size' => $estimatedsize,
            ];
        }

        return [
            'valid' => true,
            'message' => get_string('scan_valid', 'local_hlai_quizgen'),
            'estimated_size' => $estimatedsize,
        ];
    }
}
