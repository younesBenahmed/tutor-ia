<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_tutor_ia', get_string('pluginname', 'local_tutor_ia'));

    // Rate limit.
    $settings->add(new admin_setting_configtext(
        'local_tutor_ia/rate_limit',
        get_string('rate_limit', 'local_tutor_ia'),
        get_string('rate_limit_desc', 'local_tutor_ia'),
        '30', PARAM_INT
    ));

    // Daily token budget per course.
    $settings->add(new admin_setting_configtext(
        'local_tutor_ia/daily_token_limit',
        get_string('daily_token_limit', 'local_tutor_ia'),
        get_string('daily_token_limit_desc', 'local_tutor_ia'),
        '100000', PARAM_INT
    ));

    // Fallback API endpoint.
    $settings->add(new admin_setting_configtext(
        'local_tutor_ia/api_endpoint_fallback',
        get_string('api_endpoint_fallback', 'local_tutor_ia'),
        get_string('api_endpoint_fallback_desc', 'local_tutor_ia'),
        '', PARAM_URL
    ));

    // Fallback model name.
    $settings->add(new admin_setting_configtext(
        'local_tutor_ia/model_name_fallback',
        get_string('model_name_fallback', 'local_tutor_ia'),
        get_string('model_name_fallback_desc', 'local_tutor_ia'),
        '', PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
