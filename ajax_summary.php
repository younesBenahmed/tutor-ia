<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', 'generate', PARAM_ALPHA);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('local/tutor_ia:use', $context);
require_sesskey();

if ($action === 'save') {
    // Save the generated summary.
    $summary_text = required_param('summary', PARAM_RAW);
    $content = \local_tutor_ia\content_extractor::get_course_content($courseid);
    $content_hash = md5($content);
    $summary_html = format_text($summary_text, FORMAT_MARKDOWN);

    $existing = $DB->get_record('local_tutor_ia_summaries', ['courseid' => $courseid]);
    if ($existing) {
        $existing->content_hash = $content_hash;
        $existing->summary_html = $summary_html;
        $existing->summary_raw = $summary_text;
        $existing->timecreated = time();
        $DB->update_record('local_tutor_ia_summaries', $existing);
    } else {
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->content_hash = $content_hash;
        $record->summary_html = $summary_html;
        $record->summary_raw = $summary_text;
        $record->timecreated = time();
        $DB->insert_record('local_tutor_ia_summaries', $record);
    }
    echo json_encode(['status' => 'ok']);
    die();
}

// Generate summary via streaming.
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$content = \local_tutor_ia\content_extractor::get_course_content($courseid);

$prompt = "Tu es un assistant pedagogique. Genere un resume structure du contenu de cours suivant.\n\n";
$prompt .= "Regles :\n";
$prompt .= "- Utilise des titres Markdown (## et ###)\n";
$prompt .= "- Liste les points cles avec des puces\n";
$prompt .= "- Mets en **gras** les definitions et termes importants\n";
$prompt .= "- Inclus les formules si pertinent (en notation $$)\n";
$prompt .= "- Maximum 1500 mots\n";
$prompt .= "- Reponds en francais\n\n";
$prompt .= "=== CONTENU DU COURS ===\n";
$prompt .= $content;

$messages = [
    ['role' => 'system', 'content' => $prompt],
    ['role' => 'user', 'content' => 'Genere le resume structure de ce cours.']
];

$api_url = get_config('local_dreamu_ai', 'api_endpoint');
$model_name = get_config('local_dreamu_ai', 'model_name');
$api_key = get_config('local_dreamu_ai', 'api_key');

if (empty($api_url)) {
    $api_url = 'http://172.18.0.1:9200/v1/chat/completions';
}
if (empty($model_name)) {
    $model_name = 'hal9001-supreme';
}
if (empty($api_key)) {
    $api_key = 'dummy';
}

$data = [
    'model' => $model_name,
    'messages' => $messages,
    'temperature' => 0.5,
    'stream' => true
];

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
header('X-Accel-Buffering: no');

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key,
    'Accept: text/event-stream'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 180);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
    echo $data;
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
    return strlen($data);
});

curl_exec($ch);
curl_close($ch);
die();
