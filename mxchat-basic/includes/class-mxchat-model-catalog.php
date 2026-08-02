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
                    // OpenAI retired gpt-5.1-chat-latest and gpt-5.3-chat-latest on
                    // 2026-08-10 (replacement gpt-5.6-sol per the deprecations page);
                    // stored settings migrate via mxchat_migrate_deprecated_models()
                    // and the integrator carries a read-time rescue (plan e46b8f).
                    //
                    // 'deprecated_on' (Y-m-d) + 'replacement' are optional per-model
                    // fields: pickers label a future date as "retires <date>" and hide
                    // the entry once the date passes (a saved value keeps rendering via
                    // settings_dropdown_groups($current_model)).
                    'gpt-5.6-sol'          => array('label' => 'GPT-5.6 Sol',          'description' => __('Recommended — newest OpenAI flagship for reasoning, coding and chat', 'mxchat')),
                    'gpt-5.6-terra'        => array('label' => 'GPT-5.6 Terra',        'description' => __('Balanced GPT-5.6 — strong capability at lower cost', 'mxchat')),
                    'gpt-5.6-luna'         => array('label' => 'GPT-5.6 Luna',         'description' => __('Fastest and cheapest GPT-5.6 for lightweight tasks', 'mxchat')),
                    'gpt-5.5'              => array('label' => 'GPT-5.5',              'description' => __('Previous flagship — powerful reasoning and coding model', 'mxchat')),
                    'gpt-5.4'              => array('label' => 'GPT-5.4',              'description' => __('Flagship reasoning and coding model', 'mxchat')),
                    'gpt-5.4-mini'         => array('label' => 'GPT-5.4 Mini',         'description' => __('Fast and affordable with 400K context', 'mxchat')),
                    'gpt-5.4-nano'         => array('label' => 'GPT-5.4 Nano',         'description' => __('Fastest and cheapest for lightweight tasks', 'mxchat')),
                    'gpt-5.2'              => array('label' => 'GPT-5.2',              'description' => __('Best general-purpose & agentic model with fast responses', 'mxchat')),
                    'gpt-5.1-2025-11-13'   => array('label' => 'GPT-5.1',              'description' => __('Flagship for coding & agentic tasks with low reasoning (400K context)', 'mxchat')),
                    'gpt-5'                => array('label' => 'GPT-5',                'description' => __('Flagship for coding, reasoning, and agentic tasks across domains', 'mxchat'), 'deprecated_on' => '2026-12-11', 'replacement' => 'gpt-5.6-sol'),
                    'gpt-5-mini'           => array('label' => 'GPT-5 Mini',           'description' => __('Fast and lightweight', 'mxchat'), 'deprecated_on' => '2026-12-11', 'replacement' => 'gpt-5.6-terra'),
                    'gpt-5-nano'           => array('label' => 'GPT-5 Nano',           'description' => __('Fastest and cheapest; ideal for summarization and classification', 'mxchat'), 'deprecated_on' => '2026-12-11', 'replacement' => 'gpt-5.6-luna'),
                ),
            ),
            'claude' => array(
                'label'                       => __('Anthropic Claude', 'mxchat'),
                'key_option'                  => 'claude_api_key',
                'requires_key_to_load_models' => false,
                'models' => array(
                    'claude-fable-5'              => array('label' => 'Claude Fable 5',      'description' => __('Latest Flagship — newest and most capable Anthropic model', 'mxchat')),
                    'claude-opus-5'               => array('label' => 'Claude Opus 5',       'description' => __('Latest Opus — best for complex agentic and coding work', 'mxchat')),
                    'claude-opus-4-8'             => array('label' => 'Claude Opus 4.8',     'description' => __('Previous Opus generation', 'mxchat')),
                    'claude-opus-4-7'             => array('label' => 'Claude Opus 4.7',     'description' => __('Previous Anthropic flagship model', 'mxchat')),
                    'claude-opus-4-6'             => array('label' => 'Claude Opus 4.6',     'description' => __('Most capable Claude model - recommended', 'mxchat')),
                    'claude-sonnet-5'             => array('label' => 'Claude Sonnet 5',     'description' => __('Newest Sonnet - excellent balance of speed and capability', 'mxchat')),
                    'claude-sonnet-4-6'           => array('label' => 'Claude Sonnet 4.6',   'description' => __('Previous Sonnet - excellent balance of speed and capability', 'mxchat')),
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
                    // DeepSeek retired deepseek-chat / deepseek-reasoner on 2026-07-24;
                    // stored settings migrate via mxchat_migrate_deprecated_models().
                    'deepseek-v4-flash' => array('label' => 'DeepSeek V4 Flash', 'description' => __('Fast and cost-effective', 'mxchat')),
                    'deepseek-v4-pro'   => array('label' => 'DeepSeek V4 Pro',   'description' => __('Most capable DeepSeek model', 'mxchat')),
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
     * Deprecation-aware picker label for a catalog entry (plan e46b8f).
     *
     * @param array $entry Model entry from chat_models().
     * @return string|null Label to show (with a "retires <date>" suffix when a
     *                     future 'deprecated_on' is set), or null when the
     *                     retirement date has passed and the entry must be
     *                     hidden from NEW selections.
     */
    private static function picker_label($entry) {
        if (empty($entry['deprecated_on'])) {
            return $entry['label'];
        }
        $ts = strtotime($entry['deprecated_on'] . ' 00:00:00 UTC');
        if ($ts !== false && time() >= $ts) {
            return null; // retired — hide from new selections
        }
        return sprintf(
            /* translators: 1: model label, 2: retirement date */
            __('%1$s — retires %2$s', 'mxchat'),
            $entry['label'],
            date_i18n(get_option('date_format', 'F j, Y'), $ts)
        );
    }

    /**
     * Settings-dropdown-shaped chat model map keyed by group label, which is
     * what mxchat_model_callback() in class-mxchat-admin.php renders as
     * <optgroup>. Each model maps to its label-with-description string.
     *
     * Retired models (past 'deprecated_on') are hidden — EXCEPT the install's
     * currently saved model, which is re-injected with a "retired" marker so
     * the settings screen never renders a blank select (plan e46b8f).
     *
     * @param string $current_model Optionally, the install's saved model id.
     */
    public static function settings_dropdown_groups($current_model = '') {
        $out = array();
        foreach (self::chat_models() as $provider_slug => $provider) {
            $out[$provider['label']] = array();
            foreach ($provider['models'] as $id => $entry) {
                $label = self::picker_label($entry);
                if ($label === null && $id !== $current_model) {
                    continue;
                }
                if ($label === null) {
                    /* translators: %s: model label */
                    $label = sprintf(__('%s — retired by provider, choose a replacement', 'mxchat'), $entry['label']);
                }
                $out[$provider['label']][$id] = $label;
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
                $label = self::picker_label($entry);
                if ($label === null) {
                    continue; // retired — never offered as a new selection
                }
                $out[$provider_slug][] = array(
                    'value'       => $id,
                    'label'       => $label,
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
                    $label = self::picker_label($entry);
                    if ($label === null) {
                        continue; // retired — not offered during onboarding
                    }
                    $chat_models[$id] = $label;
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

    /**
     * The catalog's CURRENT recommended model for image analysis, per provider.
     *
     * plan-mxchat-20260717-eb371e: add-ons that need "a vision-capable model"
     * (mxchat-vision's analysis paths, mxchat-admin-chat's in-admin image
     * analysis) previously froze a model id at their own call sites — and those
     * pins silently aged out (mxchat-vision was still sending gpt-4o). This is
     * the ONE place that opinion lives now; add-ons ask, with their old pin
     * demoted to a fallback for when mxchat-basic is absent or too old.
     *
     * Choices are deliberate:
     *   - openai: gpt-5.6-sol — the current flagship, vision-capable; OpenAI's
     *     stated replacement for the gpt-5.x-chat-latest aliases retired
     *     2026-08-10 (which this map previously pinned; plan e46b8f).
     *   - claude: the current Haiku — image analysis wants fast + cheap; Haiku
     *     is vision-capable and the catalog's newest Haiku generation.
     *   - xai: the catalog's dated id for what the bare 'grok-4' alias resolves
     *     to today — documented vision-capable. The grok-4-1-fast pair is newer
     *     but its image-input support is unconfirmed; promote it HERE once
     *     confirmed and every asking add-on follows.
     *   - gemini: current stable Flash — vision-capable, no per-image surcharge.
     *
     * NOT covered: image-GENERATION models (Gemini Imagen / Flash Image etc.).
     * Those are a different capability the catalog does not model; add-ons that
     * pin one (mxchat-vision's editing endpoints) are correctly pinned.
     *
     * @param string $provider 'openai' | 'claude' | 'xai' | 'gemini'.
     * @return string Model id, or '' for an unknown provider (caller keeps its
     *                fallback). Overridable via mxchat_default_vision_model.
     */
    public static function default_vision_model($provider) {
        $map = array(
            'openai' => 'gpt-5.6-sol',
            'claude' => 'claude-haiku-4-5-20251001',
            'xai'    => 'grok-4-0709',
            'gemini' => 'gemini-3.5-flash',
        );
        $model = isset($map[$provider]) ? $map[$provider] : '';
        return (string) apply_filters('mxchat_default_vision_model', $model, $provider);
    }

    /**
     * Per-model, per-surface reasoning_effort value.
     *
     * plan-mxchat-20260714-dcb71c: the single source of truth for the
     * reasoning_effort ladder that was previously hand-maintained in FIVE
     * parallel places (integrator streaming/non-streaming chat, integrator
     * web-search Responses, content-generator, mxchat-advanced-content
     * class-ai-client + class-content-calendar). Adding a model to
     * chat_models() and updating THIS method now propagates the routing to
     * every surface — no more drift-on-every-model-add.
     *
     * The mapping is DELIBERATELY per-context because the surfaces genuinely
     * differ today (they are NOT a single clamped ladder):
     *   - 'chat'      — /v1/chat/completions main chat. gpt-5.5 and gpt-5.4 use
     *                   'none'; the *-chat-latest / mini / nano / gpt-5.2 ids
     *                   send NO reasoning_effort.
     *   - 'content'   — content generation (content-generator, AC ai-client,
     *                   AC content-calendar). gpt-5.5 / gpt-5.4 use 'low';
     *                   gpt-5.3-chat-latest / gpt-5.4-mini / gpt-5.4-nano use
     *                   'minimal'; only gpt-5.2 and gpt-5.1-chat-latest send
     *                   nothing. This is an ALLOWLIST (5621dd safe-default):
     *                   a future gpt-5* id not listed gets NO param rather than
     *                   a guessed value that might 400.
     *   - 'websearch' — /v1/responses web-search. Only the gpt-5.6 trio, 5.5,
     *                   5.4, and 5.1-2025-11-13 send 'low'; every other gpt-5 id
     *                   (including gpt-5 / mini / nano / chat-latest) sends
     *                   nothing.
     *
     * @param string $model   Chat model id.
     * @param string $context 'chat' | 'content' | 'websearch'. Defaults to 'chat'.
     * @return string|null    'none'|'low'|'minimal' to send, or null to omit the
     *                        reasoning_effort / reasoning.effort param entirely.
     */
    public static function reasoning_effort_for($model, $context = 'chat') {
        $model = (string) $model;

        // Only the gpt-5 family carries reasoning_effort. Everything else omits.
        if (strpos($model, 'gpt-5') !== 0) {
            return null;
        }

        switch ($context) {
            case 'websearch':
                // integrator web-search (Responses API). Explicit allowlist, no
                // "else" — anything not listed omits reasoning entirely.
                $map = array(
                    'gpt-5.6-sol'        => 'low',
                    'gpt-5.6-terra'      => 'low',
                    'gpt-5.6-luna'       => 'low',
                    'gpt-5.5'            => 'low',
                    'gpt-5.4'            => 'low',
                    'gpt-5.1-2025-11-13' => 'low',
                );
                return isset($map[$model]) ? $map[$model] : null;

            case 'content':
                // content-generator + AC class-ai-client + AC content-calendar.
                // ALLOWLIST (5621dd): a gpt-5* id absent here sends nothing.
                $map = array(
                    'gpt-5.6-sol'         => 'low',
                    'gpt-5.6-terra'       => 'low',
                    'gpt-5.6-luna'        => 'low',
                    'gpt-5.5'             => 'low',
                    'gpt-5.4'             => 'low',
                    'gpt-5.1-2025-11-13'  => 'low',
                    'gpt-5.4-mini'        => 'minimal',
                    'gpt-5.4-nano'        => 'minimal',
                    'gpt-5.3-chat-latest' => 'minimal',
                    'gpt-5'               => 'minimal',
                    'gpt-5-mini'          => 'minimal',
                    'gpt-5-nano'          => 'minimal',
                );
                return isset($map[$model]) ? $map[$model] : null;

            case 'chat':
            default:
                // integrator streaming + non-streaming main chat.
                // gpt-5.1/5.3-chat-latest stay listed here (and in 'content')
                // even though the catalog no longer offers them: bot-level /
                // add-on-saved ids that miss the e46b8f migration must keep
                // routing correctly until every surface is swept.
                $no_reasoning = array('gpt-5.2', 'gpt-5.1-chat-latest', 'gpt-5.3-chat-latest', 'gpt-5.4-mini', 'gpt-5.4-nano');
                if (in_array($model, $no_reasoning, true)) {
                    return null;
                }
                if ($model === 'gpt-5.1-2025-11-13') {
                    return 'low';
                }
                if ($model === 'gpt-5.5') {
                    return 'none';
                }
                if ($model === 'gpt-5.4') {
                    return 'none';
                }
                if (in_array($model, array('gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'), true)) {
                    return 'low';
                }
                return 'minimal';
        }
    }

    /**
     * Whether a NON-DEFAULT temperature value may be sent to this model.
     *
     * plan-mxchat-20260714-dcb71c: consolidates the scattered temperature
     * omissions. Two families reject a custom temperature:
     *   - gpt-5* — accept only the default (1); any other value 400s. Callers
     *     that send temperature=1 (the main chat path) need not consult this —
     *     1 is the default and is accepted. Callers that send a NON-default
     *     value (content-generator 0.7, AC 0.7) gate on this and omit for gpt-5.
     *   - The Anthropic flagships that removed the temperature param entirely
     *     (claude-opus-4-7 / 4-8, claude-fable-5, claude-sonnet-5) — 400 if sent
     *     at all. This is the list the integrator's mxchat_claude_omits_temperature()
     *     and content-generator's call_claude() have hand-maintained.
     *
     * @param string $model Chat model id.
     * @return bool         true if a custom temperature may be sent.
     */
    public static function supports_temperature($model) {
        $model = (string) $model;

        // gpt-5 family accepts only the default temperature (1).
        if (strpos($model, 'gpt-5') === 0) {
            return false;
        }

        // Anthropic flagships that reject the temperature param outright.
        // TRAP: exact-match list — every NEW Anthropic flagship (4.7 and newer)
        // must be added here or it 400s on every chat message while looking
        // fine in the picker. A claude-opus-* prefix rule would wrongly catch
        // claude-opus-4-6 and older, which still accept temperature.
        $claude_no_temp = array('claude-opus-5', 'claude-opus-4-7', 'claude-opus-4-8', 'claude-fable-5', 'claude-sonnet-5');
        if (in_array($model, $claude_no_temp, true)) {
            return false;
        }

        return true;
    }

    /**
     * The completion-token parameter key an OpenAI /v1/chat/completions call
     * must use for this model. gpt-5* require 'max_completion_tokens'; the
     * legacy 'max_tokens' 400s. All other OpenAI-compatible providers
     * (xAI, DeepSeek, custom) use 'max_tokens'.
     *
     * plan-mxchat-20260714-dcb71c: centralizes the `$is_gpt5 ?
     * 'max_completion_tokens' : 'max_tokens'` switch duplicated in
     * content-generator and AC class-ai-client.
     *
     * @param string $model Chat model id.
     * @return string       'max_completion_tokens' | 'max_tokens'
     */
    public static function openai_token_param($model) {
        return strpos((string) $model, 'gpt-5') === 0 ? 'max_completion_tokens' : 'max_tokens';
    }
}
