<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Add navigation node for Tutor IA settings in course navigation.
 *
 * @param navigation_node $parentnode
 * @param stdClass $course
 * @param context_course $context
 */
function local_tutor_ia_extend_navigation_course(navigation_node $parentnode, stdClass $course, context_course $context) {
    if (has_capability('local/tutor_ia:configure', $context)) {
        $url = new moodle_url('/local/tutor_ia/course_settings.php', ['courseid' => $course->id]);
        $parentnode->add(
            get_string('settings_title', 'local_tutor_ia'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'tutor_ia_settings',
            new pix_icon('i/settings', '')
        );
    }
    if (has_capability('local/tutor_ia:viewlogs', $context)) {
        $dashboardurl = new moodle_url('/local/tutor_ia/dashboard.php', ['courseid' => $course->id]);
        $parentnode->add(
            get_string('dashboard', 'local_tutor_ia'),
            $dashboardurl,
            navigation_node::TYPE_SETTING,
            null,
            'tutor_ia_dashboard',
            new pix_icon('i/report', '')
        );
    }
}
