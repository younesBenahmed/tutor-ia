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

if ($action === 'generate_quiz') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');

    $context_text = \local_tutor_ia\content_extractor::get_course_content($courseid);

    $quiz_prompt = "A partir de la conversation suivante, genere exactement 3 questions pour verifier la comprehension de l'etudiant.\n";
    $quiz_prompt .= "Format JSON strict, rien d'autre que le JSON :\n";
    $quiz_prompt .= '[{"question": "...", "choices": ["A", "B", "C", "D"], "correct": 0, "explanation": "..."}]' . "\n";
    $quiz_prompt .= "correct est l'index (0-3) du bon choix. explanation explique pourquoi.\n";
    $quiz_prompt .= "Les questions doivent porter sur ce qui a ete discute dans la conversation, pas sur le cours en general.\n";

    $messages = json_decode($history_json, true);
    if (!is_array($messages)) {
        $messages = [['role' => 'user', 'content' => $history_json]];
    }
    array_unshift($messages, ['role' => 'system', 'content' => $quiz_prompt]);
    $messages[] = ['role' => 'user', 'content' => 'Genere le quiz maintenant. Reponds UNIQUEMENT avec le JSON.'];

    $api_url = get_config('local_dreamu_ai', 'api_endpoint') ?: 'http://100.76.166.71:8200/v1/chat/completions';
    $model_name = get_config('local_dreamu_ai', 'model_name') ?: 'Qwen/Qwen3-Coder-30B-A3B-Instruct-FP8';
    $api_key = get_config('local_dreamu_ai', 'api_key') ?: 'sk-dummy';

    $data = ['model' => $model_name, 'messages' => $messages, 'temperature' => 0.3, 'stream' => true];

    if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
    @ini_set('zlib.output_compression', 0);
    @ini_set('implicit_flush', 1);
    header('X-Accel-Buffering: no');

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key, 'Accept: text/event-stream']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
        echo $data;
        if (ob_get_level() > 0) ob_flush();
        flush();
        return strlen($data);
    });
    curl_exec($ch);
    curl_close($ch);
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
