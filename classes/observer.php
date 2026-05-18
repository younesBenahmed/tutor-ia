<?php
namespace local_tutor_ia;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for cache invalidation.
 */
class observer {

    /**
     * Invalidate content cache when a course module is created, updated, or deleted.
     *
     * @param \core\event\base $event
     */
    public static function invalidate_cache(\core\event\base $event): void {
        $courseid = $event->courseid;
        if ($courseid > 1) {
            content_extractor::invalidate_cache($courseid);
        }
    }
}
