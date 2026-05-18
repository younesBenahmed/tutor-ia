<?php
defined('MOODLE_INTERNAL') || die();

class tutor_ia_api {

    /**
     * Build the system prompt with strict syllabus enforcement.
     *
     * @param string $course_content Extracted course content
     * @param string $syllabus Optional custom syllabus from professor
     * @param string $coursename Course full name
     * @return string
     */
    private function build_system_prompt($course_content, $syllabus = '', $coursename = '') {
        $prompt = "Tu es un tuteur pédagogique IA intégré à la plateforme Moodle pour le cours : {$coursename}.\n\n";

        // Strict rules section.
        $prompt .= "=== RÈGLES STRICTES ===\n";
        $prompt .= "1. Tu ne dois répondre QU'AUX questions en rapport avec le contenu du cours ci-dessous.\n";
        $prompt .= "2. Si un étudiant pose une question hors-sujet (culture générale, autre matière, questions personnelles, etc.), " .
                   "tu DOIS refuser poliment avec EXACTEMENT cette phrase : " .
                   "\"Cette question ne fait pas partie du programme de ce cours. Je ne peux répondre qu'aux questions en lien avec le contenu du cours.\"\n";
        $prompt .= "3. Ne génère JAMAIS de code complet à la place de l'étudiant. Guide-le, donne des indices, explique les concepts.\n";
        $prompt .= "4. Réponds en français, de manière claire, concise et structurée (utilise le Markdown).\n";
        $prompt .= "5. IMPORTANT POUR LES MATHÉMATIQUES : N'utilise JAMAIS les crochets \\[ ou \\]. " .
                   "Utilise STRICTEMENT le symbole \$\$ pour les équations centrées (exemple : \$\$\\frac{a}{b}\$\$) " .
                   "et le symbole \$ pour les variables en ligne (exemple : l'énergie \$E=mc^2\$).\n\n";

        // Custom syllabus from professor if defined.
        if (!empty($syllabus)) {
            $prompt .= "=== PROGRAMME / INSTRUCTIONS DU PROFESSEUR ===\n";
            $prompt .= trim($syllabus) . "\n\n";
        }

        // Course content.
        $prompt .= "=== CONTENU DU COURS ===\n";
        $prompt .= $course_content . "\n";

        return $prompt;
    }

    /**
     * Send a question to the AI with streaming response.
     *
     * @param string $history_json JSON-encoded chat history
     * @param string $course_content Extracted course content
     * @param string $syllabus Optional custom syllabus
     * @param string $coursename Course full name
     */
    public function ask_question($history_json, $course_content, $syllabus = '', $coursename = '') {

        $api_url = 'http://100.76.166.71:8200/v1/chat/completions';
        $model_name = 'hal-9001-chat';
        $api_key = 'sk-dummy';

        $system_prompt = $this->build_system_prompt($course_content, $syllabus, $coursename);

        $messages = [
            ['role' => 'system', 'content' => $system_prompt]
        ];

        $history = json_decode($history_json, false);
        if (is_array($history)) {
            $messages = array_merge($messages, $history);
        } else {
            $messages[] = ['role' => 'user', 'content' => $history_json];
        }

        $data = [
            'model' => $model_name,
            'messages' => $messages,
            'temperature' => 0.7,
            'stream' => true
        ];

        // Disable PHP buffering for streaming.
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }
        @ini_set('zlib.output_compression', 0);
        @ini_set('implicit_flush', 1);
        header('X-Accel-Buffering: no');

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
            'Accept: text/event-stream'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
            echo $data;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            return strlen($data);
        });

        $result = curl_exec($ch);

        if ($result === false) {
            $error = curl_error($ch);
            echo "data: {\"choices\":[{\"delta\":{\"content\":\"\\n\\n Erreur de connexion a l'IA : $error\"}}]}\n\n";
        }

        curl_close($ch);
    }
}
