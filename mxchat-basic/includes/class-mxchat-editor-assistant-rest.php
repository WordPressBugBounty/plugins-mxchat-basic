<?php
/**
 * REST endpoint that transforms a single block of text using whichever chat
 * provider the site has configured in mxchat-basic.
 *
 * POST /wp-json/mxchat-editor/v1/transform
 *   { action: <slug>, content: <string>, locale?: <iso code> }
 *   → { result: <string>, action: <slug>, model: <string> }
 *
 * Auth: standard WP REST nonce (X-WP-Nonce header set by the Gutenberg sidebar
 * via wp.apiFetch). Caller must have edit_posts capability.
 *
 * Provider dispatch: reads mxchat_options['model'] + the matching API key
 * (openai api_key, gemini_api_key, claude_api_key for Anthropic) and makes a
 * one-shot chat-completion call. Grok / custom providers are out of scope for v0.1.
 *
 * @package MxChatEditorAssistant
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Editor_Assistant_REST {

    const NAMESPACE_V1 = 'mxchat-editor/v1';
    const ROUTE_TRANSFORM = '/transform';

    public static function register() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(
            self::NAMESPACE_V1,
            self::ROUTE_TRANSFORM,
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'handle_transform'),
                'permission_callback' => array(__CLASS__, 'permission_check'),
                'args' => array(
                    'action' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                    'content' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                    'locale' => array(
                        'required' => false,
                        'type'     => 'string',
                    ),
                ),
            )
        );
    }

    public static function permission_check() {
        return current_user_can('edit_posts');
    }

    /**
     * Action registry. Each action defines a system prompt template; the user
     * content is appended verbatim. Keep prompts short — block-level surgery,
     * not essay generation.
     */
    public static function get_actions() {
        return array(
            'rewrite_concise' => array(
                'label'       => __('Rewrite — concise', 'mxchat'),
                'description' => __('Shorter, tighter version of the selected block.', 'mxchat'),
                'system'      => 'Rewrite the user\'s text to be more concise. Preserve the meaning, tone, and any inline HTML formatting (links, em, strong). Return ONLY the rewritten text — no preface, no explanation, no quotation marks around the output.',
            ),
            'rewrite_expand' => array(
                'label'       => __('Rewrite — expand', 'mxchat'),
                'description' => __('Longer, more detailed version of the selected block.', 'mxchat'),
                'system'      => 'Rewrite the user\'s text in an expanded form, adding helpful detail and clarification while keeping the same meaning. Preserve inline HTML formatting. Return ONLY the rewritten text.',
            ),
            'rewrite_formal' => array(
                'label'       => __('Rewrite — formal', 'mxchat'),
                'description' => __('More formal, professional register.', 'mxchat'),
                'system'      => 'Rewrite the user\'s text in a more formal, professional tone. Preserve meaning and inline HTML formatting. Return ONLY the rewritten text.',
            ),
            'rewrite_casual' => array(
                'label'       => __('Rewrite — casual', 'mxchat'),
                'description' => __('More casual, conversational tone.', 'mxchat'),
                'system'      => 'Rewrite the user\'s text in a more casual, conversational tone. Preserve meaning and inline HTML formatting. Return ONLY the rewritten text.',
            ),
            'summarize' => array(
                'label'       => __('Summarize', 'mxchat'),
                'description' => __('One-paragraph summary of the selected block.', 'mxchat'),
                'system'      => 'Summarize the user\'s text in a single paragraph. Capture the key point only. Return ONLY the summary text, no preface.',
            ),
            'translate' => array(
                'label'       => __('Translate', 'mxchat'),
                'description' => __('Translate to a target language.', 'mxchat'),
                'system'      => 'Translate the user\'s text into {LOCALE_LABEL}. Preserve meaning and inline HTML formatting. Return ONLY the translated text, no preface, no explanation.',
                'needs_locale' => true,
            ),
            'continue' => array(
                'label'       => __('Continue writing', 'mxchat'),
                'description' => __('Generate the next sentence(s) that would naturally follow.', 'mxchat'),
                'system'      => 'Continue the user\'s text. Generate 1-3 sentences that naturally follow what they have written, matching tone and style. Return ONLY the continuation — do NOT repeat the user\'s text. Do NOT add a preface like "Here\'s a continuation:".',
            ),
            'fix_grammar' => array(
                'label'       => __('Fix grammar & spelling', 'mxchat'),
                'description' => __('Correct grammar and spelling without changing meaning.', 'mxchat'),
                'system'      => 'Correct grammar and spelling in the user\'s text. Do NOT change meaning, tone, or style. If there is nothing to fix, return the original text unchanged. Return ONLY the corrected text.',
            ),
        );
    }

    /**
     * Lighter version of the action registry for JS — strips the system prompt
     * so it doesn't ship to the browser. Order is preserved.
     */
    public static function get_actions_for_client() {
        $out = array();
        foreach (self::get_actions() as $slug => $cfg) {
            $out[] = array(
                'slug'         => $slug,
                'label'        => $cfg['label'],
                'description'  => $cfg['description'],
                'needs_locale' => !empty($cfg['needs_locale']),
            );
        }
        return $out;
    }

    public static function handle_transform(WP_REST_Request $request) {
        // Folded into core (plan-8cb0cb) as a FREE feature — no PRO license gate.
        // The block-editor availability is controlled by the mxchat_editor_assistant_enabled
        // toggle (off by default); when off, this route is never registered.
        $slug    = sanitize_key($request->get_param('action'));
        $content = (string) $request->get_param('content');
        $locale  = sanitize_text_field((string) $request->get_param('locale'));

        $actions = self::get_actions();
        if (!isset($actions[$slug])) {
            return new WP_Error(
                'mxchat_editor_assistant_unknown_action',
                __('Unknown action.', 'mxchat'),
                array('status' => 400)
            );
        }

        $content = trim($content);
        if ($content === '') {
            return new WP_Error(
                'mxchat_editor_assistant_empty',
                __('Block has no text content to transform.', 'mxchat'),
                array('status' => 400)
            );
        }
        if (strlen($content) > 16000) {
            return new WP_Error(
                'mxchat_editor_assistant_too_long',
                __('Block is too long for v0.1 (16k char cap). Split it first.', 'mxchat'),
                array('status' => 413)
            );
        }

        $action = $actions[$slug];
        $system_prompt = $action['system'];
        if (!empty($action['needs_locale'])) {
            $locale_label = self::resolve_locale_label($locale);
            if ($locale_label === '') {
                return new WP_Error(
                    'mxchat_editor_assistant_locale',
                    __('Target language is required for Translate.', 'mxchat'),
                    array('status' => 400)
                );
            }
            $system_prompt = str_replace('{LOCALE_LABEL}', $locale_label, $system_prompt);
        }

        $provider_result = self::dispatch_to_provider($system_prompt, $content);
        if (is_wp_error($provider_result)) {
            return $provider_result;
        }

        return rest_ensure_response(array(
            'result' => $provider_result['text'],
            'action' => $slug,
            'model'  => $provider_result['model'],
        ));
    }

    public static function resolve_locale_label($iso) {
        $map = array(
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'ja' => 'Japanese',
            'pt' => 'Portuguese',
        );
        return isset($map[$iso]) ? $map[$iso] : '';
    }

    /**
     * Provider dispatch — one-shot chat completion against whichever model is
     * configured in mxchat_options. Mirrors the providers mxchat-basic ships:
     * OpenAI (gpt-*), Anthropic (claude-*), Google (gemini-*).
     *
     * Grok and any custom provider degrade with a clear error in v0.1.
     */
    private static function dispatch_to_provider($system_prompt, $user_content) {
        $opts = get_option('mxchat_options', array());
        $model = isset($opts['model']) ? (string) $opts['model'] : '';
        if ($model === '') {
            return new WP_Error(
                'mxchat_editor_assistant_no_model',
                __('No AI model is configured in MxChat Settings. Set a model first.', 'mxchat'),
                array('status' => 503)
            );
        }

        $provider = self::detect_provider($model);
        switch ($provider) {
            case 'openai':
                return self::call_openai($opts, $model, $system_prompt, $user_content);
            case 'anthropic':
                return self::call_anthropic($opts, $model, $system_prompt, $user_content);
            case 'google':
                return self::call_gemini($opts, $model, $system_prompt, $user_content);
            case 'grok':
                return self::call_grok($opts, $model, $system_prompt, $user_content);
            case 'custom':
                return self::call_custom($opts, $system_prompt, $user_content);
            default:
                return new WP_Error(
                    'mxchat_editor_assistant_provider_unsupported',
                    sprintf(
                        /* translators: %s: model name */
                        __('The Editor Assistant supports OpenAI, Anthropic, Google, Grok (xAI), and custom OpenAI-compatible providers. Configured model (%s) is not yet supported — set the site to one of those in MxChat → Settings.', 'mxchat'),
                        esc_html($model)
                    ),
                    array('status' => 501)
                );
        }
    }

    /**
     * Map the configured model id to a provider family. Mirrors mxchat-basic's
     * own routing: prefix-based, with 'custom-provider' as the sentinel model
     * for the OpenAI-compatible custom/Azure path.
     */
    public static function detect_provider($model) {
        $m = strtolower($model);
        if ($m === 'custom-provider') {
            return 'custom';
        }
        if (strpos($m, 'gpt-') === 0 || strpos($m, 'o1') === 0 || strpos($m, 'o3') === 0 || strpos($m, 'o4') === 0) {
            return 'openai';
        }
        if (strpos($m, 'claude') !== false) {
            return 'anthropic';
        }
        if (strpos($m, 'gemini') !== false) {
            return 'google';
        }
        if (strpos($m, 'grok') === 0) {
            return 'grok';
        }
        return 'unsupported';
    }

    private static function call_openai($opts, $model, $system_prompt, $user_content) {
        $api_key = isset($opts['api_key']) ? trim((string) $opts['api_key']) : '';
        if ($api_key === '') {
            return new WP_Error(
                'mxchat_editor_assistant_no_key',
                __('OpenAI API key is missing in MxChat settings.', 'mxchat'),
                array('status' => 503)
            );
        }
        $body = array(
            'model'       => $model,
            'messages'    => array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user',   'content' => $user_content),
            ),
            'temperature' => 0.4,
        );

        // GPT-5 reasoning models reject non-default temperature (API 400: only the
        // default 1 is supported) and route reasoning via reasoning_effort. Mirror the
        // streaming sibling (class-mxchat-editor-assistant-stream.php stream_openai_compatible)
        // and mxchat-basic's integrator EXACTLY so the non-streaming path (multi-block +
        // any non-stream fallback) is GPT-5-safe. Without this the multi-block path 400s on
        // every GPT-5 model — which is most installs (plan-8cb0cb fold consistency fix).
        // Only triggers for gpt-5* ids; Grok / custom OpenAI-compatible keep the 0.4 above.
        if (strpos($model, 'gpt-5') === 0) {
            $body['temperature'] = 1;
            $no_reasoning_models = array('gpt-5.2', 'gpt-5.1-chat-latest', 'gpt-5.3-chat-latest', 'gpt-5.4-mini', 'gpt-5.4-nano');
            if (!in_array($model, $no_reasoning_models, true)) {
                if ($model === 'gpt-5.1-2025-11-13') {
                    $body['reasoning_effort'] = 'low';
                } elseif ($model === 'gpt-5.5' || $model === 'gpt-5.4') {
                    $body['reasoning_effort'] = 'none';
                } elseif (in_array($model, array('gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'), true)) {
                    // plan-6fb107: gpt-5.6 rejects 'minimal' (live 400); core uses 'low'.
                    $body['reasoning_effort'] = 'low';
                } else {
                    $body['reasoning_effort'] = 'minimal';
                }
            }
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data) || empty($data['choices'][0]['message']['content'])) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'OpenAI request failed';
            return new WP_Error('mxchat_editor_assistant_openai', $msg, array('status' => 502));
        }
        return array(
            'text'  => trim((string) $data['choices'][0]['message']['content']),
            'model' => $model,
        );
    }

    private static function call_anthropic($opts, $model, $system_prompt, $user_content) {
        // mxchat-basic stores the Claude/Anthropic key under 'claude_api_key' (the
        // canonical core option). Read that first; fall back to 'anthropic_api_key'
        // for forward-compat if a future field ever uses that name.
        $api_key = trim((string) ($opts['claude_api_key'] ?? $opts['anthropic_api_key'] ?? ''));
        if ($api_key === '') {
            return new WP_Error(
                'mxchat_editor_assistant_no_key',
                __('Anthropic API key is missing in MxChat settings.', 'mxchat'),
                array('status' => 503)
            );
        }
        $body = array(
            'model'      => $model,
            'max_tokens' => 1024,
            // Prompt-cache breakpoint (plan 1ff43b): the editor-assistant
            // instruction is a fixed template reused across requests.
            'system'     => trim((string) $system_prompt) === '' ? $system_prompt : array(
                array('type' => 'text', 'text' => $system_prompt, 'cache_control' => array('type' => 'ephemeral')),
            ),
            'messages'   => array(
                array('role' => 'user', 'content' => $user_content),
            ),
        );
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 45,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Anthropic request failed';
            return new WP_Error('mxchat_editor_assistant_anthropic', $msg, array('status' => 502));
        }
        // claude-fable-5 prepends a `thinking` block, so content[0] is not the
        // answer — take the first TEXT block.
        $text = '';
        foreach ((array) ($data['content'] ?? array()) as $block) {
            if (isset($block['type'], $block['text']) && $block['type'] === 'text' && trim($block['text']) !== '') {
                $text = trim($block['text']);
                break;
            }
        }
        if ($text === '') {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Anthropic request failed';
            return new WP_Error('mxchat_editor_assistant_anthropic', $msg, array('status' => 502));
        }
        return array(
            'text'  => $text,
            'model' => $model,
        );
    }

    private static function call_gemini($opts, $model, $system_prompt, $user_content) {
        $api_key = isset($opts['gemini_api_key']) ? trim((string) $opts['gemini_api_key']) : '';
        if ($api_key === '') {
            return new WP_Error(
                'mxchat_editor_assistant_no_key',
                __('Google Gemini API key is missing in MxChat settings.', 'mxchat'),
                array('status' => 503)
            );
        }
        // Gemini's REST shape — system prompt rides as a separate system_instruction.
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($model),
            rawurlencode($api_key)
        );
        $body = array(
            'system_instruction' => array(
                'parts' => array(array('text' => $system_prompt)),
            ),
            'contents' => array(
                array(
                    'role'  => 'user',
                    'parts' => array(array('text' => $user_content)),
                ),
            ),
            'generationConfig' => array(
                'temperature' => 0.4,
            ),
        );
        $response = wp_remote_post($url, array(
            'timeout' => 45,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Gemini request failed';
            return new WP_Error('mxchat_editor_assistant_gemini', $msg, array('status' => 502));
        }
        $parts = isset($data['candidates'][0]['content']['parts']) ? $data['candidates'][0]['content']['parts'] : array();
        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        if ($text === '') {
            return new WP_Error('mxchat_editor_assistant_gemini', __('Gemini returned no text content.', 'mxchat'), array('status' => 502));
        }
        return array(
            'text'  => trim($text),
            'model' => $model,
        );
    }

    /**
     * Grok (xAI) — OpenAI-compatible Chat Completions at api.x.ai. Reads the same
     * 'xai_api_key' option mxchat-basic uses for its Grok provider.
     */
    private static function call_grok($opts, $model, $system_prompt, $user_content) {
        $api_key = isset($opts['xai_api_key']) ? trim((string) $opts['xai_api_key']) : '';
        if ($api_key === '') {
            return new WP_Error(
                'mxchat_editor_assistant_no_key',
                __('Grok (xAI) API key is missing in MxChat settings.', 'mxchat'),
                array('status' => 503)
            );
        }
        $body = array(
            'model'       => $model,
            'messages'    => array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user',   'content' => $user_content),
            ),
            'temperature' => 0.4,
        );
        $response = wp_remote_post('https://api.x.ai/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data) || empty($data['choices'][0]['message']['content'])) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Grok request failed';
            return new WP_Error('mxchat_editor_assistant_grok', $msg, array('status' => 502));
        }
        return array(
            'text'  => trim((string) $data['choices'][0]['message']['content']),
            'model' => $model,
        );
    }

    /**
     * Custom OpenAI-compatible provider (Ollama, LM Studio, vLLM, llama.cpp,
     * Azure OpenAI, …). Resolves the same custom_provider_* config keys + auth
     * scheme + Azure api-version query string that mxchat-basic's chat dispatcher
     * uses, so a site configured for Azure chat works here with zero extra setup.
     */
    private static function call_custom($opts, $system_prompt, $user_content) {
        $cfg = self::resolve_custom_provider($opts);
        if ($cfg['base_url'] === '') {
            return new WP_Error(
                'mxchat_editor_assistant_no_custom_base',
                __('Custom provider Base URL is not configured in MxChat settings.', 'mxchat'),
                array('status' => 503)
            );
        }
        $body = array(
            'model'       => $cfg['model'],
            'messages'    => array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user',   'content' => $user_content),
            ),
            'temperature' => 0.4,
        );
        $response = wp_remote_post($cfg['chat_url'], array(
            'timeout' => 45,
            'headers' => $cfg['headers'],
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data) || empty($data['choices'][0]['message']['content'])) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Custom provider request failed';
            return new WP_Error('mxchat_editor_assistant_custom', $msg, array('status' => 502));
        }
        return array(
            'text'  => trim((string) $data['choices'][0]['message']['content']),
            'model' => $cfg['model'],
        );
    }

    /**
     * Resolve the custom-provider config from mxchat_options. Returns the same
     * shape mxchat-basic's mxchat_resolve_custom_provider() returns, but with
     * an associative-array header map (wp_remote_post / curl friendly).
     *
     * @return array{base_url:string,api_key:string,model:string,auth_scheme:string,api_version:string,chat_url:string,headers:array}
     */
    public static function resolve_custom_provider($opts) {
        $base_url    = isset($opts['custom_provider_base_url']) ? rtrim(trim((string) $opts['custom_provider_base_url']), '/') : '';
        $api_key     = isset($opts['custom_provider_api_key']) ? trim((string) $opts['custom_provider_api_key']) : '';
        $model       = isset($opts['custom_provider_model']) ? trim((string) $opts['custom_provider_model']) : '';
        $auth_scheme = isset($opts['custom_provider_auth_scheme']) ? (string) $opts['custom_provider_auth_scheme'] : 'bearer';
        $api_version = isset($opts['custom_provider_api_version']) ? trim((string) $opts['custom_provider_api_version']) : '';

        $chat_url = $base_url === '' ? '' : $base_url . '/chat/completions';
        if ($chat_url !== '' && $api_version !== '') {
            $chat_url .= (strpos($chat_url, '?') === false ? '?' : '&') . 'api-version=' . rawurlencode($api_version);
        }

        $headers = array('Content-Type' => 'application/json');
        if ($api_key !== '') {
            if ($auth_scheme === 'api-key') {
                $headers['api-key'] = $api_key;
            } else {
                $headers['Authorization'] = 'Bearer ' . $api_key;
            }
        }

        return array(
            'base_url'    => $base_url,
            'api_key'     => $api_key,
            'model'       => $model !== '' ? $model : 'default',
            'auth_scheme' => $auth_scheme,
            'api_version' => $api_version,
            'chat_url'    => $chat_url,
            'headers'     => $headers,
        );
    }
}
