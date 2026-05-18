<?php
namespace local_tutor_ia;

defined('MOODLE_INTERNAL') || die();

/**
 * Centralized course content extraction with DB-level caching.
 */
class content_extractor {

    /** @var int Maximum total content length in characters. */
    const MAX_TOTAL = 15000;

    /** @var int Maximum per-module content length. */
    const MAX_PER_ITEM = 2000;

    /**
     * Get course content, using DB cache if available.
     *
     * @param int $courseid
     * @return string Extracted text content
     */
    public static function get_course_content(int $courseid): string {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Check DB cache.
        $cached = $DB->get_record('local_tutor_ia_content_cache', ['courseid' => $courseid]);
        if ($cached) {
            // Verify content hasn't changed by comparing hash.
            $current_hash = self::compute_content_hash($course);
            if ($cached->content_hash === $current_hash) {
                return $cached->extracted_content;
            }
            // Hash mismatch: content changed, delete old cache.
            $DB->delete_records('local_tutor_ia_content_cache', ['id' => $cached->id]);
        }

        // Extract fresh content.
        $content = self::extract_raw_content($course);
        $hash = md5($content);

        // Store in cache.
        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->content_hash = $hash;
        $record->extracted_content = $content;
        $record->tokens_count = (int)(strlen($content) / 4);
        $record->timecreated = time();
        $DB->insert_record('local_tutor_ia_content_cache', $record);

        return $content;
    }

    /**
     * Invalidate the cache for a course.
     *
     * @param int $courseid
     */
    public static function invalidate_cache(int $courseid): void {
        global $DB;
        $DB->delete_records('local_tutor_ia_content_cache', ['courseid' => $courseid]);
    }

    /**
     * Compute a quick hash of the course structure to detect changes.
     *
     * @param object $course
     * @return string MD5 hash
     */
    private static function compute_content_hash($course): string {
        global $DB;

        // Hash based on module count + last modification times.
        $sql = "SELECT COUNT(*) as cnt, MAX(cm.added) as last_added
                FROM {course_modules} cm
                WHERE cm.course = :courseid AND cm.deletioninprogress = 0";
        $info = $DB->get_record_sql($sql, ['courseid' => $course->id]);

        $hash_input = $course->id . '_' . ($info->cnt ?? 0) . '_' . ($info->last_added ?? 0) . '_' . $course->timemodified;
        return md5($hash_input);
    }

    /**
     * Extract raw content from all course modules.
     *
     * @param object $course
     * @return string
     */
    public static function extract_raw_content($course): string {
        global $DB;

        $sections_content = [];
        $modinfo = get_fast_modinfo($course);
        $fs = get_file_storage();

        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            $sectionnum = $sectioninfo->section;
            $sectionname = !empty($sectioninfo->name) ? $sectioninfo->name : "Section {$sectionnum}";
            if ($sectionnum == 0) {
                $sectionname = "General";
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

                $item_text = self::extract_module_content($cm, $course, $fs);
                if (!empty($item_text)) {
                    $items[] = $item_text;
                }
            }

            if (!empty($items)) {
                $sections_content[] = "--- {$sectionname} ---\n" . implode("\n", $items);
            }
        }

        $full_text = "Contenu du cours \"{$course->fullname}\" :\n\n" . implode("\n\n", $sections_content);

        if (strlen($full_text) > self::MAX_TOTAL) {
            $full_text = mb_substr($full_text, 0, self::MAX_TOTAL) . "\n... [Contenu tronque]";
        }

        return $full_text;
    }

    /**
     * Extract content from a single course module.
     *
     * @param object $cm Course module info
     * @param object $course
     * @param object $fs File storage
     * @return string
     */
    private static function extract_module_content($cm, $course, $fs): string {
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
                    if (strlen($content) > 20) {
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
                $context = \context_module::instance($cm->id);
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
                if (!empty($cm->name)) {
                    $record = $DB->get_record($cm->modname, ['id' => $cm->instance]);
                    if ($record && isset($record->intro) && !empty($record->intro)) {
                        $text = "[{$cm->modname}: {$cm->name}] " . strip_tags($record->intro);
                    }
                }
                break;
        }

        if (strlen($text) > self::MAX_PER_ITEM) {
            $text = mb_substr($text, 0, self::MAX_PER_ITEM) . '...';
        }

        return $text;
    }
}
