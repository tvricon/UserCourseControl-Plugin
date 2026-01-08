<?php
namespace local_usercoursecontrol;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use context_course;
use context_system;
use \external_api;
use \external_function_parameters;
use \external_multiple_structure;
use \external_single_structure;
use \external_value;
use \invalid_parameter_exception;

/**
 * External API for local_usercoursecontrol.
 */
class external extends external_api {

    /**
     * Parameters for get_grade_status.
     * @return external_function_parameters
     */
    public static function get_grade_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Target user username', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
            'gradeitemid' => new external_value(PARAM_INT, 'Grade item ID (0 or omit = all grade items)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get grade status for individual grade items (assignments, quizzes, etc.) with modification history.
     *
     * - If gradeitemid = 0 or omitted: Returns ALL grade items in the course (excluding course total).
     * - If gradeitemid is specified: Returns only that grade item.
     * - Reads current final grade from grade_grades.
     * - Detects modification using grade_grades_history with UPDATE actions only.
     * - If history is disabled, returns was_modified = false.
     *
     * @param string $username
     * @param int $courseid
     * @param int $gradeitemid
     * @return array
     */
    public static function get_grade_status(string $username, int $courseid, int $gradeitemid = 0): array {
        global $DB, $CFG, $USER;

        require_once($CFG->libdir . '/gradelib.php');

        $params = self::validate_parameters(self::get_grade_status_parameters(), [
            'username' => $username,
            'courseid' => $courseid,
            'gradeitemid' => $gradeitemid,
        ]);

        $username = $params['username'];
        $courseid = (int)$params['courseid'];
        $gradeitemid = (int)$params['gradeitemid'];

        $course = get_course($courseid);
        $context = context_course::instance($course->id);
        self::validate_context($context);

        $targetuser = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST);

        if ((int)$USER->id === (int)$targetuser->id) {
            require_capability('moodle/grade:view', $context);
        } else {
            require_capability('moodle/grade:viewall', $context);
        }

        // Fetch grade items.
        if ($gradeitemid > 0) {
            // Specific grade item - exclude deleted/hidden items.
            $gradeitems = $DB->get_records_select('grade_items',
                'id = :gradeitemid AND courseid = :courseid AND hidden = 0',
                ['gradeitemid' => $gradeitemid, 'courseid' => $course->id],
                'id ASC',
                'id, courseid, itemtype, itemmodule, iteminstance, itemname, grademax, hidden');
        } else {
            // All grade items excluding course total and deleted/hidden items.
            $gradeitems = $DB->get_records_select('grade_items',
                'courseid = :courseid AND itemtype != :itemtype AND hidden = 0',
                ['courseid' => $course->id, 'itemtype' => 'course'],
                'id ASC',
                'id, courseid, itemtype, itemmodule, iteminstance, itemname, grademax, hidden');
        }

        $result = [];
        foreach ($gradeitems as $gradeitem) {
            // Filter out grade items from deleted activities.
            // If itemtype = 'mod', verify the activity module still exists.
            if ($gradeitem->itemtype === 'mod' && !empty($gradeitem->itemmodule) && !empty($gradeitem->iteminstance)) {
                $moduletable = $gradeitem->itemmodule;
                // Check if the module instance exists in the corresponding table (mdl_assign, mdl_quiz, etc.).
                $moduleexists = $DB->record_exists($moduletable, ['id' => $gradeitem->iteminstance]);
                if (!$moduleexists) {
                    // Activity was deleted, skip this grade item.
                    continue;
                }
            }

            $itemdata = self::get_grade_item_status($DB, $gradeitem, $targetuser->id);
            $result[] = $itemdata;
        }

        return $result;
    }

    /**
     * Helper: Get grade status for a single grade item.
     *
     * @param object $DB
     * @param object $gradeitem
     * @param int $userid
     * @return array
     */
    private static function get_grade_item_status($DB, $gradeitem, int $userid): array {
        $currentfinal = null;
        $lastmodified = 0;
        $modifierid = null;
        $previousfinal = null;
        $wasmodified = false;

        $gg = $DB->get_record('grade_grades', [
            'itemid' => $gradeitem->id,
            'userid' => $userid,
        ], 'id, finalgrade, timemodified', IGNORE_MISSING);

        if ($gg) {
            if ($gg->finalgrade !== null) {
                $currentfinal = (float)$gg->finalgrade;
            }
            $lastmodified = (int)$gg->timemodified;

            // Detect modifications by checking grade_grades_history.
            // The history table stores the OLD grade value before each update.
            // We need to find a history record that is DIFFERENT from the current grade.
            $sql = "SELECT id, finalgrade, timemodified, usermodified, loggeduser, action
                      FROM {grade_grades_history}
                     WHERE itemid = :itemid
                       AND userid = :userid
                       AND finalgrade IS NOT NULL";
            
            // If current grade exists, exclude history records matching current value.
            if ($currentfinal !== null) {
                $sql .= " AND ABS(finalgrade - :currentgrade) > 0.001";
                $params = [
                    'itemid' => $gradeitem->id,
                    'userid' => $userid,
                    'currentgrade' => $currentfinal,
                ];
            } else {
                $params = [
                    'itemid' => $gradeitem->id,
                    'userid' => $userid,
                ];
            }
            
            $sql .= " ORDER BY timemodified DESC, id DESC";
            $historyrecords = $DB->get_records_sql($sql, $params, 0, 1);
            
            if (!empty($historyrecords)) {
                $entry = reset($historyrecords);
                $wasmodified = true;
                $lastmodified = (int)$entry->timemodified;
                $previousfinal = (float)$entry->finalgrade;
                // Extract modifier userid.
                if (property_exists($entry, 'usermodified') && !empty($entry->usermodified)) {
                    $modifierid = (int)$entry->usermodified;
                } else if (property_exists($entry, 'loggeduser') && !empty($entry->loggeduser)) {
                    $modifierid = (int)$entry->loggeduser;
                }
            }
        }

        return [
            'gradeitemid' => (int)$gradeitem->id,
            'itemname' => $gradeitem->itemname ?? '',
            'itemtype' => $gradeitem->itemtype ?? '',
            'itemmodule' => $gradeitem->itemmodule ?? '',
            'grademax' => isset($gradeitem->grademax) ? (float)$gradeitem->grademax : null,
            'current_final_grade' => $currentfinal,
            'was_modified' => (bool)$wasmodified,
            'last_modified' => (int)$lastmodified,
            'modifier_userid' => $modifierid,
            'previous_final_grade' => $previousfinal,
        ];
    }

    /**
     * Return structure for get_grade_status.
     * @return external_multiple_structure
     */
    public static function get_grade_status_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'gradeitemid' => new external_value(PARAM_INT, 'Grade item ID'),
            'itemname' => new external_value(PARAM_TEXT, 'Grade item name (assignment name, quiz name, etc.)'),
            'itemtype' => new external_value(PARAM_TEXT, 'Item type (mod, manual, etc.)'),
            'itemmodule' => new external_value(PARAM_TEXT, 'Module name (assign, quiz, etc.) if itemtype=mod'),
            'grademax' => new external_value(PARAM_FLOAT, 'Maximum grade for this item', VALUE_DEFAULT, null),
            'current_final_grade' => new external_value(PARAM_FLOAT, 'Current final grade, or null', VALUE_DEFAULT, null),
            'was_modified' => new external_value(PARAM_BOOL, 'True if grade was updated (history UPDATE entry exists)'),
            'last_modified' => new external_value(PARAM_INT, 'Timestamp of last grade modification (history) or 0 if none', VALUE_DEFAULT, 0),
            'modifier_userid' => new external_value(PARAM_INT, 'User ID of modifier if available', VALUE_DEFAULT, null),
            'previous_final_grade' => new external_value(PARAM_FLOAT, 'Previous final grade from history if available', VALUE_DEFAULT, null),
        ]));
    }

    /**
     * Parameters for list_suspended_courses.
     * @return external_function_parameters
     */
    public static function list_suspended_courses_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Target user username', VALUE_REQUIRED),
        ]);
    }

    /**
     * List courses where the user is enrolled but suspended (user_enrolments.status = 1).
     *
     * @param string $username
     * @return array
     */
    public static function list_suspended_courses(string $username): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::list_suspended_courses_parameters(), [
            'username' => $username,
        ]);

        $username = $params['username'];

        $syscontext = context_system::instance();
        self::validate_context($syscontext);
        require_capability('moodle/user:viewdetails', $syscontext);

        $targetuser = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST);

        $sql = "SELECT c.id AS courseid, c.fullname, c.shortname, ue.timemodified AS suspensiontimestamp
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                 WHERE ue.userid = :userid
                   AND ue.status = :suspended
                   AND e.status = :enrolactive";
        $records = $DB->get_records_sql($sql, [
            'userid' => $targetuser->id,
            'suspended' => 1,
            'enrolactive' => 0,
        ]);

        $result = [];
        foreach ($records as $r) {
            $result[] = [
                'courseid' => (int)$r->courseid,
                'fullname' => (string)$r->fullname,
                'shortname' => (string)$r->shortname,
                'suspensiontimestamp' => (int)($r->suspensiontimestamp ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Return structure for list_suspended_courses.
     * @return external_multiple_structure
     */
    public static function list_suspended_courses_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
            'suspensiontimestamp' => new external_value(PARAM_INT, 'Suspension timestamp if available, 0 otherwise', VALUE_DEFAULT, 0),
        ]));
    }

    /**
     * Parameters for unsuspend_user.
     * @return external_function_parameters
     */
    public static function unsuspend_user_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Target user username', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Reinstate (unsuspend) a user in a course without re-enrolling.
     * Uses enrol plugin API: update_user_enrol().
     *
     * @param string $username
     * @param int $courseid
     * @return array
     */
    public static function unsuspend_user(string $username, int $courseid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        $params = self::validate_parameters(self::unsuspend_user_parameters(), [
            'username' => $username,
            'courseid' => $courseid,
        ]);

        $username = $params['username'];
        $courseid = (int)$params['courseid'];

        $course = get_course($courseid);
        $context = context_course::instance($course->id);
        self::validate_context($context);

        $targetuser = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST);

        $sql = "SELECT ue.*, e.id AS enrolid, e.enrol
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND ue.userid = :userid";
        $enrols = $DB->get_records_sql($sql, ['courseid' => $course->id, 'userid' => $targetuser->id]);

        if (empty($enrols)) {
            throw new invalid_parameter_exception('User is not enrolled in the specified course.');
        }

        $previousstatus = null;
        $newstatus = null;
        $success = false;

        foreach ($enrols as $e) {
            if ((int)$e->status === 1) { // Suspended.
                $plugin = enrol_get_plugin($e->enrol);
                if (!$plugin) {
                    throw new invalid_parameter_exception('Enrol plugin not available: ' . $e->enrol);
                }
                require_capability('enrol/' . $e->enrol . ':manage', $context);

                // Fetch the full enrol instance object.
                $enrolinstance = $DB->get_record('enrol', ['id' => $e->enrolid], '*', MUST_EXIST);

                $previousstatus = (int)$e->status;
                $plugin->update_user_enrol($enrolinstance, (int)$targetuser->id, ENROL_USER_ACTIVE);
                $newstatus = 0;
                $success = true;
                break; // Unsuspend first matching suspended enrolment.
            }
        }

        if ($previousstatus === null) {
            // No suspended enrolment found; fail gracefully.
            $hasactive = false;
            foreach ($enrols as $e) {
                if ((int)$e->status === 0) {
                    $hasactive = true;
                    break;
                }
            }
            $previousstatus = $hasactive ? 0 : null;
            $newstatus = $previousstatus;
            $success = false;
        }

        return [
            'success' => (bool)$success,
            'previous_status' => $previousstatus,
            'new_status' => $newstatus,
        ];
    }

    /**
     * Return structure for unsuspend_user.
     * @return external_single_structure
     */
    public static function unsuspend_user_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True if the user was successfully unsuspended'),
            'previous_status' => new external_value(PARAM_INT, 'Previous enrolment status (1 suspended, 0 active) or null', VALUE_DEFAULT, null),
            'new_status' => new external_value(PARAM_INT, 'New enrolment status (0 active) or unchanged', VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Parameters for get_user_courses_filtered.
     * @return external_function_parameters
     */
    public static function get_user_courses_filtered_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Target user username', VALUE_REQUIRED),
            'coursefullnamefilter' => new external_value(PARAM_TEXT, 'Comma or newline separated partial course fullname matches (uses LIKE)', VALUE_DEFAULT, ''),
            'courseshortnamefilter' => new external_value(PARAM_TEXT, 'Comma or newline separated exact course shortname matches', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get user's enrolled courses with optional filtering at database level.
     * 
     * Filters courses by fullname (partial match) and/or shortname (exact match).
     * Returns only courses where the user is enrolled (active or suspended).
     * 
     * @param string $username Target user's username
     * @param string $coursefullnamefilter Comma/newline separated partial fullname filters
     * @param string $courseshortnamefilter Comma/newline separated exact shortname filters
     * @return array Array of course enrollment records
     */
    public static function get_user_courses_filtered(string $username, string $coursefullnamefilter = '', string $courseshortnamefilter = ''): array {
        global $DB;

        $params = self::validate_parameters(self::get_user_courses_filtered_parameters(), [
            'username' => $username,
            'coursefullnamefilter' => $coursefullnamefilter,
            'courseshortnamefilter' => $courseshortnamefilter,
        ]);

        $username = $params['username'];
        $fullnamefilter = trim($params['coursefullnamefilter']);
        $shortnamefilter = trim($params['courseshortnamefilter']);

        // Validate context and capabilities.
        $syscontext = context_system::instance();
        self::validate_context($syscontext);
        require_capability('moodle/user:viewdetails', $syscontext);

        // Get target user.
        $targetuser = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST);

        // Base SQL query joining user enrollments with courses.
        $sql = "SELECT c.id AS courseid, 
                       c.fullname, 
                       c.shortname, 
                       c.category,
                       c.startdate,
                       c.enddate,
                       ue.status AS enrolmentstatus,
                       ue.timecreated AS enrolmenttimecreated,
                       ue.timemodified AS enrolmenttimemodified,
                       e.enrol AS enrolmethod
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                 WHERE ue.userid = :userid
                   AND e.status = :enrolactive";

        $sqlparams = [
            'userid' => $targetuser->id,
            'enrolactive' => 0,
        ];

        // Build WHERE clause for course filters.
        $whereclauses = [];

        // Process fullname filters (partial match using LIKE).
        if (!empty($fullnamefilter)) {
            $fullnames = preg_split('/[\r\n,]+/', $fullnamefilter, -1, PREG_SPLIT_NO_EMPTY);
            $fullnames = array_map('trim', $fullnames);
            $fullnames = array_filter($fullnames);

            if (!empty($fullnames)) {
                $fullnameconditions = [];
                foreach ($fullnames as $idx => $name) {
                    $paramname = 'fullname' . $idx;
                    $fullnameconditions[] = $DB->sql_like('c.fullname', ':' . $paramname, false);
                    $sqlparams[$paramname] = '%' . $DB->sql_like_escape($name) . '%';
                }
                $whereclauses[] = '(' . implode(' OR ', $fullnameconditions) . ')';
            }
        }

        // Process shortname filters (exact match).
        if (!empty($shortnamefilter)) {
            $shortnames = preg_split('/[\r\n,]+/', $shortnamefilter, -1, PREG_SPLIT_NO_EMPTY);
            $shortnames = array_map('trim', $shortnames);
            $shortnames = array_filter($shortnames);

            if (!empty($shortnames)) {
                list($insql, $inparams) = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'shortname');
                $whereclauses[] = "c.shortname $insql";
                $sqlparams = array_merge($sqlparams, $inparams);
            }
        }

        // Append filter conditions to SQL.
        if (!empty($whereclauses)) {
            $sql .= ' AND ' . implode(' AND ', $whereclauses);
        }

        $sql .= " ORDER BY c.fullname ASC";

        // Execute query.
        $records = $DB->get_records_sql($sql, $sqlparams);

        $result = [];
        foreach ($records as $r) {
            $result[] = [
                'courseid' => (int)$r->courseid,
                'fullname' => (string)$r->fullname,
                'shortname' => (string)$r->shortname,
                'category' => (int)$r->category,
                'startdate' => (int)$r->startdate,
                'enddate' => (int)$r->enddate,
                'enrolmentstatus' => (int)$r->enrolmentstatus,
                'enrolmenttimecreated' => (int)$r->enrolmenttimecreated,
                'enrolmenttimemodified' => (int)$r->enrolmenttimemodified,
                'enrolmethod' => (string)$r->enrolmethod,
            ];
        }

        return $result;
    }

    /**
     * Return structure for get_user_courses_filtered.
     * @return external_multiple_structure
     */
    public static function get_user_courses_filtered_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
            'category' => new external_value(PARAM_INT, 'Course category ID'),
            'startdate' => new external_value(PARAM_INT, 'Course start date timestamp'),
            'enddate' => new external_value(PARAM_INT, 'Course end date timestamp'),
            'enrolmentstatus' => new external_value(PARAM_INT, 'Enrollment status (0=active, 1=suspended)'),
            'enrolmenttimecreated' => new external_value(PARAM_INT, 'Enrollment creation timestamp'),
            'enrolmenttimemodified' => new external_value(PARAM_INT, 'Enrollment modification timestamp'),
            'enrolmethod' => new external_value(PARAM_TEXT, 'Enrollment method (manual, self, etc.)'),
        ]));
    }
}
