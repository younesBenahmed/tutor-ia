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

// Top keywords.
$keywords_raw = $DB->get_records_sql(
    "SELECT topic_keywords, COUNT(*) as cnt FROM {local_tutor_ia_logs}
     WHERE courseid = :cid AND timecreated > :since AND topic_keywords IS NOT NULL AND topic_keywords != ''
     GROUP BY topic_keywords ORDER BY cnt DESC LIMIT 10",
    ['cid' => $courseid, 'since' => $month_ago]);

// Activity by hour (last 30 days).
$hourly_data = $DB->get_records_sql(
    "SELECT HOUR(FROM_UNIXTIME(timecreated)) as hr, SUM(message_count) as msgs
     FROM {local_tutor_ia_logs}
     WHERE courseid = :cid AND timecreated > :since
     GROUP BY HOUR(FROM_UNIXTIME(timecreated))
     ORDER BY hr",
    ['cid' => $courseid, 'since' => $month_ago]);

// Build hourly array (0-23).
$hours = array_fill(0, 24, 0);
foreach ($hourly_data as $h) {
    $hours[(int)$h->hr] = (int)$h->msgs;
}

// Render.
echo '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:32px;">';

$cards = [
    ['Sessions (7j)', $sessions_week],
    ['Sessions (30j)', $sessions_month],
    ['Etudiants actifs', $distinct_users],
    ['Messages total', $total_messages],
    ['Tokens consommes', number_format($total_tokens)],
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
echo '<h3 style="margin:0 0 16px;">Activite par heure (30 derniers jours)</h3>';
echo '<canvas id="hourlyChart" height="80"></canvas>';
echo '</div>';

// Keywords.
if (!empty($keywords_raw)) {
    echo '<div style="background:#fff; border:1px solid #dee2e6; border-radius:12px; padding:24px; margin-bottom:24px;">';
    echo '<h3 style="margin:0 0 16px;">Sujets les plus demandes</h3>';
    echo '<div style="display:flex; flex-wrap:wrap; gap:8px;">';
    foreach ($keywords_raw as $kw) {
        $size = min(1.4, 0.8 + ($kw->cnt * 0.1));
        echo '<span style="background:#e9ecef; padding:6px 14px; border-radius:20px; font-size:' . $size . 'em;">' .
             s($kw->topic_keywords) . ' <small style="color:#6c757d;">(' . $kw->cnt . ')</small></span>';
    }
    echo '</div>';
    echo '</div>';
}

// Chart.js (included in Moodle).
$hours_json = json_encode(array_values($hours));
$labels_json = json_encode(array_map(function($h) { return $h . 'h'; }, range(0, 23)));

echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    if (typeof Chart === "undefined") {
        var s = document.createElement("script");
        s.src = "https://cdn.jsdelivr.net/npm/chart.js";
        s.onload = buildChart;
        document.head.appendChild(s);
    } else {
        buildChart();
    }
    function buildChart() {
        new Chart(document.getElementById("hourlyChart"), {
            type: "bar",
            data: {
                labels: ' . $labels_json . ',
                datasets: [{
                    label: "Messages",
                    data: ' . $hours_json . ',
                    backgroundColor: "rgba(15, 111, 197, 0.6)",
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }
});
</script>';

echo $OUTPUT->footer();
