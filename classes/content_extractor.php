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
 * Content extraction engine for the AI Quiz Generator plugin.
 *
 * Extracts text content from various sources using ONLY native PHP and system commands.
 * NO external Composer dependencies required.
 *
 * - Uploaded files (PDF, DOCX, PPTX, TXT)
 * - Moodle activities (Page, Lesson, Book, SCORM)
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

// Only load Moodle module libraries if $CFG is available.
global $CFG;
if (isset($CFG)) {
    require_once($CFG->dirroot . '/mod/page/lib.php');
    require_once($CFG->dirroot . '/mod/book/lib.php');
    require_once($CFG->dirroot . '/mod/lesson/lib.php');
}

// NOTE: No Composer/vendor dependencies - all extraction uses native PHP or system commands

/**
 * Content extractor class.
 */
class content_extractor {
    /**
     * Extract content from multiple sources.
     *
     * @param array $sources Array of content sources
     *        Each source: ['type' => 'file|activity', 'id' => int, 'path' => string]
     * @param int $courseid Course ID
     * @return array Extracted content
     *        ['text' => string, 'word_count' => int, 'sources' => array]
     * @throws \moodle_exception If extraction fails
     */
    public static function extract_from_sources(array $sources, int $courseid): array {
        $alltext = '';
        $wordcount = 0;
        $extractedsources = [];

        foreach ($sources as $source) {
            $type = $source['type'] ?? '';

            try {
                if ($type === 'file') {
                    $result = self::extract_from_file($source['path']);
                    $alltext .= "\n\n" . $result['text'];
                    $wordcount += $result['word_count'];
                    $extractedsources[] = [
                        'type' => 'file',
                        'name' => basename($source['path']),
                        'word_count' => $result['word_count'],
                    ];
                } else if ($type === 'activity') {
                    $result = self::extract_from_activity($source['id'], $source['module_type'], $courseid);
                    $alltext .= "\n\n" . $result['text'];
                    $wordcount += $result['word_count'];
                    $extractedsources[] = [
                        'type' => 'activity',
                        'module_type' => $source['module_type'],
                        'name' => $result['name'],
                        'word_count' => $result['word_count'],
                    ];
                }
            } catch (\Exception $e) {
                // Silently continue with other sources.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Clean up and normalize text.
        $alltext = self::clean_text($alltext);

        return [
            'text' => $alltext,
            'word_count' => $wordcount,
            'sources' => $extractedsources,
        ];
    }

    /**
     * Extract content from uploaded file.
     *
     * @param string $filepath Full path to file
     * @param string $originalfilename Optional original filename (for extension detection from .tmp files)
     * @return array ['text' => string, 'word_count' => int]
     * @throws \moodle_exception If extraction fails
     */
    public static function extract_from_file(string $filepath, string $originalfilename = ''): array {
        if (!file_exists($filepath)) {
            throw new \moodle_exception('error:contentextraction', 'local_hlai_quizgen', '', 'File not found');
        }

        // Use original filename for extension if provided, otherwise use filepath.
        $filenameforext = !empty($originalfilename) ? $originalfilename : $filepath;
        $extension = strtolower(pathinfo($filenameforext, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'pdf':
                $text = self::extract_from_pdf($filepath);
                break;
            case 'docx':
            case 'doc':
                $text = self::extract_from_docx($filepath);
                break;
            case 'pptx':
            case 'ppt':
                $text = self::extract_from_pptx($filepath);
                break;
            case 'xlsx':
            case 'xls':
            case 'csv':
                $text = self::extract_from_excel($filepath);
                break;
            case 'txt':
                $text = file_get_contents($filepath);
                break;
            default:
                throw new \moodle_exception('error:invalidfiletype', 'local_hlai_quizgen');
        }

        $wordcount = str_word_count($text);

        return [
            'text' => $text,
            'word_count' => $wordcount,
        ];
    }

    /**
     * Extract text from PDF file using available system tools.
     * Tries multiple methods: pdftotext, Ghostscript, then pure PHP.
     * NO external PHP libraries required.
     *
     * @param string $filepath Path to PDF file
     * @return string Extracted text
     */
    private static function extract_from_pdf(string $filepath): string {
        // Method 1: Try pdftotext (best quality).
        $text = self::extract_pdf_pdftotext($filepath);
        if (!empty($text)) {
            return $text;
        }

        // Method 2: Try Ghostscript (gs) - available on most servers.
        $text = self::extract_pdf_ghostscript($filepath);
        if (!empty($text)) {
            return $text;
        }

        // Method 3: Try pure PHP extraction (basic, no dependencies).
        $text = self::extract_pdf_native($filepath);
        if (!empty($text)) {
            return $text;
        }

        throw new \moodle_exception(
            'error:contentextraction',
            'local_hlai_quizgen',
            '',
            'PDF extraction failed - no suitable extraction method available or the PDF contains only images'
        );
    }

    /**
     * Extract PDF using pdftotext command.
     *
     * @param string $filepath Path to the PDF file
     * @return string Extracted text content
     */
    private static function extract_pdf_pdftotext(string $filepath): string {
        $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null') ?? '');
        if (empty($pdftotext)) {
            return '';
        }

        try {
            $outputfile = tempnam(sys_get_temp_dir(), 'pdf_');
            $cmd = escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($filepath) . ' ' . escapeshellarg($outputfile) . ' 2>/dev/null';
            exec($cmd, $output, $returncode);

            if ($returncode === 0 && file_exists($outputfile)) {
                $text = file_get_contents($outputfile);
                @unlink($outputfile);
                $text = preg_replace('/\s+/', ' ', $text);
                return trim($text);
            }
            @unlink($outputfile);
        } catch (\Exception $e) {
            // Silently fail, try next method.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }
        return '';
    }

    /**
     * Extract PDF using Ghostscript (gs) command.
     *
     * @param string $filepath Path to the PDF file
     * @return string Extracted text content
     */
    private static function extract_pdf_ghostscript(string $filepath): string {
        $gs = trim(shell_exec('which gs 2>/dev/null') ?? '');
        if (empty($gs)) {
            return '';
        }

        try {
            $outputfile = tempnam(sys_get_temp_dir(), 'pdf_') . '.txt';

            // Use Ghostscript to extract text.
            $cmd = escapeshellcmd($gs) . ' -sDEVICE=txtwrite -o ' . escapeshellarg($outputfile) . ' ' . escapeshellarg($filepath) . ' 2>/dev/null';
            exec($cmd, $output, $returncode);

            if ($returncode === 0 && file_exists($outputfile)) {
                $text = file_get_contents($outputfile);
                @unlink($outputfile);
                $text = preg_replace('/\s+/', ' ', $text);
                return trim($text);
            }
            @unlink($outputfile);
        } catch (\Exception $e) {
            // Silently fail, try next method.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }
        return '';
    }

    /**
     * Extract PDF using pure PHP (basic extraction for simple PDFs).
     * Works without any external dependencies.
     *
     * @param string $filepath Path to the PDF file
     * @return string Extracted text content
     */
    private static function extract_pdf_native(string $filepath): string {
        try {
            $content = file_get_contents($filepath);
            if ($content === false) {
                return '';
            }

            $text = '';

            // Method 1: Extract text between BT and ET markers (text blocks).
            if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
                foreach ($matches[1] as $block) {
                    // Extract text from Tj and TJ operators.
                    if (preg_match_all('/\((.*?)\)\s*Tj/s', $block, $tjmatches)) {
                        $text .= implode(' ', $tjmatches[1]) . ' ';
                    }
                    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjarraymatches)) {
                        foreach ($tjarraymatches[1] as $arr) {
                            if (preg_match_all('/\((.*?)\)/', $arr, $items)) {
                                $text .= implode('', $items[1]) . ' ';
                            }
                        }
                    }
                }
            }

            // Method 2: Look for stream content with text.
            if (empty($text) && preg_match_all('/stream\s*(.*?)\s*endstream/s', $content, $streams)) {
                foreach ($streams[1] as $stream) {
                    // Try to find readable text in streams.
                    $decoded = @gzuncompress($stream);
                    if ($decoded !== false) {
                        if (preg_match_all('/\((.*?)\)/', $decoded, $textmatches)) {
                            $text .= implode(' ', $textmatches[1]) . ' ';
                        }
                    }
                }
            }

            // Clean up extracted text.
            $text = preg_replace('/[^\x20-\x7E\s]/', '', $text); // Remove non-printable
            $text = preg_replace('/\s+/', ' ', $text);
            return trim($text);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Extract text from DOCX file using native PHP ZipArchive.
     * NO external PHP libraries required.
     *
     * @param string $filepath Path to DOCX file
     * @return string Extracted text
     */
    private static function extract_from_docx(string $filepath): string {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($filepath) !== true) {
                throw new \Exception('Could not open DOCX file');
            }

            $text = '';

            // Extract from main document.
            $xml = $zip->getFromName('word/document.xml');
            if ($xml) {
                // Extract text from <w:t> tags.
                preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/i', $xml, $matches);
                if (!empty($matches[1])) {
                    $text .= implode(' ', $matches[1]) . "\n";
                }
            }

            // Also extract from headers/footers if present.
            for ($i = 1; $i <= 3; $i++) {
                $header = $zip->getFromName("word/header{$i}.xml");
                if ($header) {
                    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/i', $header, $matches);
                    if (!empty($matches[1])) {
                        $text .= implode(' ', $matches[1]) . "\n";
                    }
                }
            }

            $zip->close();

            // Clean up text.
            $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            if (empty($text)) {
                throw new \Exception('No text content found in DOCX');
            }

            return $text;
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:contentextraction',
                'local_hlai_quizgen',
                '',
                'DOCX extraction failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Extract text from PPTX file using native PHP ZipArchive.
     * NO external PHP libraries required.
     *
     * @param string $filepath Path to PPTX file
     * @return string Extracted text
     */
    private static function extract_from_pptx(string $filepath): string {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($filepath) !== true) {
                throw new \Exception('Could not open PPTX file');
            }

            $text = '';

            // Extract text from each slide.
            for ($i = 1; $i <= 200; $i++) {
                $slidename = "ppt/slides/slide{$i}.xml";
                $xml = $zip->getFromName($slidename);

                if ($xml === false) {
                    break; // No more slides
                }

                $text .= "--- Slide {$i} ---\n";

                // Extract text from <a:t> tags (PowerPoint text elements).
                preg_match_all('/<a:t>([^<]*)<\/a:t>/i', $xml, $matches);
                if (!empty($matches[1])) {
                    $text .= implode(' ', $matches[1]) . "\n";
                }

                $text .= "\n";
            }

            $zip->close();

            // Clean up text.
            $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            if (empty($text)) {
                throw new \Exception('No text content found in PPTX');
            }

            return $text;
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:contentextraction',
                'local_hlai_quizgen',
                '',
                'PPTX extraction failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Extract text from Excel/CSV file using native PHP.
     * NO external PHP libraries required.
     *
     * @param string $filepath Path to Excel/CSV file
     * @return string Extracted text
     */
    private static function extract_from_excel(string $filepath): string {
        try {
            $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

            // Handle CSV (native PHP).
            if ($extension === 'csv') {
                $text = '';
                if (($handle = fopen($filepath, 'r')) !== false) {
                    $rownum = 0;
                    while (($row = fgetcsv($handle)) !== false && $rownum < 500) {
                        $rownum++;
                        if ($rownum === 1) {
                            $text .= "Headers: " . implode(', ', $row) . "\n\n";
                        } else {
                            $text .= implode(' | ', $row) . "\n";
                        }
                    }
                    fclose($handle);
                }
                return trim($text);
            }

            // Handle XLSX (native PHP using ZipArchive).
            if ($extension === 'xlsx') {
                return self::extract_xlsx_native($filepath);
            }

            // XLS (old Excel format) not supported without libraries
            if ($extension === 'xls') {
                throw new \moodle_exception(
                    'error:contentextraction',
                    'local_hlai_quizgen',
                    '',
                    'XLS format not supported. Please convert to XLSX or CSV format.'
                );
            }

            throw new \moodle_exception(
                'error:contentextraction',
                'local_hlai_quizgen',
                '',
                'Unsupported spreadsheet format: ' . $extension
            );
        } catch (\moodle_exception $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:contentextraction',
                'local_hlai_quizgen',
                '',
                'Excel extraction failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Extract text from XLSX file using native PHP ZipArchive.
     *
     * @param string $filepath Path to XLSX file
     * @return string Extracted text
     */
    private static function extract_xlsx_native(string $filepath): string {
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new \Exception('Could not open XLSX file');
        }

        $text = '';

        // Get shared strings (where actual text is stored).
        $sharedstrings = [];
        $stringsxml = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsxml) {
            preg_match_all('/<t[^>]*>([^<]*)<\/t>/i', $stringsxml, $matches);
            if (!empty($matches[1])) {
                $sharedstrings = $matches[1];
            }
        }

        // Get content from each sheet.
        for ($sheetnum = 1; $sheetnum <= 10; $sheetnum++) {
            $sheetxml = $zip->getFromName("xl/worksheets/sheet{$sheetnum}.xml");
            if ($sheetxml === false) {
                break;
            }

            $text .= "--- Sheet {$sheetnum} ---\n";

            // Extract cell values.
            preg_match_all('/<v>([^<]*)<\/v>/i', $sheetxml, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $value) {
                    // Check if it's a shared string reference.
                    if (is_numeric($value) && isset($sharedstrings[(int)$value])) {
                        $text .= $sharedstrings[(int)$value] . ' ';
                    } else {
                        $text .= $value . ' ';
                    }
                }
            }

            $text .= "\n\n";
        }

        $zip->close();

        // Clean up text.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (empty($text)) {
            throw new \Exception('No text content found in XLSX');
        }

        return $text;
    }

    /**
     * Extract content from URL.
     *
     * @param string $url URL to extract content from
     * @param int $maxlength Maximum content length (default 50000 chars)
     * @return array ['text' => string, 'word_count' => int, 'title' => string]
     * @throws \moodle_exception If extraction fails
     */
    public static function extract_from_url(string $url, int $maxlength = 50000): array {
        // Validate URL.
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \moodle_exception('error:invalidurl', 'local_hlai_quizgen');
        }

        try {
            // Use Moodle's curl wrapper.
            require_once(dirname(__DIR__, 3) . '/lib/filelib.php');

            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_TIMEOUT' => 30,
                'CURLOPT_FOLLOWLOCATION' => true,
                'CURLOPT_MAXREDIRS' => 5,
                'CURLOPT_SSL_VERIFYPEER' => true,
            ]);

            $content = $curl->get($url);

            if (empty($content)) {
                throw new \Exception('Empty response from URL');
            }

            // Extract title.
            $title = '';
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $content, $matches)) {
                $title = trim(strip_tags($matches[1]));
            }

            // Remove script and style tags.
            $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
            $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);

            // Strip all HTML tags.
            $text = strip_tags($content);

            // Clean up whitespace.
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            // Limit length.
            if (strlen($text) > $maxlength) {
                $text = substr($text, 0, $maxlength) . '... (content truncated)';
            }

            $wordcount = str_word_count($text);

            return [
                'text' => $text,
                'word_count' => $wordcount,
                'title' => $title ?: parse_url($url, PHP_URL_HOST),
            ];
        } catch (\Exception $e) {
            throw new \moodle_exception('error:urlextraction', 'local_hlai_quizgen', '', 'Failed to extract content from URL: ' . $e->getMessage());
        }
    }

    /**
     * Extract content from Moodle activity.
     *
     * @param int $cmid Course module ID
     * @param string $moduletype Module type (page, book, lesson, etc.)
     * @param int $courseid Course ID
     * @return array ['text' => string, 'name' => string, 'word_count' => int]
     * @throws \moodle_exception If extraction fails
     */
    public static function extract_from_activity(int $cmid, string $moduletype, int $courseid): array {
        global $DB;

        $cm = get_coursemodule_from_id($moduletype, $cmid, $courseid);
        if (!$cm) {
            throw new \moodle_exception('error:invalidactivity', 'local_hlai_quizgen');
        }

        $text = '';
        $name = $cm->name;

        switch ($moduletype) {
            case 'page':
                $text = self::extract_from_page($cm);
                break;
            case 'book':
                $text = self::extract_from_book($cm);
                break;
            case 'lesson':
                $text = self::extract_from_lesson($cm);
                break;
            case 'resource':
                $text = self::extract_from_resource($cm);
                break;
            case 'url':
                $text = self::extract_from_url_activity($cm);
                break;
            case 'folder':
                $text = self::extract_from_folder_activity($cm);
                break;
            case 'scorm':
                $text = self::extract_from_scorm($cm);
                break;
            case 'forum':
                $text = self::extract_from_forum($cm);
                break;
            case 'label':
                $text = self::extract_from_label($cm);
                break;
            default:
                throw new \moodle_exception('error:unsupportedmodule', 'local_hlai_quizgen', '', $moduletype);
        }

        $wordcount = str_word_count($text);

        return [
            'text' => $text,
            'name' => $name,
            'word_count' => $wordcount,
        ];
    }

    /**
     * Extract text from Page activity.
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_page(\stdClass $cm): string {
        global $DB;

        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

        $text = "# " . $page->name . "\n\n";
        if (!empty($page->intro)) {
            $text .= self::html_to_structured_text($page->intro) . "\n\n";
        }
        $text .= self::html_to_structured_text($page->content);

        return $text;
    }

    /**
     * Extract text from Book activity.
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_book(\stdClass $cm): string {
        global $DB;

        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $chapters = $DB->get_records('book_chapters', ['bookid' => $book->id], 'pagenum ASC');

        $text = "# " . $book->name . "\n\n";
        if (!empty($book->intro)) {
            $text .= self::html_to_structured_text($book->intro) . "\n\n";
        }

        foreach ($chapters as $chapter) {
            if (!$chapter->hidden) {
                $text .= "\n\n## Chapter: " . $chapter->title . "\n\n";
                $text .= self::html_to_structured_text($chapter->content) . "\n";
            }
        }

        return $text;
    }

    /**
     * Extract text from Lesson activity.
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_lesson(\stdClass $cm): string {
        global $DB;

        $lesson = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);
        $pages = $DB->get_records('lesson_pages', ['lessonid' => $lesson->id], 'prevpageid ASC');

        $text = "# " . $lesson->name . "\n\n";
        if (!empty($lesson->intro)) {
            $text .= self::html_to_structured_text($lesson->intro) . "\n\n";
        }

        foreach ($pages as $page) {
            $text .= "\n\n## " . $page->title . "\n\n";
            $text .= self::html_to_structured_text($page->contents) . "\n";
        }

        return $text;
    }

    /**
     * Extract text from Resource (file).
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_resource(\stdClass $cm): string {
        global $DB;

        $resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);

        // Get file from file storage.
        $fs = get_file_storage();
        $context = \context_module::instance($cm->id);
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);

        if (empty($files)) {
            return '';
        }

        $file = reset($files);
        $filepath = $file->copy_content_to_temp();
        $originalfilename = $file->get_filename();

        try {
            $result = self::extract_from_file($filepath, $originalfilename);
            unlink($filepath);  // Clean up temp file.
            return $result['text'];
        } catch (\Exception $e) {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            // Return empty string to allow other activities to be processed.
            return '';
        }
    }

    /**
     * Extract text from URL activity.
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_url_activity(\stdClass $cm): string {
        global $DB;

        $url = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);

        // Get URL details.
        $text = '';

        if (!empty($url->name)) {
            $text .= "# " . $url->name . "\n\n";
        }

        if (!empty($url->intro)) {
            $text .= self::html_to_structured_text($url->intro) . "\n\n";
        }

        if (!empty($url->externalurl)) {
            $text .= "URL: " . $url->externalurl . "\n\n";

            // Try to fetch and extract content from the URL if possible.
            try {
                $content = self::fetch_url_content($url->externalurl);
                if (!empty($content)) {
                    $text .= $content;
                }
            } catch (\Exception $e) {
                // If URL fetch fails, continue with what we have.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $text;
    }

    /**
     * Extract text from Folder activity.
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_folder_activity(\stdClass $cm): string {
        global $DB;

        $folder = $DB->get_record('folder', ['id' => $cm->instance], '*', MUST_EXIST);

        $text = '';

        if (!empty($folder->name)) {
            $text .= "# " . $folder->name . "\n\n";
        }

        if (!empty($folder->intro)) {
            $text .= self::html_to_structured_text($folder->intro) . "\n\n";
        }

        // Get files from folder.
        $fs = get_file_storage();
        $context = \context_module::instance($cm->id);
        $files = $fs->get_area_files($context->id, 'mod_folder', 'content', 0, 'sortorder, filepath, filename', false);

        if (!empty($files)) {
            foreach ($files as $file) {
                $filename = $file->get_filename();
                $text .= "## File: " . $filename . "\n\n";

                // Try to extract content from supported file types.
                $filepath = $file->copy_content_to_temp();
                $originalfilename = $file->get_filename();

                try {
                    $result = self::extract_from_file($filepath, $originalfilename);
                    $text .= $result['text'] . "\n\n";
                    unlink($filepath);
                } catch (\Exception $e) {
                    // If extraction fails for this file, skip it and continue.
                    if (file_exists($filepath)) {
                        unlink($filepath);
                    }
                }
            }
        }

        return $text;
    }

    /**
     * Fetch and extract text content from a URL.
     *
     * @param string $url URL to fetch
     * @return string Extracted text content
     */
    private static function fetch_url_content(string $url): string {
        // Basic URL validation.
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        // Only fetch from HTTP/HTTPS URLs.
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            return '';
        }

        // Use Moodle's curl wrapper for security.
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 10, 'CURLOPT_FOLLOWLOCATION' => true]);

        $content = $curl->get($url);

        if (empty($content)) {
            return '';
        }

        // Extract text from HTML.
        $text = self::html_to_structured_text($content);

        return $text;
    }

    /**
     * Clean and normalize extracted text.
     *
     * @param string $text Raw text
     * @return string Cleaned text
     */
    private static function clean_text(string $text): string {
        // Remove excessive whitespace.
        $text = preg_replace('/\s+/', ' ', $text);

        // Remove multiple line breaks.
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Trim.
        $text = trim($text);

        return $text;
    }

    /**
     * Validate file upload.
     *
     * @param array $file File from $_FILES
     * @return bool True if valid
     * @throws \moodle_exception If validation fails
     */
    public static function validate_file_upload(array $file): bool {
        $maxsize = get_config('local_hlai_quizgen', 'max_file_size_mb') ?: 50;
        $maxbytes = $maxsize * 1024 * 1024;

        if ($file['size'] > $maxbytes) {
            throw new \moodle_exception('error:filetoobig', 'local_hlai_quizgen', '', $maxsize);
        }

        $allowedextensions = ['pdf', 'docx', 'pptx', 'txt'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedextensions)) {
            throw new \moodle_exception('error:invalidfiletype', 'local_hlai_quizgen');
        }

        return true;
    }

    /**
     * Extract content from multiple course module IDs.
     *
     * @param int $courseid Course ID
     * @param array $cmids Array of course module IDs
     * @return string Combined extracted text
     */
    public static function extract_from_activities(int $courseid, array $cmids): string {
        global $DB;

        if (empty($cmids)) {
            return '';
        }

        $allcontent = '';

        foreach ($cmids as $cmid) {
            try {
                // Get module type.
                $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', IGNORE_MISSING);
                if (!$cm) {
                    continue;
                }

                $module = $DB->get_record('modules', ['id' => $cm->module], '*', MUST_EXIST);
                $moduletype = $module->name;

                // Extract content.
                $result = self::extract_from_activity($cmid, $moduletype, $courseid);

                // Use clear, descriptive activity markers with actual names (not generic module types).
                // This helps the AI understand what the content is about and use proper names.
                $activityname = $result['name'];
                $modulelabel = ucfirst($moduletype);

                // CRITICAL: Mark with actual activity name prominently so AI uses it as topic title.
                // Format: === TOPIC: [Activity Name] ([Type]) ===.
                $allcontent .= "\n\n=== TOPIC: {$activityname} ({$modulelabel}) ===\n";
                $allcontent .= "Activity Name: {$activityname}\n";
                $allcontent .= "Activity Type: {$modulelabel}\n";
                $allcontent .= "---\n";
                $allcontent .= $result['text'];
                $allcontent .= "\n=== END TOPIC ===\n";
            } catch (\Exception $e) {
                // Skip activities that fail to extract.
                continue;
            }
        }

        return $allcontent;
    }

    /**
     * Extract content from SCORM package.
     *
     * Extracts text from SCORM packages by:
     * 1. Locating the SCORM package ZIP file
     * 2. Parsing imsmanifest.xml for content structure
     * 3. Extracting text from HTML files
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_scorm(\stdClass $cm): string {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/scorm/lib.php');

        $text = '';

        try {
            // Get SCORM instance.
            $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);

            // Get SCORM package directory.
            $fs = get_file_storage();
            $context = \context_module::instance($cm->id);

            // Get package file.
            $files = $fs->get_area_files($context->id, 'mod_scorm', 'package', 0, 'itemid, filepath, filename', false);

            if (empty($files)) {
                return '';
            }

            $packagefile = reset($files);

            // Create temp directory.
            $tempdir = make_temp_directory('hlai_scorm_' . $cm->id);
            $zipfile = $tempdir . '/package.zip';

            // Save package to temp file.
            $packagefile->copy_content_to($zipfile);

            // Extract ZIP.
            $extractdir = $tempdir . '/extracted';
            if (!mkdir($extractdir, 0777, true)) {
                return '';
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipfile) === true) {
                $zip->extractTo($extractdir);
                $zip->close();

                // Parse imsmanifest.xml for content files.
                $manifestfile = $extractdir . '/imsmanifest.xml';
                $contentfiles = [];

                if (file_exists($manifestfile)) {
                    $manifest = simplexml_load_file($manifestfile);
                    if ($manifest) {
                        // Extract resource hrefs from manifest.
                        $manifest->registerXPathNamespace('imscp', 'http://www.imsglobal.org/xsd/imscp_v1p1');
                        $manifest->registerXPathNamespace('adlcp', 'http://www.adlnet.org/xsd/adlcp_v1p3');

                        $resources = $manifest->xpath('//resource[@href]');
                        foreach ($resources as $resource) {
                            $href = (string)$resource['href'];
                            if (!empty($href)) {
                                $contentfiles[] = $href;
                            }
                        }

                        // Also check for file elements.
                        $files = $manifest->xpath('//file[@href]');
                        foreach ($files as $file) {
                            $href = (string)$file['href'];
                            if (!empty($href) && (strpos($href, '.html') !== false || strpos($href, '.htm') !== false)) {
                                $contentfiles[] = $href;
                            }
                        }
                    }
                }

                // If no manifest or no files found, scan for HTML files.
                if (empty($contentfiles)) {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($extractdir)
                    );
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $ext = strtolower($file->getExtension());
                            if ($ext === 'html' || $ext === 'htm') {
                                $relativepath = str_replace($extractdir . '/', '', $file->getPathname());
                                $contentfiles[] = $relativepath;
                            }
                        }
                    }
                }

                // Extract text from HTML files.
                foreach ($contentfiles as $contentfile) {
                    $filepath = $extractdir . '/' . $contentfile;
                    if (file_exists($filepath) && is_file($filepath)) {
                        $htmlcontent = file_get_contents($filepath);

                        // Strip HTML tags and extract text.
                        $plaintext = strip_tags($htmlcontent);
                        // Clean up whitespace.
                        $plaintext = preg_replace('/\s+/', ' ', $plaintext);
                        $plaintext = trim($plaintext);

                        if (!empty($plaintext)) {
                            $text .= $plaintext . "\n\n";
                        }
                    }
                }

                // Clean up temp directory.
                self::delete_directory($tempdir);
            } else {
                self::delete_directory($tempdir);
                return '';
            }
        } catch (\Exception $e) {
            // SCORM extraction failed - return whatever text we have.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }

        return $text;
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir Directory path
     * @return bool Success
     */
    private static function delete_directory(string $dir): bool {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!self::delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    /**
     * Extract text from Forum activity.
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_forum(\stdClass $cm): string {
        global $DB;

        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);

        $text = "# " . $forum->name . "\n\n";
        if (!empty($forum->intro)) {
            $text .= self::html_to_structured_text($forum->intro) . "\n\n";
        }

        // Get forum discussions and posts (limit to avoid too much content).
        $discussions = $DB->get_records('forum_discussions', ['forum' => $forum->id], 'timemodified DESC', '*', 0, 10);

        foreach ($discussions as $discussion) {
            $text .= "\n\n## Discussion: " . $discussion->name . "\n\n";

            // Get first post of each discussion.
            $posts = $DB->get_records('forum_posts', ['discussion' => $discussion->id], 'created ASC', '*', 0, 5);
            foreach ($posts as $post) {
                if (!empty($post->message)) {
                    $text .= self::html_to_structured_text($post->message) . "\n";
                }
            }
            $text .= "\n";
        }

        return $text;
    }

    /**
     * Extract text from Label activity.
     *
     * @param \stdClass $cm Course module object
     * @return string Extracted text
     */
    private static function extract_from_label(\stdClass $cm): string {
        global $DB;

        $label = $DB->get_record('label', ['id' => $cm->instance], '*', MUST_EXIST);

        $text = '';
        if (!empty($label->intro)) {
            $text = self::html_to_structured_text($label->intro);
        }

        return $text;
    }

    /**
     * Convert HTML to structured text preserving headings.
     *
     * Converts HTML content to text while preserving structural elements:
     * - H1-H6 tags become markdown-style headings (# Heading, ## Subheading)
     * - Lists are preserved with bullet points or numbers
     * - Paragraphs maintain separation
     * - Strong/bold text is emphasized
     *
     * @param string $html HTML content
     * @return string Structured text with markdown-style formatting
     */
    private static function html_to_structured_text(string $html): string {
        if (empty($html)) {
            return '';
        }

        // Convert headings to markdown style.
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n\n# $1\n\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n\n## $1\n\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n\n### $1\n\n", $html);
        $html = preg_replace('/<h4[^>]*>(.*?)<\/h4>/is', "\n\n#### $1\n\n", $html);
        $html = preg_replace('/<h5[^>]*>(.*?)<\/h5>/is', "\n\n##### $1\n\n", $html);
        $html = preg_replace('/<h6[^>]*>(.*?)<\/h6>/is', "\n\n###### $1\n\n", $html);

        // Convert lists.
        $html = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $html);
        $html = preg_replace('/<\/ul>|<\/ol>/is', "\n", $html);

        // Convert paragraphs.
        $html = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html);

        // Convert line breaks.
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Preserve strong/bold as emphasis.
        $html = preg_replace('/<(strong|b)[^>]*>(.*?)<\/\1>/is', "**$2**", $html);

        // Remove remaining HTML tags.
        $text = strip_tags($html);

        // Clean up whitespace.
        $text = preg_replace('/\n{4,}/', "\n\n\n", $text);
        $text = preg_replace('/ +/', ' ', $text);

        return trim($text);
    }
}
