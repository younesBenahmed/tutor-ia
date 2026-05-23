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
    private function build_system_prompt($course_content, $syllabus = '', $coursename = '', $socratic = false) {
        $prompt = "IMPORTANT: Tu DOIS répondre UNIQUEMENT en français. Toutes tes réponses doivent être en langue française, sans exception.\n\n";
        $prompt .= "Tu es un tuteur pédagogique IA intégré à la plateforme Moodle pour le cours : {$coursename}.\n\n";

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

        // Socratic mode.
        if ($socratic) {
            $prompt .= "=== MODE SOCRATIQUE ===\n";
            $prompt .= "Tu NE DOIS JAMAIS donner la reponse directement.\n";
            $prompt .= "Pose des questions guidees pour que l'etudiant trouve par lui-meme.\n";
            $prompt .= "Utilise la maieutique : decompose le probleme, fais reflechir.\n";
            $prompt .= "Si l'etudiant est bloque apres 3 echanges sur le meme sujet, donne un indice plus explicite.\n";
            $prompt .= "Ne donne JAMAIS de code complet, seulement des fragments ou pseudo-code.\n\n";
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
    public function ask_question($history_json, $course_content, $syllabus = '', $coursename = '', $socratic = false, $logid = 0) {

        // Try to use AI Grader settings if available, otherwise use defaults.
        $api_url = get_config('local_dreamu_ai', 'api_endpoint');
        $model_name = get_config('local_dreamu_ai', 'model_name');
        $api_key = get_config('local_dreamu_ai', 'api_key');

        if (empty($api_url)) {
            $api_url = 'http://172.18.0.1:9200/v1/chat/completions';
        }
        if (empty($model_name)) {
            $model_name = 'hal9001-supreme';
        }
        if (empty($api_key)) {
            $api_key = 'dummy';
        }

        $system_prompt = $this->build_system_prompt($course_content, $syllabus, $coursename, $socratic);

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

        $streamed_bytes = 0;
        $in_think = false;
        $think_buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$streamed_bytes, &$in_think, &$think_buffer) {
            $streamed_bytes += strlen($data);

            // Filter <think>...</think> blocks from DeepSeek R1 streaming.
            // SSE data comes as lines: "data: {json}\n\n"
            // We need to parse each SSE line, extract content, filter think tags, reconstruct.
            $lines = explode("\n", $data);
            $output = '';
            foreach ($lines as $line) {
                if (strpos($line, 'data: ') === 0 && strpos($line, '[DONE]') === false) {
                    $json_str = substr($line, 6);
                    $parsed = @json_decode($json_str, true);
                    if ($parsed && isset($parsed['choices'][0]['delta']['content'])) {
                        $content = $parsed['choices'][0]['delta']['content'];

                        // Track think blocks.
                        if (strpos($content, '<think>') !== false) {
                            $in_think = true;
                            $content = preg_replace('/<think>.*$/s', '', $content);
                        }
                        if ($in_think) {
                            if (strpos($content, '</think>') !== false) {
                                $in_think = false;
                                $content = preg_replace('/^.*<\/think>/s', '', $content);
                            } else {
                                // Still inside think block — drop this line entirely.
                                continue;
                            }
                        }

                        if (!empty($content)) {
                            // Reconstruct SSE line with filtered content.
                            $parsed['choices'][0]['delta']['content'] = $content;
                            $output .= 'data: ' . json_encode($parsed, JSON_UNESCAPED_UNICODE) . "\n";
                        }
                        // If content is empty after filtering, drop the line silently.
                    } else {
                        $output .= $line . "\n";
                    }
                } else {
                    $output .= $line . "\n";
                }
            }

            echo $output;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            return strlen($data);
        });

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primary_failed = ($result === false || $http_code >= 500);

        if ($primary_failed) {
            $error = curl_error($ch);
            curl_close($ch);

            // Try fallback endpoint.
            $fallback_url = get_config('local_tutor_ia', 'api_endpoint_fallback');
            $fallback_model = get_config('local_tutor_ia', 'model_name_fallback');

            if (!empty($fallback_url)) {
                if (!empty($fallback_model)) {
                    $data['model'] = $fallback_model;
                }

                $ch2 = curl_init($fallback_url);
                curl_setopt($ch2, CURLOPT_POST, true);
                curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $api_key,
                    'Accept: text/event-stream'
                ]);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch2, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$streamed_bytes) {
                    $streamed_bytes += strlen($data);
                    echo $data;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    return strlen($data);
                });

                $result2 = curl_exec($ch2);
                $http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

                if ($result2 === false || $http_code2 >= 500) {
                    echo "data: {\"choices\":[{\"delta\":{\"content\":\"\\n\\n Le service IA est temporairement indisponible. Veuillez reessayer plus tard.\"}}]}\n\n";
                }

                curl_close($ch2);
            } else {
                echo "data: {\"choices\":[{\"delta\":{\"content\":\"\\n\\n Erreur de connexion a l'IA : $error\"}}]}\n\n";
            }
        } else {
            curl_close($ch);
        }

        // Estimate tokens used (rough: ~4 chars per token for streamed response).
        if ($logid > 0 && $streamed_bytes > 0) {
            global $DB;
            $estimated_tokens = (int) ceil($streamed_bytes / 4);
            $DB->set_field('local_tutor_ia_logs', 'tokens_used', $estimated_tokens, ['id' => $logid]);
        }
    }
}
