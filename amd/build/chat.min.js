define('local_tutor_ia/chat', ['jquery'], function($) {
    return {
        init: function() {
            var sendBtn = $('#tutor-ia-btn');
            var inputField = $('#tutor-ia-input');
            var chatBox = $('#tutor-ia-chatbox');
            var launcher = $('#tutor-ia-launcher');
            var chatWindow = $('#tutor-ia-window');
            var closeBtn = $('#tutor-ia-close');

            var chatHistory = [];
            var abortController = null;

            launcher.on('click', function() { chatWindow.css('display', 'flex'); launcher.hide(); inputField.focus(); });
            closeBtn.on('click', function() { chatWindow.hide(); launcher.show(); });

            /**
             * Detect the course ID from the page using multiple strategies.
             * Priority:
             *   1. data-courseid attribute on the launcher (set server-side by hooks.php)
             *   2. M.cfg.courseId (Moodle JS config)
             *   3. body class: body.course-<id>
             *   4. URL pattern /course/view.php?id=X
             *   5. URL pattern /mod/xxx/view.php?id=X -> cmid, passed as cmid param
             *   6. Fallback to 1
             */
            function detectCourseId() {
                // Strategy 1: data attribute from server-side hooks.php (most reliable).
                var dataId = launcher.attr('data-courseid');
                if (dataId && parseInt(dataId) > 1) {
                    return {courseid: parseInt(dataId), cmid: 0};
                }

                // Strategy 2: Moodle JS config.
                if (typeof M !== 'undefined' && M.cfg && M.cfg.courseId && parseInt(M.cfg.courseId) > 1) {
                    return {courseid: parseInt(M.cfg.courseId), cmid: 0};
                }

                // Strategy 3: body class.
                var bodyClasses = document.body.className;
                var bodyMatch = bodyClasses.match(/\bcourse-(\d+)\b/);
                if (bodyMatch && parseInt(bodyMatch[1]) > 1) {
                    return {courseid: parseInt(bodyMatch[1]), cmid: 0};
                }

                // Strategy 4: URL /course/view.php?id=X
                var urlParams = new URLSearchParams(window.location.search);
                var pathname = window.location.pathname;

                if (pathname.match(/\/course\/view\.php/)) {
                    var id = urlParams.get('id');
                    if (id && parseInt(id) > 1) {
                        return {courseid: parseInt(id), cmid: 0};
                    }
                }

                // Strategy 5: URL /mod/xxx/view.php?id=X (this is cmid, not courseid).
                if (pathname.match(/\/mod\/[^/]+\/view\.php/)) {
                    var cmid = urlParams.get('id');
                    if (cmid && parseInt(cmid) > 0) {
                        return {courseid: 0, cmid: parseInt(cmid)};
                    }
                }

                // Fallback.
                return {courseid: urlParams.get('id') || 1, cmid: 0};
            }

            // Protect math expressions from marked.js processing.
            function formatTextWithMathProtection(text) {
                var mathBlocks = [];
                // Step 1: Extract block math $$ ... $$
                var safeText = text.replace(/\$\$([\s\S]*?)\$\$/g, function(match) {
                    mathBlocks.push(match);
                    return '@@MATH_BLOCK_' + (mathBlocks.length - 1) + '@@';
                });
                // Step 2: Extract inline math $ ... $
                safeText = safeText.replace(/\$([\s\S]*?)\$/g, function(match) {
                    mathBlocks.push(match);
                    return '@@MATH_BLOCK_' + (mathBlocks.length - 1) + '@@';
                });

                // Step 3: Let marked.js process markdown.
                var formatted = typeof marked !== 'undefined' ? marked.parse(safeText) : safeText.replace(/\n/g, '<br>');

                // Step 4: Restore math expressions.
                mathBlocks.forEach(function(block, i) {
                    formatted = formatted.replace('@@MATH_BLOCK_' + i + '@@', block);
                });

                return formatted;
            }

            function sendMessage() {
                if (abortController !== null) {
                    abortController.abort();
                    return;
                }

                var message = inputField.val().trim();
                if (message === '') return;

                sendBtn.html('&#9209; Stop').css({'background-color': '#dc3545'});
                inputField.prop('disabled', true);
                abortController = new AbortController();

                chatBox.append('<div style="margin-bottom: 10px; text-align: right;"><span style="background-color: #0f6fc5; color: white; padding: 10px 15px; border-radius: 15px 15px 0 15px; display: inline-block; max-width: 85%;">' + $('<span>').text(message).html() + '</span></div>');
                chatHistory.push({"role": "user", "content": message});

                inputField.val('');
                chatBox.scrollTop(chatBox[0].scrollHeight);

                var courseInfo = detectCourseId();

                var messageId = 'ia-msg-' + Date.now();
                chatBox.append('<div style="margin-bottom: 10px; text-align: left;"><span id="' + messageId + '" class="ia-markdown" style="background-color: white; border: 1px solid #e4e6eb; color: black; padding: 10px 15px; border-radius: 15px 15px 15px 0; display: inline-block; width: 100%; box-sizing: border-box; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"><em>L\'IA lit le cours...</em></span></div>');
                chatBox.scrollTop(chatBox[0].scrollHeight);

                function resetUI(finalText) {
                    chatHistory.push({"role": "assistant", "content": finalText});
                    abortController = null;

                    sendBtn.html('Envoyer').css({'background-color': '#0f6fc5'});
                    sendBtn.prop('disabled', false);
                    inputField.prop('disabled', false).focus();

                    // Render math with KaTeX.
                    setTimeout(function() {
                        var el = document.getElementById(messageId);
                        if (!el) return;

                        if (window.renderMathInElement) {
                            window.renderMathInElement(el, {
                                delimiters: [
                                    {left: '$$', right: '$$', display: true},
                                    {left: '$', right: '$', display: false}
                                ],
                                throwOnError: false
                            });
                        }
                        // Trigger Moodle's native filter if available.
                        try {
                            require(['core_filters/events'], function(events) {
                                events.notifyFilterContentUpdated($(el));
                            });
                        } catch(e) {}
                    }, 50);
                }

                // Build request body.
                var bodyParams = {
                    courseid: courseInfo.courseid,
                    history: JSON.stringify(chatHistory),
                    sesskey: M.cfg.sesskey
                };
                if (courseInfo.cmid > 0) {
                    bodyParams.cmid = courseInfo.cmid;
                }

                fetch(M.cfg.wwwroot + '/local/tutor_ia/ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(bodyParams),
                    signal: abortController.signal
                }).then(function(response) {
                    var reader = response.body.getReader();
                    var decoder = new TextDecoder('utf-8');
                    var fullText = "";
                    $('#' + messageId).html('');

                    function readStream() {
                        reader.read().then(function(result) {
                            if (result.done) {
                                resetUI(fullText);
                                return;
                            }

                            var chunk = decoder.decode(result.value, {stream: true});
                            var lines = chunk.split('\n');

                            lines.forEach(function(line) {
                                if (line.startsWith('data: ') && !line.includes('[DONE]')) {
                                    try {
                                        var dataStr = line.substring(6).trim();
                                        if (dataStr) {
                                            var data = JSON.parse(dataStr);
                                            if (data.choices && data.choices[0].delta && data.choices[0].delta.content) {
                                                fullText += data.choices[0].delta.content;

                                                var formatted = formatTextWithMathProtection(fullText);
                                                $('#' + messageId).html(formatted);
                                                chatBox.scrollTop(chatBox[0].scrollHeight);
                                            }
                                        }
                                    } catch(e) {}
                                }
                            });
                            readStream();
                        }).catch(function(error) {
                            if (error.name === 'AbortError') {
                                fullText += "\n\n*[Generation interrompue]*";
                                $('#' + messageId).html(formatTextWithMathProtection(fullText));
                                resetUI(fullText);
                            }
                        });
                    }
                    readStream();

                }).catch(function(error) {
                    if (error.name === 'AbortError') {
                        $('#' + messageId).html("*[Recherche interrompue avant de commencer]*");
                        resetUI("Interrompu"); return;
                    }
                    $('#' + messageId).html('<span style="color:red;">Erreur reseau ou serveur.</span>');
                    chatHistory.pop(); abortController = null;
                    sendBtn.html('Envoyer').css({'background-color': '#0f6fc5'}).prop('disabled', false);
                    inputField.prop('disabled', false).focus();
                });
            }

            sendBtn.on('click', sendMessage);
            inputField.on('keypress', function(e) { if (e.which === 13) sendMessage(); });
        }
    };
});
