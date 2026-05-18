<?php
namespace local_tutor_ia\event;

defined('MOODLE_INTERNAL') || die();

class session_started extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    public static function get_name() {
        return get_string('event_session_started', 'local_tutor_ia');
    }

    public function get_description() {
        return "The user with id '{$this->userid}' started an AI tutor session in course '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }
}
