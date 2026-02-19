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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External API for diagnostic operations.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_quizgen\external;

/**
 * Diagnostic external functions for the local_hlai_quizgen plugin.
 *
 * Provides 4 external service functions for diagnosing and repairing
 * question deployment issues: fix_category, check_question_types,
 * repair_questions, and diagnose.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostic_external extends \external_api {
    // -----------------------------------------------------------------------
    // 1. fix_category
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for fix_category.
     *
     * @return \external_function_parameters
     */
    public static function fix_category_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Force-fix the question.category field by adding it if missing.
     *
     * Gets question columns, checks for category column, and attempts
     * to fix the category field on questions in the given course context.
     *
     * @param int $courseid The course ID.
     * @return array Result with questions_checked, questions_fixed, has_category_column, message, details.
     */
    public static function fix_category($courseid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::fix_category_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context and capability.
        $coursecontext = \context_course::instance($courseid);
        self::validate_context($coursecontext);
        require_capability('local/hlai_quizgen:generatequestions', $coursecontext);

        $result = [
            'columns' => [],
            'questions_checked' => 0,
            'questions_fixed' => 0,
            'errors' => [],
        ];

        // Get actual column info from question table.
        $columns = $DB->get_columns('question');
        foreach ($columns as $name => $col) {
            $result['columns'][$name] = $col->type ?? 'unknown';
        }

        // Check if category column exists.
        $hascategory = isset($columns['category']);
        $result['has_category_column'] = $hascategory;

        // Get our questions with their bank entry category IDs.
        $sql = "SELECT q.id as questionid, qbe.questioncategoryid, qc.contextid as cat_contextid
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE qc.contextid = ?";

        $questions = $DB->get_records_sql($sql, [$coursecontext->id]);
        $result['questions_checked'] = count($questions);

        // Try to fix each question using direct SQL if needed.
        foreach ($questions as $q) {
            try {
                if ($hascategory) {
                    // Column exists, use set_field.
                    $DB->set_field('question', 'category', $q->questioncategoryid, ['id' => $q->questionid]);
                } else {
                    // Column might not exist or be detected, try raw SQL.
                    // First check if we can read the current value.
                    try {
                        $current = $DB->get_field('question', 'category', ['id' => $q->questionid]);
                        // If we got here, column exists - update it.
                        if (empty($current) || $current != $q->questioncategoryid) {
                            $DB->set_field('question', 'category', $q->questioncategoryid, ['id' => $q->questionid]);
                            $result['questions_fixed']++;
                        }
                    } catch (\Exception $e) {
                        // Column truly doesn't exist, can't fix.
                        $result['errors'][] = get_string(
                            'ajax_question_column_missing',
                            'local_hlai_quizgen',
                            (object)['id' => $q->questionid, 'error' => $e->getMessage()]
                        );
                    }
                }
                if ($hascategory) {
                    $result['questions_fixed']++;
                }
            } catch (\Exception $e) {
                $result['errors'][] = get_string(
                    'ajax_question_error',
                    'local_hlai_quizgen',
                    (object)['id' => $q->questionid, 'error' => $e->getMessage()]
                );
            }
        }

        // Verify by reading back a sample.
        $sampledata = [];
        if (!empty($questions)) {
            $sampleid = array_key_first($questions);
            try {
                $sample = $DB->get_record('question', ['id' => $questions[$sampleid]->questionid]);
                $sampledata = [
                    'id' => $sample->id,
                    'category' => $sample->category ?? get_string('ajax_not_set', 'local_hlai_quizgen'),
                    'qtype' => $sample->qtype,
                ];
            } catch (\Exception $e) {
                $sampledata = ['error' => $e->getMessage()];
            }
        }

        $message = get_string(
            'ajax_questions_checked_fixed',
            'local_hlai_quizgen',
            (object)['checked' => $result['questions_checked'], 'fixed' => $result['questions_fixed']]
        );

        // Build the details JSON with columns, errors, and sample.
        $details = json_encode([
            'columns' => $result['columns'],
            'errors' => $result['errors'],
            'sample' => $sampledata,
        ]);

        return [
            'questions_checked' => (int) $result['questions_checked'],
            'questions_fixed' => (int) $result['questions_fixed'],
            'has_category_column' => $hascategory,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Describes the return value for fix_category.
     *
     * @return \external_single_structure
     */
    public static function fix_category_returns() {
        return new \external_single_structure([
            'questions_checked' => new \external_value(PARAM_INT, 'Number of questions checked'),
            'questions_fixed' => new \external_value(PARAM_INT, 'Number of questions fixed'),
            'has_category_column' => new \external_value(PARAM_BOOL, 'Whether the question table has a category column'),
            'message' => new \external_value(PARAM_TEXT, 'Summary message'),
            'details' => new \external_value(PARAM_RAW, 'JSON-encoded details: columns, errors, sample'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 2. check_question_types
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for check_question_types.
     *
     * @return \external_function_parameters
     */
    public static function check_question_types_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Check if question type-specific data exists for questions in a course.
     *
     * Gets all questions, checks that type-specific data exists for each,
     * and repairs draft status by setting non-ready questions to ready.
     *
     * @param int $courseid The course ID.
     * @return array Result with total_questions, repaired_status, message, details.
     */
    public static function check_question_types($courseid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::check_question_types_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context and capability.
        $coursecontext = \context_course::instance($courseid);
        self::validate_context($coursecontext);
        require_capability('local/hlai_quizgen:generatequestions', $coursecontext);

        // Get all questions in this course's categories.
        $sql = "SELECT q.id, q.qtype, q.name, qv.status as version_status
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE qc.contextid = ?";

        $questions = $DB->get_records_sql($sql, [$coursecontext->id]);

        $bytype = [];
        $missingtypedata = [];
        $draftstatus = [];
        $repairedstatus = 0;

        // Batch-load type-specific data to avoid N+1 queries.
        // Group question IDs by type for efficient IN-clause lookups.
        $idsByType = [];
        foreach ($questions as $q) {
            if (!isset($bytype[$q->qtype])) {
                $bytype[$q->qtype] = 0;
            }
            $bytype[$q->qtype]++;

            if ($q->version_status !== 'ready') {
                $draftstatus[] = [
                    'id' => $q->id,
                    'name' => substr($q->name, 0, 50),
                    'status' => $q->version_status,
                ];
            }

            $idsByType[$q->qtype][$q->id] = $q;
        }

        // Pre-fetch existing type data with bulk queries (one query per type instead of one per question).
        $typedataexists = [];
        $typetablemap = [
            'multichoice' => ['table' => 'qtype_multichoice_options', 'field' => 'questionid'],
            'truefalse'   => ['table' => 'question_truefalse', 'field' => 'question'],
            'shortanswer' => ['table' => 'qtype_shortanswer_options', 'field' => 'questionid'],
            'essay'       => ['table' => 'qtype_essay_options', 'field' => 'questionid'],
            'match'       => ['table' => 'qtype_match_options', 'field' => 'questionid'],
            'matching'    => ['table' => 'qtype_match_options', 'field' => 'questionid'],
        ];

        foreach ($typetablemap as $qtype => $info) {
            if (empty($idsByType[$qtype])) {
                continue;
            }
            $ids = array_keys($idsByType[$qtype]);
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $existing = $DB->get_fieldset_sql(
                "SELECT {$info['field']} FROM {{$info['table']}} WHERE {$info['field']} {$insql}",
                $inparams
            );
            foreach ($existing as $eid) {
                $typedataexists[(int)$eid] = true;
            }
        }

        // Now check each question using the pre-fetched data.
        foreach ($questions as $q) {
            $hastypedata = isset($typetablemap[$q->qtype])
                ? isset($typedataexists[$q->id])
                : true; // Unknown types, assume ok.

            if (!$hastypedata) {
                $missingtypedata[] = [
                    'id' => $q->id,
                    'name' => substr($q->name, 0, 50),
                    'qtype' => $q->qtype,
                ];
            }
        }

        // Repair: Batch-set all non-ready questions to ready using IN clause.
        if (!empty($draftstatus)) {
            $draftids = array_column($draftstatus, 'id');
            try {
                [$insql, $inparams] = $DB->get_in_or_equal($draftids, SQL_PARAMS_NAMED);
                $DB->set_field_select('question_versions', 'status', 'ready', "questionid {$insql}", $inparams);
                $repairedstatus = count($draftids);
            } catch (\Exception $e) {
                // Ignore errors.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $message = get_string(
            'ajax_checkqtypes_result',
            'local_hlai_quizgen',
            (object)['missing' => count($missingtypedata), 'notready' => count($draftstatus), 'repaired' => $repairedstatus]
        );

        // Build the details JSON with by_type, missing_type_data, draft_status.
        $details = json_encode([
            'by_type' => $bytype,
            'missing_type_data' => $missingtypedata,
            'draft_status' => $draftstatus,
        ]);

        return [
            'total_questions' => count($questions),
            'repaired_status' => (int) $repairedstatus,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Describes the return value for check_question_types.
     *
     * @return \external_single_structure
     */
    public static function check_question_types_returns() {
        return new \external_single_structure([
            'total_questions' => new \external_value(PARAM_INT, 'Total number of questions checked'),
            'repaired_status' => new \external_value(PARAM_INT, 'Number of draft questions repaired to ready status'),
            'message' => new \external_value(PARAM_TEXT, 'Summary message'),
            'details' => new \external_value(PARAM_RAW, 'JSON-encoded details: by_type, missing_type_data, draft_status'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 3. repair_questions
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for repair_questions.
     *
     * @return \external_function_parameters
     */
    public static function repair_questions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Repair questions that have NULL or mismatched category field.
     *
     * Checks whether the question table has a category column. If so,
     * finds all questions with NULL or mismatched category and repairs them.
     * If not, checks question_bank_entries linkage and orphaned questions.
     *
     * @param int $courseid The course ID.
     * @return array Result with has_category_column, found, repaired, message, details.
     */
    public static function repair_questions($courseid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::repair_questions_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Validate context and capability.
        $coursecontext = \context_course::instance($courseid);
        self::validate_context($coursecontext);
        require_capability('local/hlai_quizgen:generatequestions', $coursecontext);

        // Check if the question table has a 'category' column.
        $questioncolumns = $DB->get_columns('question');
        $hascategorycolumn = isset($questioncolumns['category']);

        $found = 0;
        $repaired = 0;
        $errors = [];
        $message = '';
        $extrainfo = [];

        if (!$hascategorycolumn) {
            // Moodle 4.x without category column - check question_bank_entries instead.
            $message = get_string('ajax_no_category_column', 'local_hlai_quizgen');

            // Verify question_bank_entries are properly linked.
            $sql = "SELECT COUNT(*) as cnt
                    FROM {question_bank_entries} qbe
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                    WHERE qc.contextid = ?";
            $count = $DB->get_field_sql($sql, [$coursecontext->id]);
            $extrainfo['questions_in_bank_entries'] = (int) $count;

            // Check for orphaned questions (no bank entry).
            $sql = "SELECT COUNT(q.id) as cnt
                    FROM {question} q
                    LEFT JOIN {question_versions} qv ON qv.questionid = q.id
                    WHERE qv.id IS NULL";
            $orphaned = $DB->get_field_sql($sql);
            $extrainfo['orphaned_questions'] = (int) $orphaned;
        } else {
            // Has category column - try to repair.
            // Find all questions that have bank entries but NULL/mismatched category.
            $sql = "SELECT q.id as questionid, qbe.questioncategoryid, q.category as current_category
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                    WHERE qc.contextid = ?";

            $questions = $DB->get_records_sql($sql, [$coursecontext->id]);
            $found = count($questions);

            foreach ($questions as $q) {
                // Check if category needs repair.
                if (empty($q->current_category) || $q->current_category != $q->questioncategoryid) {
                    try {
                        $DB->set_field('question', 'category', $q->questioncategoryid, ['id' => $q->questionid]);
                        $repaired++;
                    } catch (\Exception $e) {
                        $errors[] = get_string(
                            'ajax_question_error',
                            'local_hlai_quizgen',
                            (object)['id' => $q->questionid, 'error' => $e->getMessage()]
                        );
                    }
                }
            }

            $message = get_string(
                'ajax_repair_result',
                'local_hlai_quizgen',
                (object)['found' => $found, 'repaired' => $repaired]
            );
        }

        // Build the details JSON with errors and extra info.
        $details = json_encode([
            'errors' => $errors,
            'extra' => $extrainfo,
        ]);

        return [
            'has_category_column' => $hascategorycolumn,
            'found' => (int) $found,
            'repaired' => (int) $repaired,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Describes the return value for repair_questions.
     *
     * @return \external_single_structure
     */
    public static function repair_questions_returns() {
        return new \external_single_structure([
            'has_category_column' => new \external_value(PARAM_BOOL, 'Whether the question table has a category column'),
            'found' => new \external_value(PARAM_INT, 'Number of questions found'),
            'repaired' => new \external_value(PARAM_INT, 'Number of questions repaired'),
            'message' => new \external_value(PARAM_TEXT, 'Summary message'),
            'details' => new \external_value(PARAM_RAW, 'JSON-encoded details: errors, extra info'),
        ]);
    }

    // -----------------------------------------------------------------------
    // 4. diagnose
    // -----------------------------------------------------------------------

    /**
     * Describes the parameters for diagnose.
     *
     * @return \external_function_parameters
     */
    public static function diagnose_parameters() {
        return new \external_function_parameters([
            'requestid' => new \external_value(PARAM_INT, 'Request ID to diagnose (0 if not used)', VALUE_DEFAULT, 0),
            'courseid' => new \external_value(
                PARAM_INT,
                'Course ID to diagnose all requests for (0 if not used)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Diagnose question deployment status.
     *
     * If requestid is provided, diagnoses that specific request.
     * If courseid is provided, diagnoses all requests for the course (limit 5).
     * At least one of requestid or courseid must be non-zero.
     *
     * @param int $requestid The request ID (0 if not used).
     * @param int $courseid The course ID (0 if not used).
     * @return array Result with data (JSON string of the full diagnostic result).
     */
    public static function diagnose($requestid = 0, $courseid = 0) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::diagnose_parameters(), [
            'requestid' => $requestid,
            'courseid' => $courseid,
        ]);
        $requestid = $params['requestid'];
        $courseid = $params['courseid'];

        // At least one parameter is required.
        if (!$requestid && !$courseid) {
            throw new \invalid_parameter_exception(
                get_string('ajax_requestid_or_courseid_required', 'local_hlai_quizgen')
            );
        }

        if ($requestid) {
            // Diagnose specific request.
            $request = $DB->get_record('local_hlai_quizgen_requests', ['id' => $requestid]);
            if (!$request) {
                throw new \moodle_exception('ajax_request_not_found', 'local_hlai_quizgen');
            }

            $coursecontext = \context_course::instance($request->courseid);
            self::validate_context($coursecontext);

            if ($request->userid != $USER->id && !has_capability('moodle/site:config', \context_system::instance())) {
                throw new \required_capability_exception(
                    $coursecontext,
                    'local/hlai_quizgen:generatequestions',
                    'ajax_access_denied',
                    'local_hlai_quizgen'
                );
            }

            $diagnostic = \local_hlai_quizgen\api::diagnose_deployment($requestid);
        } else {
            // Diagnose all requests for a course.
            $coursecontext = \context_course::instance($courseid);
            self::validate_context($coursecontext);
            require_capability('local/hlai_quizgen:generatequestions', $coursecontext);

            // Get all requests for this course.
            $requests = $DB->get_records('local_hlai_quizgen_requests', ['courseid' => $courseid], 'id DESC', 'id', 0, 5);

            $diagnostic = [
                'course_id' => $courseid,
                'context_id' => $coursecontext->id,
                'requests_found' => count($requests),
                'request_diagnostics' => [],
            ];

            // Get categories in course context.
            $categories = $DB->get_records_sql(
                "SELECT qc.id, qc.name, qc.contextid, qc.parent,
                        (SELECT COUNT(*) FROM {question_bank_entries} qbe
                         WHERE qbe.questioncategoryid = qc.id) as question_count
                 FROM {question_categories} qc
                 WHERE qc.contextid = ?
                 ORDER BY qc.id DESC",
                [$coursecontext->id]
            );
            $diagnostic['categories_in_course'] = [];
            foreach ($categories as $cat) {
                $diagnostic['categories_in_course'][] = [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'question_count' => (int) $cat->question_count,
                ];
            }

            // Diagnose each request.
            foreach ($requests as $req) {
                $diagnostic['request_diagnostics'][$req->id] = \local_hlai_quizgen\api::diagnose_deployment($req->id);
            }
        }

        return [
            'data' => json_encode($diagnostic),
        ];
    }

    /**
     * Describes the return value for diagnose.
     *
     * @return \external_single_structure
     */
    public static function diagnose_returns() {
        return new \external_single_structure([
            'data' => new \external_value(PARAM_RAW, 'JSON-encoded full diagnostic result'),
        ]);
    }
}
