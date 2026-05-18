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

// Optional: course module ID for more precise context detection.
$cmid = optional_param('cmid', 0, PARAM_INT);

global $DB, $SESSION;

// Resolve course ID from cmid if needed.
if ($cmid > 0 && $courseid <= 1) {
    $cm = $DB->get_record('course_modules', ['id' => $cmid]);
    if ($cm) {
        $courseid = $cm->course;
    }
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Check if tutor_ia is enabled for this course.
$config = $DB->get_record('local_tutor_ia_config', ['courseid' => $courseid]);
if ($config && !$config->enabled) {
    header('Content-Type: text/event-stream');
    echo "data: {\"choices\":[{\"delta\":{\"content\":\"Le tuteur IA n'est pas activé pour ce cours.\"}}]}\n\n";
    echo "data: [DONE]\n\n";
    die();
}

// Get syllabus if defined.
$syllabus = '';
if ($config && !empty($config->syllabus)) {
    $syllabus = $config->syllabus;
}

// ============================================================
// Course content extraction with caching
// ============================================================
$cache_key = 'tutor_ia_content_' . $courseid;
$cache_version_key = 'tutor_ia_version_' . $courseid;

// Cache content in session for 10 minutes.
$context_text = '';
$use_cache = false;

if (isset($SESSION->{$cache_key}) && isset($SESSION->{$cache_version_key})) {
    $cached_time = $SESSION->{$cache_version_key};
    if (time() - $cached_time < 600) { // 10 min cache
        $context_text = $SESSION->{$cache_key};
        $use_cache = true;
    }
}

if (!$use_cache) {
    $context_text = extract_course_content($course);
    $SESSION->{$cache_key} = $context_text;
    $SESSION->{$cache_version_key} = time();
}

// Stream response.
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$api = new tutor_ia_api();
$api->ask_question($history_json, $context_text, $syllabus, $course->fullname);
die();

// ============================================================
// Content extraction function
// ============================================================
function extract_course_content($course) {
    global $DB;

    $max_total = 15000;
    $max_per_item = 2000;
    $sections_content = [];

    $modinfo = get_fast_modinfo($course);
    $fs = get_file_storage();

    // Process by section for better organization.
    foreach ($modinfo->get_section_info_all() as $sectioninfo) {
        $sectionnum = $sectioninfo->section;
        $sectionname = !empty($sectioninfo->name) ? $sectioninfo->name : "Section {$sectionnum}";

        if ($sectionnum == 0) {
            $sectionname = "Général";
        }

        $items = [];

        if (!isset($modinfo->sections[$sectionnum])) {
            continue;
        }

        foreach ($modinfo->sections[$sectionnum] as $cmid) {
            if (!isset($modinfo->cms[$cmid])) {
                continue;
            }
            $cm = $modinfo->cms[$cmid];
            if (!$cm->uservisible) {
                continue;
            }

            $item_text = extract_module_content($cm, $course, $fs, $max_per_item);
            if (!empty($item_text)) {
                $items[] = $item_text;
            }
        }

        if (!empty($items)) {
            $section_block = "--- {$sectionname} ---\n" . implode("\n", $items);
            $sections_content[] = $section_block;
        }
    }

    $full_text = "Contenu du cours \"{$course->fullname}\" :\n\n" . implode("\n\n", $sections_content);

    // Smart truncation: if over limit, truncate from the end.
    if (strlen($full_text) > $max_total) {
        $full_text = mb_substr($full_text, 0, $max_total) . "\n... [Contenu tronqué]";
    }

    return $full_text;
}

/**
 * Extract content from a single course module.
 */
function extract_module_content($cm, $course, $fs, $max_len) {
    global $DB;

    $text = '';

    switch ($cm->modname) {
        case 'page':
            $page = $DB->get_record('page', ['id' => $cm->instance]);
            if ($page && !empty($page->content)) {
                $text = "[Page: {$cm->name}] " . strip_tags($page->content);
            }
            break;

        case 'label':
            $label = $DB->get_record('label', ['id' => $cm->instance]);
            if ($label && !empty($label->intro)) {
                $content = strip_tags($label->intro);
                if (strlen($content) > 20) { // Skip trivially short labels.
                    $text = "[Label] " . $content;
                }
            }
            break;

        case 'book':
            $book = $DB->get_record('book', ['id' => $cm->instance]);
            if ($book) {
                $chapters = $DB->get_records('book_chapters', ['bookid' => $book->id], 'pagenum ASC');
                $book_text = '';
                foreach ($chapters as $chapter) {
                    $book_text .= strip_tags($chapter->content) . ' ';
                }
                if (!empty($book_text)) {
                    $text = "[Livre: {$cm->name}] " . trim($book_text);
                }
            }
            break;

        case 'resource':
            $context = context_module::instance($cm->id);
            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);
            foreach ($files as $file) {
                if ($file->get_mimetype() == 'application/pdf') {
                    $pdfcontent = $file->get_content();
                    $tmpfile = tempnam(sys_get_temp_dir(), 'pdf_');
                    file_put_contents($tmpfile, $pdfcontent);
                    $extracted = shell_exec("pdftotext " . escapeshellarg($tmpfile) . " -");
                    if (!empty($extracted)) {
                        $text = "[PDF: {$cm->name}] " . $extracted;
                    }
                    @unlink($tmpfile);
                }
            }
            break;

        case 'assign':
            $assign = $DB->get_record('assign', ['id' => $cm->instance]);
            if ($assign && !empty($assign->intro)) {
                $text = "[Devoir: {$cm->name}] " . strip_tags($assign->intro);
            }
            break;

        case 'url':
            $url = $DB->get_record('url', ['id' => $cm->instance]);
            if ($url) {
                $desc = !empty($url->intro) ? strip_tags($url->intro) : '';
                $text = "[Lien: {$cm->name}] URL: {$url->externalurl}";
                if (!empty($desc)) {
                    $text .= " - " . $desc;
                }
            }
            break;

        case 'forum':
            $forum = $DB->get_record('forum', ['id' => $cm->instance]);
            if ($forum && !empty($forum->intro)) {
                $text = "[Forum: {$cm->name}] " . strip_tags($forum->intro);
                // Also get the first discussion post if it exists.
                $discussion = $DB->get_record_sql(
                    "SELECT d.id, p.message FROM {forum_discussions} d
                     JOIN {forum_posts} p ON p.discussion = d.id AND p.parent = 0
                     WHERE d.forum = ? ORDER BY d.timemodified DESC LIMIT 1",
                    [$forum->id]
                );
                if ($discussion && !empty($discussion->message)) {
                    $text .= " " . strip_tags($discussion->message);
                }
            }
            break;

        default:
            // For unknown modules, try to get the intro at least.
            if (!empty($cm->name)) {
                $record = $DB->get_record($cm->modname, ['id' => $cm->instance]);
                if ($record && isset($record->intro) && !empty($record->intro)) {
                    $text = "[{$cm->modname}: {$cm->name}] " . strip_tags($record->intro);
                }
            }
            break;
    }

    // Truncate individual items.
    if (strlen($text) > $max_len) {
        $text = mb_substr($text, 0, $max_len) . '...';
    }

    return $text;
}
