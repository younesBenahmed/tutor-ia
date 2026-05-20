<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', 'view', PARAM_ALPHA);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_login($course);
require_capability('local/tutor_ia:use', $context);

$PAGE->set_url(new moodle_url('/local/tutor_ia/flashcards.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('flashcards_title', 'local_tutor_ia'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('flashcards_title', 'local_tutor_ia'));

// AJAX: update card status.
if ($action === 'update_status') {
    require_sesskey();
    $cardid = required_param('cardid', PARAM_INT);
    $status = required_param('status', PARAM_INT);
    $card = $DB->get_record('local_tutor_ia_flashcards', ['id' => $cardid, 'userid' => $USER->id], '*', MUST_EXIST);
    $card->status = $status;
    $card->review_count++;
    // Leitner intervals: known=1d,3d,7d,14d; review=reset to 1d
    if ($status == 1) {
        $intervals = [86400, 259200, 604800, 1209600];
        $idx = min($card->review_count - 1, count($intervals) - 1);
        $card->next_review = time() + $intervals[$idx];
    } else {
        $card->next_review = time() + 86400;
        $card->review_count = 0;
    }
    $DB->update_record('local_tutor_ia_flashcards', $card);
    echo json_encode(['status' => 'ok']);
    die();
}

// AJAX: generate flashcards.
if ($action === 'generate') {
    require_sesskey();
    $content = \local_tutor_ia\content_extractor::get_course_content($courseid);

    $num_cards = strlen($content) > 2000 ? 15 : max(5, (int)(strlen($content) / 100));
    $prompt = "Genere exactement {$num_cards} flashcards a partir du contenu de cours suivant.\n";
    $prompt .= "IMPORTANT: Reponds UNIQUEMENT avec un tableau JSON, sans balise <think>, sans bloc markdown, sans explication.\n";
    $prompt .= "Format exact: [{\"front\": \"question ou terme\", \"back\": \"reponse ou definition\"}]\n";
    $prompt .= "Les flashcards doivent couvrir les concepts cles, definitions, et formules importantes.\n\n";
    $prompt .= $content;

    $api_url = get_config('local_dreamu_ai', 'api_endpoint') ?: 'http://100.76.166.71:11434/v1/chat/completions';
    $model_name = get_config('local_dreamu_ai', 'model_name') ?: 'qwen2.5-coder:32b';
    $api_key = get_config('local_dreamu_ai', 'api_key') ?: 'ollama';

    $data = [
        'model' => $model_name,
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => 'Genere les flashcards maintenant.']
        ],
        'temperature' => 0.5,
        'stream' => false
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $text = $result['choices'][0]['message']['content'] ?? '';

    // Clean up common model artifacts.
    $text = preg_replace('/<think>[\s\S]*?<\/think>/', '', $text);
    $text = preg_replace('/```json\s*/', '', $text);
    $text = preg_replace('/```\s*/', '', $text);
    $text = trim($text);

    // Extract JSON array.
    preg_match('/\[[\s\S]*\]/', $text, $matches);
    if (empty($matches)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to parse: ' . substr($text, 0, 200)]);
        die();
    }

    $cards = json_decode($matches[0], true);
    if (!is_array($cards) || count($cards) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON: ' . substr($matches[0], 0, 200)]);
        die();
    }

    // Delete old cards for this user/course.
    $DB->delete_records('local_tutor_ia_flashcards', ['courseid' => $courseid, 'userid' => $USER->id]);

    // Insert new cards.
    $now = time();
    foreach ($cards as $card) {
        if (empty($card['front']) || empty($card['back'])) continue;
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->userid = $USER->id;
        $record->front = $card['front'];
        $record->back = $card['back'];
        $record->status = 0;
        $record->next_review = $now;
        $record->review_count = 0;
        $record->timecreated = $now;
        $DB->insert_record('local_tutor_ia_flashcards', $record);
    }

    echo json_encode(['status' => 'ok', 'count' => count($cards)]);
    die();
}

// View page.
echo $OUTPUT->header();

$cards = $DB->get_records('local_tutor_ia_flashcards',
    ['courseid' => $courseid, 'userid' => $USER->id],
    'CASE WHEN status = 2 THEN 0 WHEN status = 0 THEN 1 ELSE 2 END, next_review ASC');
$card_count = count($cards);
$review_count = 0;
foreach ($cards as $c) {
    if ($c->status == 2 || ($c->status == 0) || ($c->next_review && $c->next_review <= time())) {
        $review_count++;
    }
}

echo '<div style="max-width:700px; margin:0 auto;">';
echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">';
echo '<h2 style="margin:0;">' . get_string('flashcards_title', 'local_tutor_ia') . '</h2>';
echo '<button id="generate-btn" class="btn btn-primary btn-sm">' . ($card_count > 0 ? get_string('flashcards_regenerate', 'local_tutor_ia') : get_string('flashcards_generate', 'local_tutor_ia')) . '</button>';
echo '</div>';

if ($card_count > 0) {
    echo '<p style="color:#6c757d; margin-bottom:16px;">' . $card_count . ' cartes | ' . $review_count . ' a revoir</p>';

    echo '<div id="flashcard-area">';
    $cards_array = array_values($cards);
    echo '<div id="card-container" style="perspective:1000px; margin-bottom:20px;">';
    echo '<div id="flashcard" style="width:100%; min-height:250px; position:relative; cursor:pointer; transition:transform 0.6s; transform-style:preserve-3d;" data-flipped="0">';
    echo '<div id="card-front" style="position:absolute; width:100%; min-height:250px; backface-visibility:hidden; background:#fff; border:1px solid #dee2e6; border-radius:16px; display:flex; align-items:center; justify-content:center; padding:32px; font-size:1.2em; font-weight:600; text-align:center;"></div>';
    echo '<div id="card-back" style="position:absolute; width:100%; min-height:250px; backface-visibility:hidden; background:#f0f7ff; border:1px solid #cce5ff; border-radius:16px; display:flex; align-items:center; justify-content:center; padding:32px; font-size:1.05em; text-align:center; transform:rotateY(180deg);"></div>';
    echo '</div></div>';

    echo '<div id="card-actions" style="display:flex; justify-content:center; gap:12px; margin-bottom:16px;">';
    echo '<button id="btn-review" class="btn btn-warning">A revoir</button>';
    echo '<button id="btn-known" class="btn btn-success">Je sais</button>';
    echo '</div>';

    echo '<div style="text-align:center; color:#6c757d;"><span id="card-counter">1</span> / ' . $card_count . '</div>';
    echo '</div>';

    // Pass cards data to JS.
    $cards_json = [];
    foreach ($cards_array as $c) {
        $cards_json[] = ['id' => (int)$c->id, 'front' => $c->front, 'back' => $c->back, 'status' => (int)$c->status];
    }
    echo '<script>var FLASHCARDS = ' . json_encode($cards_json) . '; var SESSKEY = "' . sesskey() . '"; var COURSEID = ' . $courseid . ';</script>';
} else {
    echo '<div style="text-align:center; padding:60px; background:#f8f9fa; border-radius:16px; border:1px solid #dee2e6;">';
    echo '<p style="font-size:1.1em; color:#6c757d;">Aucune flashcard. Cliquez sur le bouton pour en generer.</p>';
    echo '</div>';
}
echo '</div>';

// JS for flashcard interaction.
echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    var currentIdx = 0;
    var flashcard = document.getElementById("flashcard");
    var front = document.getElementById("card-front");
    var back = document.getElementById("card-back");
    var counter = document.getElementById("card-counter");

    function showCard(idx) {
        if (!window.FLASHCARDS || idx >= FLASHCARDS.length) return;
        var card = FLASHCARDS[idx];
        front.textContent = card.front;
        back.textContent = card.back;
        flashcard.style.transform = "";
        flashcard.dataset.flipped = "0";
        counter.textContent = (idx + 1);
    }

    if (flashcard) {
        showCard(0);
        flashcard.addEventListener("click", function() {
            if (this.dataset.flipped === "0") {
                this.style.transform = "rotateY(180deg)";
                this.dataset.flipped = "1";
            } else {
                this.style.transform = "";
                this.dataset.flipped = "0";
            }
        });
    }

    var btnKnown = document.getElementById("btn-known");
    var btnReview = document.getElementById("btn-review");

    function updateStatus(status) {
        if (!window.FLASHCARDS || currentIdx >= FLASHCARDS.length) return;
        var card = FLASHCARDS[currentIdx];
        fetch(window.location.pathname + "?courseid=" + COURSEID + "&action=update_status&cardid=" + card.id + "&status=" + status + "&sesskey=" + SESSKEY, {method: "POST"});
        card.status = status;
        currentIdx++;
        if (currentIdx < FLASHCARDS.length) {
            showCard(currentIdx);
        } else {
            document.getElementById("flashcard-area").innerHTML = "<div style=\"text-align:center; padding:40px; background:#d4edda; border-radius:16px;\"><p style=\"font-size:1.2em; font-weight:bold;\">Session terminee !</p><p>Revenez plus tard pour reviser.</p></div>";
        }
    }

    if (btnKnown) btnKnown.addEventListener("click", function() { updateStatus(1); });
    if (btnReview) btnReview.addEventListener("click", function() { updateStatus(2); });

    var genBtn = document.getElementById("generate-btn");
    if (genBtn) {
        genBtn.addEventListener("click", function() {
            genBtn.disabled = true;
            genBtn.textContent = "Generation en cours...";
            fetch(window.location.pathname + "?courseid=" + COURSEID + "&action=generate&sesskey=" + SESSKEY, {method: "POST"})
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === "ok") {
                        window.location.reload();
                    } else {
                        genBtn.textContent = "Erreur, reessayez";
                        genBtn.disabled = false;
                    }
                });
        });
    }
});
</script>';

echo $OUTPUT->footer();
