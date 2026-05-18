<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$regenerate = optional_param('regenerate', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/tutor_ia:use', $context);

$PAGE->set_url(new moodle_url('/local/tutor_ia/summary.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('summary_title', 'local_tutor_ia'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('summary_title', 'local_tutor_ia'));

// Check for cached summary.
$content = \local_tutor_ia\content_extractor::get_course_content($courseid);
$content_hash = md5($content);

$cached_summary = $DB->get_record('local_tutor_ia_summaries', ['courseid' => $courseid]);
if ($cached_summary && $cached_summary->content_hash === $content_hash && !$regenerate) {
    // Serve cached summary.
    echo $OUTPUT->header();
    echo '<div style="max-width:800px; margin:0 auto;">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">';
    echo '<h2 style="margin:0;">' . get_string('summary_title', 'local_tutor_ia') . '</h2>';
    echo '<div>';
    echo '<a href="?courseid=' . $courseid . '&regenerate=1" class="btn btn-outline-secondary btn-sm">' . get_string('summary_regenerate', 'local_tutor_ia') . '</a> ';
    echo '<button onclick="window.print()" class="btn btn-outline-primary btn-sm">' . get_string('summary_print', 'local_tutor_ia') . '</button>';
    echo '</div>';
    echo '</div>';
    echo '<div class="ia-summary-content" style="background:#fff; border:1px solid #dee2e6; border-radius:12px; padding:32px; line-height:1.8;">';
    echo $cached_summary->summary_html;
    echo '</div>';
    echo '</div>';
    echo '<style>@media print { .navbar, #page-header, #nav-drawer, .btn, footer { display:none !important; } .ia-summary-content { border:none !important; padding:0 !important; } }</style>';
    echo $OUTPUT->footer();
    die();
}

// Generate new summary via streaming.
echo $OUTPUT->header();
echo '<div style="max-width:800px; margin:0 auto;">';
echo '<h2>' . get_string('summary_title', 'local_tutor_ia') . '</h2>';
echo '<p style="color:#6c757d;">' . get_string('summary_generating', 'local_tutor_ia') . '</p>';
echo '<div id="summary-content" class="ia-summary-content" style="background:#fff; border:1px solid #dee2e6; border-radius:12px; padding:32px; line-height:1.8; min-height:200px;"><em>Generation en cours...</em></div>';
echo '<div id="summary-actions" style="margin-top:16px; display:none;">';
echo '<a href="?courseid=' . $courseid . '&regenerate=1" class="btn btn-outline-secondary btn-sm">' . get_string('summary_regenerate', 'local_tutor_ia') . '</a> ';
echo '<button onclick="window.print()" class="btn btn-outline-primary btn-sm">' . get_string('summary_print', 'local_tutor_ia') . '</button>';
echo '</div>';
echo '</div>';

echo '<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>';
echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    var container = document.getElementById("summary-content");
    var actions = document.getElementById("summary-actions");
    var fullText = "";

    fetch(M.cfg.wwwroot + "/local/tutor_ia/ajax_summary.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "courseid=' . $courseid . '&sesskey=" + M.cfg.sesskey
    }).then(function(response) {
        var reader = response.body.getReader();
        var decoder = new TextDecoder("utf-8");

        function readStream() {
            reader.read().then(function(result) {
                if (result.done) {
                    if (typeof marked !== "undefined") {
                        container.innerHTML = marked.parse(fullText);
                    }
                    actions.style.display = "block";
                    // Save via AJAX.
                    fetch(M.cfg.wwwroot + "/local/tutor_ia/ajax_summary.php", {
                        method: "POST",
                        headers: {"Content-Type": "application/x-www-form-urlencoded"},
                        body: "courseid=' . $courseid . '&sesskey=" + M.cfg.sesskey + "&action=save&summary=" + encodeURIComponent(fullText)
                    });
                    return;
                }
                var chunk = decoder.decode(result.value, {stream: true});
                var lines = chunk.split("\\n");
                lines.forEach(function(line) {
                    if (line.startsWith("data: ") && !line.includes("[DONE]")) {
                        try {
                            var data = JSON.parse(line.substring(6).trim());
                            if (data.choices && data.choices[0].delta && data.choices[0].delta.content) {
                                fullText += data.choices[0].delta.content;
                                if (typeof marked !== "undefined") {
                                    container.innerHTML = marked.parse(fullText);
                                } else {
                                    container.innerText = fullText;
                                }
                            }
                        } catch(e) {}
                    }
                });
                readStream();
            });
        }
        readStream();
    });
});
</script>';
echo '<style>@media print { .navbar, #page-header, #nav-drawer, .btn, footer { display:none !important; } .ia-summary-content { border:none !important; padding:0 !important; } }</style>';
echo $OUTPUT->footer();
