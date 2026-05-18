<?php
defined('MOODLE_INTERNAL') || die();
$tasks = [
    [
        'classname' => 'local_tutor_ia\task\reset_daily_budgets',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '0',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_tutor_ia\task\detect_difficulty_zones',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
