<?php
namespace local_tutor_ia;

defined('MOODLE_INTERNAL') || die();

class hooks {

    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $DB, $COURSE;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        // Determine if we are in a course context and if tutor_ia is enabled.
        $courseid = 0;

        // Try to get course ID from the page context.
        $context = $PAGE->context;
        if ($context->contextlevel == CONTEXT_COURSE) {
            $courseid = $context->instanceid;
        } else if ($context->contextlevel == CONTEXT_MODULE) {
            // We're on a module page - get the course from the context path.
            $coursecontext = $context->get_course_context(false);
            if ($coursecontext) {
                $courseid = $coursecontext->instanceid;
            }
        }

        // Fallback: use the global $COURSE.
        if ($courseid <= 1 && isset($COURSE) && $COURSE->id > 1) {
            $courseid = $COURSE->id;
        }

        // Don't show on site home (courseid=1) or outside course context.
        if ($courseid <= 1) {
            return;
        }

        // Check if tutor_ia is enabled for this course.
        try {
            $config = $DB->get_record('local_tutor_ia_config', ['courseid' => $courseid]);
            if (!$config || !$config->enabled) {
                return;
            }
        } catch (\Exception $e) {
            // Table may not exist yet (before upgrade). Don't show widget.
            return;
        }

        $html = '';

        // 1. External libraries.
        $html .= '<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>';
        $html .= '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/katex.min.css">';
        $html .= '<script src="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/katex.min.js"></script>';
        $html .= '<script src="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/contrib/auto-render.min.js"></script>';

        // 2. CSS.
        $html .= '<style>
            #tutor-ia-launcher { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; background-color: #0f6fc5; border-radius: 50%; box-shadow: 0 4px 10px rgba(0,0,0,0.3); cursor: pointer; z-index: 9999; display: flex; align-items: center; justify-content: center; transition: transform 0.2s; }
            #tutor-ia-launcher:hover { transform: scale(1.1); }
            #tutor-ia-window { position: fixed; bottom: 100px; right: 30px; width: 380px; height: 550px; max-height: 80vh; background-color: #f0f2f5; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); z-index: 9998; display: none; flex-direction: column; overflow: hidden; border: 1px solid #ddd; font-family: sans-serif; }
            #tutor-ia-header { background-color: #0f6fc5; color: white; padding: 15px; font-weight: bold; font-size: 16px; display: flex; justify-content: space-between; align-items: center; }
            #tutor-ia-close { cursor: pointer; font-size: 18px; }
            #tutor-ia-chatbox { flex: 1; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; font-size: 14px; }
            #tutor-ia-input-area { padding: 10px; background-color: white; border-top: 1px solid #ccc; display: flex; gap: 10px; }
            #tutor-ia-input { flex: 1; padding: 10px; border-radius: 20px; border: 1px solid #ced4da; outline: none; }
            #tutor-ia-btn { background-color: #0f6fc5; color: white; border: none; padding: 10px 20px; border-radius: 20px; cursor: pointer; font-weight: bold; }
            .ia-markdown table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            .ia-markdown th, .ia-markdown td { border: 1px solid #ddd; padding: 8px; }
            .ia-markdown th { background-color: #f2f2f2; }
            .katex-display { margin: 10px 0; overflow-x: auto; overflow-y: hidden; }
        </style>';

        // 3. Pass course ID as a data attribute for the JS to pick up.
        $html .= '<div id="tutor-ia-launcher" title="Parler au Tuteur IA" data-courseid="' . $courseid . '">';
        $html .= '<svg viewBox="0 0 24 24" width="30" height="30" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
        $html .= '</div>';

        $html .= '<div id="tutor-ia-window">';
        $html .= '<div id="tutor-ia-header"><span>Assistant IA - ' . format_string($COURSE->shortname) . '</span><span id="tutor-ia-close" title="Fermer">&#10005;</span></div>';
        $html .= '<div id="tutor-ia-chatbox"></div>';
        $html .= '<div id="tutor-ia-input-area">';
        $html .= '<input type="text" id="tutor-ia-input" placeholder="Posez votre question...">';
        $html .= '<button id="tutor-ia-btn">Envoyer</button>';
        $html .= '</div>';
        $html .= '</div>';

        $hook->add_html($html);
        $PAGE->requires->js_call_amd('local_tutor_ia/chat', 'init');
    }
}
