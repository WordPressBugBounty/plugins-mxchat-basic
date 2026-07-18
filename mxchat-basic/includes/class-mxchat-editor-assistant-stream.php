<?php
/**
 * MxChat Editor Assistant — streaming transform endpoint (v0.2).
 *
 * Server-Sent Events over admin-ajax (NOT REST). WordPress' REST layer buffers
 * the whole response before serializing, which defeats token-by-token streaming;
 * admin-ajax lets us set our own headers and flush() chunks as they arrive. This
 * mirrors the proven streaming pattern mxchat-basic already ships for its chat
 * widget (class-mxchat-admin.php perform_streaming_test / process_streaming_test_data).
 *
 * POST admin-ajax.php?action=mxchat_editor_assistant_stream
 *   { action: <slug>, content: <string>, locale?: <iso>, nonce: <stream nonce> }
 *   → SSE frames: `data: {"content":"<chunk>"}` … then `data: [DONE]`
 *     (errors arrive as `data: {"error":"<message>"}` then [DONE])
 *
 * Streaming providers: OpenAI (gpt-*), Anthropic (claude-*), Grok (grok-*), and
 * the custom OpenAI-compatible provider. Gemini and any non-streaming provider
 * degrade gracefully here — a single one-shot call is emitted as one content
 * frame, so the client UX is uniform (it just fills instantly instead of
 * progressively). The non-streaming REST /transform endpoint remains the
 * fallback the multi-block path uses.
 *
 * @package MxChatEditorAssistant
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Editor_Assistant_Stream {

    const AJAX_ACTION = 'mxchat_editor_assistant_stream';
    const NONCE       = 'mxchat_editor_assistant_stream';

    public static function register() {
        add_action('wp_ajax_' . self::AJAX_ACTION, array(__CLASS__, 'handle_stream'));
    }

    /**
     * Emit a single SSE frame and flush it to the client.
     */
    private static function emit($payload) {
        echo 'data: ' . wp_json_encode($payload) . "\n\n";
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        }
        @flush();
    }

    private static function emit_error_and_done($message) {
        self::emit(array('error' => $message));
        echo "data: [DONE]\n\n";
        @flush();
    }

    public static function handle_stream() {
        // Streaming headers up front — once we start echoing, we can't send a
        // normal JSON error, so all failures below are SSE error frames.
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no'); // disable nginx proxy buffering
        }

        // Auth: same bar as the REST transform endpoint.
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            self::emit_error_and_done(__('Security check failed. Reload the editor and try again.', 'mxchat'));
            wp_die('', '', array('response' => null));
        }
        if (!current_user_can('edit_posts')) {
            self::emit_error_and_done(__('You do not have permission to use the Editor Assistant.', 'mxchat'));
            wp_die('', '', array('response' => null));
        }
        // Folded into core (plan-8cb0cb) as a FREE feature — no PRO license gate.
        // Availability is controlled by the mxchat_editor_assistant_enabled toggle
        // (off by default); when off, this AJAX action is never registered.

        $slug    = isset($_POST['action_slug']) ? sanitize_key(wp_unslash($_POST['action_slug'])) : '';
        $content = isset($_POST['content']) ? trim((string) wp_unslash($_POST['content'])) : '';
        $locale  = isset($_POST['locale']) ? sanitize_text_field(wp_unslash($_POST['locale'])) : '';

        $actions = MxChat_Editor_Assistant_REST::get_actions();
        if ($slug === '' || !isset($actions[$slug])) {
            self::emit_error_and_done(__('Unknown action.', 'mxchat'));
            wp_die('', '', array('response' => null));
        }
        if ($content === '') {
            self::emit_error_and_done(__('Block has no text content to transform.', 'mxchat'));
            wp_die('', '', array('response' => null));
        }
        if (strlen($content) > 16000) {
            self::emit_error_and_done(__('Block is too long (16k character cap). Split it first.', 'mxchat'));
            wp_die('', '', array('response' => null));
        }

        $action = $actions[$slug];
        $system_prompt = $action['system'];
        if (!empty($action['needs_locale'])) {
            $locale_label = MxChat_Editor_Assistant_REST::resolve_locale_label($locale);
            if ($locale_label === '') {
                self::emit_error_and_done(__('Target language is required for Translate.', 'mxchat'));
                wp_die('', '', array('response' => null));
            }
            $system_prompt = str_replace('{LOCALE_LABEL}', $locale_label, $system_prompt);
        }

        $opts  = get_option('mxchat_options', array());
        $model = isset($opts['model']) ? (string) $opts['model'] : '';
        if ($model === '') {
            self::emit_error_and_done(__('No AI model is configured in MxChat Settings. Set a model first.', 'mxchat'));
            wp_die('', '', array('response' => null));
        }

        $provider = MxChat_Editor_Assistant_REST::detect_provider($model);

        switch ($provider) {
            case 'openai':
                self::stream_openai_compatible('https://api.openai.com/v1/chat/completions', array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . self::key($opts, 'api_key'),
                ), $model, $system_prompt, $content, self::key($opts, 'api_key'), __('OpenAI', 'mxchat'));
                break;

            case 'grok':
                self::stream_openai_compatible('https://api.x.ai/v1/chat/completions', array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . self::key($opts, 'xai_api_key'),
                ), $model, $system_prompt, $content, self::key($opts, 'xai_api_key'), __('Grok (xAI)', 'mxchat'));
                break;

            case 'anthropic':
                self::stream_anthropic($opts, $model, $system_prompt, $content);
                break;

            case 'custom':
                $cfg = MxChat_Editor_Assistant_REST::resolve_custom_provider($opts);
                if ($cfg['base_url'] === '') {
                    self::emit_error_and_done(__('Custom provider Base URL is not configured in MxChat settings.', 'mxchat'));
                    break;
                }
                // Convert assoc headers back to the colon-style list curl expects.
                $hdrs = array();
                foreach ($cfg['headers'] as $k => $v) {
                    $hdrs[] = $k . ': ' . $v;
                }
                self::stream_openai_compatible($cfg['chat_url'], $hdrs, $cfg['model'], $system_prompt, $content, $cfg['api_key'], __('custom provider', 'mxchat'));
                break;

            case 'google':
            default:
                // Gemini + anything else: no streaming branch — one-shot the
                // non-streaming dispatcher and emit the full result as one frame.
                self::stream_via_fallback($slug, $content, $locale);
                break;
        }

        wp_die('', '', array('response' => null));
    }

    private static function key($opts, $name) {
        return isset($opts[$name]) ? trim((string) $opts[$name]) : '';
    }

    /**
     * Stream from any OpenAI-compatible Chat Completions endpoint (OpenAI, Grok,
     * custom/Azure). Parses `choices[].delta.content` chunks.
     */
    private static function stream_openai_compatible($url, $headers, $model, $system_prompt, $user_content, $api_key, $provider_label) {
        if ($api_key === '' && stripos(implode(' ', $headers), 'api-key:') === false) {
            /* translators: %s: provider label */
            self::emit_error_and_done(sprintf(__('%s API key is missing in MxChat settings.', 'mxchat'), $provider_label));
            return;
        }

        $body = array(
            'model'       => $model,
            'messages'    => array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user',   'content' => $user_content),
            ),
            'temperature' => 0.4,
            'stream'      => true,
        );

        // GPT-5 reasoning models reject non-default temperature (API 400: only the
        // default 1 is supported). Mirror mxchat-admin-chat's send_to_openai (15bd6a)
        // / mxchat-basic 3.2.9's integrator exactly: send temperature 1 and route
        // reasoning_effort. Only triggers for gpt-5* ids, so Grok / custom OpenAI-
        // compatible providers keep the 0.4 default above.
        if (strpos($model, 'gpt-5') === 0) {
            $body['temperature'] = 1;
            $no_reasoning_models = array('gpt-5.2', 'gpt-5.1-chat-latest', 'gpt-5.3-chat-latest', 'gpt-5.4-mini', 'gpt-5.4-nano');
            if (!in_array($model, $no_reasoning_models, true)) {
                if ($model === 'gpt-5.1-2025-11-13') {
                    $body['reasoning_effort'] = 'low';
                } elseif ($model === 'gpt-5.5' || $model === 'gpt-5.4') {
                    $body['reasoning_effort'] = 'none';
                } elseif (in_array($model, array('gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'), true)) {
                    // plan-6fb107: gpt-5.6 rejects 'minimal' (live 400: "does not support
                    // 'minimal'"); core uses 'low'. Mirror mxchat-basic integrator :8444.
                    $body['reasoning_effort'] = 'low';
                } else {
                    $body['reasoning_effort'] = 'minimal';
                }
            }
        }

        $emitted = self::run_curl_stream($url, $headers, $body, 'openai');
        self::finish_stream($emitted, $provider_label);
    }

    /**
     * Stream from Anthropic Messages API. Parses content_block_delta text deltas.
     */
    private static function stream_anthropic($opts, $model, $system_prompt, $user_content) {
        // mxchat-basic stores the Claude/Anthropic key under 'claude_api_key'
        // (canonical core option). Read that first; fall back to 'anthropic_api_key'.
        $api_key = self::key($opts, 'claude_api_key');
        if ($api_key === '') {
            $api_key = self::key($opts, 'anthropic_api_key');
        }
        if ($api_key === '') {
            self::emit_error_and_done(__('Anthropic API key is missing in MxChat settings.', 'mxchat'));
            return;
        }
        $headers = array(
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        );
        $body = array(
            'model'      => $model,
            'max_tokens' => 1024,
            'system'     => $system_prompt,
            'messages'   => array(
                array('role' => 'user', 'content' => $user_content),
            ),
            'stream'     => true,
        );
        $emitted = self::run_curl_stream('https://api.anthropic.com/v1/messages', $headers, $body, 'anthropic');
        self::finish_stream($emitted, __('Anthropic', 'mxchat'));
    }

    /**
     * Shared curl streaming core. Returns the number of content chunks emitted
     * (0 means nothing streamed — caller surfaces an error).
     */
    private static function run_curl_stream($url, $headers, $body, $format) {
        $emitted = 0;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, wp_json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$emitted, $format) {
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, 'data: ') !== 0) {
                    continue;
                }
                $json_str = substr($line, 6);
                if ($json_str === '[DONE]') {
                    continue;
                }
                $json = json_decode($json_str, true);
                if (!is_array($json)) {
                    continue;
                }
                if ($format === 'anthropic') {
                    if (isset($json['type'], $json['delta']['text']) && $json['type'] === 'content_block_delta') {
                        self::emit(array('content' => $json['delta']['text']));
                        $emitted++;
                    }
                } else { // openai-compatible
                    if (isset($json['choices'][0]['delta']['content'])) {
                        self::emit(array('content' => $json['choices'][0]['delta']['content']));
                        $emitted++;
                    }
                }
            }
            return strlen($data);
        });

        curl_exec($ch);
        $http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            self::emit(array('error' => 'Connection error: ' . $curl_error));
            return $emitted;
        }
        if ($http_code !== 200 && $emitted === 0) {
            self::emit(array('error' => 'Provider returned HTTP ' . $http_code));
            return $emitted;
        }
        return $emitted;
    }

    private static function finish_stream($emitted, $provider_label) {
        if ($emitted === 0) {
            /* translators: %s: provider label */
            self::emit(array('error' => sprintf(__('%s returned no streamed content.', 'mxchat'), $provider_label)));
        }
        echo "data: [DONE]\n\n";
        @flush();
    }

    /**
     * Non-streaming providers (Gemini, etc.): run the same dispatcher the REST
     * endpoint uses and emit the full result as one frame so the client UX stays
     * identical. Reuses the REST class's provider calls via a minimal request.
     */
    private static function stream_via_fallback($slug, $content, $locale) {
        $request = new WP_REST_Request('POST', '/mxchat-editor/v1/transform');
        $request->set_param('action', $slug);
        $request->set_param('content', $content);
        $request->set_param('locale', $locale);
        $result = MxChat_Editor_Assistant_REST::handle_transform($request);

        if (is_wp_error($result)) {
            self::emit(array('error' => $result->get_error_message()));
        } else {
            $data = $result->get_data();
            $text = isset($data['result']) ? (string) $data['result'] : '';
            if ($text === '') {
                self::emit(array('error' => __('Provider returned no content.', 'mxchat')));
            } else {
                self::emit(array('content' => $text));
            }
        }
        echo "data: [DONE]\n\n";
        @flush();
    }
}
