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
$action = optional_param('action', 'chat', PARAM_ALPHANUMEXT);
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

// Rate limiting with gamification support.
$rate_limit = get_config('local_tutor_ia', 'rate_limit') ?: 30;
$window = 3600;
$recent_logs = $DB->get_records_select(
    'local_tutor_ia_logs',
    'userid = :uid AND courseid = :cid AND timecreated > :since',
    ['uid' => $USER->id, 'cid' => $courseid, 'since' => time() - $window]
);
$recent_count = 0;
$bonus_total = 0;
foreach ($recent_logs as $rl) {
    $recent_count += $rl->message_count;
    $bonus_total += ($rl->bonus_tokens ?? 0);
}
$effective_limit = $rate_limit + $bonus_total;

if ($recent_count >= $effective_limit) {
    $gamification_enabled = !empty($config->gamification);

    if ($gamification_enabled && $action !== 'earn_tokens' && $action !== 'generate_quiz') {
        // Return a gamification quiz instead of blocking.
        header('Content-Type: text/event-stream');
        echo "data: {\"choices\":[{\"delta\":{\"content\":\"" .
            "\\n\\n---\\n**Limite atteinte !** Vous avez utilis\\u00e9 vos {$effective_limit} messages cette heure.\\n\\n" .
            "Mais bonne nouvelle : r\\u00e9pondez correctement au quiz ci-dessous pour gagner 5 messages suppl\\u00e9mentaires !\\n\"}}]}\n\n";
        echo "data: {\"choices\":[{\"delta\":{\"content\":\"\"}}],\"gamification\":true}\n\n";
        echo "data: [DONE]\n\n";
        die();
    } else if (!$gamification_enabled && $action !== 'generate_quiz') {
        header('Content-Type: text/event-stream');
        echo "data: {\"choices\":[{\"delta\":{\"content\":\"Vous avez atteint la limite de messages pour cette heure ({$effective_limit}). Prenez le temps de relire les r\\u00e9ponses pr\\u00e9c\\u00e9dentes.\"}}]}\n\n";
        echo "data: [DONE]\n\n";
        die();
    }
}

// Handle earn_tokens action (gamification quiz correct answers).
if ($action === 'earn_tokens') {
    $correct_count = optional_param('correct', 0, PARAM_INT);
    if ($correct_count > 0) {
        $bonus = $correct_count * 5; // 5 messages per correct answer
        $log_to_update = $DB->get_record_select(
            'local_tutor_ia_logs',
            'userid = :uid AND courseid = :cid AND timecreated > :since',
            ['uid' => $USER->id, 'cid' => $courseid, 'since' => time() - $window],
            '*', IGNORE_MULTIPLE
        );
        if ($log_to_update) {
            $log_to_update->bonus_tokens = ($log_to_update->bonus_tokens ?? 0) + $bonus;
            $DB->update_record('local_tutor_ia_logs', $log_to_update);
        }
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'bonus' => $bonus, 'new_limit' => $effective_limit + $bonus]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'bonus' => 0]);
    }
    die();
}

if ($action === 'generate_quiz') {
    $quiz_prompt = "A partir de la conversation suivante, genere exactement 3 questions QCM.\n";
    $quiz_prompt .= "IMPORTANT: Reponds UNIQUEMENT avec un tableau JSON, sans balise <think>, sans bloc markdown, sans explication.\n";
    $quiz_prompt .= "Format exact: [{\"question\": \"...\", \"choices\": [\"A\", \"B\", \"C\", \"D\"], \"correct\": 0, \"explanation\": \"...\"}]\n";
    $quiz_prompt .= "correct est l'index (0-3) du bon choix.\n";
    $quiz_prompt .= "Les questions doivent porter sur ce qui a ete discute dans la conversation.\n";

    $messages = json_decode($history_json, true);
    if (!is_array($messages)) {
        $messages = [['role' => 'user', 'content' => $history_json]];
    }
    array_unshift($messages, ['role' => 'system', 'content' => $quiz_prompt]);
    $messages[] = ['role' => 'user', 'content' => 'Genere le quiz. UNIQUEMENT le JSON.'];

    $api_url = get_config('local_dreamu_ai', 'api_endpoint') ?: 'http://172.18.0.1:9200/v1/chat/completions';
    $model_name = get_config('local_dreamu_ai', 'model_name') ?: 'hal9001-supreme';
    $api_key = get_config('local_dreamu_ai', 'api_key') ?: 'dummy';

    $data = ['model' => $model_name, 'messages' => $messages, 'temperature' => 0.3, 'stream' => false];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $text = $result['choices'][0]['message']['content'] ?? '';

    // Clean model artifacts.
    $text = preg_replace('/<think>[\s\S]*?<\/think>/', '', $text);
    $text = preg_replace('/```json\s*/', '', $text);
    $text = preg_replace('/```\s*/', '', $text);
    $text = trim($text);

    header('Content-Type: application/json');
    echo $text;
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

// Extract topic keyword from the last user message.
$topic = '';
$history_arr = json_decode($history_json, true);
if (is_array($history_arr)) {
    $last_msg = '';
    foreach (array_reverse($history_arr) as $m) {
        if (isset($m['role']) && $m['role'] === 'user') {
            $last_msg = strtolower($m['content']);
            break;
        }
    }
    if (empty($last_msg)) {
        $last_msg = strtolower($history_json);
    }
    // Remove stopwords and extract the most significant 1-2 word topic.
    $stopwords = ['c', 'est', 'quoi', 'un', 'une', 'le', 'la', 'les', 'des', 'de', 'du', 'en', 'et', 'a', 'que',
        'comment', 'pourquoi', 'quel', 'quelle', 'quels', 'je', 'tu', 'il', 'on', 'nous', 'faire', 'peut',
        'sert', 'moi', 'me', 'te', 'se', 'ce', 'ca', 'son', 'sa', 'ses', 'mon', 'ma', 'mes', 'pour', 'avec',
        'dans', 'sur', 'par', 'pas', 'ne', 'plus', 'aussi', 'bien', 'tout', 'tres', 'trop', 'peu', 'dit'];
    $words = preg_split('/[\s\?\!\.\,\:]+/', $last_msg, -1, PREG_SPLIT_NO_EMPTY);
    $words = array_filter($words, function($w) use ($stopwords) {
        return strlen($w) > 2 && !in_array($w, $stopwords);
    });
    $words = array_values($words);
    if (count($words) >= 2) {
        $topic = $words[0] . ' ' . $words[1];
    } else if (count($words) === 1) {
        $topic = $words[0];
    }
    $topic = mb_substr($topic, 0, 50);
}

if ($existing_log) {
    $existing_log->message_count++;
    $existing_log->session_duration = $now - $existing_log->timecreated;
    $existing_log->timemodified = $now;
    if (!empty($topic) && (empty($existing_log->topic_keywords) || strlen($existing_log->topic_keywords) < 200)) {
        $existing_log->topic_keywords = empty($existing_log->topic_keywords)
            ? $topic
            : $existing_log->topic_keywords . ', ' . $topic;
    }
    $DB->update_record('local_tutor_ia_logs', $existing_log);
    $logid = $existing_log->id;
} else {
    $log = new stdClass();
    $log->courseid = $courseid;
    $log->userid = $USER->id;
    $log->message_count = 1;
    $log->topic_keywords = $topic;
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
