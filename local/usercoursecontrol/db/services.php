<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_usercoursecontrol_get_grade_status' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'get_grade_status',
        'description' => 'Get current course total grade and modification history indicator for a user in a course.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_usercoursecontrol_list_suspended_courses' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'list_suspended_courses',
        'description' => 'List courses where the user is enrolled but suspended.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_usercoursecontrol_unsuspend_user' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'unsuspend_user',
        'description' => 'Reinstate (unsuspend) a user in a course without re-enrolling using the enrol plugin API.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_usercoursecontrol_get_user_courses_filtered' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'get_user_courses_filtered',
        'description' => 'Get user enrolled courses with database-level filtering by course fullname and/or shortname.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_usercoursecontrol_get_turnitin_assignments' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'get_turnitin_assignments',
        'description' => 'Get Turnitin assignments filtered by course names and due date range.',
        'type'        => 'read',
        'ajax'        => false,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_usercoursecontrol_toggle_turnitin_allowlate' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'toggle_turnitin_allowlate',
        'description' => 'Toggle allowlate field for a single Turnitin assignment part.',
        'type'        => 'write',
        'ajax'        => false,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_usercoursecontrol_bulk_toggle_turnitin_allowlate' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'bulk_toggle_turnitin_allowlate',
        'description' => 'Bulk toggle allowlate field for multiple Turnitin assignment parts.',
        'type'        => 'write',
        'ajax'        => false,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_usercoursecontrol_get_assignments' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'get_assignments',
        'description' => 'Get standard Moodle assignments filtered by course names and due date range.',
        'type'        => 'read',
        'ajax'        => false,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_usercoursecontrol_set_assignment_cutoff' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'set_assignment_cutoff',
        'description' => 'Set cutoff date for a standard Moodle assignment.',
        'type'        => 'write',
        'ajax'        => false,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_usercoursecontrol_bulk_set_assignment_cutoff' => [
        'classname'   => 'local_usercoursecontrol\\external',
        'methodname'  => 'bulk_set_assignment_cutoff',
        'description' => 'Bulk set cutoff date for multiple standard Moodle assignments.',
        'type'        => 'write',
        'ajax'        => false,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];

$services = [
    'local_usercoursecontrol_service' => [
        'shortname'     => 'local_usercoursecontrol_service',
        'enabled'       => 1,
        'restrictedusers' => 0,
        'downloadfiles' => 0,
        'uploadfiles'   => 0,
        'functions'     => [
            'local_usercoursecontrol_get_grade_status',
            'local_usercoursecontrol_list_suspended_courses',
            'local_usercoursecontrol_unsuspend_user',
            'local_usercoursecontrol_get_user_courses_filtered',
            'local_usercoursecontrol_get_turnitin_assignments',
            'local_usercoursecontrol_toggle_turnitin_allowlate',
            'local_usercoursecontrol_bulk_toggle_turnitin_allowlate',
            'local_usercoursecontrol_get_assignments',
            'local_usercoursecontrol_set_assignment_cutoff',
            'local_usercoursecontrol_bulk_set_assignment_cutoff',
        ],
    ],
];
