<?php
defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'difficulty_alert' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
];
