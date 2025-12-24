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
        ],
    ],
];
