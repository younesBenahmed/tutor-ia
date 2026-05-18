<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/moodle2/backup_local_plugin.class.php');

class backup_local_tutor_ia_plugin extends backup_local_plugin {

    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element('tutor_ia_config');

        $plugin->add_child($wrapper);

        $config = new backup_nested_element('config', null, [
            'courseid', 'enabled', 'socratic_mode', 'syllabus', 'timecreated', 'timemodified'
        ]);
        $wrapper->add_child($config);

        $config->set_source_table('local_tutor_ia_config', ['courseid' => backup::VAR_COURSEID]);

        return $plugin;
    }
}
