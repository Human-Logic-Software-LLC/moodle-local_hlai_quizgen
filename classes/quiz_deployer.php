<?php
// This file is part of Moodle - http://moodle.org/.
//
// Moodle is free software: you can redistribute it and/or modify.
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,.
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the.
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License.
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Quiz deployer page.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 STARTER
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Quiz deployment engine for the AI Quiz Generator plugin.
 *
 * Handles deployment of generated questions to:
 * - Question bank
 * - New quiz activities
 * - Existing quiz activities
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Quiz deployer class.
 */
class quiz_deployer {
    /**
     * Deploy questions to question bank.
     *
     * @param array $questionids Array of question IDs to deploy
     * @param int $courseid Course ID
     * @param string $categoryname Category name (optional)
     * @return array Array of deployed question IDs in question bank
     * @throws \moodle_exception If deployment fails
     */
    public static function deploy_to_question_bank(array $questionids, int $courseid, string $categoryname = null): array {
        global $DB, $USER;

        debugging("DEBUG deploy_to_question_bank: Starting with " . count($questionids) . " questions, courseid=$courseid", DEBUG_DEVELOPER);

        $context = \context_course::instance($courseid);
        require_capability('moodle/question:add', $context);

        // Create or get question category.
        try {
            $category = self::get_or_create_category($courseid, $categoryname);
            debugging("DEBUG deploy_to_question_bank: Got/created category ID: " . $category->id, DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:deployment',
                'local_hlai_quizgen',
                '',
                "Failed to create/get question category: " . $e->getMessage()
            );
        }

        $deployedids = [];
        $errors = [];
        $questionnumber = 1;

        foreach ($questionids as $questionid) {
            try {
                debugging("DEBUG deploy_to_question_bank: Processing question ID: $questionid", DEBUG_DEVELOPER);

                // Get question from our table.
                $genquestion = $DB->get_record('hlai_quizgen_questions', ['id' => $questionid], '*', MUST_EXIST);
                debugging("DEBUG deploy_to_question_bank: Loaded genquestion, type=" . ($genquestion->questiontype ?? 'null'), DEBUG_DEVELOPER);

                // Convert to Moodle question format.
                $moodlequestionid = self::convert_to_moodle_question($genquestion, $category->id, $category->name, $questionnumber);
                $questionnumber++;

                debugging("DEBUG deploy_to_question_bank: Created Moodle question ID: $moodlequestionid", DEBUG_DEVELOPER);

                // Update our record with Moodle question ID.
                $DB->set_field('hlai_quizgen_questions', 'moodle_questionid', $moodlequestionid, ['id' => $questionid]);
                $DB->set_field('hlai_quizgen_questions', 'status', 'deployed', ['id' => $questionid]);
                $DB->set_field('hlai_quizgen_questions', 'timedeployed', time(), ['id' => $questionid]);

                $deployedids[] = $moodlequestionid;

                // Log deployment.
                try {
                    api::log_action('question_deployed', $genquestion->requestid, $USER->id, [
                        'questionid' => $questionid,
                        'moodle_questionid' => $moodlequestionid,
                        'categoryid' => $category->id,
                    ]);
                } catch (\Exception $logex) {
                    // Logging failure shouldn't stop deployment.
                    debugging("DEBUG deploy_to_question_bank: Warning - log_action failed: " . $logex->getMessage(), DEBUG_DEVELOPER);
                }
            } catch (\Exception $e) {
                $errormsg = "Question $questionid: " . $e->getMessage();
                debugging("DEBUG deploy_to_question_bank: ERROR - $errormsg", DEBUG_DEVELOPER);
                $errors[] = $errormsg;
            }
        }

        debugging("DEBUG deploy_to_question_bank: Completed. Deployed: " . count($deployedids) . ", Errors: " . count($errors), DEBUG_DEVELOPER);

        // If no questions were deployed successfully, throw an exception with details.
        if (empty($deployedids)) {
            $errormsg = 'No questions could be deployed to question bank.';
            if (!empty($errors)) {
                $errormsg .= ' Errors: ' . implode('; ', $errors);
            }
            throw new \moodle_exception('error:deployment', 'local_hlai_quizgen', '', $errormsg);
        }

        return $deployedids;
    }

    /**
     * Create a new quiz activity with questions.
     *
     * @param array $questionids Array of question IDs
     * @param int $courseid Course ID
     * @param string $quizname Quiz name
     * @param array $settings Quiz settings (optional)
     * @return int Quiz course module ID
     * @throws \moodle_exception If creation fails
     */
    public static function create_quiz(array $questionids, int $courseid, string $quizname, array $settings = []): int {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot . '/course/lib.php');

        $context = \context_course::instance($courseid);
        require_capability('mod/quiz:addinstance', $context);

        // First deploy questions to question bank.
        $categoryname = $quizname . ' - AI Generated Questions';
        $moodlequestionids = self::deploy_to_question_bank($questionids, $courseid, $categoryname);

        if (empty($moodlequestionids)) {
            throw new \moodle_exception('error:deployment', 'local_hlai_quizgen', '', 'No questions deployed');
        }

        // Create quiz instance.
        $quiz = new \stdClass();
        $quiz->course = $courseid;
        $quiz->name = $quizname;
        $quiz->intro = $settings['intro'] ?? 'AI-generated quiz';
        $quiz->introformat = FORMAT_HTML;
        $quiz->timeopen = $settings['timeopen'] ?? 0;
        $quiz->timeclose = $settings['timeclose'] ?? 0;
        $quiz->timelimit = $settings['timelimit'] ?? 0;
        $quiz->overduehandling = 'autosubmit';
        $quiz->graceperiod = 0;
        $quiz->preferredbehaviour = 'deferredfeedback';
        $quiz->canredoquestions = 0;
        $quiz->attempts = $settings['attempts'] ?? 1;
        $quiz->attemptonlast = 0;
        $quiz->grademethod = QUIZ_GRADEHIGHEST;
        $quiz->decimalpoints = 2;
        $quiz->questiondecimalpoints = -1;
        $quiz->reviewattempt = 0x11110;
        $quiz->reviewcorrectness = 0x11110;
        $quiz->reviewmarks = 0x11110;
        $quiz->reviewspecificfeedback = 0x11110;
        $quiz->reviewgeneralfeedback = 0x11110;
        $quiz->reviewrightanswer = 0x11110;
        $quiz->reviewoverallfeedback = 0x11110;
        $quiz->questionsperpage = $settings['questionsperpage'] ?? 1;
        $quiz->navmethod = 'free';
        $quiz->shuffleanswers = $settings['shuffleanswers'] ?? 1;
        $quiz->sumgrades = 0;
        $quiz->grade = $settings['grade'] ?? 100;
        $quiz->timecreated = time();
        $quiz->timemodified = time();
        $quiz->password = '';
        $quiz->subnet = '';
        $quiz->browsersecurity = '-';
        $quiz->delay1 = 0;
        $quiz->delay2 = 0;
        $quiz->showuserpicture = 0;
        $quiz->showblocks = 0;
        $quiz->completionattemptsexhausted = 0;
        $quiz->completionpass = 0;

        $quizid = $DB->insert_record('quiz', $quiz);
        $quiz->id = $quizid;

        // Create course module.
        $moduleinfo = new \stdClass();
        $moduleinfo->course = $courseid;
        $moduleinfo->module = $DB->get_field('modules', 'id', ['name' => 'quiz']);
        $moduleinfo->instance = $quizid;
        $moduleinfo->section = 0;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;

        $cmid = add_course_module($moduleinfo);

        $moduleinfo->coursemodule = $cmid;
        $sectionid = course_add_cm_to_section($courseid, $cmid, 0);

        $DB->set_field('course_modules', 'section', $sectionid, ['id' => $cmid]);

        // CRITICAL FIX: Create quiz_sections entry (required for Moodle 4.x).
        $quizsection = new \stdClass();
        $quizsection->quizid = $quizid;
        $quizsection->firstslot = 1;
        $quizsection->heading = '';
        $quizsection->shufflequestions = 0;

        $DB->insert_record('quiz_sections', $quizsection);

        // Add questions to quiz.
        $addedcount = self::add_questions_to_quiz($quizid, $moodlequestionids);

        // Rebuild course cache.
        rebuild_course_cache($courseid, true);

        return $cmid;
    }

    /**
     * Add questions to existing quiz.
     *
     * @param array $questionids Array of question IDs
     * @param int $quizid Quiz ID
     * @return int Number of questions added
     * @throws \moodle_exception If addition fails
     */
    public static function add_to_existing_quiz(array $questionids, int $quizid): int {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quizid, $quiz->course);
        $context = \context_module::instance($cm->id);

        require_capability('mod/quiz:manage', $context);

        // Deploy to question bank first.
        $categoryname = $quiz->name . ' - AI Generated Questions';
        $moodlequestionids = self::deploy_to_question_bank($questionids, $quiz->course, $categoryname);

        // Add to quiz.
        $added = self::add_questions_to_quiz($quizid, $moodlequestionids);

        return $added;
    }

    /**
     * Get or create question category.
     *
     * @param int $courseid Course ID
     * @param string $name Category name
     * @return \stdClass Category object
     */
    private static function get_or_create_category(int $courseid, string $name = null): \stdClass {
        global $DB, $CFG;

        require_once($CFG->libdir . '/questionlib.php');

        // Get the course context and validate it exists.
        $context = \context_course::instance($courseid);

        // Ensure context is valid and has a proper ID.
        if (empty($context->id) || $context->id <= 0) {
            throw new \moodle_exception(
                'error:deployment',
                'local_hlai_quizgen',
                '',
                "Invalid course context for course ID: $courseid"
            );
        }

        // Verify the context record exists in the database.
        $contextrecord = $DB->get_record('context', ['id' => $context->id]);
        if (!$contextrecord) {
            // Context doesn't exist - this shouldn't happen but let's handle it.
            // Force context rebuild for this course.
            \context_helper::build_all_paths(true);
            $context = \context_course::instance($courseid, MUST_EXIST);
        }

        debugging("DEBUG get_or_create_category: Course $courseid, Context ID: " . $context->id, DEBUG_DEVELOPER);

        if (empty($name)) {
            $name = 'AI Generated Questions - ' . date('Y-m-d H:i:s');
        }

        // Check if category exists.
        $category = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'name' => $name,
        ]);

        if ($category) {
            return $category;
        }

        // Get or create top category for this context (fixes duplicate top category issue).
        // Use get_records to handle potential duplicates, then take the first one.
        $topcategories = $DB->get_records('question_categories', [
            'contextid' => $context->id,
            'parent' => 0,
        ], 'id ASC', '*', 0, 1);

        if (!empty($topcategories)) {
            $topcategory = reset($topcategories);
        } else {
            // Create top category using Moodle's question_get_default_category if available,.
            // Otherwise create it manually for course context.
            // Note: question_get_default_category creates a "Default for" category.
            $topcategory = question_get_default_category($context->id);
            if (!$topcategory) {
                // Create top category manually for course context.
                $topcategory = new \stdClass();
                $topcategory->name = 'top';
                $topcategory->contextid = $context->id;
                $topcategory->info = '';
                $topcategory->infoformat = FORMAT_MOODLE;
                $topcategory->stamp = make_unique_id_code();
                $topcategory->parent = 0;
                $topcategory->sortorder = 0;
                $topcategory->idnumber = null;
                $topcategory->id = $DB->insert_record('question_categories', $topcategory);

                debugging("DEBUG: Created top category for course context, ID: " . $topcategory->id, DEBUG_DEVELOPER);
            }
        }

        // Create new category as child of top category.
        $category = new \stdClass();
        $category->name = $name;
        $category->contextid = $context->id;
        $category->info = 'Questions generated by AI Quiz Generator';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->parent = $topcategory->id;  // Set parent to avoid creating orphan top-level categories.
        $category->sortorder = 999;
        $category->idnumber = null;  // FIX: Add idnumber field for Moodle 4.1.9+ compatibility.

        $category->id = $DB->insert_record('question_categories', $category);

        // Log important info for debugging.
        debugging("DEBUG get_or_create_category: Created new category:", DEBUG_DEVELOPER);
        debugging("  - Category ID: " . $category->id, DEBUG_DEVELOPER);
        debugging("  - Category Name: " . $category->name, DEBUG_DEVELOPER);
        debugging("  - Context ID: " . $category->contextid . " (COURSE context for course $courseid)", DEBUG_DEVELOPER);
        debugging("  - Parent Category ID: " . $category->parent, DEBUG_DEVELOPER);
        debugging("  - NOTE: Look for this category in the COURSE question bank, not the QUIZ question bank!", DEBUG_DEVELOPER);

        return $category;
    }

    /**
     * Convert generated question to Moodle question format.
     *
     * @param \stdClass $genquestion Generated question object
     * @param int $categoryid Question category ID
     * @param string $categoryname Category name for all questions
     * @param int $questionnumber Question number for unique identification
     * @return int Moodle question ID
     * @throws \moodle_exception If conversion fails
     */
    private static function convert_to_moodle_question(\stdClass $genquestion, int $categoryid, string $categoryname = '', int $questionnumber = 1): int {
        global $DB, $USER, $CFG;

        // DEBUG: Log the start of question conversion.
        debugging(
            "DEBUG: Starting convert_to_moodle_question for genquestion ID: " .
            ($genquestion->id ?? 'unknown') . ", type: " . ($genquestion->questiontype ?? 'unknown'),
            DEBUG_DEVELOPER
        );

        // Check Moodle version to determine which fields to use.
        // Moodle 4.0+ removed 'category' from question table, uses question_bank_entries instead.
        $moodleversion = $CFG->version ?? 0;
        $ismoodle4plus = ($moodleversion >= 2022041900); // Moodle 4.0 release version.

        // Get actual columns in the question table to ensure compatibility.
        $questioncolumns = $DB->get_columns('question');
        $hascategorycolumn = isset($questioncolumns['category']);
        $hashiddencolumn = isset($questioncolumns['hidden']);

        debugging("DEBUG: Moodle version: $moodleversion, is4plus: " . ($ismoodle4plus ? 'yes' : 'no') .
                  ", has category column: " . ($hascategorycolumn ? 'yes' : 'no') .
                  ", has hidden column: " . ($hashiddencolumn ? 'yes' : 'no'), DEBUG_DEVELOPER);

        // Base question record.
        $question = new \stdClass();

        // Only set category if the column exists (pre-Moodle 4.0).
        if ($hascategorycolumn) {
            $question->category = $categoryid;
        }

        $question->parent = 0;

        // Format question name: Extract clean text snippet for better readability.
        $questionsnippet = strip_tags($genquestion->questiontext ?? '');
        $questionsnippet = preg_replace('/\s+/', ' ', $questionsnippet); // Remove extra whitespace
        $questionsnippet = trim($questionsnippet);

        if (!empty($categoryname)) {
            // Remove " - AI Generated Questions" suffix for cleaner display.
            $cleanname = str_replace(' - AI Generated Questions', '', $categoryname);
            // Format: "Quiz Name: Q1 - Question snippet".
            $question->name = $cleanname . ': Q' . $questionnumber . ' - ' . substr($questionsnippet, 0, 60);
        } else {
            $question->name = substr($questionsnippet, 0, 100) . '...';
        }
        $question->questiontext = $genquestion->questiontext ?? '';
        $question->questiontextformat = FORMAT_HTML;

        // Ensure generalfeedback is a string (AI may return array in some cases).
        $generalfeedback = $genquestion->generalfeedback ?? '';
        if (is_array($generalfeedback)) {
            $generalfeedback = json_encode($generalfeedback);
        }
        $question->generalfeedback = (string) $generalfeedback;
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 1.0;
        $question->penalty = $genquestion->penalty ?? 0.3333333;

        // Map scenario type to essay for Moodle compatibility.
        $question->qtype = ($genquestion->questiontype === 'scenario') ? 'essay' : $genquestion->questiontype;
        $question->length = 1;
        $question->stamp = make_unique_id_code();

        // Only set hidden if the column exists.
        if ($hashiddencolumn) {
            $question->hidden = 0;
        }

        $question->timecreated = time();
        $question->timemodified = time();
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;

        // DEBUG: Log question object before insert.
        debugging("DEBUG: Inserting into 'question' table. Fields: " . json_encode(array_keys((array)$question)), DEBUG_DEVELOPER);

        try {
            $questionid = $DB->insert_record('question', $question);
            debugging("DEBUG: Successfully inserted question, ID: $questionid", DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:deployment',
                'local_hlai_quizgen',
                '',
                "STEP 1 FAILED - Insert into 'question' table failed: " . $e->getMessage() .
                " | Question data: " . json_encode($question)
            );
        }

        // Create question bank entry and version for Moodle 4.x.
        $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);

        $qbentry = new \stdClass();
        $qbentry->questioncategoryid = $categoryid;
        $qbentry->idnumber = null;
        $qbentry->ownerid = $USER->id;

        try {
            $entryid = $DB->insert_record('question_bank_entries', $qbentry);
            debugging("DEBUG: Successfully inserted question_bank_entries, ID: $entryid", DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:deployment',
                'local_hlai_quizgen',
                '',
                "STEP 2 FAILED - Insert into 'question_bank_entries' table failed: " . $e->getMessage() .
                " | Entry data: " . json_encode($qbentry)
            );
        }

        $qversion = new \stdClass();
        $qversion->questionbankentryid = $entryid;
        $qversion->version = 1;
        $qversion->questionid = $questionid;
        $qversion->status = 'ready';

        try {
            $DB->insert_record('question_versions', $qversion);
            debugging("DEBUG: Successfully inserted question_versions", DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:deployment',
                'local_hlai_quizgen',
                '',
                "STEP 3 FAILED - Insert into 'question_versions' table failed: " . $e->getMessage() .
                " | Version data: " . json_encode($qversion)
            );
        }

        // Type-specific data.
        try {
            debugging("DEBUG: Adding type-specific data for type: " . $genquestion->questiontype, DEBUG_DEVELOPER);
            switch ($genquestion->questiontype) {
                case 'multichoice':
                    self::add_multichoice_data($questionid, $genquestion);
                    break;
                case 'truefalse':
                    self::add_truefalse_data($questionid, $genquestion);
                    break;
                case 'shortanswer':
                    self::add_shortanswer_data($questionid, $genquestion);
                    break;
                case 'matching':
                    self::add_matching_data($questionid, $genquestion);
                    break;
                case 'essay':
                case 'scenario':
                    // Scenario questions are treated as essay questions in Moodle.
                    self::add_essay_data($questionid, $genquestion);
                    break;
                default:
                    debugging("DEBUG: Unknown question type: " . $genquestion->questiontype, DEBUG_DEVELOPER);
            }
            debugging("DEBUG: Successfully added type-specific data", DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:deployment',
                'local_hlai_quizgen',
                '',
                "STEP 4 FAILED - Adding type-specific data for '" . $genquestion->questiontype . "' failed: " . $e->getMessage()
            );
        }

        // Add question tags for better organization and filtering.
        try {
            self::tag_question($questionid, $genquestion, $category);
            debugging("DEBUG: Successfully added tags", DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            // Tags are optional, log but don't fail.
            debugging("DEBUG: Warning - Failed to add tags: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // VERIFICATION: Confirm question was properly created and linked.
        $verifyq = $DB->get_record('question', ['id' => $questionid]);
        $verifyqbe = $DB->get_record_sql(
            "SELECT qbe.*, qv.status as version_status
             FROM {question_bank_entries} qbe
             JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
             WHERE qv.questionid = ?",
            [$questionid]
        );
        $verifycat = $DB->get_record('question_categories', ['id' => $categoryid]);

        debugging("DEBUG VERIFICATION: Question ID=$questionid created successfully:", DEBUG_DEVELOPER);
        debugging("  - question.category = " . ($verifyq->category ?? 'NULL'), DEBUG_DEVELOPER);
        debugging("  - question.hidden = " . ($verifyq->hidden ?? 'NULL'), DEBUG_DEVELOPER);
        debugging("  - question.qtype = " . ($verifyq->qtype ?? 'NULL'), DEBUG_DEVELOPER);
        debugging("  - bank_entry.questioncategoryid = " . ($verifyqbe->questioncategoryid ?? 'NULL'), DEBUG_DEVELOPER);
        debugging("  - bank_entry.version_status = " . ($verifyqbe->version_status ?? 'NULL'), DEBUG_DEVELOPER);
        debugging("  - category.name = " . ($verifycat->name ?? 'NULL'), DEBUG_DEVELOPER);
        debugging("  - category.contextid = " . ($verifycat->contextid ?? 'NULL'), DEBUG_DEVELOPER);
        debugging("  - category.parent = " . ($verifycat->parent ?? 'NULL'), DEBUG_DEVELOPER);

        // Also log what the user should look for.
        debugging("DEBUG: To find this question, go to Course Question Bank (not Quiz) and look for category: " . ($verifycat->name ?? 'unknown'), DEBUG_DEVELOPER);

        return $questionid;
    }

    /**
     * Tag a question with AI-generated, topic, difficulty, and Bloom's level tags.
     *
     * @param int $questionid Moodle question ID
     * @param \stdClass $genquestion Generated question object
     * @param \stdClass $category Question category object
     */
    private static function tag_question(int $questionid, \stdClass $genquestion, \stdClass $category): void {
        global $DB;

        // Validate category has a valid contextid.
        if (empty($category->contextid) || $category->contextid <= 0) {
            debugging("DEBUG tag_question: Invalid contextid in category", DEBUG_DEVELOPER);
            return;
        }

        // Get context for the question category with proper error handling.
        try {
            $context = \context::instance_by_id($category->contextid, IGNORE_MISSING);
            if (!$context) {
                debugging("DEBUG tag_question: Context not found for ID: " . $category->contextid, DEBUG_DEVELOPER);
                return;
            }
        } catch (\Exception $e) {
            debugging("DEBUG tag_question: Failed to get context: " . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }

        $tags = [];

        // 1. AI-generated tag.
        $tags[] = 'ai-generated';

        // 2. Topic tag (if available).
        if (!empty($genquestion->topicid)) {
            $topic = $DB->get_record('hlai_quizgen_topics', ['id' => $genquestion->topicid], 'title');
            if ($topic) {
                // Clean and format topic name for tag.
                $topictag = strtolower(trim($topic->title));
                $topictag = preg_replace('/[^a-z0-9\s-]/', '', $topictag);
                $topictag = preg_replace('/\s+/', '-', $topictag);
                $topictag = substr($topictag, 0, 50); // Limit length.
                if (!empty($topictag)) {
                    $tags[] = 'topic:' . $topictag;
                }
            }
        }

        // 3. Difficulty level tag.
        if (!empty($genquestion->difficulty)) {
            $tags[] = 'difficulty:' . strtolower($genquestion->difficulty);
        }

        // 4. Bloom's taxonomy level tag.
        if (!empty($genquestion->blooms_level)) {
            $bloomslevel = strtolower($genquestion->blooms_level);
            $tags[] = 'blooms:' . $bloomslevel;

            // Also add cognitive domain category.
            $cognitivedomain = self::get_cognitive_domain($bloomslevel);
            if ($cognitivedomain) {
                $tags[] = 'cognitive:' . $cognitivedomain;
            }
        }

        // 5. Question type tag.
        if (!empty($genquestion->questiontype)) {
            $tags[] = 'qtype:' . $genquestion->questiontype;
        }

        // Apply tags to the question.
        \core_tag_tag::set_item_tags('core_question', 'question', $questionid, $context, $tags);
    }

    /**
     * Get cognitive domain for Bloom's level.
     *
     * @param string $bloomslevel Bloom's taxonomy level
     * @return string|null Cognitive domain (lower, middle, higher) or null
     */
    private static function get_cognitive_domain(string $bloomslevel): ?string {
        $domains = [
            'remember' => 'lower',
            'understand' => 'lower',
            'apply' => 'middle',
            'analyze' => 'middle',
            'evaluate' => 'higher',
            'create' => 'higher',
        ];

        return $domains[$bloomslevel] ?? null;
    }

    /**
     * Add multichoice question data.
     *
     * @param int $questionid Question ID
     * @param \stdClass $genquestion Generated question
     */
    private static function add_multichoice_data(int $questionid, \stdClass $genquestion): void {
        global $DB;

        // Add multichoice options.
        $options = new \stdClass();
        $options->questionid = $questionid;
        $options->layout = 0;  // Vertical.
        $options->single = 1;  // Single answer.
        $options->shuffleanswers = 1;
        $options->correctfeedback = '';
        $options->correctfeedbackformat = FORMAT_HTML;
        $options->partiallycorrectfeedback = '';
        $options->partiallycorrectfeedbackformat = FORMAT_HTML;
        $options->incorrectfeedback = '';
        $options->incorrectfeedbackformat = FORMAT_HTML;
        $options->answernumbering = 'abc';
        $options->showstandardinstruction = 0;

        $DB->insert_record('qtype_multichoice_options', $options);

        // Add answers.
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');
        foreach ($answers as $answer) {
            $answerrecord = new \stdClass();
            $answerrecord->question = $questionid;
            $answerrecord->answer = $answer->answer;
            $answerrecord->answerformat = FORMAT_MOODLE;
            $answerrecord->fraction = $answer->fraction;
            $answerrecord->feedback = $answer->feedback ?? '';
            $answerrecord->feedbackformat = FORMAT_MOODLE;

            $DB->insert_record('question_answers', $answerrecord);
        }
    }

    /**
     * Add true/false question data.
     *
     * @param int $questionid Question ID
     * @param \stdClass $genquestion Generated question
     */
    private static function add_truefalse_data(int $questionid, \stdClass $genquestion): void {
        global $DB;

        // Get answers and create answer records.
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');

        $trueid = 0;
        $falseid = 0;

        foreach ($answers as $answer) {
            $answerrecord = new \stdClass();
            $answerrecord->question = $questionid;
            $answerrecord->answer = $answer->answer;  // "True" or "False".
            $answerrecord->answerformat = FORMAT_MOODLE;
            $answerrecord->fraction = $answer->fraction;
            $answerrecord->feedback = $answer->feedback ?? '';
            $answerrecord->feedbackformat = FORMAT_MOODLE;

            $answerid = $DB->insert_record('question_answers', $answerrecord);

            // Track which answer is true vs false.
            if (stripos($answer->answer, 'true') !== false) {
                $trueid = $answerid;
            } else {
                $falseid = $answerid;
            }
        }

        // Create the truefalse question record.
        $tfrecord = new \stdClass();
        $tfrecord->question = $questionid;
        $tfrecord->trueanswer = $trueid;
        $tfrecord->falseanswer = $falseid;
        $tfrecord->showstandardinstruction = 1;

        $DB->insert_record('question_truefalse', $tfrecord);
    }

    /**
     * Add short answer question data.
     *
     * @param int $questionid Question ID
     * @param \stdClass $genquestion Generated question
     */
    private static function add_shortanswer_data(int $questionid, \stdClass $genquestion): void {
        global $DB;

        // Add short answer options.
        $options = new \stdClass();
        $options->questionid = $questionid;
        $options->usecase = 0;  // Case insensitive.

        $DB->insert_record('qtype_shortanswer_options', $options);

        // Add answers.
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');
        foreach ($answers as $answer) {
            $answerrecord = new \stdClass();
            $answerrecord->question = $questionid;
            $answerrecord->answer = $answer->answer;
            $answerrecord->answerformat = FORMAT_MOODLE;
            $answerrecord->fraction = $answer->fraction;
            $answerrecord->feedback = $answer->feedback ?? '';
            $answerrecord->feedbackformat = FORMAT_MOODLE;

            $DB->insert_record('question_answers', $answerrecord);
        }
    }

    /**
     * Add essay question data.
     *
     * @param int $questionid Question ID
     * @param \stdClass $genquestion Generated question
     */
    private static function add_essay_data(int $questionid, \stdClass $genquestion): void {
        global $DB;

        // Build grading criteria from general feedback and answer examples.
        $graderinfo = '';

        // Add general feedback as grading guidance.
        // FIX: Ensure generalfeedback is a string (AI may return array in some cases).
        $genfeedback = $genquestion->generalfeedback ?? '';
        if (is_array($genfeedback)) {
            $genfeedback = json_encode($genfeedback);
        }
        if (!empty($genfeedback)) {
            $graderinfo .= '<h4>Grading Guidance:</h4>';
            $graderinfo .= '<p>' . (string) $genfeedback . '</p>';
        }

        // Get sample answers/criteria from answers table.
        $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');
        if (!empty($answers)) {
            $graderinfo .= '<h4>Expected Content / Grading Criteria:</h4>';
            $graderinfo .= '<ul>';
            foreach ($answers as $answer) {
                if (!empty($answer->answer)) {
                    $graderinfo .= '<li><strong>Key Point:</strong> ' . htmlspecialchars($answer->answer);
                    if (!empty($answer->feedback)) {
                        $graderinfo .= '<br><em>Note: ' . htmlspecialchars($answer->feedback) . '</em>';
                    }
                    $graderinfo .= '</li>';
                }
            }
            $graderinfo .= '</ul>';
        }

        // Add essay options.
        $options = new \stdClass();
        $options->questionid = $questionid;
        $options->responseformat = 'editor';
        $options->responserequired = 1;
        $options->responsefieldlines = 15;
        $options->attachments = 0;
        $options->attachmentsrequired = 0;
        $options->graderinfo = $graderinfo;
        $options->graderinfoformat = FORMAT_HTML;
        $options->responsetemplate = '';
        $options->responsetemplateformat = FORMAT_HTML;

        $DB->insert_record('qtype_essay_options', $options);
    }

    /**
     * Add matching question data.
     *
     * @param int $questionid Question ID
     * @param \stdClass $genquestion Generated question
     */
    private static function add_matching_data(int $questionid, \stdClass $genquestion): void {
        global $DB;

        // Add matching options.
        $options = new \stdClass();
        $options->questionid = $questionid;
        $options->shuffleanswers = 1;
        $options->correctfeedback = '';
        $options->correctfeedbackformat = FORMAT_HTML;
        $options->partiallycorrectfeedback = '';
        $options->partiallycorrectfeedbackformat = FORMAT_HTML;
        $options->incorrectfeedback = '';
        $options->incorrectfeedbackformat = FORMAT_HTML;
        $options->shownumcorrect = 1;

        $DB->insert_record('qtype_match_options', $options);

        // Get subquestions from our answers table or from question object.
        if (!empty($genquestion->subquestions)) {
            // Subquestions stored in question object (from AI response).
            $subquestions = $genquestion->subquestions;
        } else {
            // Try to get from answers table (if stored there).
            $answers = $DB->get_records('hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');
            $subquestions = [];
            foreach ($answers as $answer) {
                $subquestions[] = [
                    'text' => $answer->answer,
                    'answer' => $answer->feedback, // In matching, feedback stores the match.
                ];
            }
        }

        // Add subquestions and answers.
        foreach ($subquestions as $index => $subq) {
            // Create subquestion record.
            $subquestionrecord = new \stdClass();
            $subquestionrecord->question = $questionid;
            $subquestionrecord->questiontext = $subq['text'] ?? '';
            $subquestionrecord->questiontextformat = FORMAT_HTML;
            $subquestionrecord->answertext = $subq['answer'] ?? '';
            $subquestionrecord->answertextformat = FORMAT_HTML;

            $DB->insert_record('qtype_match_subquestions', $subquestionrecord);
        }
    }

    /**
     * Add questions to quiz.
     *
     * @param int $quizid Quiz ID
     * @param array $questionids Array of Moodle question IDs
     * @return int Number of questions added
     */
    private static function add_questions_to_quiz(int $quizid, array $questionids): int {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quizid, $quiz->course);
        $context = \context_module::instance($cm->id);

        // Get max page number.
        $maxpage = $DB->get_field_sql(
            "SELECT MAX(page) FROM {quiz_slots} WHERE quizid = ?",
            [$quizid]
        );
        $page = $maxpage === null ? 0 : $maxpage + 1;

        // Get max slot number.
        $maxslot = $DB->get_field_sql(
            "SELECT MAX(slot) FROM {quiz_slots} WHERE quizid = ?",
            [$quizid]
        );
        $slot = $maxslot === null ? 1 : $maxslot + 1;

        $added = 0;
        $questionsperpage = $quiz->questionsperpage ?? 1;

        foreach ($questionids as $questionid) {
            // Get question bank entry for this question.
            $qversion = $DB->get_record_sql(
                "SELECT qv.questionbankentryid
                 FROM {question_versions} qv
                 WHERE qv.questionid = ?
                 ORDER BY qv.version DESC
                 LIMIT 1",
                [$questionid]
            );

            if (!$qversion) {
                continue;
            }

            // Create quiz slot.
            $slotrecord = new \stdClass();
            $slotrecord->quizid = $quizid;
            $slotrecord->slot = $slot;
            $slotrecord->page = $page;
            $slotrecord->requireprevious = 0;
            $slotrecord->maxmark = 1.0;

            $slotid = $DB->insert_record('quiz_slots', $slotrecord);

            // Create question reference linking slot to question.
            $reference = new \stdClass();
            $reference->usingcontextid = $context->id;
            $reference->component = 'mod_quiz';
            $reference->questionarea = 'slot';
            $reference->itemid = $slotid;
            $reference->questionbankentryid = $qversion->questionbankentryid;
            $reference->version = null; // Use latest version.

            $DB->insert_record('question_references', $reference);

            $added++;
            $slot++;

            // Increment page based on questions per page setting.
            if ($questionsperpage > 0 && ($added % $questionsperpage == 0)) {
                $page++;
            }
        }

        // Update quiz sumgrades.
        quiz_update_sumgrades($quiz);

        return $added;
    }
}
