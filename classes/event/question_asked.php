<?php
namespace local_tutor_ia\event;

defined('MOODLE_INTERNAL') || die();

class question_asked extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_tutor_ia_logs';
    }

    public static function get_name() {
        return get_string('event_question_asked', 'local_tutor_ia');
    }

    public function get_description() {
        return "The user with id '{$this->userid}' asked a question to the AI tutor in course '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }
}
