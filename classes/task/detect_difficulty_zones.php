<?php
namespace local_tutor_ia\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to detect difficulty zones and notify teachers.
 */
class detect_difficulty_zones extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_detect_difficulty', 'local_tutor_ia');
    }

    public function execute() {
        global $DB;

        $threshold = get_config('local_tutor_ia', 'alert_threshold') ?: 5;
        $window = 7 * 86400; // 7 days
        $since = time() - $window;

        // Get all courses with tutor_ia enabled.
        $courses = $DB->get_records('local_tutor_ia_config', ['enabled' => 1]);

        foreach ($courses as $config) {
            $courseid = $config->courseid;

            // Get topic keywords with distinct user count.
            $sql = "SELECT topic_keywords, COUNT(DISTINCT userid) as user_count
                    FROM {local_tutor_ia_logs}
                    WHERE courseid = :cid
                      AND timecreated > :since
                      AND topic_keywords IS NOT NULL
                      AND topic_keywords != ''
                    GROUP BY topic_keywords
                    HAVING COUNT(DISTINCT userid) >= :threshold
                    ORDER BY user_count DESC
                    LIMIT 5";

            $hotspots = $DB->get_records_sql($sql, [
                'cid' => $courseid,
                'since' => $since,
                'threshold' => $threshold,
            ]);

            if (empty($hotspots)) {
                continue;
            }

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                continue;
            }

            // Build notification message.
            $topics = [];
            foreach ($hotspots as $h) {
                $topics[] = '"' . $h->topic_keywords . '" (' . $h->user_count . ' etudiants)';
            }
            $message_text = get_string('alert_message', 'local_tutor_ia', (object)[
                'coursename' => $course->fullname,
                'topics' => implode(', ', $topics),
                'days' => 7,
            ]);

            // Get teachers for this course.
            $context = \context_course::instance($courseid);
            $teachers = get_users_by_capability($context, 'local/tutor_ia:viewlogs');

            foreach ($teachers as $teacher) {
                $message = new \core\message\message();
                $message->component = 'local_tutor_ia';
                $message->name = 'difficulty_alert';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $teacher;
                $message->subject = get_string('alert_subject', 'local_tutor_ia', $course->shortname);
                $message->fullmessage = $message_text;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = '<p>' . nl2br(s($message_text)) . '</p>';
                $message->smallmessage = $message_text;
                $message->notification = 1;
                $message->contexturl = new \moodle_url('/local/tutor_ia/dashboard.php', ['courseid' => $courseid]);
                $message->contexturlname = get_string('dashboard', 'local_tutor_ia');

                message_send($message);
            }

            mtrace("  Sent difficulty alert for course {$courseid}: " . count($hotspots) . " hot topics, " . count($teachers) . " teachers notified.");
        }
    }
}
