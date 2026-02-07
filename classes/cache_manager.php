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
 * Cache manager for AI responses.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

/**
 * Manages caching of AI responses to reduce API calls and costs.
 */
class cache_manager {
    /** @var int Cache duration for topic analysis (7 days). */
    const CACHE_TTL_TOPICS = 604800;

    /** @var int Cache duration for question generation (3 days). */
    const CACHE_TTL_QUESTIONS = 259200;

    /** @var int Cache duration for distractor generation (3 days). */
    const CACHE_TTL_DISTRACTORS = 259200;

    /**
     * Get cached AI response if available.
     *
     * @param string $cachetype Type of cache (topics, questions, distractors)
     * @param string $cachekey Unique key for the cached item
     * @return mixed|null Cached data or null if not found/expired
     */
    public static function get_cached_response($cachetype, $cachekey) {
        global $DB;

        try {
            $cache = $DB->get_record('local_hlai_quizgen_cache', [
                'cachetype' => $cachetype,
                'cachekey' => $cachekey,
            ]);

            if (!$cache) {
                return null;
            }

            // Check if expired.
            $ttl = self::get_ttl_for_type($cachetype);
            if (time() - $cache->timecreated > $ttl) {
                self::delete_cache($cachetype, $cachekey);
                return null;
            }

            // Update hit counter.
            $DB->execute(
                "UPDATE {local_hlai_quizgen_cache}
                 SET hits = hits + 1, lastaccessed = ?
                 WHERE id = ?",
                [time(), $cache->id]
            );

            return json_decode($cache->data, true);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Store AI response in cache.
     *
     * @param string $cachetype Type of cache (topics, questions, distractors)
     * @param string $cachekey Unique key for the cached item
     * @param mixed $data Data to cache
     * @param array $metadata Additional metadata
     * @return bool Success status
     */
    public static function set_cached_response($cachetype, $cachekey, $data, $metadata = []) {
        global $DB;

        try {
            // Check if already exists.
            $existing = $DB->get_record('local_hlai_quizgen_cache', [
                'cachetype' => $cachetype,
                'cachekey' => $cachekey,
            ]);

            $record = new \stdClass();
            $record->cachetype = $cachetype;
            $record->cachekey = $cachekey;
            $record->data = json_encode($data);
            $record->metadata = json_encode($metadata);
            $record->lastaccessed = time();

            if ($existing) {
                $record->id = $existing->id;
                $record->hits = $existing->hits;
                $DB->update_record('local_hlai_quizgen_cache', $record);
            } else {
                $record->timecreated = time();
                $record->hits = 0;
                $DB->insert_record('local_hlai_quizgen_cache', $record);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete cached item.
     *
     * @param string $cachetype Type of cache
     * @param string $cachekey Cache key
     * @return bool Success status
     */
    public static function delete_cache($cachetype, $cachekey) {
        global $DB;

        try {
            $DB->delete_records('local_hlai_quizgen_cache', [
                'cachetype' => $cachetype,
                'cachekey' => $cachekey,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate cache key for topic analysis.
     *
     * @param string $content Content text
     * @param int $courseid Course ID
     * @return string Cache key
     */
    public static function generate_topic_cache_key($content, $courseid) {
        $normalized = self::normalize_content($content);
        return 'topic_' . $courseid . '_' . hash('sha256', $normalized);
    }

    /**
     * Generate cache key for question generation.
     *
     * @param string $topic Topic text
     * @param string $questiontype Question type
     * @param string $difficulty Difficulty level
     * @param string $blooms Bloom's taxonomy level
     * @return string Cache key
     */
    public static function generate_question_cache_key($topic, $questiontype, $difficulty, $blooms) {
        $key = implode('_', [$topic, $questiontype, $difficulty, $blooms]);
        return 'question_' . hash('sha256', $key);
    }

    /**
     * Generate cache key for distractor generation.
     *
     * @param string $questiontext Question text
     * @param string $correctanswer Correct answer
     * @param string $questiontype Question type
     * @return string Cache key
     */
    public static function generate_distractor_cache_key($questiontext, $correctanswer, $questiontype) {
        $key = $questiontext . '|' . $correctanswer . '|' . $questiontype;
        return 'distractor_' . hash('sha256', $key);
    }

    /**
     * Clean up expired cache entries.
     *
     * @return int Number of entries deleted
     */
    public static function cleanup_expired_cache() {
        global $DB;

        $deleted = 0;
        $types = ['topics', 'questions', 'distractors'];

        foreach ($types as $type) {
            $ttl = self::get_ttl_for_type($type);
            $expirytime = time() - $ttl;

            $count = $DB->count_records_select(
                'local_hlai_quizgen_cache',
                'cachetype = ? AND timecreated < ?',
                [$type, $expirytime]
            );

            $DB->delete_records_select(
                'local_hlai_quizgen_cache',
                'cachetype = ? AND timecreated < ?',
                [$type, $expirytime]
            );

            $deleted += $count;
        }

        return $deleted;
    }

    /**
     * Get cache statistics.
     *
     * @return array Statistics array
     */
    public static function get_cache_statistics() {
        global $DB;

        $stats = [
            'total_entries' => 0,
            'total_hits' => 0,
            'by_type' => [],
        ];

        $types = ['topics', 'questions', 'distractors'];

        foreach ($types as $type) {
            $records = $DB->get_records('local_hlai_quizgen_cache', ['cachetype' => $type]);
            $count = count($records);
            $hits = array_sum(array_column($records, 'hits'));

            $stats['by_type'][$type] = [
                'count' => $count,
                'hits' => $hits,
                'hit_rate' => $count > 0 ? round($hits / $count, 2) : 0,
            ];

            $stats['total_entries'] += $count;
            $stats['total_hits'] += $hits;
        }

        // Calculate overall hit rate.
        $stats['overall_hit_rate'] = $stats['total_entries'] > 0
            ? round($stats['total_hits'] / $stats['total_entries'], 2)
            : 0;

        // Calculate storage size.
        $sql = "SELECT SUM(LENGTH(data)) as total_size FROM {local_hlai_quizgen_cache}";
        $result = $DB->get_record_sql($sql);
        $stats['storage_bytes'] = $result->total_size ?? 0;
        $stats['storage_mb'] = round($stats['storage_bytes'] / 1048576, 2);

        return $stats;
    }

    /**
     * Clear all cache entries.
     *
     * @param string|null $cachetype Optional: specific type to clear
     * @return bool Success status
     */
    public static function clear_all_cache($cachetype = null) {
        global $DB;

        try {
            if ($cachetype) {
                $DB->delete_records('local_hlai_quizgen_cache', ['cachetype' => $cachetype]);
            } else {
                $DB->delete_records('local_hlai_quizgen_cache');
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get TTL for cache type.
     *
     * @param string $cachetype Cache type
     * @return int TTL in seconds
     */
    private static function get_ttl_for_type($cachetype) {
        switch ($cachetype) {
            case 'topics':
                return self::CACHE_TTL_TOPICS;
            case 'questions':
                return self::CACHE_TTL_QUESTIONS;
            case 'distractors':
                return self::CACHE_TTL_DISTRACTORS;
            default:
                return 259200; // 3 days default.
        }
    }

    /**
     * Normalize content for consistent cache keys.
     *
     * @param string $content Content text
     * @return string Normalized content
     */
    private static function normalize_content($content) {
        // Remove extra whitespace.
        $content = preg_replace('/\s+/', ' ', $content);
        // Trim and lowercase.
        $content = trim(strtolower($content));
        return $content;
    }

    /**
     * Check if caching is enabled.
     *
     * @return bool True if enabled
     */
    public static function is_caching_enabled() {
        return (bool) get_config('local_hlai_quizgen', 'enable_caching');
    }

    /**
     * Get cache hit rate for specific type.
     *
     * @param string $cachetype Cache type
     * @return float Hit rate (0-1)
     */
    public static function get_hit_rate($cachetype) {
        global $DB;

        $records = $DB->get_records('local_hlai_quizgen_cache', ['cachetype' => $cachetype]);

        if (empty($records)) {
            return 0.0;
        }

        $totalaccesses = array_sum(array_column($records, 'hits'));
        $totalentries = count($records);

        return $totalentries > 0 ? $totalaccesses / $totalentries : 0.0;
    }
}
