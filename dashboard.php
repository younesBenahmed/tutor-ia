<?php
require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/tutor_ia:viewlogs', $context);

$PAGE->set_url(new moodle_url('/local/tutor_ia/dashboard.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('dashboard_title', 'local_tutor_ia'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('dashboard_title', 'local_tutor_ia'));

echo $OUTPUT->header();

// Time ranges.
$now = time();
$week_ago = $now - (7 * 86400);
$month_ago = $now - (30 * 86400);

// Key metrics.
$sessions_week = $DB->count_records_select('local_tutor_ia_logs',
    'courseid = :cid AND timecreated > :since', ['cid' => $courseid, 'since' => $week_ago]);
$sessions_month = $DB->count_records_select('local_tutor_ia_logs',
    'courseid = :cid AND timecreated > :since', ['cid' => $courseid, 'since' => $month_ago]);
$distinct_users = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT userid) FROM {local_tutor_ia_logs} WHERE courseid = :cid AND timecreated > :since",
    ['cid' => $courseid, 'since' => $month_ago]);
$total_messages = $DB->get_field_sql(
    "SELECT COALESCE(SUM(message_count), 0) FROM {local_tutor_ia_logs} WHERE courseid = :cid AND timecreated > :since",
    ['cid' => $courseid, 'since' => $month_ago]);
$total_tokens = $DB->get_field_sql(
    "SELECT COALESCE(SUM(tokens_used), 0) FROM {local_tutor_ia_logs} WHERE courseid = :cid AND timecreated > :since",
    ['cid' => $courseid, 'since' => $month_ago]);

// Top keywords: split comma-separated keywords and count individually.
$all_logs = $DB->get_records_select('local_tutor_ia_logs',
    'courseid = :cid AND timecreated > :since AND topic_keywords IS NOT NULL AND topic_keywords != :empty',
    ['cid' => $courseid, 'since' => $month_ago, 'empty' => ''], '', 'id, topic_keywords');
$keyword_counts = [];
foreach ($all_logs as $log) {
    if (empty($log->topic_keywords)) {
        continue;
    }
    $parts = explode(',', $log->topic_keywords);
    foreach ($parts as $kw) {
        $kw = trim(strtolower($kw));
        if (strlen($kw) > 1) {
            $keyword_counts[$kw] = ($keyword_counts[$kw] ?? 0) + 1;
        }
    }
}
arsort($keyword_counts);
$keyword_counts = array_slice($keyword_counts, 0, 15, true);

// Activity by hour (last 30 days).
$hourly_data = $DB->get_records_sql(
    "SELECT HOUR(FROM_UNIXTIME(timecreated)) as hr, SUM(message_count) as msgs
     FROM {local_tutor_ia_logs}
     WHERE courseid = :cid AND timecreated > :since
     GROUP BY HOUR(FROM_UNIXTIME(timecreated))
     ORDER BY hr",
    ['cid' => $courseid, 'since' => $month_ago]);

$hours = array_fill(0, 24, 0);
foreach ($hourly_data as $h) {
    $hours[(int)$h->hr] = (int)$h->msgs;
}

// Render metrics.
echo '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:32px;">';
$cards = [
    ['Sessions (7j)', $sessions_week],
    ['Sessions (30j)', $sessions_month],
    ["\xC3\x89tudiants actifs", $distinct_users],
    ['Messages total', $total_messages],
    ["Tokens consomm\xC3\xa9s", number_format($total_tokens)],
];
foreach ($cards as $card) {
    echo '<div style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:12px; padding:20px; text-align:center;">';
    echo '<div style="font-size:2em; font-weight:700; color:#212529;">' . $card[1] . '</div>';
    echo '<div style="font-size:.85em; color:#6c757d; margin-top:4px;">' . $card[0] . '</div>';
    echo '</div>';
}
echo '</div>';

// Activity chart.
echo '<div style="background:#fff; border:1px solid #dee2e6; border-radius:12px; padding:24px; margin-bottom:24px;">';
echo '<h3 style="margin:0 0 16px;">Activit&eacute; par heure (30 derniers jours)</h3>';

// Pure CSS bar chart (no external dependency).
$max_msgs = max(1, max($hours));
echo '<div style="display:flex; align-items:flex-end; gap:2px; height:200px; padding:0 4px;">';
for ($h = 0; $h < 24; $h++) {
    $val = $hours[$h];
    $pct = round(($val / $max_msgs) * 100);
    $color = $val > 0 ? 'rgba(15, 111, 197, 0.7)' : '#e9ecef';
    $title = $h . 'h : ' . $val . ' message' . ($val > 1 ? 's' : '');
    echo '<div style="flex:1; display:flex; flex-direction:column; align-items:center; height:100%;">';
    echo '<div style="flex:1; width:100%; display:flex; align-items:flex-end;">';
    echo '<div style="width:100%; height:' . max($pct, 2) . '%; background:' . $color . '; border-radius:3px 3px 0 0; min-height:2px;" title="' . $title . '"></div>';
    echo '</div>';
    echo '<div style="font-size:0.65em; color:#6c757d; margin-top:4px;">' . $h . 'h</div>';
    echo '</div>';
}
echo '</div>';
if ($max_msgs > 1) {
    echo '<div style="text-align:right; font-size:0.75em; color:#aaa; margin-top:4px;">Max : ' . $max_msgs . ' messages</div>';
}

echo '</div>';

// Keywords cloud.
if (!empty($keyword_counts)) {
    echo '<div style="background:#fff; border:1px solid #dee2e6; border-radius:12px; padding:24px; margin-bottom:24px;">';
    echo '<h3 style="margin:0 0 16px;">Sujets les plus demand&eacute;s</h3>';
    echo '<div style="display:flex; flex-wrap:wrap; gap:8px;">';
    foreach ($keyword_counts as $kw => $cnt) {
        $size = min(1.4, 0.85 + ($cnt * 0.1));
        echo '<span style="background:#e9ecef; padding:6px 14px; border-radius:20px; font-size:' . $size . 'em;">' .
             s($kw) . ' <small style="color:#6c757d;">(' . $cnt . ')</small></span>';
    }
    echo '</div>';
    echo '</div>';
} else {
    echo '<div style="background:#fff; border:1px solid #dee2e6; border-radius:12px; padding:24px; margin-bottom:24px;">';
    echo '<h3 style="margin:0 0 16px;">Sujets les plus demand&eacute;s</h3>';
    echo '<p style="color:#6c757d;">Pas encore de donn&eacute;es. Les sujets appara&icirc;tront au fur et &agrave; mesure que les &eacute;tudiants utiliseront le chatbot.</p>';
    echo '</div>';
}


echo $OUTPUT->footer();
