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

/**
 * Quiz deployer class.
 */
class quiz_deployer {
    /**
     * Deploy questions to question bank.
     *
     * @param array $questionids Array of question IDs to deploy
     * @param int $courseid Course ID
     * @param string|null $categoryname Category name (optional)
     * @param \context|null $modulecontext Module context for Moodle 5.x quiz question banks (optional)
     * @return array Array of deployed question IDs in question bank
     * @throws \moodle_exception If deployment fails
     */
    public static function deploy_to_question_bank(
        array $questionids,
        int $courseid,
        ?string $categoryname = null,
        ?\context $modulecontext = null
    ): array {
        global $DB, $USER, $CFG;

        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        debugging(
            "deploy_to_question_bank: Starting with " . count($questionids) .
            " questions, courseid=$courseid, modulecontext=" . ($modulecontext ? $modulecontext->id : 'null'),
            DEBUG_DEVELOPER
        );

        // Use module context if provided (Moodle 5.x quiz), otherwise course context.
        if ($modulecontext) {
            $context = $modulecontext;
        } else {
            $context = \context_course::instance($courseid);
        }
        require_capability('moodle/question:add', $context);

        // Create or get question category.
        try {
            $category = self::get_or_create_category($courseid, $categoryname, $modulecontext);
            debugging(
                "deploy_to_question_bank: Got/created category ID: " .
                $category->id . " contextid: " . $category->contextid,
                DEBUG_DEVELOPER
            );
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

        // Batch-fetch all questions to avoid N+1 queries.
        [$qinsql, $qinparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        $allgenquestions = $DB->get_records_select(
            'local_hlai_quizgen_questions',
            "id $qinsql",
            $qinparams
        );

        foreach ($questionids as $questionid) {
            try {
                debugging("deploy_to_question_bank: Processing question ID: $questionid", DEBUG_DEVELOPER);

                // Get question from pre-fetched batch.
                if (!isset($allgenquestions[$questionid])) {
                    throw new \moodle_exception('questionnotfound', 'local_hlai_quizgen');
                }
                $genquestion = $allgenquestions[$questionid];
                debugging(
                    "deploy_to_question_bank: Loaded genquestion, type=" .
                    ($genquestion->questiontype ?? 'null'),
                    DEBUG_DEVELOPER
                );

                // DUPLICATE DETECTION: If this plugin question already has a valid moodle_questionid,
                // check if that Moodle question still exists. If so, skip creation entirely.
                if (!empty($genquestion->moodle_questionid)) {
                    $existingmq = $DB->get_record('question', ['id' => $genquestion->moodle_questionid]);
                    if ($existingmq) {
                        debugging("deploy_to_question_bank: Question $questionid already linked to Moodle question "
                            . "{$genquestion->moodle_questionid} - skipping", DEBUG_DEVELOPER);
                        $deployedids[] = (int)$genquestion->moodle_questionid;
                        $DB->set_field('local_hlai_quizgen_questions', 'status', 'deployed', ['id' => $questionid]);
                        $questionnumber++;
                        continue;
                    }
                    // Moodle question was deleted - clear stale reference and re-create.
                    debugging("deploy_to_question_bank: Stale moodle_questionid "
                        . "{$genquestion->moodle_questionid} for question $questionid - will re-create", DEBUG_DEVELOPER);
                }

                // DUPLICATE DETECTION: Search for an existing Moodle question in this category
                // that matches this plugin question (prevents duplicates from failed re-deploys).
                $existingmoodleid = self::find_existing_moodle_question(
                    $genquestion,
                    $category->id,
                    $category->name,
                    $questionnumber
                );
                if ($existingmoodleid) {
                    debugging(
                        "deploy_to_question_bank: Found existing Moodle " .
                        "question $existingmoodleid for plugin question $questionid - linking",
                        DEBUG_DEVELOPER
                    );
                    $updateobj = new \stdClass();
                    $updateobj->id = $questionid;
                    $updateobj->moodle_questionid = $existingmoodleid;
                    $updateobj->status = 'deployed';
                    $updateobj->timedeployed = time();
                    $DB->update_record('local_hlai_quizgen_questions', $updateobj);
                    $deployedids[] = $existingmoodleid;
                    $questionnumber++;
                    continue;
                }

                // TRANSACTION: Wrap question creation + tracking in a single transaction.
                // If tracking save fails, the Moodle question creation rolls back too.
                // This guarantees: either BOTH succeed, or NEITHER exists.
                $transaction = $DB->start_delegated_transaction();
                try {
                    // Create the Moodle question.
                    $moodlequestionid = self::convert_to_moodle_question(
                        $genquestion,
                        $category->id,
                        $category->name,
                        $questionnumber
                    );
                    $questionnumber++;

                    debugging("deploy_to_question_bank: Created Moodle question ID: $moodlequestionid", DEBUG_DEVELOPER);

                    // Save tracking in the SAME transaction - atomic with question creation.
                    $updateobj = new \stdClass();
                    $updateobj->id = $questionid;
                    $updateobj->moodle_questionid = $moodlequestionid;
                    $updateobj->status = 'deployed';
                    $updateobj->timedeployed = time();
                    $DB->update_record('local_hlai_quizgen_questions', $updateobj);

                    // Verify the tracking actually persisted.
                    $verifyrecord = $DB->get_record(
                        'local_hlai_quizgen_questions',
                        ['id' => $questionid],
                        'moodle_questionid, status'
                    );
                    if (!$verifyrecord || (int)$verifyrecord->moodle_questionid !== (int)$moodlequestionid) {
                        $gotvalue = $verifyrecord ? $verifyrecord->moodle_questionid : 'NULL';
                        throw new \moodle_exception(
                            'error:deployment',
                            'local_hlai_quizgen',
                            '',
                            "Verification failed! Expected " .
                            "moodle_questionid=$moodlequestionid " .
                            "but got $gotvalue for plugin question $questionid"
                        );
                    }

                    // COMMIT: Both question creation and tracking succeeded.
                    $transaction->allow_commit();
                    debugging(
                        "deploy_to_question_bank: COMMITTED transaction - " .
                        "moodle_questionid=$moodlequestionid saved for plugin question $questionid",
                        DEBUG_DEVELOPER
                    );
                } catch (\Exception $txex) {
                    // ROLLBACK: Undo the Moodle question creation since tracking failed.
                    // This prevents orphaned Moodle questions.
                    try {
                        $transaction->rollback($txex);
                    } catch (\Exception $rbex) {
                        // Rollback itself can throw - just log it.
                        debugging("deploy_to_question_bank: Rollback exception: " . $rbex->getMessage(), DEBUG_DEVELOPER);
                    }
                    debugging(
                        "deploy_to_question_bank: ROLLED BACK transaction " .
                        "for question $questionid: " . $txex->getMessage(),
                        DEBUG_DEVELOPER
                    );
                    throw $txex; // Re-throw to be caught by outer try-catch.
                }

                $deployedids[] = $moodlequestionid;

                // Log deployment (outside transaction - logging failure shouldn't affect deployment).
                try {
                    api::log_action('question_deployed', $genquestion->requestid, $USER->id, [
                        'questionid' => $questionid,
                        'moodle_questionid' => $moodlequestionid,
                        'categoryid' => $category->id,
                    ]);
                } catch (\Exception $logex) {
                    debugging(
                        "deploy_to_question_bank: Warning - log_action failed: " .
                        $logex->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            } catch (\Exception $e) {
                $errormsg = "Question $questionid: " . $e->getMessage();
                debugging("deploy_to_question_bank: ERROR - $errormsg", DEBUG_DEVELOPER);
                $errors[] = $errormsg;
            }
        }

        debugging(
            "deploy_to_question_bank: Completed. Deployed: " . count($deployedids) .
            ", Errors: " . count($errors),
            DEBUG_DEVELOPER
        );

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
     * Find an existing Moodle question that matches a plugin question in the given category.
     *
     * This prevents duplicate Moodle questions when re-deploying after a partial failure.
     * Matches by question text content within the same category.
     *
     * @param \stdClass $genquestion The plugin question record
     * @param int $categoryid The target question category ID
     * @param string $categoryname Category name for question naming
     * @param int $questionnumber The question number in sequence
     * @return int|null The existing Moodle question ID, or null if not found
     */
    private static function find_existing_moodle_question(
        \stdClass $genquestion,
        int $categoryid,
        string $categoryname = '',
        int $questionnumber = 1
    ): ?int {
        global $DB;

        // Search by matching question text in the same category.
        // This catches questions created by a previous failed deployment.
        $questiontext = $genquestion->questiontext ?? '';
        if (empty($questiontext)) {
            return null;
        }

        $existing = $DB->get_record_sql(
            "SELECT q.id
             FROM {question} q
             JOIN {question_versions} qv ON qv.questionid = q.id
             JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             WHERE qbe.questioncategoryid = ?
             AND q.questiontext = ?
             AND q.qtype = ?
             ORDER BY q.id DESC
             LIMIT 1",
            [
                $categoryid,
                $questiontext,
                ($genquestion->questiontype === 'scenario') ? 'essay' : $genquestion->questiontype,
            ]
        );

        if ($existing) {
            debugging(
                "find_existing_moodle_question: Found match - Moodle question " .
                "{$existing->id} for plugin question {$genquestion->id}",
                DEBUG_DEVELOPER
            );
            return (int)$existing->id;
        }

        return null;
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
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $context = \context_course::instance($courseid);
        require_capability('mod/quiz:addinstance', $context);

        // MOODLE 5.x FIX: Create quiz and course module FIRST, so we can get the
        // module context for question bank categories. In Moodle 5.0+, the quiz
        // question bank only shows categories from the quiz's own module context.

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
        $quiz->grademethod = \QUIZ_GRADEHIGHEST;
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

        debugging("create_quiz: Created quiz ID: $quizid", DEBUG_DEVELOPER);

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

        // CRITICAL FIX: Create quiz_sections entry (required for Moodle 4.x/5.x).
        try {
            $quizsection = new \stdClass();
            $quizsection->quizid = $quizid;
            $quizsection->firstslot = 1;
            $quizsection->heading = '';
            $quizsection->shufflequestions = 0;

            $DB->insert_record('quiz_sections', $quizsection);
            debugging("create_quiz: Created quiz_sections entry for quiz $quizid", DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            debugging("create_quiz: Warning - Could not create quiz_sections entry: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Determine correct context for question bank categories based on Moodle version:
        // Moodle 5.0+: Quiz question bank ONLY shows module context, so must use module context.
        // Moodle 4.x: Quiz question bank shows BOTH course + module context, so use course context.
        $categoryname = $quizname . ' - AI Generated Questions';
        if (self::is_moodle_5_or_later()) {
            $quizmodulecontext = \context_module::instance($cmid);
            debugging(
                "create_quiz: Moodle 5.x detected — using module context ID=" .
                $quizmodulecontext->id,
                DEBUG_DEVELOPER
            );
            $moodlequestionids = self::deploy_to_question_bank($questionids, $courseid, $categoryname, $quizmodulecontext);
        } else {
            debugging("create_quiz: Moodle 4.x detected — using course context", DEBUG_DEVELOPER);
            $moodlequestionids = self::deploy_to_question_bank($questionids, $courseid, $categoryname);
        }

        if (empty($moodlequestionids)) {
            throw new \moodle_exception('error:deployment', 'local_hlai_quizgen', '', 'No questions deployed');
        }

        // Add questions to quiz slots.
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

        // Deploy to question bank with version-appropriate context:
        // Moodle 5.0+: use module context (quiz bank only shows own context).
        // Moodle 4.x: use course context (quiz bank shows both, course context is stable).
        $categoryname = $quiz->name . ' - AI Generated Questions';
        if (self::is_moodle_5_or_later()) {
            $moodlequestionids = self::deploy_to_question_bank($questionids, $quiz->course, $categoryname, $context);
        } else {
            $moodlequestionids = self::deploy_to_question_bank($questionids, $quiz->course, $categoryname);
        }

        // Add to quiz.
        $added = self::add_questions_to_quiz($quizid, $moodlequestionids);

        return $added;
    }

    /**
     * Get or create question category.
     *
     * @param int $courseid Course ID
     * @param string|null $name Category name
     * @param \context|null $modulecontext Module context for Moodle 5.x quiz/qbank (optional)
     * @return \stdClass Category object
     */
    private static function get_or_create_category(
        int $courseid,
        ?string $name = null,
        ?\context $modulecontext = null
    ): \stdClass {
        global $DB, $CFG;

        require_once($CFG->libdir . '/questionlib.php');

        // Moodle 5.x: Use module context (quiz/qbank) if provided, otherwise course context.
        if ($modulecontext) {
            $context = $modulecontext;
            debugging(
                "get_or_create_category: Using MODULE context ID: " .
                $context->id . " for course $courseid",
                DEBUG_DEVELOPER
            );
        } else {
            $context = \context_course::instance($courseid);
            debugging(
                "get_or_create_category: Using COURSE context ID: " .
                $context->id . " for course $courseid",
                DEBUG_DEVELOPER
            );
        }

        // Ensure context is valid and has a proper ID.
        if (empty($context->id) || $context->id <= 0) {
            throw new \moodle_exception(
                'error:deployment',
                'local_hlai_quizgen',
                '',
                "Invalid context for course ID: $courseid"
            );
        }

        if (empty($name)) {
            $name = 'AI Generated Questions - ' . date('Y-m-d H:i:s');
        }

        // Check if category exists.
        $category = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'name' => $name,
        ]);

        if ($category) {
            debugging("get_or_create_category: Found existing category ID: " . $category->id, DEBUG_DEVELOPER);
            return $category;
        }

        // Get or create the parent category hierarchy for this context.
        // Moodle 5.x: For CONTEXT_MODULE (quiz), use question_get_default_category().
        // For CONTEXT_COURSE, manually manage the top category.
        $defaultcategory = null;

        if ($modulecontext && $context->contextlevel == CONTEXT_MODULE) {
            // Moodle 5.x module context: try the built-in function first.
            try {
                $defaultcategory = question_get_default_category($context->id);
                if ($defaultcategory) {
                    debugging(
                        "get_or_create_category: Got default category " .
                        "via question_get_default_category(): ID " .
                        $defaultcategory->id,
                        DEBUG_DEVELOPER
                    );
                }
            } catch (\Exception $e) {
                debugging(
                    "get_or_create_category: " .
                    "question_get_default_category() failed: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        if (!$defaultcategory) {
            // Fallback: manually find/create top and default categories.
            $topcategory = $DB->get_record('question_categories', [
                'contextid' => $context->id,
                'parent' => 0,
            ]);

            if (!$topcategory) {
                $topcategory = new \stdClass();
                $topcategory->name = 'top';
                $topcategory->contextid = $context->id;
                $topcategory->info = '';
                $topcategory->infoformat = FORMAT_MOODLE;
                $topcategory->stamp = make_unique_id_code();
                $topcategory->parent = 0;
                $topcategory->sortorder = 0;
                $topcategory->idnumber = null;

                try {
                    $topcategory->id = $DB->insert_record('question_categories', $topcategory);
                    debugging("get_or_create_category: Created top category ID: " . $topcategory->id, DEBUG_DEVELOPER);
                } catch (\Exception $e) {
                    throw new \moodle_exception(
                        'error:deployment',
                        'local_hlai_quizgen',
                        '',
                        "Failed to create top category: " . $e->getMessage()
                    );
                }
            } else {
                debugging("get_or_create_category: Found existing top category ID: " . $topcategory->id, DEBUG_DEVELOPER);
            }

            // Find existing default category under this context's top.
            $defaultcategory = $DB->get_record_sql(
                "SELECT * FROM {question_categories}
                 WHERE contextid = ? AND parent = ? AND id != ?
                 ORDER BY sortorder ASC, id ASC
                 LIMIT 1",
                [$context->id, $topcategory->id, $topcategory->id]
            );

            if (!$defaultcategory) {
                // Determine default category name based on context type.
                if ($modulecontext && $context->contextlevel == CONTEXT_MODULE) {
                    // Get the module instance name for the default category.
                    $cm = $DB->get_record_sql(
                        "SELECT cm.id, cm.instance, m.name as modname
                         FROM {context} ctx
                         JOIN {course_modules} cm ON cm.id = ctx.instanceid
                         JOIN {modules} m ON m.id = cm.module
                         WHERE ctx.id = ? AND ctx.contextlevel = ?",
                        [$context->id, CONTEXT_MODULE]
                    );
                    if ($cm) {
                        $modname = $DB->get_field($cm->modname, 'name', ['id' => $cm->instance]);
                        $defaultcatname = 'Default for ' . ($modname ?: 'Quiz');
                    } else {
                        $defaultcatname = 'Default for Quiz';
                    }
                } else {
                    $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);
                    $defaultcatname = 'Default for ' . $coursename;
                }

                $defaultcategory = new \stdClass();
                $defaultcategory->name = $defaultcatname;
                $defaultcategory->contextid = $context->id;
                $defaultcategory->info = '';
                $defaultcategory->infoformat = FORMAT_MOODLE;
                $defaultcategory->stamp = make_unique_id_code();
                $defaultcategory->parent = $topcategory->id;
                $defaultcategory->sortorder = 1;
                $defaultcategory->idnumber = null;

                try {
                    $defaultcategory->id = $DB->insert_record('question_categories', $defaultcategory);
                    debugging(
                        "get_or_create_category: Created default " .
                        "category ID: " . $defaultcategory->id,
                        DEBUG_DEVELOPER
                    );
                } catch (\Exception $e) {
                    throw new \moodle_exception(
                        'error:deployment',
                        'local_hlai_quizgen',
                        '',
                        "Failed to create default category: " . $e->getMessage()
                    );
                }
            } else {
                debugging(
                    "get_or_create_category: Found existing default " .
                    "category ID: " . $defaultcategory->id,
                    DEBUG_DEVELOPER
                );
            }
        }

        // Create new category as child of default category.
        $category = new \stdClass();
        $category->name = $name;
        $category->contextid = $context->id;
        $category->info = 'Questions generated by AI Quiz Generator';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->parent = $defaultcategory->id;  // Parent to default category.
        $category->sortorder = 999;
        $category->idnumber = null;

        try {
            $category->id = $DB->insert_record('question_categories', $category);
            debugging("get_or_create_category: Created new category ID: " . $category->id, DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'error:deployment',
                'local_hlai_quizgen',
                '',
                "Failed to create question category '$name': " . $e->getMessage()
            );
        }

        // Log important info for debugging.
        $contexttype = $modulecontext ? 'MODULE' : 'COURSE';
        debugging("get_or_create_category: Category hierarchy created:", DEBUG_DEVELOPER);
        debugging("  - Default Category ID: " . $defaultcategory->id, DEBUG_DEVELOPER);
        debugging("  - New Category ID: " . $category->id . " - Name: " . $category->name, DEBUG_DEVELOPER);
        debugging("  - Context ID: " . $category->contextid . " ({$contexttype} context for course $courseid)", DEBUG_DEVELOPER);

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
    private static function convert_to_moodle_question(
        \stdClass $genquestion,
        int $categoryid,
        string $categoryname = '',
        int $questionnumber = 1
    ): int {
        global $DB, $USER, $CFG;

        // Log the start of question conversion.
        debugging(
            "deploy: Starting convert_to_moodle_question for genquestion ID: " .
            ($genquestion->id ?? 'unknown') . ", type: " . ($genquestion->questiontype ?? 'unknown'),
            DEBUG_DEVELOPER
        );

        // Get actual columns in the question table to ensure compatibility.
        // Moodle 4.0+ removed 'category' from question table; Moodle 5.x removed 'hidden'.
        $questioncolumns = $DB->get_columns('question');
        $hascategorycolumn = isset($questioncolumns['category']);
        $hashiddencolumn = isset($questioncolumns['hidden']);

        // Base question record.
        $question = new \stdClass();

        // Only set category if the column exists (pre-Moodle 4.0).
        if ($hascategorycolumn) {
            $question->category = $categoryid;
        }

        $question->parent = 0;

        // Format question name: Extract clean text snippet for better readability.
        $questionsnippet = strip_tags($genquestion->questiontext ?? '');
        $questionsnippet = preg_replace('/\s+/', ' ', $questionsnippet); // Remove extra whitespace.
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

        // Log question object before insert.
        debugging("deploy: Inserting into 'question' table. Fields: " . json_encode(array_keys((array)$question)), DEBUG_DEVELOPER);

        try {
            $questionid = $DB->insert_record('question', $question);
            debugging("deploy: Successfully inserted question, ID: $questionid", DEBUG_DEVELOPER);
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
            debugging("deploy: Successfully inserted question_bank_entries, ID: $entryid", DEBUG_DEVELOPER);
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
            debugging("deploy: Successfully inserted question_versions", DEBUG_DEVELOPER);
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
            debugging("deploy: Adding type-specific data for type: " . $genquestion->questiontype, DEBUG_DEVELOPER);
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
                    debugging("deploy: Unknown question type: " . $genquestion->questiontype, DEBUG_DEVELOPER);
            }
            debugging("deploy: Successfully added type-specific data", DEBUG_DEVELOPER);
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
            debugging("deploy: Successfully added tags", DEBUG_DEVELOPER);
        } catch (\Exception $e) {
            // Tags are optional, log but don't fail.
            debugging("deploy: Warning - Failed to add tags: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // VERIFICATION: Confirm question was properly created and linked.
        $verifyqbe = $DB->get_record_sql(
            "SELECT qbe.questioncategoryid, qv.status as version_status
             FROM {question_bank_entries} qbe
             JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
             WHERE qv.questionid = ?",
            [$questionid]
        );

        debugging(
            "deploy: Question $questionid created — category=$categoryid, " .
            "bank_entry_cat=" . ($verifyqbe->questioncategoryid ?? 'NULL') .
            ", version_status=" . ($verifyqbe->version_status ?? 'NULL'),
            DEBUG_DEVELOPER
        );

        return $questionid;
    }

    /**
     * Tag a question with AI-generated, topic, difficulty, and Bloom's level tags.
     *
     * @param int $questionid Moodle question ID
     * @param \stdClass $genquestion Generated question object
     * @param \stdClass $category Question category object
     * @return void
     */
    private static function tag_question(int $questionid, \stdClass $genquestion, \stdClass $category): void {
        global $DB;

        // Validate category has a valid contextid.
        if (empty($category->contextid) || $category->contextid <= 0) {
            debugging("tag_question: Invalid contextid in category", DEBUG_DEVELOPER);
            return;
        }

        // Get context for the question category with proper error handling.
        try {
            $context = \context::instance_by_id($category->contextid, IGNORE_MISSING);
            if (!$context) {
                debugging("tag_question: Context not found for ID: " . $category->contextid, DEBUG_DEVELOPER);
                return;
            }
        } catch (\Exception $e) {
            debugging("tag_question: Failed to get context: " . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }

        $tags = [];

        // 1. AI-generated tag.
        $tags[] = 'ai-generated';

        // 2. Topic tag (if available).
        if (!empty($genquestion->topicid)) {
            $topic = $DB->get_record('local_hlai_quizgen_topics', ['id' => $genquestion->topicid], 'title');
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
     * @return void
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
        $answers = $DB->get_records('local_hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');
        foreach ($answers as $answer) {
            $answerrecord = new \stdClass();
            $answerrecord->question = $questionid;
            $answerrecord->answer = $answer->answer;
            $answerrecord->answerformat = FORMAT_MOODLE;
            $answerrecord->fraction = self::normalize_fraction((float)$answer->fraction);
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
     * @return void
     */
    private static function add_truefalse_data(int $questionid, \stdClass $genquestion): void {
        global $DB;

        // Get answers and create answer records.
        $answers = $DB->get_records('local_hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');

        $trueid = 0;
        $falseid = 0;

        foreach ($answers as $answer) {
            $answerrecord = new \stdClass();
            $answerrecord->question = $questionid;
            $answerrecord->answer = $answer->answer; // Contains true or false value.
            $answerrecord->answerformat = FORMAT_MOODLE;
            $answerrecord->fraction = self::normalize_fraction((float)$answer->fraction);
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
     * @return void
     */
    private static function add_shortanswer_data(int $questionid, \stdClass $genquestion): void {
        global $DB;

        // Add short answer options.
        $options = new \stdClass();
        $options->questionid = $questionid;
        $options->usecase = 0;  // Case insensitive.

        $DB->insert_record('qtype_shortanswer_options', $options);

        // Add answers.
        $answers = $DB->get_records('local_hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');
        foreach ($answers as $answer) {
            $answerrecord = new \stdClass();
            $answerrecord->question = $questionid;
            $answerrecord->answer = $answer->answer;
            $answerrecord->answerformat = FORMAT_MOODLE;
            $answerrecord->fraction = self::normalize_fraction((float)$answer->fraction);
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
     * @return void
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
        $answers = $DB->get_records('local_hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');
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
     * @return void
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
            $answers = $DB->get_records('local_hlai_quizgen_answers', ['questionid' => $genquestion->id], 'sortorder ASC');
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

        // Batch-fetch question bank entries to avoid N+1 queries.
        [$vinsql, $vinparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        $allversions = $DB->get_records_sql(
            "SELECT qv.questionid, qv.questionbankentryid
               FROM {question_versions} qv
              WHERE qv.questionid $vinsql
           ORDER BY qv.questionid, qv.version DESC",
            $vinparams
        );
        // Keep only the latest version per questionid.
        $versionmap = [];
        foreach ($allversions as $v) {
            if (!isset($versionmap[$v->questionid])) {
                $versionmap[$v->questionid] = $v;
            }
        }

        foreach ($questionids as $questionid) {
            // Look up pre-fetched question bank entry.
            $qversion = $versionmap[$questionid] ?? null;

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

            try {
                $slotid = $DB->insert_record('quiz_slots', $slotrecord);
                debugging("deploy: Created quiz_slot ID: $slotid for question: $questionid", DEBUG_DEVELOPER);
            } catch (\Exception $e) {
                debugging("deploy: ERROR creating quiz_slot: " . $e->getMessage(), DEBUG_DEVELOPER);
                throw new \moodle_exception(
                    'error:deployment',
                    'local_hlai_quizgen',
                    '',
                    "Failed to create quiz slot: " . $e->getMessage()
                );
            }

            // Create question reference linking slot to question.
            $reference = new \stdClass();
            $reference->usingcontextid = $context->id;
            $reference->component = 'mod_quiz';
            $reference->questionarea = 'slot';
            $reference->itemid = $slotid;
            $reference->questionbankentryid = $qversion->questionbankentryid;
            $reference->version = null; // Use latest version.

            try {
                $DB->insert_record('question_references', $reference);
                debugging("deploy: Created question_reference for slot: $slotid", DEBUG_DEVELOPER);
            } catch (\Exception $e) {
                debugging("deploy: ERROR creating question_reference: " . $e->getMessage(), DEBUG_DEVELOPER);
                throw new \moodle_exception(
                    'error:deployment',
                    'local_hlai_quizgen',
                    '',
                    "Failed to create question reference: " . $e->getMessage()
                );
            }

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

    /**
     * Normalize a fraction value to Moodle's expected 0.0-1.0 range.
     *
     * AI may return fractions as percentages (e.g. 100 instead of 1.0).
     * Moodle expects: 1.0 = full credit, 0.0 = no credit.
     *
     * @param float $fraction The raw fraction value
     * @return float Normalized fraction between 0.0 and 1.0
     */
    private static function normalize_fraction(float $fraction): float {
        // If fraction is greater than 1, assume it's a percentage (e.g. 100 = 100%).
        if ($fraction > 1.0) {
            $fraction = $fraction / 100.0;
        }
        // Clamp to valid Moodle range.
        return max(0.0, min(1.0, $fraction));
    }

    /**
     * Detect if running on Moodle 5.0 or later.
     *
     * Moodle 5.0 changed the question bank architecture: each quiz has its own
     * isolated question bank (module context). In 4.x, the quiz bank shows both
     * course and module context categories, so course context works fine.
     *
     * Detection: checks if mod_qbank module exists (introduced in Moodle 5.0).
     *
     * @return bool True if Moodle 5.0+, false if 4.x
     */
    private static function is_moodle_5_or_later(): bool {
        global $DB;

        // Cache the result to avoid repeated DB queries.
        static $ismoodle5 = null;
        if ($ismoodle5 !== null) {
            return $ismoodle5;
        }

        // The mod_qbank module was introduced in Moodle 5.0 as a reliable detection method.
        $ismoodle5 = $DB->record_exists('modules', ['name' => 'qbank']);

        debugging("is_moodle_5_or_later: " . ($ismoodle5 ? 'YES (mod_qbank found)' : 'NO (Moodle 4.x)'), DEBUG_DEVELOPER);

        return $ismoodle5;
    }
}
