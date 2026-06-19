<?php
/**
 * MxChat Model Catalog — single source of truth for chat + embedding models.
 *
 * Plan: plan-mxchat-20260527-d14e89. Consolidates model definitions that
 * previously lived in three places (Settings dropdown in
 * class-mxchat-admin.php, autosave allowlist in admin/class-ajax-handler.php,
 * modal picker catalog in js/mxchat-admin.js, plus a fourth in
 * admin-onboarding-page.php). Adding a new chat/embedding model now means
 * editing THIS file only.
 *
 * Schema:
 *   chat_models() / embedding_models() return:
 *     [ providerSlug => [
 *         'label'         => Display name shown in pickers,
 *         'key_option'    => sub-key under get_option('mxchat_options'),
 *         'requires_key_to_load_models' => bool — OpenRouter is true (key needed
 *                            before model list can be fetched). Surfaces consult
 *                            this to disable / gate the provider in pickers that
 *                            can't run the inline key-then-fetch flow.
 *         'models'        => [ model_id => [ 'label' => …, 'description' => … ] ]
 *       ]
 *     ]
 *
 * @package MxChat
 * @since   3.2.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Model_Catalog {

    /**
     * Chat models grouped by provider. Order within a provider matches the
     * order shown in the Settings modal picker — keep the recommended /
     * flagship model at top.
     */
    public static function chat_models() {
        return array(
            'openrouter' => array(
                'label'                       => __('OpenRouter', 'mxchat'),
                'key_option'                  => 'openrouter_api_key',
                'requires_key_to_load_models' => true,
                'disable_in_onboarding'       => true,
                'models' => array(
                    'openrouter' => array(
                        'label'       => 'OpenRouter',
                        'description' => __('Access 100+ models from multiple providers (add API key to browse)', 'mxchat'),
                    ),
                ),
            ),
            'gemini' => array(
                'label'                       => __('Google Gemini', 'mxchat'),
                'key_option'                  => 'gemini_api_key',
                'requires_key_to_load_models' => false,
                'models' => array(
                    'gemini-3.5-flash'              => array('label' => 'Gemini 3.5 Flash',          'description' => __('Stable — newest Flash generation, recommended default', 'mxchat')),
                    'gemini-3.1-pro-preview'        => array('label' => 'Gemini 3.1 Pro',            'description' => __('Preview — most intelligent, multimodal & agentic', 'mxchat')),
                    'gemini-3-flash-preview'        => array('label' => 'Gemini 3 Flash',            'description' => __('Preview — balanced speed and scale', 'mxchat')),
                    'gemini-3.1-flash-lite'         => array('label' => 'Gemini 3.1 Flash-Lite',     'description' => __('Stable — cost-efficient, high throughput', 'mxchat')),
                    'gemini-3.1-flash-lite-preview' => array('label' => 'Gemini 3.1 Flash-Lite (Preview)', 'description' => __('Preview — latest cost-efficient model', 'mxchat')),
                    'gemini-2.5-pro'                => array('label' => 'Gemini 2.5 Pro',            'description' => __('Stable — advanced thinking, code, math & long context', 'mxchat')),
                    'gemini-2.5-flash'              => array('label' => 'Gemini 2.5 Flash',          'description' => __('Stable — strong price-performance with thinking', 'mxchat')),
                    'gemini-2.5-flash-lite'         => array('label' => 'Gemini 2.5 Flash-Lite',     'description' => __('Stable — ultra fast and low cost', 'mxchat')),
                ),
            ),
            'openai' => array(
                'label'                       => __('OpenAI', 'mxchat'),
                'key_option'                  => 'api_key',
                'requires_key_to_load_models' => false,
                'models' => array(
                    'gpt-5.5'              => array('label' => 'GPT-5.5',              'description' => __('Latest Flagship — newest OpenAI reasoning and coding model', 'mxchat')),
                    'gpt-5.4'              => array('label' => 'GPT-5.4',              'description' => __('Flagship reasoning and coding model', 'mxchat')),
                    'gpt-5.4-mini'         => array('label' => 'GPT-5.4 Mini',         'description' => __('Fast and affordable with 400K context', 'mxchat')),
                    'gpt-5.4-nano'         => array('label' => 'GPT-5.4 Nano',         'description' => __('Fastest and cheapest for lightweight tasks', 'mxchat')),
                    'gpt-5.3-chat-latest'  => array('label' => 'GPT-5.3 Chat',         'description' => __('Optimized for natural conversations with reduced hallucinations', 'mxchat')),
                    'gpt-5.2'              => array('label' => 'GPT-5.2',              'description' => __('Best general-purpose & agentic model with fast responses', 'mxchat')),
                    'gpt-5.1-chat-latest'  => array('label' => 'GPT-5.1 Chat Latest',  'description' => __('Recommended for most use cases', 'mxchat')),
                    'gpt-5.1-2025-11-13'   => array('label' => 'GPT-5.1',              'description' => __('Flagship for coding & agentic tasks with low reasoning (400K context)', 'mxchat')),
                    'gpt-5'                => array('label' => 'GPT-5',                'description' => __('Flagship for coding, reasoning, and agentic tasks across domains', 'mxchat')),
                    'gpt-5-mini'           => array('label' => 'GPT-5 Mini',           'description' => __('Fast and lightweight', 'mxchat')),
                    'gpt-5-nano'           => array('label' => 'GPT-5 Nano',           'description' => __('Fastest and cheapest; ideal for summarization and classification', 'mxchat')),
                ),
            ),
            'claude' => array(
                'label'                       => __('Anthropic Claude', 'mxchat'),
                'key_option'                  => 'claude_api_key',
                'requires_key_to_load_models' => false,
                'models' => array(
                    'claude-fable-5'              => array('label' => 'Claude Fable 5',      'description' => __('Latest Flagship — newest and most capable Anthropic model', 'mxchat')),
                    'claude-opus-4-8'             => array('label' => 'Claude Opus 4.8',     'description' => __('Previous flagship — most capable Opus-tier model', 'mxchat')),
                    'claude-opus-4-7'             => array('label' => 'Claude Opus 4.7',     'description' => __('Previous Anthropic flagship model', 'mxchat')),
                    'claude-opus-4-6'             => array('label' => 'Claude Opus 4.6',     'description' => __('Most capable Claude model - recommended', 'mxchat')),
                    'claude-sonnet-4-6'           => array('label' => 'Claude Sonnet 4.6',   'description' => __('Latest Sonnet - excellent balance of speed and capability', 'mxchat')),
                    'claude-opus-4-5'             => array('label' => 'Claude Opus 4.5',     'description' => __('Highly capable for complex tasks', 'mxchat')),
                    'claude-sonnet-4-5-20250929'  => array('label' => 'Claude Sonnet 4.5',   'description' => __('Best for complex agents and coding', 'mxchat')),
                    'claude-opus-4-1-20250805'    => array('label' => 'Claude Opus 4.1',     'description' => __('Exceptional for specialized complex tasks', 'mxchat')),
                    'claude-haiku-4-5-20251001'   => array('label' => 'Claude Haiku 4.5',    'description' => __('Fastest and most intelligent Haiku', 'mxchat')),
                ),
            ),
            'xai' => array(
                'label'                       => __('xAI Grok', 'mxchat'),
                'key_option'                  => 'xai_api_key',
                'requires_key_to_load_models' => false,
                'models' => array(
                    'grok-4-1-fast-reasoning'     => array('label' => 'Grok 4.1 Fast (Reasoning)',     'description' => __('2M context window and reasoning', 'mxchat')),
                    'grok-4-1-fast-non-reasoning' => array('label' => 'Grok 4.1 Fast (Non-Reasoning)', 'description' => __('2M context window and faster responses', 'mxchat')),
                    'grok-4-0709'                 => array('label' => 'Grok 4',                        'description' => __('Latest flagship model - unparalleled performance in natural language, math and reasoning', 'mxchat')),
                    'grok-3-beta'                 => array('label' => 'Grok-3',                        'description' => __('Powerful model with 131K context', 'mxchat')),
                    'grok-3-fast-beta'            => array('label' => 'Grok-3 Fast',                   'description' => __('High performance with faster responses', 'mxchat')),
                    'grok-3-mini-beta'            => array('label' => 'Grok-3 Mini',                   'description' => __('Affordable model with good performance', 'mxchat')),
                    'grok-3-mini-fast-beta'       => array('label' => 'Grok-3 Mini Fast',              'description' => __('Quick and cost-effective', 'mxchat')),
                ),
            ),
            'deepseek' => array(
                'label'                       => __('DeepSeek', 'mxchat'),
                'key_option'                  => 'deepseek_api_key',
                'requires_key_to_load_models' => false,
                'models' => array(
                    'deepseek-chat' => array('label' => 'DeepSeek-V3', 'description' => __('Advanced AI assistant', 'mxchat')),
                ),
            ),
            'custom' => array(
                'label'                       => __('Custom (OpenAI-compatible)', 'mxchat'),
                'key_option'                  => 'custom_provider_api_key',
                'requires_key_to_load_models' => false,
                'disable_in_onboarding'       => true,
                'models' => array(
                    'custom-provider' => array(
                        'label'       => 'Custom Provider',
                        'description' => __('OpenAI-compatible local LLM (Ollama, LM Studio, vLLM, llama.cpp, Azure OpenAI) — configure Base URL, key, and model in API Keys tab', 'mxchat'),
                    ),
                ),
            ),
        );
    }

    /**
     * Embedding models grouped by provider. Order within a provider matches
     * the order shown in the Settings dropdown.
     */
    public static function embedding_models() {
        return array(
            'openai' => array(
                'label'      => __('OpenAI', 'mxchat'),
                'key_option' => 'api_key',
                'models' => array(
                    'text-embedding-ada-002' => array('label' => 'Ada 2',          'description' => __('1536 dim, recommended', 'mxchat')),
                    'text-embedding-3-small' => array('label' => 'TE3 Small',      'description' => __('1536 dim, efficient', 'mxchat')),
                    'text-embedding-3-large' => array('label' => 'TE3 Large',      'description' => __('3072 dim, powerful', 'mxchat')),
                ),
            ),
            'voyage' => array(
                'label'      => __('Voyage AI', 'mxchat'),
                'key_option' => 'voyage_api_key',
                'models' => array(
                    'voyage-3-large' => array('label' => 'Voyage-3 Large', 'description' => __('2048 dim, most capable', 'mxchat')),
                ),
            ),
            'gemini' => array(
                'label'      => __('Google Gemini', 'mxchat'),
                'key_option' => 'gemini_api_key',
                'models' => array(
                    'gemini-embedding-001' => array('label' => 'Gemini Embedding', 'description' => __('1536 dim', 'mxchat')),
                ),
            ),
        );
    }

    /**
     * Returns the display label for the given chat-or-embedding provider slug.
     * Chat providers checked first; embedding-only providers fall through.
     */
    public static function provider_label($slug) {
        $chat = self::chat_models();
        if (isset($chat[$slug])) return $chat[$slug]['label'];
        $emb = self::embedding_models();
        if (isset($emb[$slug])) return $emb[$slug]['label'];
        return $slug;
    }

    /**
     * Returns the mxchat_options sub-key that holds the API key for the given
     * provider slug. Chat providers checked first; embedding-only providers
     * (voyage) fall through.
     */
    public static function key_option_for_provider($slug) {
        $chat = self::chat_models();
        if (isset($chat[$slug])) return $chat[$slug]['key_option'];
        $emb = self::embedding_models();
        if (isset($emb[$slug])) return $emb[$slug]['key_option'];
        return '';
    }

    /**
     * Flat allowlist of every chat model id across all providers. Used by the
     * sanitize() flow in class-mxchat-admin.php and the AJAX autosave validator
     * in admin/class-ajax-handler.php so a new model id only needs editing in
     * the catalog above.
     */
    public static function chat_model_ids() {
        $ids = array();
        foreach (self::chat_models() as $provider) {
            foreach ($provider['models'] as $id => $_unused) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Flat allowlist of every embedding model id across all providers.
     */
    public static function embedding_model_ids() {
        $ids = array();
        foreach (self::embedding_models() as $provider) {
            foreach ($provider['models'] as $id => $_unused) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Settings-dropdown-shaped chat model map keyed by group label, which is
     * what mxchat_model_callback() in class-mxchat-admin.php renders as
     * <optgroup>. Each model maps to its label-with-description string.
     */
    public static function settings_dropdown_groups() {
        $out = array();
        foreach (self::chat_models() as $provider_slug => $provider) {
            $out[$provider['label']] = array();
            foreach ($provider['models'] as $id => $entry) {
                $out[$provider['label']][$id] = $entry['label'];
            }
        }
        return $out;
    }

    /**
     * Shape used by js/mxchat-admin.js's modal picker grid: provider slug =>
     * array of { value, label, description }. Mirrors the original inline
     * `models = {…}` literal in js/mxchat-admin.js so the modal can read from
     * a localized PHP source without changing its render path.
     */
    public static function js_picker_shape() {
        $out = array();
        foreach (self::chat_models() as $provider_slug => $provider) {
            $out[$provider_slug] = array();
            foreach ($provider['models'] as $id => $entry) {
                $out[$provider_slug][] = array(
                    'value'       => $id,
                    'label'       => $entry['label'],
                    'description' => $entry['description'],
                );
            }
        }
        return $out;
    }

    /**
     * Shape used by the Onboarding wizard's JS catalog (per-provider
     * `{ label, chatModels, embeddingModels, hasKey, requiresKeyToLoadModels }`).
     * `hasKey` is filled in by the caller after consulting mxchat_options.
     */
    public static function onboarding_js_catalog() {
        $chat = self::chat_models();
        $emb  = self::embedding_models();
        $out  = array();

        $all_slugs = array_unique(array_merge(array_keys($chat), array_keys($emb)));
        foreach ($all_slugs as $slug) {
            $chat_models = array();
            if (isset($chat[$slug])) {
                foreach ($chat[$slug]['models'] as $id => $entry) {
                    $chat_models[$id] = $entry['label'];
                }
            }
            $embed_models = array();
            if (isset($emb[$slug])) {
                foreach ($emb[$slug]['models'] as $id => $entry) {
                    $embed_models[$id] = $entry['label'];
                }
            }
            $label = self::provider_label($slug);
            $requires_key = isset($chat[$slug]) ? !empty($chat[$slug]['requires_key_to_load_models']) : false;
            $disable_onb  = isset($chat[$slug]) ? !empty($chat[$slug]['disable_in_onboarding']) : false;

            $out[$slug] = array(
                'label'                   => $label,
                'chatModels'              => $chat_models,
                'embeddingModels'         => $embed_models,
                'requiresKeyToLoadModels' => $requires_key,
                'disableInOnboarding'     => ($disable_onb || $requires_key),
                'keyOption'               => self::key_option_for_provider($slug),
            );
        }
        return $out;
    }

    /**
     * Whether the given chat model can do model-driven function calling (tool use).
     *
     * Used by the native function-calling loop (plan-mxchat-20260617-a41dee) to
     * decide whether to offer tools to the model and to gate the admin toggle.
     *
     * Design: every modern flagship across the catalog's providers supports tool
     * calling, so the DEFAULT is "capable" for any model whose id matches a known
     * provider family prefix (or the OpenRouter meta). This deliberately avoids a
     * per-model allowlist that would go stale the moment the catalog gains a model
     * (the same drift trap documented in CLAUDE.md's model-registry checklist).
     * A model can be force-excluded via the $no_tools list, and the whole verdict
     * is overridable through the `mxchat_model_supports_tools` filter.
     *
     * @param string $model_id Chat model id (e.g. 'gpt-5.5', 'claude-opus-4-8') or
     *                         the 'openrouter' meta selector.
     * @return bool
     */
    public static function supports_tools($model_id) {
        $model_id = (string) $model_id;

        // The OpenRouter meta resolves to a per-account sub-model at request time;
        // most routable models support tools, so default it capable (the loop
        // no-ops gracefully if the chosen sub-model rejects a tools array).
        if ($model_id === 'openrouter') {
            return (bool) apply_filters('mxchat_model_supports_tools', true, $model_id);
        }

        // Known tool-capable provider family prefixes across the catalog.
        $capable_prefixes = array(
            'gpt-', 'o1-', 'o3-', 'o4-',      // OpenAI
            'claude-',                        // Anthropic
            'gemini-',                        // Google
            'grok-', 'xai-',                  // xAI
            'deepseek-',                      // DeepSeek (OpenAI-compatible tools)
            'custom-',                        // OpenAI-compatible custom endpoints
        );

        // Explicit exclusions for any catalog model known NOT to support tools.
        // Empty today — kept as the drift-safe escape hatch.
        $no_tools = array();

        $supported = false;
        if (!in_array($model_id, $no_tools, true)) {
            foreach ($capable_prefixes as $prefix) {
                if (strpos($model_id, $prefix) === 0) {
                    $supported = true;
                    break;
                }
            }
        }

        return (bool) apply_filters('mxchat_model_supports_tools', $supported, $model_id);
    }
}
