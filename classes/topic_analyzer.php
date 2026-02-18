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
 * Topic analyzer for the AI Quiz Generator plugin.
 *
 * Uses AI Hub to analyze content and extract topics/learning objectives.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Topic analyzer class.
 */
class topic_analyzer {
    /**
     * Analyze content and extract topics.
     *
     * @param string $content Content text to analyze
     * @param int $requestid Request ID to associate topics with
     * @return array Array of topic objects
     * @throws \moodle_exception If analysis fails
     */
    public static function analyze_content(string $content, int $requestid): array {
        global $DB;

        // Memory safety: Limit content size at entry point to prevent memory exhaustion.
        $maxcontentsize = 10 * 1024 * 1024; // 10MB maximum.
        if (strlen($content) > $maxcontentsize) {
            $content = substr($content, 0, $maxcontentsize);
        }

        // Require gateway client.
        if (!gateway_client::is_ready()) {
            throw new \moodle_exception(
                'error:noaiprovider',
                'local_hlai_quizgen',
                '',
                'Gateway not configured. Please configure the AI Service URL and API Key in plugin settings.'
            );
        }

        // Check cache first if enabled.
        $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid], 'courseid');
        $cachekey = cache_manager::generate_topic_cache_key($content, $request->courseid);

        // Topic caching disabled for now.

        // STRATEGY: First try direct marker extraction (fast, reliable for bulk scans).
        // Only fall back to AI if no markers found (e.g., uploaded files, URLs).
        $topics = [];

        // Check if content has TOPIC markers (from bulk course scans).
        if (strpos($content, '=== TOPIC:') !== false) {
            $topics = self::extract_topics_from_markers($content);
        }

        // If direct extraction found topics, use them.
        if (!empty($topics)) {
            // Save topics to database.
            $savedtopics = self::save_topics($topics, $requestid);

            // Cache the topics if enabled.
            if (cache_manager::is_caching_enabled()) {
                cache_manager::set_cached_response('topics', $cachekey, $topics, [
                    'requestid' => $requestid,
                    'courseid' => $request->courseid,
                ]);
            }

            return $savedtopics;
        }

        // Fall back to AI analysis for content without markers (uploads, URLs, etc.).

        // Call gateway for topic analysis.
        // Use 'best' quality for topic analysis - complex task needs higher quality model.
        try {
            $payload = [
                'content' => $content,
                'courseid' => $request->courseid,
            ];

            // Call gateway with 'best' quality.
            $response = gateway_client::analyze_topics($payload, 'best');

            // Extract topics from response.
            $topics = $response['topics'] ?? [];

            // Save topics to database.
            $savedtopics = self::save_topics($topics, $requestid);

            // Cache the topics if enabled.
            if (cache_manager::is_caching_enabled()) {
                cache_manager::set_cached_response('topics', $cachekey, $topics, [
                    'requestid' => $requestid,
                    'courseid' => $request->courseid,
                ]);
            }

            return $savedtopics;
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:topicanalysis',
                'local_hlai_quizgen',
                '',
                null,
                $e->getMessage()
            );
        }
    }

    // NOTE: AI prompts are proprietary and located on the Human Logic AI Gateway server.
    // This plugin only sends data payloads to the gateway. All prompt engineering is server-side.

    /**
     * Parse AI response to extract topics.
     *
     * @param string $response AI response text
     * @return array Array of topic data
     * @throws \moodle_exception If parsing fails
     */
    private static function parse_topic_response(string $response): array {
        // Try to extract JSON from response.
        // Sometimes AI adds markdown code blocks.
        $response = trim($response);

        // Remove markdown code blocks if present.
        // phpcs:disable moodle.Strings.ForbiddenStrings.Found
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } else if (preg_match('/```\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        }
        // phpcs:enable moodle.Strings.ForbiddenStrings.Found

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'error:topicanalysis',
                'local_hlai_quizgen',
                '',
                null,
                'Failed to parse AI response as JSON: ' .
                    json_last_error_msg() .
                    ' | Response preview: ' .
                    substr($response, 0, 200)
            );
        }

        if (empty($data['topics'])) {
            throw new \moodle_exception(
                'error:topicanalysis',
                'local_hlai_quizgen',
                '',
                null,
                'No topics found in AI response'
            );
        }

        // Filter out invalid topics (numbers, symbols, exercises).
        $data['topics'] = self::filter_invalid_topics($data['topics']);

        if (empty($data['topics'])) {
            throw new \moodle_exception(
                'error:topicanalysis',
                'local_hlai_quizgen',
                '',
                null,
                'No valid topics found after filtering'
            );
        }

        return $data['topics'];
    }

    /**
     * Extract topics directly from TOPIC markers in content.
     *
     * This is a reliable fallback when AI fails to extract topics.
     * Parses content for "=== TOPIC: Name (Type) ===" markers.
     *
     * @param string $content Content with TOPIC markers
     * @return array Array of topic data
     */
    public static function extract_topics_from_markers(string $content): array {
        $topics = [];

        // Match TOPIC markers: === TOPIC: Name (Type) ===.
        // Captures: 1=Name, 2=Type (optional).
        $pattern = '/===\s*TOPIC:\s*(.+?)\s*(?:\(([^)]+)\))?\s*===/i';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $index => $match) {
                $name = trim($match[1][0]);
                $type = isset($match[2]) ? trim($match[2][0]) : 'Content';
                $startpos = $match[0][1];

                // Skip invalid names.
                if (empty($name) || strlen($name) < 3) {
                    continue;
                }

                // Skip generic module-only names.
                $lowername = strtolower($name);
                if (in_array($lowername, ['scorm', 'lesson', 'forum', 'page', 'book', 'resource', 'label', 'url', 'folder'])) {
                    continue;
                }

                // Get content excerpt (text between this marker and next marker or END TOPIC).
                $excerpt = '';
                $contentstart = $startpos + strlen($match[0][0]);

                // Find end of this topic section.
                $endmarker = strpos($content, '=== END TOPIC ===', $contentstart);
                $nextmarker = strpos($content, '=== TOPIC:', $contentstart);

                if ($endmarker !== false) {
                    $sectionend = $endmarker;
                } else if ($nextmarker !== false) {
                    $sectionend = $nextmarker;
                } else {
                    $sectionend = min($contentstart + 2000, strlen($content));
                }

                $sectioncontent = substr($content, $contentstart, $sectionend - $contentstart);

                // Extract first meaningful paragraph as description.
                $lines = explode("\n", trim($sectioncontent));
                $desclines = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    // Skip metadata lines.
                    if (preg_match('/^(Activity Name|Activity Type|---)/', $line)) {
                        continue;
                    }
                    if (!empty($line) && strlen($line) > 20) {
                        $desclines[] = $line;
                        if (count($desclines) >= 2) {
                            break;
                        }
                    }
                }
                $description = implode(' ', $desclines);

                // Clean up garbled PDF text (spaced characters, encoding issues).
                $description = self::clean_garbled_text($description);

                if (strlen($description) > 300) {
                    $description = substr($description, 0, 297) . '...';
                }

                // Get content excerpt (first 300 chars of actual content).
                $excerpt = substr(trim($sectioncontent), 0, 300);
                $excerpt = preg_replace('/^(Activity Name:.*?\n|Activity Type:.*?\n|---\n)+/s', '', $excerpt);

                // Clean up garbled text in excerpt too.
                $excerpt = self::clean_garbled_text($excerpt);

                $topics[] = [
                    'title' => self::clean_topic_title($name),
                    'description' => $description ?: "Content from {$type}: {$name}",
                    'level' => 1,
                    'subtopics' => [],
                    'learning_objectives' => [],
                    'content_excerpt' => trim($excerpt),
                ];
            }
        }

        // Deduplicate by title.
        $seentitles = [];
        $uniquetopics = [];
        foreach ($topics as $topic) {
            $normalizedtitle = strtolower(trim($topic['title'] ?? ''));
            if (isset($seentitles[$normalizedtitle])) {
                continue;
            }
            $seentitles[$normalizedtitle] = true;
            $uniquetopics[] = $topic;
        }

        return $uniquetopics;
    }

    /**
     * Filter out invalid topics and clean up topic titles.
     *
     * @param array $topics Array of topics to filter
     * @return array Filtered and cleaned topics
     */
    private static function filter_invalid_topics(array $topics): array {
        $validtopics = [];

        foreach ($topics as $topic) {
            $title = trim($topic['title'] ?? '');

            // Skip if empty.
            if (empty($title)) {
                continue;
            }

            // CLEAN UP: Remove module type prefixes from titles.
            // "SCORM: Control Safety Hazards" → "Control Safety Hazards".
            // "SECTION: Valves: Introduction" → "Valves: Introduction".
            // "COURSE: Valves" → "Valves".
            $title = self::clean_topic_title($title);
            $topic['title'] = $title;

            // Skip if empty after cleaning.
            if (empty($title)) {
                continue;
            }

            // Skip pure numbers (1, 2, 3.5, etc.).
            if (is_numeric($title)) {
                continue;
            }

            // Skip single characters or symbols.
            if (strlen($title) <= 2 && !ctype_alnum($title)) {
                continue;
            }

            // Skip if title is ONLY a generic module name (no actual content).
            $lowertitle = strtolower($title);
            $genericonlynames = [
                'scorm', 'scorm package', 'scorm module',
                'lesson', 'lesson module',
                'forum', 'forum module',
                'page', 'page module',
                'book', 'book module',
                'resource', 'file', 'url', 'label', 'folder',
                'assignment', 'quiz', 'workshop', 'glossary',
                'wiki', 'choice', 'feedback', 'survey',
                'database', 'chat', 'external tool', 'lti', 'h5p',
                'section', 'course', 'module', 'activity', 'topic',
            ];

            $skipthis = false;
            foreach ($genericonlynames as $genericname) {
                // Only skip if title is EXACTLY or ONLY the generic name (with optional number).
                if (preg_match('/^' . preg_quote($genericname, '/') . '\s*\d*$/i', $title)) {
                    $skipthis = true;
                    break;
                }
            }

            if ($skipthis) {
                continue;
            }

            // Skip exercise-only markers.
            $excludepatterns = ['exercise', 'practice', 'worksheet', 'test', 'exam', 'homework'];
            foreach ($excludepatterns as $pattern) {
                if (preg_match('/^' . $pattern . '\s*\d*$/i', $title)) {
                    $skipthis = true;
                    break;
                }
            }

            if ($skipthis) {
                continue;
            }

            // Topic is valid - also clean subtopics.
            if (!empty($topic['subtopics'])) {
                $topic['subtopics'] = self::filter_invalid_topics($topic['subtopics']);
            }

            $validtopics[] = $topic;
        }

        // DEDUPLICATION: Remove duplicate topics (same title after cleaning).
        $validtopics = self::remove_duplicate_topics($validtopics);

        return $validtopics;
    }

    /**
     * Remove duplicate topics based on cleaned title.
     *
     * After removing prefixes like "SCORM:" and "SECTION:", topics may have identical titles.
     * Keep only the first occurrence of each unique title.
     *
     * @param array $topics Array of topics
     * @return array Deduplicated topics
     */
    private static function remove_duplicate_topics(array $topics): array {
        $seentitles = [];
        $uniquetopics = [];

        foreach ($topics as $topic) {
            // Normalize title for comparison (lowercase, trim whitespace).
            $normalizedtitle = strtolower(trim($topic['title'] ?? ''));

            // Skip if we've already seen this title.
            if (isset($seentitles[$normalizedtitle])) {
                continue;
            }

            // Mark this title as seen.
            $seentitles[$normalizedtitle] = $topic['title'];
            $uniquetopics[] = $topic;
        }

        return $uniquetopics;
    }

    /**
     * Clean up garbled text from PDF extraction.
     *
     * If text is too garbled (spaced characters, encoding issues), return empty string
     * to trigger fallback description.
     *
     * @param string $text Text to clean
     * @return string Cleaned text or empty if too garbled
     */
    private static function clean_garbled_text(string $text): string {
        if (empty($text)) {
            return '';
        }

        // Pattern 1: Detect split words like "Hos pitality", "Certi fi cati on".
        // These are 2-4 letter fragments separated by spaces that should be one word.
        $fragments = preg_match_all('/\b[A-Za-z]{1,4}\s[A-Za-z]{1,4}\s[A-Za-z]{1,4}\b/', $text);
        if ($fragments > 1) {
            return '';
        }

        // Pattern 2: Detect "letter space letter space letter" within what should be words.
        // Catches "V er s i on", "a c t i v i" type patterns.
        if (preg_match('/[a-z]\s[a-z]{1,2}\s[a-z]/i', $text)) {
            return '';
        }

        // Pattern 3: Detect single-letter words (excluding 'a', 'I', 'A').
        // Normal text rarely has standalone letters like "t" or "n" as words.
        $singleletters = preg_match_all('/\s[b-hj-zB-HJ-Z]\s/', $text);
        if ($singleletters > 1) {
            return '';
        }

        // Pattern 4: Detect "word split" patterns like "Hos pitality", "Qua lity".
        // Uppercase or start of word followed by space then lowercase continuation.
        $wordsplits = preg_match_all('/[a-z]{2,4}\s[a-z]{2,4}[a-z]/i', $text);
        if ($wordsplits > 3) {
            return '';
        }

        // Pattern 5: Detect spaced numbers like "2 1 08 2024", "0 0 2".
        $numberspaces = preg_match_all('/\d\s\d/', $text);
        if ($numberspaces > 0) {
            return '';
        }

        // Pattern 6: Detect patterns like "cati on", "pitali ty" - partial word + space + ending.
        $splitendings = preg_match_all('/[a-z]{2,5}\s[a-z]{1,3}\b/i', $text);
        if ($splitendings > 2) {
            return '';
        }

        // Pattern 7: Check for too many spaces relative to text length.
        // Garbled PDF text has excessive spaces from character spacing issues.
        $spacecount = substr_count($text, ' ');
        $textlen = strlen($text);
        if ($textlen > 30 && ($spacecount / $textlen) > 0.25) {
            return '';
        }

        // Pattern 8: Detect "XX space xx" pattern (like "Hos pitality", "QM S").
        // Capital letters followed by space and then lowercase in mid-word position.
        $capsplits = preg_match_all('/[A-Z][a-z]{1,3}\s[a-z]{2,}/', $text);
        if ($capsplits > 1) {
            return '';
        }

        // Remove language tags like "en-US", "en-AE".
        $text = preg_replace('/\ben-[A-Z]{2}\b/i', '', $text);

        // Clean up multiple spaces.
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Clean up topic title by removing module type prefixes.
     *
     * Examples:
     * - "SCORM: Control Safety Hazards: Working in Confined Spaces" → "Control Safety Hazards: Working in Confined Spaces"
     * - "SECTION: Valves: Introduction to Valves" → "Valves: Introduction to Valves"
     * - "COURSE: Valves" → "Valves"
     * - "LESSON: Introduction to Python" → "Introduction to Python"
     *
     * @param string $title Original topic title
     * @return string Cleaned topic title
     */
    private static function clean_topic_title(string $title): string {
        // List of prefixes to remove (case-insensitive).
        $prefixestoremove = [
            'SCORM:', 'SECTION:', 'COURSE:', 'LESSON:', 'FORUM:', 'PAGE:',
            'BOOK:', 'RESOURCE:', 'MODULE:', 'ACTIVITY:', 'TOPIC:',
            'LABEL:', 'FOLDER:', 'URL:', 'FILE:', 'QUIZ:', 'ASSIGNMENT:',
            'WORKSHOP:', 'GLOSSARY:', 'WIKI:', 'CHOICE:', 'FEEDBACK:',
            'SURVEY:', 'DATABASE:', 'CHAT:', 'H5P:', 'LTI:',
        ];

        $originaltitle = $title;

        // Remove prefix if present at the start.
        foreach ($prefixestoremove as $prefix) {
            if (stripos($title, $prefix) === 0) {
                $title = trim(substr($title, strlen($prefix)));
                break; // Only remove one prefix.
            }
        }

        // Also handle variations like "SCORM - " or "SCORM: " with spaces.
        $title = preg_replace(
            '/^(SCORM|SECTION|COURSE|LESSON|FORUM|PAGE|BOOK|RESOURCE|MODULE|ACTIVITY|TOPIC)\s*[:>\-]\s*/i',
            '',
            $title
        );

        // If the cleaned title is now empty or just the original prefix, keep original.
        if (empty(trim($title))) {
            return $originaltitle;
        }

        return trim($title);
    }

    /**
     * Public wrapper for clean_topic_title.
     *
     * Allows external code (like wizard.php) to clean topic titles using the same logic.
     *
     * @param string $title Original topic title
     * @return string Cleaned topic title
     */
    public static function clean_topic_title_public(string $title): string {
        return self::clean_topic_title($title);
    }

    /**
     * Save topics to database.
     *
     * @param array $topics Array of topic data from AI
     * @param int $requestid Request ID
     * @return array Array of saved topic records
     */
    private static function save_topics(array $topics, int $requestid): array {
        global $DB;

        $savedtopics = [];
        $now = time();

        foreach ($topics as $maintopic) {
            // Save main topic.
            $topicrecord = new \stdClass();
            $topicrecord->requestid = $requestid;
            $topicrecord->title = $maintopic['title'];
            $topicrecord->description = $maintopic['description'] ?? '';
            $topicrecord->parent_topic_id = null;
            $topicrecord->level = 1;
            $topicrecord->selected = 1;  // Default: selected.
            $topicrecord->num_questions = 5;  // Default: 5 questions.
            $topicrecord->content_excerpt = $maintopic['content_excerpt'] ?? '';
            $topicrecord->learning_objectives = json_encode($maintopic['learning_objectives'] ?? []);
            $topicrecord->timecreated = $now;

            $topicid = $DB->insert_record('local_hlai_quizgen_topics', $topicrecord);
            $topicrecord->id = $topicid;
            $savedtopics[] = $topicrecord;

            // Save subtopics if present.
            if (!empty($maintopic['subtopics'])) {
                foreach ($maintopic['subtopics'] as $subtopic) {
                    $subtopicrecord = new \stdClass();
                    $subtopicrecord->requestid = $requestid;
                    $subtopicrecord->title = $subtopic['title'];
                    $subtopicrecord->description = $subtopic['description'] ?? '';
                    $subtopicrecord->parent_topic_id = $topicid;
                    $subtopicrecord->level = 2;
                    $subtopicrecord->selected = 0;  // Subtopics not selected by default.
                    $subtopicrecord->num_questions = 0;
                    $subtopicrecord->content_excerpt = $subtopic['content_excerpt'] ?? '';
                    $subtopicrecord->learning_objectives = json_encode([]);
                    $subtopicrecord->timecreated = $now;

                    $subtopicid = $DB->insert_record('local_hlai_quizgen_topics', $subtopicrecord);
                    $subtopicrecord->id = $subtopicid;
                    $savedtopics[] = $subtopicrecord;
                }
            }
        }

        return $savedtopics;
    }

    /**
     * Get topics for a request.
     *
     * @param int $requestid Request ID
     * @return array Array of topic objects
     */
    public static function get_topics(int $requestid): array {
        global $DB;

        $topics = $DB->get_records('local_hlai_quizgen_topics', ['requestid' => $requestid], 'level ASC, id ASC');

        // Organize topics hierarchically.
        $organized = [];
        foreach ($topics as $topic) {
            if ($topic->level == 1) {
                $topic->subtopics = [];
                $organized[$topic->id] = $topic;
            }
        }

        // Add subtopics to main topics.
        foreach ($topics as $topic) {
            if ($topic->level == 2 && isset($organized[$topic->parent_topic_id])) {
                $organized[$topic->parent_topic_id]->subtopics[] = $topic;
            }
        }

        return array_values($organized);
    }

    /**
     * Update topic selection.
     *
     * @param int $topicid Topic ID
     * @param bool $selected Selected status
     * @param int $numquestions Number of questions
     * @return bool Success
     */
    public static function update_topic_selection(int $topicid, bool $selected, int $numquestions = 0): bool {
        global $DB;

        $record = new \stdClass();
        $record->id = $topicid;
        $record->selected = $selected ? 1 : 0;
        $record->num_questions = $numquestions;

        return $DB->update_record('local_hlai_quizgen_topics', $record);
    }

    /**
     * Get selected topics for a request.
     *
     * @param int $requestid Request ID
     * @return array Array of selected topic objects
     */
    public static function get_selected_topics(int $requestid): array {
        global $DB;

        return $DB->get_records('local_hlai_quizgen_topics', [
            'requestid' => $requestid,
            'selected' => 1,
        ], 'level ASC, id ASC');
    }


    /**
     * Clone topics from cache for new request.
     *
     * @param array $cachedtopics Cached topic data
     * @param int $requestid New request ID
     * @return array Cloned topics
     */
    private static function clone_topics_from_cache(array $cachedtopics, int $requestid): array {
        global $DB;

        $clonedtopics = [];
        $parentmap = []; // Map old parent IDs to new IDs.

        // DEDUPLICATION: Track seen titles to skip duplicates.
        $seentitles = [];

        foreach ($cachedtopics as $topic) {
            // Clean and deduplicate title.
            $rawtitle = $topic['title'] ?? 'Untitled Topic';
            $cleanedtitle = self::clean_topic_title($rawtitle);
            $normalizedtitle = strtolower(trim($cleanedtitle));

            // Skip if we've already seen this title.
            if (isset($seentitles[$normalizedtitle])) {
                continue;
            }
            $seentitles[$normalizedtitle] = $cleanedtitle;

            $newtopic = new \stdClass();
            $newtopic->requestid = $requestid;
            $newtopic->title = $cleanedtitle; // Use cleaned title.
            $newtopic->description = $topic['description'] ?? '';
            $newtopic->content_excerpt = $topic['content_excerpt'] ?? '';
            // Ensure learning_objectives is always a JSON string.
            if (isset($topic['learning_objectives'])) {
                $newtopic->learning_objectives = is_string($topic['learning_objectives'])
                    ? $topic['learning_objectives']
                    : json_encode($topic['learning_objectives']);
            } else {
                $newtopic->learning_objectives = '[]';
            }
            $newtopic->parent_topic_id = 0; // Will update after first pass.
            $newtopic->level = $topic['level'] ?? 1;
            $newtopic->num_questions = $topic['num_questions'] ?? 5;
            $newtopic->selected = 1; // Auto-select cached topics.
            $newtopic->timecreated = time();

            $newid = $DB->insert_record('local_hlai_quizgen_topics', $newtopic);

            // Store mapping if this was a parent.
            if (isset($topic['id'])) {
                $parentmap[$topic['id']] = $newid;
            }

            $newtopic->id = $newid;
            $clonedtopics[] = $newtopic;
        }

        // Second pass: update parent IDs.
        foreach ($clonedtopics as $topic) {
            $originalparent = 0;
            // Find original parent in cached data.
            foreach ($cachedtopics as $cached) {
                if (
                    ($cached['title'] ?? '') === $topic->title &&
                    isset($cached['parent_topic_id'])
                ) {
                    $originalparent = $cached['parent_topic_id'];
                    break;
                }
            }

            if ($originalparent > 0 && isset($parentmap[$originalparent])) {
                $DB->set_field(
                    'local_hlai_quizgen_topics',
                    'parent_topic_id',
                    $parentmap[$originalparent],
                    ['id' => $topic->id]
                );
            }
        }

        return $clonedtopics;
    }

    /**
     * Intelligently chunk content preserving semantic meaning.
     *
     * @param string $content Content to chunk
     * @param int $maxlength Maximum chunk length in characters
     * @param int $overlap Overlap between chunks for context preservation
     * @return array Array of content chunks
     */
    private static function chunk_content(string $content, int $maxlength = 8000, int $overlap = 500): array {
        // Memory safety: Limit maximum content size to prevent memory exhaustion.
        // 10MB is a reasonable limit for text content analysis.
        $maxcontentsize = 10 * 1024 * 1024; // 10MB.
        if (strlen($content) > $maxcontentsize) {
            $content = substr($content, 0, $maxcontentsize);
        }

        if (strlen($content) <= $maxlength) {
            return [$content];
        }

        // Memory safety: Limit maximum number of chunks.
        $maxchunks = 50;

        $chunks = [];
        $position = 0;
        $contentlength = strlen($content);

        while ($position < $contentlength) {
            $chunkend = min($position + $maxlength, $contentlength);

            // Don't split in the middle - find natural boundary.
            if ($chunkend < $contentlength) {
                // Priority 1: Look for section headers (## Header, ### Header, etc.).
                $headerpos = self::find_last_boundary($content, $position, $chunkend, '/\n#{1,6}\s+.+\n/');

                if ($headerpos !== false) {
                    $chunkend = $headerpos;
                } else {
                    // Priority 2: Look for paragraph break (double newline).
                    $paragraphpos = self::find_last_boundary($content, $position, $chunkend, '/\n\n/');

                    if ($paragraphpos !== false) {
                        $chunkend = $paragraphpos;
                    } else {
                        // Priority 3: Look for sentence end.
                        $sentencepos = self::find_last_boundary($content, $position, $chunkend, '/[.!?]\s+/');

                        if ($sentencepos !== false) {
                            $chunkend = $sentencepos;
                        } else {
                            // Priority 4: Look for comma or semicolon.
                            $punctuationpos = self::find_last_boundary($content, $position, $chunkend, '/[,;]\s+/');

                            if ($punctuationpos !== false) {
                                $chunkend = $punctuationpos;
                            } else {
                                // Last resort: word boundary.
                                $wordpos = self::find_last_boundary($content, $position, $chunkend, '/\s+/');

                                if ($wordpos !== false) {
                                    $chunkend = $wordpos;
                                }
                                // If even that fails, hard truncate (very rare).
                            }
                        }
                    }
                }
            }

            // Extract chunk.
            $chunk = substr($content, $position, $chunkend - $position);
            $chunks[] = trim($chunk);

            // Memory safety: Stop if we've reached the maximum number of chunks.
            if (count($chunks) >= $maxchunks) {
                break;
            }

            // Move position forward, accounting for overlap.
            $position = $chunkend - $overlap;

            // Ensure we don't get stuck in infinite loop.
            if ($position <= ($chunkend - $maxlength)) {
                $position = $chunkend;
            }
        }

        return $chunks;
    }

    /**
     * Find last occurrence of boundary pattern within range.
     *
     * @param string $content Full content
     * @param int $start Start position
     * @param int $end End position
     * @param string $pattern Regex pattern for boundary
     * @return int|false Position of boundary or false if not found
     */
    private static function find_last_boundary(string $content, int $start, int $end, string $pattern) {
        $searchcontent = substr($content, $start, $end - $start);
        $matches = [];

        if (preg_match_all($pattern, $searchcontent, $matches, PREG_OFFSET_CAPTURE)) {
            // Get last match.
            $lastmatch = end($matches[0]);
            return $start + $lastmatch[1] + strlen($lastmatch[0]);
        }

        return false;
    }

    /**
     * Detect content type and adjust chunking strategy.
     *
     * @param string $content Content to analyze
     * @return array Chunking parameters [maxlength, overlap]
     */
    private static function get_chunking_params(string $content): array {
        // Check if content appears to be code.
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found
        $codepatterns = ['<?php', 'function ', 'class ', 'def ', 'import ', '```'];
        $iscode = false;
        foreach ($codepatterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                $iscode = true;
                break;
            }
        }

        if ($iscode) {
            // Code needs larger chunks and more overlap to preserve structure.
            return [12000, 1000];
        }

        // Check if content has clear sections.
        if (preg_match('/\n#{1,6}\s+/m', $content)) {
            // Markdown-style content with headers.
            return [10000, 800];
        }

        // Default: regular text.
        return [8000, 500];
    }
}
