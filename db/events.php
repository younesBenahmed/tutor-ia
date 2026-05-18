<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\local_tutor_ia\observer::invalidate_cache',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\local_tutor_ia\observer::invalidate_cache',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_tutor_ia\observer::invalidate_cache',
    ],
];
