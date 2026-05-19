<?php
require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/tutor_ia:configure', $context);

$PAGE->set_url(new moodle_url('/local/tutor_ia/course_settings.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('settings_title', 'local_tutor_ia'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('settings_title', 'local_tutor_ia'));

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $enabled = optional_param('enabled', 0, PARAM_INT);
    $socratic_mode = optional_param('socratic_mode', 0, PARAM_INT);
    $syllabus = optional_param('syllabus', '', PARAM_RAW);

    $existing = $DB->get_record('local_tutor_ia_config', ['courseid' => $courseid]);

    if ($existing) {
        $existing->enabled = $enabled ? 1 : 0;
        $existing->socratic_mode = $socratic_mode ? 1 : 0;
        $existing->syllabus = $syllabus;
        $existing->timemodified = time();
        $DB->update_record('local_tutor_ia_config', $existing);
    } else {
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->enabled = $enabled ? 1 : 0;
        $record->socratic_mode = $socratic_mode ? 1 : 0;
        $record->syllabus = $syllabus;
        $record->timecreated = time();
        $record->timemodified = time();
        $DB->insert_record('local_tutor_ia_config', $record);
    }

    // Clear cached content for this course from all sessions.
    \core\notification::add(get_string('settings_saved', 'local_tutor_ia'), \core\notification::SUCCESS);
    redirect($PAGE->url);
}

// Load current config.
$config = $DB->get_record('local_tutor_ia_config', ['courseid' => $courseid]);
$enabled = $config ? $config->enabled : 0;
$socratic_mode = ($config && isset($config->socratic_mode)) ? $config->socratic_mode : 0;
$syllabus = $config ? $config->syllabus : '';

echo $OUTPUT->header();

echo '<form method="post" action="' . $PAGE->url . '">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

echo '<div class="form-group row mb-3">';
echo '<label class="col-sm-3 col-form-label" for="enabled">' . get_string('enabled', 'local_tutor_ia') . '</label>';
echo '<div class="col-sm-9">';
echo '<input type="hidden" name="enabled" value="0">';
echo '<input type="checkbox" name="enabled" id="enabled" value="1"' . ($enabled ? ' checked' : '') . ' class="form-check-input">';
echo '<small class="form-text text-muted">' . get_string('enabled_desc', 'local_tutor_ia') . '</small>';
echo '</div>';
echo '</div>';

echo '<div class="form-group row mb-3">';
echo '<label class="col-sm-3 col-form-label" for="socratic_mode">' . get_string('socratic_mode', 'local_tutor_ia') . '</label>';
echo '<div class="col-sm-9">';
echo '<input type="hidden" name="socratic_mode" value="0">';
echo '<input type="checkbox" name="socratic_mode" id="socratic_mode" value="1"' . ($socratic_mode ? ' checked' : '') . ' class="form-check-input">';
echo '<small class="form-text text-muted">' . get_string('socratic_mode_desc', 'local_tutor_ia') . '</small>';
echo '</div>';
echo '</div>';

echo '<div class="form-group row mb-3">';
echo '<label class="col-sm-3 col-form-label" for="syllabus">' . get_string('syllabus', 'local_tutor_ia') . '</label>';
echo '<div class="col-sm-9">';
echo '<textarea name="syllabus" id="syllabus" class="form-control" rows="10" placeholder="' .
     get_string('syllabus_placeholder', 'local_tutor_ia') . '">' . s($syllabus) . '</textarea>';
echo '<small class="form-text text-muted">' . get_string('syllabus_desc', 'local_tutor_ia') . '</small>';
echo '</div>';
echo '</div>';

echo '<div class="form-group row">';
echo '<div class="col-sm-9 offset-sm-3">';
echo '<button type="submit" class="btn btn-primary">' . get_string('save', 'local_tutor_ia') . '</button>';
echo '</div>';
echo '</div>';

echo '</form>';

echo $OUTPUT->footer();
