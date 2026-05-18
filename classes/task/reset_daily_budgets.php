<?php
namespace local_tutor_ia\task;

defined('MOODLE_INTERNAL') || die();

class reset_daily_budgets extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_reset_budgets', 'local_tutor_ia');
    }

    public function execute() {
        global $DB;
        // Reset daily token counters by deleting logs older than 24h
        // The budget check is done by summing tokens_used from today's logs
        mtrace('Daily budget counters rely on log timestamps, no reset needed.');
    }
}
