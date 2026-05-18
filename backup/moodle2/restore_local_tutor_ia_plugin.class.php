<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/moodle2/restore_local_plugin.class.php');

class restore_local_tutor_ia_plugin extends restore_local_plugin {

    protected function define_course_plugin_structure() {
        $paths = [];
        $paths[] = new restore_path_element('tutor_ia_config', '/course/plugin_local_tutor_ia_course/tutor_ia_config/config');
        return $paths;
    }

    public function process_tutor_ia_config($data) {
        global $DB;

        $data = (object) $data;
        $courseid = $this->task->get_courseid();

        // Check if config already exists for the new course.
        $existing = $DB->get_record('local_tutor_ia_config', ['courseid' => $courseid]);

        if ($existing) {
            $existing->enabled = $data->enabled;
            $existing->socratic_mode = $data->socratic_mode ?? 0;
            $existing->syllabus = $data->syllabus ?? '';
            $existing->timemodified = time();
            $DB->update_record('local_tutor_ia_config', $existing);
        } else {
            $record = new stdClass();
            $record->courseid = $courseid;
            $record->enabled = $data->enabled;
            $record->socratic_mode = $data->socratic_mode ?? 0;
            $record->syllabus = $data->syllabus ?? '';
            $record->timecreated = time();
            $record->timemodified = time();
            $DB->insert_record('local_tutor_ia_config', $record);
        }
    }
}
