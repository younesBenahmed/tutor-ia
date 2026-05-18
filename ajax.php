<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

while (ob_get_level()) {
    ob_end_clean();
}

$courseid = required_param('courseid', PARAM_INT);
$history_json = required_param('history', PARAM_RAW);
$cmid = optional_param('cmid', 0, PARAM_INT);
$action = optional_param('action', 'chat', PARAM_ALPHA);
$assignment_context = optional_param('assignment_context', '', PARAM_RAW);

global $DB, $USER, $SESSION;

// Resolve course ID from cmid if needed.
if ($cmid > 0 && $courseid <= 1) {
    $cm = $DB->get_record('course_modules', ['id' => $cmid]);
    if ($cm) {
        $courseid = $cm->course;
    }
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// Permission check.
require_capability('local/tutor_ia:use', $context);

// Check if tutor_ia is enabled for this course.
$config = $DB->get_record('local_tutor_ia_config', ['courseid' => $courseid]);
if (!$config || !$config->enabled) {
    header('Content-Type: text/event-stream');
    echo "data: {\"choices\":[{\"delta\":{\"content\":\"Le tuteur IA n'est pas active pour ce cours.\"}}]}\n\n";
    echo "data: [DONE]\n\n";
    die();
}

// Rate limiting.
$rate_limit = get_config('local_tutor_ia', 'rate_limit') ?: 30;
$window = 3600;
$recent_count = $DB->count_records_select(
    'local_tutor_ia_logs',
    'userid = :uid AND courseid = :cid AND timecreated > :since',
    ['uid' => $USER->id, 'cid' => $courseid, 'since' => time() - $window]
);
if ($recent_count >= $rate_limit) {
    header('Content-Type: text/event-stream');
    $minutes_left = ceil(($window - (time() % $window)) / 60);
    echo "data: {\"choices\":[{\"delta\":{\"content\":\"Vous avez atteint la limite de messages pour cette heure. Prenez le temps de relire les reponses precedentes.\"}}]}\n\n";
    echo "data: [DONE]\n\n";
    die();
}

// Log this interaction.
$now = time();
$session_window = 600; // 10 min session window
$existing_log = $DB->get_record_select(
    'local_tutor_ia_logs',
    'userid = :uid AND courseid = :cid AND timemodified > :since',
    ['uid' => $USER->id, 'cid' => $courseid, 'since' => $now - $session_window]
);

if ($existing_log) {
    $existing_log->message_count++;
    $existing_log->session_duration = $now - $existing_log->timecreated;
    $existing_log->timemodified = $now;
    $DB->update_record('local_tutor_ia_logs', $existing_log);
    $logid = $existing_log->id;
} else {
    $log = new stdClass();
    $log->courseid = $courseid;
    $log->userid = $USER->id;
    $log->message_count = 1;
    $log->topic_keywords = '';
    $log->tokens_used = 0;
    $log->session_duration = 0;
    $log->timecreated = $now;
    $log->timemodified = $now;
    $logid = $DB->insert_record('local_tutor_ia_logs', $log);

    // Fire session_started event.
    $event = \local_tutor_ia\event\session_started::create([
        'context' => $context,
        'courseid' => $courseid,
    ]);
    $event->trigger();
}

// Fire question_asked event.
$event = \local_tutor_ia\event\question_asked::create([
    'context' => $context,
    'courseid' => $courseid,
    'objectid' => $logid,
]);
$event->trigger();

// Get syllabus if defined.
$syllabus = '';
if (!empty($config->syllabus)) {
    $syllabus = $config->syllabus;
}

// Get course content via the centralized extractor with DB cache.
$context_text = \local_tutor_ia\content_extractor::get_course_content($courseid);

// Append assignment context if provided.
if (!empty($assignment_context)) {
    $context_text .= "\n\n=== DEVOIR EN COURS ===\n" . clean_param($assignment_context, PARAM_TEXT);
}

// Stream response.
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$api = new tutor_ia_api();

// Check socratic mode.
$socratic = !empty($config->socratic_mode) ? true : false;

$api->ask_question($history_json, $context_text, $syllabus, $course->fullname, $socratic, $logid);
die();
