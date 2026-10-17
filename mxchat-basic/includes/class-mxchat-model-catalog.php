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
 * Model lifecycle (direct task M4, Maxwell 2026-10-16: "discontinue any model
 * that's discontinued and migrate users to a valid model"). Three states, and
 * every picker offers ONLY the first:
 *   - current            no lifecycle field. Offered for new picks.
 *   - 'legacy' => true   older but still served by the provider. Not offered
 *                        for new picks; a site that saved one keeps it, the
 *                        settings select renders it as that site's value, and
 *                        nothing is migrated.
 *   - 'deprecated_on' + 'replacement'
 *                        the provider announced a shutdown date. Not offered
 *                        for new picks from the day it is announced; saved
 *                        sites keep working until the date, then the row joins
 *                        retired_model_map() on its own (no release needed):
 *                        request-time resolve_retired() sends the replacement
 *                        at once, and the next admin load or daily check moves
 *                        the saved setting with a notice.
 *   A model the provider has already shut down is not a catalog row at all —
 *   it lives in retired_model_map() with its replacement.
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
     * order shown in the Settings modal picker — keep the recommended (cheap,
     * smart, fast for customer chat) model at top.
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
                    // Recommended (direct task M2, 2026-10-16, re-checked against
                    // Gemini 4 Argon): 3.8 Flash is $0.75 / $3.75 per million tokens
                    // through 2026 against Argon's $2 / $10, and answers a support
                    // turn faster.
                    'gemini-3.8-flash'              => array('label' => 'Gemini 3.8 Flash',          'description' => __('Recommended — newest stable Flash: fast and affordable, suited to customer chat', 'mxchat')),
                    // Gemini 4 Argon (stable, listed 2026-10). Probed live 2026-10-16:
                    // thinkingLevel low|medium|high accepted, 'minimal' 400s (as on
                    // 3.x), temperature accepted, OpenAI-compatible reasoning_effort
                    // low accepted. reasoning_effort_for() covers gemini-4 so it gets
                    // 'low' like the 3.x Flash models.
                    'gemini-4-argon'                => array('label' => 'Gemini 4 Argon',            'description' => __('Newest Google flagship — strongest reasoning, priced above Flash', 'mxchat')),
                    'gemini-3.7-flash'              => array('label' => 'Gemini 3.7 Flash',          'description' => __('Stable — efficiency-first Flash', 'mxchat'), 'legacy' => true),
                    'gemini-3.6-flash'              => array('label' => 'Gemini 3.6 Flash',          'description' => __('Stable — previous Flash generation', 'mxchat'), 'legacy' => true),
                    'gemini-3.5-flash'              => array('label' => 'Gemini 3.5 Flash',          'description' => __('Stable — older Flash, priced above 3.8 Flash on every token', 'mxchat'), 'legacy' => true),
                    'gemini-3.1-pro-preview'        => array('label' => 'Gemini 3.1 Pro',            'description' => __('Preview — previous Pro generation, multimodal & agentic', 'mxchat')),
                    'gemini-3-flash-preview'        => array('label' => 'Gemini 3 Flash',            'description' => __('Preview — balanced speed and scale', 'mxchat'), 'legacy' => true),
                    'gemini-3.5-flash-lite'         => array('label' => 'Gemini 3.5 Flash-Lite',     'description' => __('Stable — cheapest Gemini, no thinking', 'mxchat')),
                    // Google's deprecations page: 3.1 Flash-Lite shuts down no sooner
                    // than 2027-05-07 (replacement 3.5 Flash-Lite). The 3.1 Flash-Lite
                    // preview was shut down 2026-05-25: it left this list in direct
                    // task M4 and lives in retired_model_map().
                    //
                    // Direct task M4 (2026-10-16), legacy = still served, no longer
                    // offered: 3.7 Flash and 3.5 Flash are answered by 3.8 Flash and
                    // 3.6 Flash (Google routes them; probed, modelVersion says so);
                    // 3.6 Flash and 3 Flash preview are older Flash models; the 2.5
                    // trio answers 404 "no longer available to new users" (Google
                    // lists no shutdown date, so sites that used them keep access).
                    'gemini-3.1-flash-lite'         => array('label' => 'Gemini 3.1 Flash-Lite',     'description' => __('Stable — cost-efficient, high throughput', 'mxchat'), 'deprecated_on' => '2027-05-07', 'replacement' => 'gemini-3.5-flash-lite'),
                    'gemini-2.5-pro'                => array('label' => 'Gemini 2.5 Pro',            'description' => __('No longer offered by Google to new API users — pick Gemini 3.1 Pro', 'mxchat'), 'legacy' => true),
                    'gemini-2.5-flash'              => array('label' => 'Gemini 2.5 Flash',          'description' => __('No longer offered by Google to new API users — pick Gemini 3.8 Flash', 'mxchat'), 'legacy' => true),
                    'gemini-2.5-flash-lite'         => array('label' => 'Gemini 2.5 Flash-Lite',     'description' => __('No longer offered by Google to new API users — pick Gemini 3.5 Flash-Lite', 'mxchat'), 'legacy' => true),
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
                    // fields (see the lifecycle note at the top of this file): since
                    // direct task M4 a dated model is hidden from new picks from the
                    // day it is announced, keeps working for sites that saved it, and
                    // migrates to its replacement once the date passes.
                    //
                    // Replacements follow M4's rule: same provider, closest current
                    // model of the same tier at a similar or lower price. OpenAI names
                    // gpt-5.6-sol / -terra / -luna for the gpt-5 trio, but those cost
                    // 2x to 8x more ($4/$20, $2/$12, $0.20/$1.20 per million against
                    // $1.25/$10, $0.25/$2, $0.05/$0.40), so gpt-5 goes to gpt-6-sol
                    // ($2/$10, OpenAI's named target for gpt-5.1 at the same price) and
                    // the mini/nano to gpt-6-luna ($0.10/$0.50).
                    //
                    // Legacy since M4 (served, not offered): GPT-5.4 and GPT-5.2
                    // (superseded by 5.5 / 5.6 / 6 at lower cost) and GPT-5.4 Mini
                    // ($0.75/$4.50, GPT-6 Luna covers it for less).
                    // GPT-6.1 Sol (released 2026-09-29; plan 6945f6). Probed live on
                    // 2026-10-16: max_completion_tokens only, temperature 1 only,
                    // reasoning_effort low|medium|high|xhigh. Unlike gpt-6-sol it
                    // REJECTS 'none' (and 'minimal'), like Astra, so it sends
                    // 'low' everywhere. There is no GPT-6.1 Astra or Luna.
                    // The recommended OpenAI model since direct task M1 (Maxwell,
                    // 2026-10-16): cheap, smart and fast for customer chat.
                    'gpt-6.1-sol'          => array('label' => 'GPT-6.1 Sol',          'description' => __('Recommended — fast and affordable, close to GPT-6 Astra quality; suited to customer chat', 'mxchat')),
                    // GPT-6 (announced 2026-09-22; plan 45c22d). Probed live on
                    // 2026-09-27: all three need max_completion_tokens, reject any
                    // temperature but 1 and reject reasoning_effort 'minimal';
                    // Astra also rejects 'none'. Every "modern OpenAI" gate goes
                    // through is_modern_openai() so these ids are not sent legacy
                    // parameters (which 400 on the first message).
                    'gpt-6-astra'          => array('label' => 'GPT-6 Astra',          'description' => __('Newest OpenAI flagship — strongest reasoning, coding and agentic work', 'mxchat')),
                    'gpt-6-sol'            => array('label' => 'GPT-6 Sol',            'description' => __('Strong reasoning on demanding tasks at mid-tier cost', 'mxchat')),
                    'gpt-6-luna'           => array('label' => 'GPT-6 Luna',           'description' => __('Fastest and cheapest GPT-6 for high-volume, repeatable work', 'mxchat')),
                    'gpt-5.6-sol'          => array('label' => 'GPT-5.6 Sol',          'description' => __('Previous OpenAI flagship for reasoning, coding and chat', 'mxchat')),
                    'gpt-5.6-terra'        => array('label' => 'GPT-5.6 Terra',        'description' => __('Balanced GPT-5.6 — strong capability at lower cost', 'mxchat')),
                    'gpt-5.6-luna'         => array('label' => 'GPT-5.6 Luna',         'description' => __('Fastest and cheapest GPT-5.6 for lightweight tasks', 'mxchat')),
                    'gpt-5.5'              => array('label' => 'GPT-5.5',              'description' => __('Previous flagship — powerful reasoning and coding model', 'mxchat')),
                    'gpt-5.4'              => array('label' => 'GPT-5.4',              'description' => __('Flagship reasoning and coding model', 'mxchat'), 'legacy' => true),
                    'gpt-5.4-mini'         => array('label' => 'GPT-5.4 Mini',         'description' => __('Fast and affordable with 400K context', 'mxchat'), 'legacy' => true),
                    // OpenAI deprecated gpt-5.4-nano and gpt-5.1 on 2026-10-01, shutdown
                    // 2027-04-01 (replacements gpt-6-luna and gpt-6-sol). The notice
                    // names the gpt-5.1 alias, which resolves to this dated snapshot.
                    'gpt-5.4-nano'         => array('label' => 'GPT-5.4 Nano',         'description' => __('Fastest and cheapest for lightweight tasks', 'mxchat'), 'deprecated_on' => '2027-04-01', 'replacement' => 'gpt-6-luna'),
                    'gpt-5.2'              => array('label' => 'GPT-5.2',              'description' => __('Best general-purpose & agentic model with fast responses', 'mxchat'), 'legacy' => true),
                    'gpt-5.1-2025-11-13'   => array('label' => 'GPT-5.1',              'description' => __('Flagship for coding & agentic tasks with low reasoning (400K context)', 'mxchat'), 'deprecated_on' => '2027-04-01', 'replacement' => 'gpt-6-sol'),
                    'gpt-5'                => array('label' => 'GPT-5',                'description' => __('Flagship for coding, reasoning, and agentic tasks across domains', 'mxchat'), 'deprecated_on' => '2026-12-11', 'replacement' => 'gpt-6-sol'),
                    'gpt-5-mini'           => array('label' => 'GPT-5 Mini',           'description' => __('Fast and lightweight', 'mxchat'), 'deprecated_on' => '2026-12-11', 'replacement' => 'gpt-6-luna'),
                    'gpt-5-nano'           => array('label' => 'GPT-5 Nano',           'description' => __('Fastest and cheapest; ideal for summarization and classification', 'mxchat'), 'deprecated_on' => '2026-12-11', 'replacement' => 'gpt-6-luna'),
                ),
            ),
            'claude' => array(
                'label'                       => __('Anthropic Claude', 'mxchat'),
                'key_option'                  => 'claude_api_key',
                'requires_key_to_load_models' => false,
                'models' => array(
                    // The 5.5 generation and Fable 5.1 (direct task M2, 2026-10-16).
                    // Probed live: every one 400s on a non-default temperature
                    // (supports_temperature() lists them), thinks before harder
                    // answers without being asked (thinking counts against
                    // max_tokens: Opus 5.5 and Fable 5.1 sit in the 8192 group of
                    // mxchat_claude_max_tokens() by prefix, Haiku 5.5 and Sonnet 5.5
                    // finish inside 4096 like Sonnet 5), and returns the thinking
                    // block ahead of the text block, which every Claude call site
                    // already skips. thinking "disabled" is accepted only by Haiku
                    // 5.5; we never send a thinking or effort parameter.
                    //
                    // Recommended since M2: Claude Haiku 5.5 — $0.10 / $0.50 per
                    // million tokens (prompts up to 100K) against $2 / $10 for
                    // Sonnet 5.5. Same three support turns on the box's knowledge
                    // articles, 2 runs each: median 2.6 s against 3.9 s, about
                    // $0.0003 against $0.007 per turn, both answers correct and
                    // grounded (Sonnet longer, with lists). Full output in
                    // daily/orchestrated/2026-10-16-M2.md.
                    'claude-haiku-5-5'            => array('label' => 'Claude Haiku 5.5',    'description' => __('Recommended — fastest and lowest-cost current Claude; answers customer questions well', 'mxchat')),
                    'claude-sonnet-5-5'           => array('label' => 'Claude Sonnet 5.5',   'description' => __('Newest Sonnet — best balance of speed and intelligence', 'mxchat')),
                    'claude-fable-5-1'            => array('label' => 'Claude Fable 5.1',    'description' => __('Newest flagship — most capable Anthropic model, slower and priciest', 'mxchat')),
                    'claude-opus-5-5'             => array('label' => 'Claude Opus 5.5',     'description' => __('Newest Opus — long-running agentic and knowledge work', 'mxchat')),
                    'claude-sonnet-5'             => array('label' => 'Claude Sonnet 5',     'description' => __('Previous Sonnet — fast, smart and affordable', 'mxchat')),
                    'claude-fable-5'              => array('label' => 'Claude Fable 5',      'description' => __('Previous flagship', 'mxchat')),
                    'claude-opus-5'               => array('label' => 'Claude Opus 5',       'description' => __('Previous Opus — complex agentic and coding work', 'mxchat')),
                    'claude-opus-4-8'             => array('label' => 'Claude Opus 4.8',     'description' => __('Older Opus generation', 'mxchat')),
                    'claude-opus-4-7'             => array('label' => 'Claude Opus 4.7',     'description' => __('Previous Anthropic flagship model', 'mxchat'), 'legacy' => true),
                    'claude-opus-4-6'             => array('label' => 'Claude Opus 4.6',     'description' => __('Older Opus generation for complex tasks', 'mxchat'), 'legacy' => true),
                    'claude-sonnet-4-6'           => array('label' => 'Claude Sonnet 4.6',   'description' => __('Previous Sonnet - excellent balance of speed and capability', 'mxchat'), 'legacy' => true),
                    'claude-opus-4-5'             => array('label' => 'Claude Opus 4.5',     'description' => __('Highly capable for complex tasks', 'mxchat'), 'legacy' => true),
                    // Anthropic deprecated Sonnet 4.5 on 2026-09-30; retirement
                    // 2026-11-30, recommended replacement claude-sonnet-5-5.
                    // Legacy since M4 (Active on Anthropic's model-status table, not
                    // offered): Opus 4.7 / 4.6 / 4.5 and Sonnet 4.6, older generations
                    // beside Opus 4.8 / 5 / 5.5 and Sonnet 5 / 5.5.
                    'claude-sonnet-4-5-20250929'  => array('label' => 'Claude Sonnet 4.5',   'description' => __('Best for complex agents and coding', 'mxchat'), 'deprecated_on' => '2026-11-30', 'replacement' => 'claude-sonnet-5-5'),
                    'claude-haiku-4-5-20251001'   => array('label' => 'Claude Haiku 4.5',    'description' => __('Fastest and most intelligent Haiku', 'mxchat')),
                ),
            ),
            'xai' => array(
                'label'                       => __('xAI Grok', 'mxchat'),
                'key_option'                  => 'xai_api_key',
                'requires_key_to_load_models' => false,
                // Refreshed 2026-08-14 (plan-mxchat-20260814-e1aa4a) against TWO
                // primary sources that agree: a live pull of xAI's /v1/models with
                // a real key, and docs.x.ai/docs/models. Context windows below are
                // that listing's own context_length; image-input support for every
                // current-generation id was confirmed by a 32x32 probe returning a
                // correct answer (all HTTP 200), not inferred from docs.
                //
                // The legacy block below is INTENTIONALLY still here. xAI has
                // published no retirement dates for these ids and they still answer
                // HTTP 200 (grok-4-0709 re-probed 2026-08-14), so 'deprecated_on'
                // would be an invented date and deleting them would strand every
                // install saved on one. Since direct task M4 they are 'legacy': not
                // offered, saved sites unchanged. Re-probed 2026-10-16: all seven
                // answer 200 and the responses report model grok-4.3 (xAI now
                // serves them with 4.3).
                //
                // Direct task M2 (2026-10-16): grok-4.7 added (listed 2026-09-02,
                // $2 / $6, reasons before answering; probed live: temperature
                // accepted, presence_penalty rejected like 4.6, image input and
                // streaming fine). The recommended Grok model is the non-reasoning
                // 4.20 release: the cheapest current Grok ($1.25 / $2.50) and the
                // only one that answers without a reasoning pass (support turn in
                // about a second against 4.7's several), so it heads the group.
                'models' => array(
                    'grok-4.20-0309-non-reasoning'=> array('label' => 'Grok 4.20 (Non-Reasoning)',     'description' => __('Recommended — fastest and cheapest current Grok, answers without a reasoning pause; suited to customer chat', 'mxchat')),
                    'grok-4.7'                    => array('label' => 'Grok 4.7',                      'description' => __('Newest xAI flagship — reasons before answering, 500K context, accepts image input', 'mxchat')),
                    'grok-4.6'                    => array('label' => 'Grok 4.6',                      'description' => __('Previous flagship — 500K context, accepts image input', 'mxchat')),
                    'grok-4.5'                    => array('label' => 'Grok 4.5',                      'description' => __('Older flagship — 500K context, accepts image input', 'mxchat')),
                    'grok-4.3'                    => array('label' => 'Grok 4.3',                      'description' => __('Largest context in the Grok lineup — 1M tokens, accepts image input', 'mxchat')),
                    'grok-4.20-0309-reasoning'    => array('label' => 'Grok 4.20 (Reasoning)',         'description' => __('1M context with reasoning — dated release, pinned behaviour', 'mxchat')),
                    // --- Previous generations: legacy (still served by xAI, no
                    // announced retirement date, kept so saved selections keep
                    // working, never offered). ---
                    'grok-4-1-fast-reasoning'     => array('label' => 'Grok 4.1 Fast (Reasoning)',     'description' => __('Previous generation — no longer listed by xAI, still served', 'mxchat'), 'legacy' => true),
                    'grok-4-1-fast-non-reasoning' => array('label' => 'Grok 4.1 Fast (Non-Reasoning)', 'description' => __('Previous generation — no longer listed by xAI, still served', 'mxchat'), 'legacy' => true),
                    'grok-4-0709'                 => array('label' => 'Grok 4',                        'description' => __('Previous generation — no longer listed by xAI, still served', 'mxchat'), 'legacy' => true),
                    'grok-3-beta'                 => array('label' => 'Grok-3',                        'description' => __('Older generation — no longer listed by xAI, still served', 'mxchat'), 'legacy' => true),
                    'grok-3-fast-beta'            => array('label' => 'Grok-3 Fast',                   'description' => __('Older generation — no longer listed by xAI, still served', 'mxchat'), 'legacy' => true),
                    'grok-3-mini-beta'            => array('label' => 'Grok-3 Mini',                   'description' => __('Older generation — no longer listed by xAI, still served', 'mxchat'), 'legacy' => true),
                    'grok-3-mini-fast-beta'       => array('label' => 'Grok-3 Mini Fast',              'description' => __('Older generation — no longer listed by xAI, still served', 'mxchat'), 'legacy' => true),
                ),
            ),
            'deepseek' => array(
                'label'                       => __('DeepSeek', 'mxchat'),
                'key_option'                  => 'deepseek_api_key',
                'requires_key_to_load_models' => false,
                'models' => array(
                    // DeepSeek retired deepseek-chat / deepseek-reasoner on 2026-07-24;
                    // stored settings migrate via mxchat_migrate_deprecated_models().
                    //
                    // Direct task M2 (2026-10-16): DeepSeek's own pricing page names
                    // deepseek-flash (DeepSeek-V4.1-Flash, $0.15 / $0.60 per million
                    // tokens at off-peak cache-miss rates) as the model to use; the old
                    // name deepseek-v4-flash is still accepted but answered by the same
                    // model (probed: responses report model deepseek-flash). No
                    // retirement date is published, so it stays without one; legacy
                    // since M4 (served, not offered next to the name DeepSeek uses).
                    'deepseek-flash'    => array('label' => 'DeepSeek Flash',    'description' => __('Recommended — DeepSeek V4.1 Flash: fastest and cheapest DeepSeek, suited to customer chat', 'mxchat')),
                    'deepseek-v4-pro'   => array('label' => 'DeepSeek V4 Pro',   'description' => __('Most capable DeepSeek model', 'mxchat')),
                    'deepseek-v4-flash' => array('label' => 'DeepSeek V4 Flash', 'description' => __('Older name — DeepSeek now answers it with DeepSeek Flash', 'mxchat'), 'legacy' => true),
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
                    // Google's deprecations page: shutdown no sooner than 2028-05-14,
                    // replacement gemini-embedding-2. Embeddings are NEVER switched
                    // automatically (stored vectors would stop matching): once the
                    // date passes, retired_embedding_map() lists it and core shows a
                    // re-embed notice instead (direct task M4, rule E).
                    'gemini-embedding-001' => array('label' => 'Gemini Embedding', 'description' => __('1536 dim', 'mxchat'), 'deprecated_on' => '2028-05-14', 'replacement' => 'gemini-embedding-2'),
                    // Direct task M2 (2026-10-16): Google's current embedding model
                    // (its deprecations page names it the replacement for the older
                    // text-embedding ids). Probed: outputDimensionality 1536 returns
                    // 1536 values, the size every Gemini embed path requests. Added
                    // as a choice only; no saved embedding model is changed.
                    'gemini-embedding-2'   => array('label' => 'Gemini Embedding 2', 'description' => __('1536 dim, newest', 'mxchat')),
                ),
            ),
        );
    }

    /**
     * Canonical retired-model map: every chat model id a provider has retired
     * (or hard-deprecated) that stored settings may still carry, mapped to its
     * current replacement (plan-mxchat-20260822-202df5).
     *
     * THE ONLY PLACE THIS MAPPING LIVES. mxchat_migrate_deprecated_models()
     * consumes it for the main chat + content models, and add-ons that store
     * their own model ids (mxchat-multi-bot's per-bot overrides) consume it for
     * theirs — a second hardcoded list in any consumer is how a stranded-model
     * bug recurs, so route every new retirement through this map.
     *
     * Claude ids map BY TIER so a rescued site lands on the current generation,
     * not an older intermediate (plan dc91bd). Never map onto a model that is
     * itself on a deprecation list (the e46b8f rule).
     *
     * Direct task M2 (2026-10-16): Claude tiers now land on the 5.5 generation;
     * the GPT-4 mini ids land on gpt-6-luna (OpenAI's named replacement in its
     * 2026-10-14 notice; the old target gpt-5-mini is itself deprecated for
     * 2026-12-11); the DeepSeek ids land on deepseek-flash, the name DeepSeek
     * now publishes.
     *
     * Direct task M4 (2026-10-16): the map is DATE-AWARE. Static rows are the
     * ids already shut down; every catalog row with a 'deprecated_on' date that
     * has been reached is added on that day with its 'replacement', so an
     * announced retirement needs no release to take effect. A replacement must
     * be current: offered, never 'legacy', never dated (the e46b8f rule, now
     * asserted by the M4 rig for every row).
     *
     * Entry shape: retired_id => array(
     *   'to'     => replacement model id (must be current, never itself retired),
     *   'label'  => replacement display name for user-facing notices,
     *   'from_label' => (optional) the retired model's display name, for ids
     *               the catalog no longer lists,
     *   'reason' => notice clause explaining the retirement; deliberately NOT
     *               translated — migration messages are stored in an option and
     *               rendered later, matching the existing migration notices.
     * )
     */
    public static function retired_model_map() {
        $now = self::now();
        $day = gmdate('Y-m-d', $now);
        static $cache = array();
        if (isset($cache[$day])) {
            return $cache[$day];
        }

        $map = array(
            // Anthropic Claude — Opus tier. Each id answers 404 not_found_error
            // (probed 2026-10-16, direct task M4).
            'claude-3-opus-20240229'     => array('to' => 'claude-opus-5-5',          'label' => 'Claude Opus 5.5', 'from_label' => 'Claude 3 Opus',  'reason' => 'because Anthropic retired the older Claude model'), // Retired Jan 5, 2026
            'claude-opus-4-20250514'     => array('to' => 'claude-opus-5-5',          'label' => 'Claude Opus 5.5', 'from_label' => 'Claude Opus 4',  'reason' => 'because Anthropic retired the older Claude model'), // Retired Jun 15, 2026
            'claude-opus-4-1-20250805'   => array('to' => 'claude-opus-5-5',          'label' => 'Claude Opus 5.5', 'from_label' => 'Claude Opus 4.1',  'reason' => 'because Anthropic retired the older Claude model'), // Retired Aug 5, 2026 (first-party API)
            // Anthropic Claude — Sonnet tier.
            'claude-3-5-sonnet-20240620' => array('to' => 'claude-sonnet-5-5',        'label' => 'Claude Sonnet 5.5', 'from_label' => 'Claude Sonnet 3.5', 'reason' => 'because Anthropic retired the older Claude model'), // Retired Oct 28, 2025
            'claude-3-5-sonnet-20241022' => array('to' => 'claude-sonnet-5-5',        'label' => 'Claude Sonnet 5.5', 'from_label' => 'Claude Sonnet 3.5', 'reason' => 'because Anthropic retired the older Claude model'), // Retired Oct 28, 2025
            'claude-3-7-sonnet-20250219' => array('to' => 'claude-sonnet-5-5',        'label' => 'Claude Sonnet 5.5', 'from_label' => 'Claude Sonnet 3.7', 'reason' => 'because Anthropic retired the older Claude model'), // Retired Feb 19, 2026
            'claude-3-sonnet-20240229'   => array('to' => 'claude-sonnet-5-5',        'label' => 'Claude Sonnet 5.5', 'from_label' => 'Claude 3 Sonnet', 'reason' => 'because Anthropic retired the older Claude model'), // Legacy
            'claude-sonnet-4-20250514'   => array('to' => 'claude-sonnet-5-5',        'label' => 'Claude Sonnet 5.5', 'from_label' => 'Claude Sonnet 4', 'reason' => 'because Anthropic retired the older Claude model'), // Retired Jun 15, 2026
            // Anthropic Claude — Haiku tier.
            'claude-3-haiku-20240307'    => array('to' => 'claude-haiku-5-5',         'label' => 'Claude Haiku 5.5', 'from_label' => 'Claude 3 Haiku', 'reason' => 'because Anthropic retired the older Claude model'), // Retired Apr 20, 2026
            'claude-3-5-haiku-20241022'  => array('to' => 'claude-haiku-5-5',         'label' => 'Claude Haiku 5.5', 'from_label' => 'Claude Haiku 3.5', 'reason' => 'because Anthropic retired the older Claude model'), // Retired Feb 19, 2026
            // OpenAI — GPT-4 series + GPT-3.5 Turbo. OpenAI shuts these down on
            // 2026-10-23 (gpt-4, gpt-4-turbo, gpt-3.5-turbo) and 2027-01-14 (the
            // gpt-4o / gpt-4.1 snapshots); MxChat has migrated them since 3.0.55.
            // Direct task M4 re-pointed them by M4's price rule: gpt-6-sol
            // ($2/$10) for the full-size ids instead of gpt-5.6-sol ($4/$20), and
            // gpt-6-luna ($0.10/$0.50) for GPT-3.5 Turbo and the mini ids.
            'gpt-4o'                     => array('to' => 'gpt-6-sol',                'label' => 'GPT-6 Sol', 'from_label' => 'GPT-4o',        'reason' => 'due to OpenAI deprecating older models'),
            'gpt-4.1-2025-04-14'         => array('to' => 'gpt-6-sol',                'label' => 'GPT-6 Sol', 'from_label' => 'GPT-4.1',        'reason' => 'due to OpenAI deprecating older models'),
            'gpt-4-turbo'                => array('to' => 'gpt-6-sol',                'label' => 'GPT-6 Sol', 'from_label' => 'GPT-4 Turbo',        'reason' => 'due to OpenAI deprecating older models'),
            'gpt-4'                      => array('to' => 'gpt-6-sol',                'label' => 'GPT-6 Sol', 'from_label' => 'GPT-4',        'reason' => 'due to OpenAI deprecating older models'),
            'gpt-3.5-turbo'              => array('to' => 'gpt-6-luna',               'label' => 'GPT-6 Luna', 'from_label' => 'GPT-3.5 Turbo',       'reason' => 'due to OpenAI deprecating older models'),
            // OpenAI — the gpt-5.x-chat-latest aliases retired 2026-08-10 (e46b8f);
            // both answer 404 model_not_found "has been deprecated" (probed M4).
            'gpt-5.1-chat-latest'        => array('to' => 'gpt-6-sol',                'label' => 'GPT-6 Sol', 'from_label' => 'GPT-5.1 Chat Latest',        'reason' => 'because OpenAI retires the GPT-5.x Chat Latest models on August 10, 2026'),
            'gpt-5.3-chat-latest'        => array('to' => 'gpt-6-sol',                'label' => 'GPT-6 Sol', 'from_label' => 'GPT-5.3 Chat Latest',        'reason' => 'because OpenAI retires the GPT-5.x Chat Latest models on August 10, 2026'),
            // OpenAI — mini tier.
            'gpt-4o-mini'                => array('to' => 'gpt-6-luna',               'label' => 'GPT-6 Luna', 'from_label' => 'GPT-4o Mini',       'reason' => 'due to OpenAI deprecating GPT-4 series models'),
            'gpt-4.1-mini'               => array('to' => 'gpt-6-luna',               'label' => 'GPT-6 Luna', 'from_label' => 'GPT-4.1 Mini',       'reason' => 'due to OpenAI deprecating GPT-4 series models'),
            // DeepSeek — retired by DeepSeek 2026-07-24.
            'deepseek-chat'              => array('to' => 'deepseek-flash',           'label' => 'DeepSeek Flash', 'from_label' => 'DeepSeek Chat',   'reason' => 'because DeepSeek retired its older API models on July 24, 2026'),
            'deepseek-reasoner'          => array('to' => 'deepseek-flash',           'label' => 'DeepSeek Flash', 'from_label' => 'DeepSeek Reasoner',   'reason' => 'because DeepSeek retired its older API models on July 24, 2026'),
            // Direct task M4 (2026-10-16). Every id below was proven shut down
            // with a live call from the test site using its own key, or by the
            // provider's deprecations page (evidence in
            // daily/orchestrated/2026-10-16-M4.md). Until now some lived only in
            // one-off admin_init remaps or in Admin Chat's private map, so a
            // Multi-Bot bot or the content model stranded on one was never moved.
            //   Google: the 3.1 Flash-Lite preview was shut down 2026-05-25
            //   (the API now answers it with 3.1 Flash-Lite, which retires
            //   itself on 2027-05-07, so it goes straight to 3.5 Flash-Lite);
            //   3 Pro preview (shut down 2026-03-09), 1.5 and 2.0 answer 404.
            'gemini-3.1-flash-lite-preview' => array('to' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash-Lite', 'from_label' => 'Gemini 3.1 Flash-Lite (Preview)', 'reason' => 'because Google shut down the Gemini 3.1 Flash-Lite preview on May 25, 2026'),
            'gemini-3-pro-preview'       => array('to' => 'gemini-3.1-pro-preview',   'label' => 'Gemini 3.1 Pro',   'from_label' => 'Gemini 3 Pro (Preview)', 'reason' => 'because Google shut down the Gemini 3 Pro preview on March 9, 2026'),
            'gemini-1.5-pro'             => array('to' => 'gemini-3.1-pro-preview',   'label' => 'Gemini 3.1 Pro',   'from_label' => 'Gemini 1.5 Pro', 'reason' => 'because Google retired the older Gemini model'),
            'gemini-1.5-flash'           => array('to' => 'gemini-3.8-flash',         'label' => 'Gemini 3.8 Flash', 'from_label' => 'Gemini 1.5 Flash', 'reason' => 'because Google retired the older Gemini model'),
            'gemini-2.0-flash'           => array('to' => 'gemini-3.8-flash',         'label' => 'Gemini 3.8 Flash', 'from_label' => 'Gemini 2.0 Flash', 'reason' => 'because Google shut down Gemini 2.0 Flash on June 1, 2026'),
            'gemini-2.0-flash-lite'      => array('to' => 'gemini-3.5-flash-lite',    'label' => 'Gemini 3.5 Flash-Lite', 'from_label' => 'Gemini 2.0 Flash-Lite', 'reason' => 'because Google shut down Gemini 2.0 Flash-Lite on June 1, 2026'),
            //   xAI: the Grok 2 family answers 404 ("deprecated on 2025-09-15").
            //   The old one-off remap sent grok-2 to grok-4-1-fast-non-reasoning,
            //   now legacy; the current non-reasoning Grok is the right tier.
            'grok-2'                     => array('to' => 'grok-4.20-0309-non-reasoning', 'label' => 'Grok 4.20 (Non-Reasoning)', 'from_label' => 'Grok 2', 'reason' => 'because xAI retired the Grok 2 models'),
            'grok-2-1212'                => array('to' => 'grok-4.20-0309-non-reasoning', 'label' => 'Grok 4.20 (Non-Reasoning)', 'from_label' => 'Grok 2', 'reason' => 'because xAI retired the Grok 2 models'),
            'grok-2-latest'              => array('to' => 'grok-4.20-0309-non-reasoning', 'label' => 'Grok 4.20 (Non-Reasoning)', 'from_label' => 'Grok 2', 'reason' => 'because xAI retired the Grok 2 models'),
        );

        // Announced retirements whose date has passed join the map on their
        // own — no release needed (direct task M4, rule B). Until then they are
        // only hidden from new picks.
        foreach (self::chat_models() as $provider_slug => $provider) {
            foreach ($provider['models'] as $id => $entry) {
                if (isset($map[$id]) || empty($entry['deprecated_on']) || empty($entry['replacement'])) {
                    continue;
                }
                if (!self::date_has_passed($entry['deprecated_on'], $now)) {
                    continue;
                }
                $to = (string) $entry['replacement'];
                $map[$id] = array(
                    'to'         => $to,
                    'label'      => self::catalog_label($to),
                    'from_label' => $entry['label'],
                    'reason'     => sprintf('because %s retired it on %s', self::provider_company($provider_slug), gmdate('F j, Y', strtotime($entry['deprecated_on'] . ' 00:00:00 UTC'))),
                    'dated'      => $entry['deprecated_on'],
                );
            }
        }

        $cache = array($day => $map);
        return $map;
    }

    /**
     * The current replacement for a retired chat model id, or the id unchanged
     * when it is not in retired_model_map(). No I/O, no option read.
     * Every request-time remap goes through here (plan 8c4eb4), so a newly
     * retired id needs one map row and nothing else — and since direct task M4
     * an announced retirement starts resolving here on its date by itself.
     */
    public static function resolve_retired($model_id) {
        $map = self::retired_model_map();
        if (is_string($model_id) && isset($map[$model_id]['to']) && $map[$model_id]['to'] !== '') {
            return $map[$model_id]['to'];
        }
        return $model_id;
    }

    /**
     * "Now" for every lifecycle date check. The mxchat_model_catalog_now filter
     * exists so a verification rig can move the clock past an announced date
     * without waiting for it; nothing in the plugin uses it.
     *
     * @return int Unix timestamp.
     */
    public static function now() {
        return (int) apply_filters('mxchat_model_catalog_now', time());
    }

    /** Whether a Y-m-d lifecycle date (00:00 UTC) has been reached. */
    public static function date_has_passed($ymd, $now = null) {
        $ts = strtotime((string) $ymd . ' 00:00:00 UTC');
        if ($ts === false) {
            return false;
        }
        return (($now === null) ? self::now() : (int) $now) >= $ts;
    }

    /**
     * Whether a catalog entry may be offered for a NEW pick: current models
     * only — never a legacy one, never one with an announced retirement date
     * (direct task M4). Saved values keep rendering through
     * settings_dropdown_groups($current_model).
     */
    public static function is_offered($entry) {
        return is_array($entry) && empty($entry['legacy']) && empty($entry['deprecated_on']);
    }

    /**
     * Flat list of the chat model ids pickers offer for new picks, in catalog
     * order. chat_model_ids() stays the save allowlist, so a site re-saving
     * its legacy model is never rejected.
     */
    public static function offered_chat_model_ids() {
        $ids = array();
        foreach (self::chat_models() as $provider) {
            foreach ($provider['models'] as $id => $entry) {
                if (self::is_offered($entry)) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /** Catalog label for a chat model id, or the id. Reads chat_models() only. */
    private static function catalog_label($model_id) {
        foreach (self::chat_models() as $provider) {
            if (isset($provider['models'][$model_id]['label'])) {
                return $provider['models'][$model_id]['label'];
            }
        }
        return (string) $model_id;
    }

    /**
     * Display label for a chat model id: the catalog label, else the
     * from_label of a static retired row, else the id itself.
     */
    public static function chat_model_label($model_id) {
        $model_id = (string) $model_id;
        foreach (self::chat_models() as $provider) {
            if (isset($provider['models'][$model_id]['label'])) {
                return $provider['models'][$model_id]['label'];
            }
        }
        $map = self::retired_model_map();
        if (isset($map[$model_id]['from_label'])) {
            return $map[$model_id]['from_label'];
        }
        return $model_id;
    }

    /**
     * The company name used in user-facing retirement sentences ("which Google
     * has discontinued"). Shorter than provider_label(), which names the
     * product ("Google Gemini", "Anthropic Claude").
     */
    public static function provider_company($slug) {
        $names = array(
            'openai'     => 'OpenAI',
            'claude'     => 'Anthropic',
            'gemini'     => 'Google',
            'xai'        => 'xAI',
            'deepseek'   => 'DeepSeek',
            'openrouter' => 'OpenRouter',
            'voyage'     => 'Voyage AI',
            'perplexity' => 'Perplexity',
        );
        return isset($names[$slug]) ? $names[$slug] : 'the provider';
    }

    /**
     * Provider slug for any model id — chat, image or embedding — including
     * ids the catalog no longer lists (retired ones). Catalog first, then the
     * family prefix. '' when unknown.
     */
    public static function provider_slug_for_id($model_id) {
        $model_id = (string) $model_id;
        $slug = self::provider_for_chat_model($model_id);
        if ($slug !== '') {
            return $slug;
        }
        $prefixes = array(
            'gpt-' => 'openai', 'chatgpt-' => 'openai', 'dall-e' => 'openai', 'text-embedding-3' => 'openai', 'text-embedding-ada' => 'openai', 'o1' => 'openai', 'o3' => 'openai', 'o4' => 'openai',
            'claude-' => 'claude',
            'gemini-' => 'gemini', 'imagen-' => 'gemini', 'text-embedding-004' => 'gemini', 'embedding-' => 'gemini', 'nano-banana' => 'gemini',
            'grok-' => 'xai',
            'deepseek-' => 'deepseek',
            'voyage-' => 'voyage',
            'sonar' => 'perplexity', 'pplx-' => 'perplexity', 'llama-3' => 'perplexity',
        );
        foreach ($prefixes as $prefix => $p) {
            if (strpos($model_id, $prefix) === 0) {
                return $p;
            }
        }
        return '';
    }

    /**
     * Image-GENERATION model lifecycle (direct task M4). The catalog does not
     * list image models as pickers (each surface keeps its own short list),
     * but their retirements live HERE, in one place, so the content generator,
     * Admin Chat and Vision move a saved image model the same way, and a dated
     * one starts resolving on its date by itself.
     *
     *   'to'         => replacement id (current, never itself retiring)
     *   'label'      => replacement display name
     *   'from_label' => retired model's display name
     *   'on'         => provider shutdown date (Y-m-d); reached = discontinued,
     *                   future = announced (hidden from new picks, works until then)
     *
     * Evidence (probed 2026-10-16 from the test site): dall-e-3 / dall-e-2
     * answer 400 "does not exist"; imagen-4.0 :predict, the 2.5 Flash Image
     * preview and the 2.0 image-generation preview answer 404; the 3.x image
     * previews still answer but Google's deprecations page shut them down on
     * 2026-06-25. The other dates are from the OpenAI and Google deprecations
     * pages. gpt-image-1-mini has no cheaper current OpenAI image model: Flare
     * is OpenAI's named replacement and its cheapest current one.
     */
    public static function image_model_lifecycle() {
        return array(
            'dall-e-3'                                  => array('to' => 'gpt-image-2.5-flare',         'label' => 'GPT Image 2.5 Flare', 'from_label' => 'DALL-E 3',                       'on' => '2026-05-12'),
            'dall-e-2'                                  => array('to' => 'gpt-image-2.5-flare',         'label' => 'GPT Image 2.5 Flare', 'from_label' => 'DALL-E 2',                       'on' => '2026-05-12'),
            'gpt-image-1'                               => array('to' => 'gpt-image-2.5-flare',         'label' => 'GPT Image 2.5 Flare', 'from_label' => 'GPT Image 1',                    'on' => '2026-10-23'),
            'gpt-image-1.5'                             => array('to' => 'gpt-image-2.5-flare',         'label' => 'GPT Image 2.5 Flare', 'from_label' => 'GPT Image 1.5',                  'on' => '2026-12-01'),
            'gpt-image-1-mini'                          => array('to' => 'gpt-image-2.5-flare',         'label' => 'GPT Image 2.5 Flare', 'from_label' => 'GPT Image 1 Mini',               'on' => '2026-12-01'),
            'chatgpt-image-latest'                      => array('to' => 'gpt-image-2.5-flare',         'label' => 'GPT Image 2.5 Flare', 'from_label' => 'ChatGPT Image Latest',           'on' => '2026-12-01'),
            'imagen-4.0-generate-001'                   => array('to' => 'gemini-nano-banana-2.1',      'label' => 'Nano Banana 2.1',     'from_label' => 'Imagen 4',                       'on' => '2026-08-17'),
            'imagen-4.0-fast-generate-001'              => array('to' => 'gemini-nano-banana-2.1',      'label' => 'Nano Banana 2.1',     'from_label' => 'Imagen 4 Fast',                  'on' => '2026-08-17'),
            'imagen-4.0-ultra-generate-001'             => array('to' => 'gemini-nano-banana-2.1',      'label' => 'Nano Banana 2.1',     'from_label' => 'Imagen 4 Ultra',                 'on' => '2026-08-17'),
            'gemini-3.1-flash-image-preview'            => array('to' => 'gemini-3.1-flash-image',      'label' => 'Nano Banana 2',       'from_label' => 'Nano Banana 2 (Preview)',        'on' => '2026-06-25'),
            'gemini-3-pro-image-preview'                => array('to' => 'gemini-3-pro-image',          'label' => 'Nano Banana Pro',     'from_label' => 'Nano Banana Pro (Preview)',      'on' => '2026-06-25'),
            'gemini-2.5-flash-image-preview'            => array('to' => 'gemini-3.1-flash-lite-image', 'label' => 'Nano Banana 2 Lite',  'from_label' => 'Nano Banana (Preview)',          'on' => '2026-01-15'),
            'gemini-2.0-flash-preview-image-generation' => array('to' => 'gemini-3.1-flash-lite-image', 'label' => 'Nano Banana 2 Lite',  'from_label' => 'Gemini 2.0 Flash image preview', 'on' => '2025-11-14'),
            'gemini-2.5-flash-image'                    => array('to' => 'gemini-3.1-flash-lite-image', 'label' => 'Nano Banana 2 Lite',  'from_label' => 'Nano Banana',                    'on' => '2027-03-15'),
        );
    }

    /**
     * Image model ids that are discontinued as of today (shutdown date
     * reached), each with its replacement. Same entry shape as
     * retired_model_map(), so one migration routine handles both.
     */
    public static function retired_image_model_map() {
        $out = array();
        foreach (self::image_model_lifecycle() as $id => $row) {
            if (self::date_has_passed($row['on'])) {
                $out[$id] = array(
                    'to'         => $row['to'],
                    'label'      => $row['label'],
                    'from_label' => $row['from_label'],
                    'reason'     => sprintf('because %s shut it down on %s', self::provider_company(self::provider_slug_for_id($id)), gmdate('F j, Y', strtotime($row['on'] . ' 00:00:00 UTC'))),
                );
            }
        }
        return $out;
    }

    /** Image model to SEND: the replacement once the saved id is discontinued. */
    public static function resolve_retired_image($model_id) {
        $map = self::retired_image_model_map();
        return (is_string($model_id) && isset($map[$model_id])) ? $map[$model_id]['to'] : $model_id;
    }

    /**
     * Whether an image model id may be offered for a NEW pick: false for every
     * id with a lifecycle row (discontinued or announced). A picker renders
     * such an id only when it is the site's saved value.
     */
    public static function image_model_offered($model_id) {
        $rows = self::image_model_lifecycle();
        return !isset($rows[(string) $model_id]);
    }

    /**
     * Label suffix for a saved image model that is no longer offered:
     * "retires <date>" or "retired by provider, choose a replacement".
     * '' when the model is current.
     */
    public static function image_model_status_label($model_id) {
        $rows = self::image_model_lifecycle();
        if (!isset($rows[(string) $model_id])) {
            return '';
        }
        $on = $rows[(string) $model_id]['on'];
        if (self::date_has_passed($on)) {
            return __('retired by provider, choose a replacement', 'mxchat');
        }
        /* translators: %s: retirement date */
        return sprintf(__('retires %s', 'mxchat'), date_i18n(get_option('date_format', 'F j, Y'), strtotime($on . ' 00:00:00 UTC')));
    }

    /**
     * Image-GENERATION models offered for a new pick, grouped by provider
     * (plan d9da28). One list for the Content screen's Image Model select and
     * for mxchat_generate_media_image(), which accepts these ids (plus the
     * site's own saved image model) as its model argument. Retirements stay
     * in image_model_lifecycle(); an id listed there is never listed here.
     *
     * @return array provider slug => array('label' => string, 'key' => mxchat_options key, 'models' => array(id => label))
     */
    public static function image_generation_models() {
        return array(
            'openai' => array(
                'label'  => __('OpenAI', 'mxchat'),
                'key'    => 'api_key',
                'models' => array(
                    'gpt-image-2.5-flare'    => __('GPT Image 2.5 Flare (fast everyday images)', 'mxchat'),
                    'gpt-image-2.5-sunburst' => __('GPT Image 2.5 Sunburst (highest quality)', 'mxchat'),
                    'gpt-image-2'            => __('GPT Image 2', 'mxchat'),
                ),
            ),
            'xai' => array(
                'label'  => __('xAI', 'mxchat'),
                'key'    => 'xai_api_key',
                'models' => array(
                    'grok-imagine-image-2.0' => __('Grok Imagine 2.0 (Latest)', 'mxchat'),
                    'grok-imagine-image-pro' => __('Grok Imagine Pro', 'mxchat'),
                    'grok-imagine-image'     => __('Grok Imagine', 'mxchat'),
                ),
            ),
            'gemini' => array(
                'label'  => __('Google', 'mxchat'),
                'key'    => 'gemini_api_key',
                'models' => array(
                    'gemini-nano-banana-2.1'      => __('Nano Banana 2.1 (Latest)', 'mxchat'),
                    'gemini-3.1-flash-image'      => __('Nano Banana 2 (Gemini 3.1 Flash Image)', 'mxchat'),
                    'gemini-3.1-flash-lite-image' => __('Nano Banana 2 Lite (fastest, lowest cost)', 'mxchat'),
                    'gemini-3-pro-image'          => __('Nano Banana Pro (Gemini 3 Pro Image)', 'mxchat'),
                ),
            ),
        );
    }

    /**
     * Provider slug of an image-generation model id (openai | xai | gemini),
     * or '' for an id no image engine handles. Same prefixes the content
     * generator dispatches on; imagen ids are Google's (they resolve to a
     * Nano Banana replacement before anything is sent).
     */
    public static function image_model_provider($model_id) {
        $model_id = (string) $model_id;
        if (strpos($model_id, 'gpt-image') === 0 || strpos($model_id, 'chatgpt-image') === 0 || strpos($model_id, 'dall-e') === 0) {
            return 'openai';
        }
        if (strpos($model_id, 'grok') === 0) {
            return 'xai';
        }
        if (strpos($model_id, 'gemini') === 0 || strpos($model_id, 'imagen') === 0) {
            return 'gemini';
        }
        return '';
    }

    /**
     * Embedding ids the provider has shut down, each with the current model to
     * RE-EMBED with (direct task M4, rule E). Never used to switch a setting:
     * stored vectors only match the model that made them, so a discontinued
     * embedding model means re-embedding the knowledge base, which only the
     * owner can start. Core shows a notice instead.
     *
     * Static rows: Google's deprecations page; text-embedding-004,
     * embedding-001 and embedding-2-preview answer 404 (probed 2026-10-16).
     * Dated catalog rows (gemini-embedding-001, 2028-05-14) join on their date.
     */
    public static function retired_embedding_map() {
        $map = array(
            'text-embedding-004'         => array('to' => 'gemini-embedding-2', 'label' => 'Gemini Embedding 2', 'from_label' => 'Text Embedding 004'),
            'embedding-001'              => array('to' => 'gemini-embedding-2', 'label' => 'Gemini Embedding 2', 'from_label' => 'Embedding 001'),
            'embedding-2-preview'        => array('to' => 'gemini-embedding-2', 'label' => 'Gemini Embedding 2', 'from_label' => 'Embedding 2 (Preview)'),
            'gemini-embedding-exp-03-07' => array('to' => 'gemini-embedding-2', 'label' => 'Gemini Embedding 2', 'from_label' => 'Gemini Embedding (Experimental)'),
            'gemini-embedding-exp'       => array('to' => 'gemini-embedding-2', 'label' => 'Gemini Embedding 2', 'from_label' => 'Gemini Embedding (Experimental)'),
            'embedding-gecko-001'        => array('to' => 'gemini-embedding-2', 'label' => 'Gemini Embedding 2', 'from_label' => 'Embedding Gecko'),
        );
        $labels = array();
        foreach (self::embedding_models() as $provider) {
            foreach ($provider['models'] as $id => $entry) {
                $labels[$id] = $entry['label'];
            }
        }
        foreach (self::embedding_models() as $provider) {
            foreach ($provider['models'] as $id => $entry) {
                if (!empty($entry['deprecated_on']) && !empty($entry['replacement']) && self::date_has_passed($entry['deprecated_on'])) {
                    $to = (string) $entry['replacement'];
                    $map[$id] = array('to' => $to, 'label' => isset($labels[$to]) ? $labels[$to] : $to, 'from_label' => $entry['label']);
                }
            }
        }
        return $map;
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
     * The provider slug that owns a chat model id, or '' when the catalog
     * doesn't list it (plan b65e8d).
     *
     * NOTE for callers doing retirement work: a model the provider has already
     * retired is frequently ABSENT from this catalog while still saved on an
     * install — that is precisely the state the liveness check exists to catch.
     * Treat '' as "catalog doesn't know", not "not a real model", and fall back
     * to a family-prefix guess if you need one.
     */
    public static function provider_for_chat_model($model_id) {
        $model_id = (string) $model_id;
        foreach (self::chat_models() as $provider_slug => $provider) {
            if (isset($provider['models'][$model_id])) {
                return $provider_slug;
            }
        }
        return '';
    }

    /**
     * URL + auth headers for a provider's public model-listing endpoint
     * (plan b65e8d — the daily liveness check).
     *
     * Only providers that actually publish a listing are mapped. OpenRouter is
     * deliberately excluded: its 'openrouter' catalog entry is a meta-selector
     * that resolves to a per-account sub-model at request time, so "is the
     * configured id listed" is not a meaningful question. DeepSeek and the
     * custom OpenAI-compatible provider are excluded too — a self-hosted or
     * proxied endpoint's /models is not authoritative about retirement.
     *
     * @param string $provider Provider slug.
     * @param string $api_key  The install's key for that provider.
     * @return array|null array('url' => string, 'headers' => array) or null.
     */
    public static function models_listing_request($provider, $api_key) {
        $api_key = (string) $api_key;
        if ($api_key === '') {
            return null;
        }

        switch ($provider) {
            case 'openai':
                return array(
                    'url'     => 'https://api.openai.com/v1/models',
                    'headers' => array('Authorization' => 'Bearer ' . $api_key),
                );
            case 'claude':
                return array(
                    'url'     => 'https://api.anthropic.com/v1/models?limit=1000',
                    'headers' => array(
                        'x-api-key'         => $api_key,
                        'anthropic-version' => '2023-06-01',
                    ),
                );
            case 'gemini':
                return array(
                    'url'     => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000',
                    'headers' => array('x-goog-api-key' => $api_key),
                );
            case 'xai':
                return array(
                    'url'     => 'https://api.x.ai/v1/models',
                    'headers' => array('Authorization' => 'Bearer ' . $api_key),
                );
        }

        return null;
    }

    /**
     * A minimal 1-token chat request used ONLY to confirm whether a model id
     * still exists (plan b65e8d). Never used for content — the response is
     * thrown away; only the HTTP status and error code are read.
     *
     * WHY THIS EXISTS: a provider's model LISTING is not authoritative about
     * retirement, in either direction (all probed live 2026-08-13):
     *   - OpenAI KEEPS retired ids listed — gpt-5.3-chat-latest is in
     *     /v1/models and 404s "model_deprecated" on use.
     *   - Anthropic DROPS working aliases — claude-opus-4-5 is absent from
     *     /v1/models and answers HTTP 200 perfectly.
     *   - xAI's listing is partial — grok-4-0709 is absent and answers 200.
     * So "absent from the listing" is a suspicion, not a verdict. This probe is
     * the verdict, and it is the only thing allowed to raise a warning.
     *
     * @return array|null array('url','headers','body') or null when unsupported.
     */
    public static function inference_probe_request($provider, $api_key, $model) {
        $api_key = (string) $api_key;
        $model   = (string) $model;
        if ($api_key === '' || $model === '') {
            return null;
        }

        $msg = array(array('role' => 'user', 'content' => 'hi'));

        switch ($provider) {
            case 'openai':
                return array(
                    'url'     => 'https://api.openai.com/v1/chat/completions',
                    'headers' => array('Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'),
                    'body'    => array('model' => $model, 'messages' => $msg, 'max_completion_tokens' => 1),
                );
            case 'xai':
                return array(
                    'url'     => 'https://api.x.ai/v1/chat/completions',
                    'headers' => array('Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'),
                    'body'    => array('model' => $model, 'messages' => $msg, 'max_tokens' => 1),
                );
            case 'claude':
                return array(
                    'url'     => 'https://api.anthropic.com/v1/messages',
                    'headers' => array(
                        'x-api-key'         => $api_key,
                        'anthropic-version' => '2023-06-01',
                        'Content-Type'      => 'application/json',
                    ),
                    'body'    => array('model' => $model, 'max_tokens' => 1, 'messages' => $msg),
                );
            case 'gemini':
                return array(
                    'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/'
                                 . rawurlencode($model) . ':generateContent',
                    'headers' => array('x-goog-api-key' => $api_key, 'Content-Type' => 'application/json'),
                    'body'    => array(
                        'contents'         => array(array('parts' => array(array('text' => 'hi')))),
                        'generationConfig' => array('maxOutputTokens' => 1),
                    ),
                );
        }

        return null;
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
     * Picker label for a catalog entry offered for NEW picks, or null when the
     * entry is not offered (direct task M4: legacy, or any announced
     * retirement date — before or after the date). Plan e46b8f added the
     * "retires <date>" label for dated models; since M4 a dated model is
     * hidden from new picks the day it is announced, and the date only shows
     * on the saved value (saved_label()).
     *
     * @param array $entry Model entry from chat_models().
     * @return string|null
     */
    private static function picker_label($entry) {
        return self::is_offered($entry) ? $entry['label'] : null;
    }

    /**
     * Label for a site's SAVED model that is no longer offered, so the
     * settings select shows the truth instead of a blank (plan e46b8f, M4).
     */
    private static function saved_label($entry) {
        if (!empty($entry['deprecated_on'])) {
            $ts = strtotime($entry['deprecated_on'] . ' 00:00:00 UTC');
            if ($ts !== false && self::now() >= $ts) {
                /* translators: %s: model label */
                return sprintf(__('%s — retired by provider, choose a replacement', 'mxchat'), $entry['label']);
            }
            return sprintf(
                /* translators: 1: model label, 2: retirement date */
                __('%1$s — retires %2$s', 'mxchat'),
                $entry['label'],
                date_i18n(get_option('date_format', 'F j, Y'), $ts)
            );
        }
        if (!empty($entry['legacy'])) {
            /* translators: %s: model label */
            return sprintf(__('%s — older model, no longer offered', 'mxchat'), $entry['label']);
        }
        return $entry['label'];
    }

    /**
     * Settings-dropdown-shaped chat model map keyed by group label, which is
     * what mxchat_model_callback() in class-mxchat-admin.php renders as
     * <optgroup>. Each model maps to its label-with-description string.
     *
     * Only current models are listed (direct task M4) — EXCEPT the install's
     * currently saved model, which is re-injected with its status ("retires
     * <date>", "older model, no longer offered", "retired by provider") so the
     * settings screen never renders a blank select and a save never silently
     * changes it (plan e46b8f).
     *
     * @param string     $current_model  Optionally, the install's saved model id.
     * @param array|null $provider_slugs Optional allowlist of provider slugs
     *                                   (catalog keys, e.g. 'openai', 'claude').
     *                                   null = every provider. Lets a consumer
     *                                   that can only dispatch to some providers
     *                                   (the content generator — plan ccb751)
     *                                   derive its picker from the same catalog.
     */
    public static function settings_dropdown_groups($current_model = '', $provider_slugs = null) {
        $current_model = (string) $current_model;
        $out = array();
        foreach (self::chat_models() as $provider_slug => $provider) {
            if (is_array($provider_slugs) && !in_array($provider_slug, $provider_slugs, true)) {
                continue;
            }
            $out[$provider['label']] = array();
            foreach ($provider['models'] as $id => $entry) {
                $label = self::picker_label($entry);
                if ($label === null) {
                    if ($id !== $current_model) {
                        continue;
                    }
                    $label = self::saved_label($entry);
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
                    continue; // legacy / dated / retired — never offered as a new selection (M4)
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
                        continue; // legacy / dated / retired — not offered during onboarding (M4)
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
     *   - xai: grok-4.6, the current flagship. Promoted 2026-08-14 (plan
     *     e1aa4a) from grok-4-0709, which was two generations behind and no
     *     longer appears in xAI's /v1/models listing. Image input is CONFIRMED,
     *     not assumed: a 32x32 fixture probe on 2026-08-14 returned the correct
     *     colour on grok-4.6 / 4.5 / 4.3 and both 4.20 variants (all HTTP 200).
     *     grok-4.3 is the cheaper vision option (roughly half the image-token
     *     price, 1M context) if cost ever outweighs capability here.
     *   - gemini: current stable Flash — vision-capable, no per-image surcharge.
     *
     * Re-checked in direct task M2 (2026-10-16), each with a 32x32 colour
     * probe on the box (all answered correctly): claude moves to Haiku 5.5
     * (newest Haiku, $0.10 / $0.50 against Haiku 4.5's $1 / $5, 0.8 s); xai
     * moves to grok-4.7 (newest flagship, same price as 4.6, 1.2 s against
     * 4.6's 4.6 s). openai stays on gpt-5.6-sol (not deprecated, answered in
     * 1.0 s against gpt-6.1-sol's 2.6 s) and gemini on 3.8 Flash.
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
            'claude' => 'claude-haiku-5-5',
            'xai'    => 'grok-4.7',
            'gemini' => 'gemini-3.8-flash',
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
     *                   'none'; the *-chat-latest / 5.4-mini / 5.4-nano /
     *                   gpt-5.2 ids send NO reasoning_effort; the original
     *                   gpt-5 / gpt-5-mini / gpt-5-nano send 'minimal' (their
     *                   generation requires it — 25b972 probe). Unknown future
     *                   gpt-5* ids send NOTHING (allowlist fall-through).
     *   - 'content'   — content generation (content-generator, AC ai-client,
     *                   AC content-calendar). gpt-5.5 / gpt-5.4 use 'low';
     *                   gpt-5.4-mini / gpt-5.4-nano use 'none' (they never
     *                   supported 'minimal' — 25b972); gpt-5.3-chat-latest and
     *                   the original gpt-5 / -mini / -nano use 'minimal'; only
     *                   gpt-5.2 and gpt-5.1-chat-latest send nothing. This is
     *                   an ALLOWLIST (5621dd safe-default): a future gpt-5* id
     *                   not listed gets NO param rather than a guessed value
     *                   that might 400.
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

        // plan a9774b: every Gemini 3.x output price is "including thinking
        // tokens" and the model thinks at its default level unless told
        // otherwise — measured on this site's own support prompt, 71% of
        // billed output on 3.8 Flash was hidden thinking, with indistinguishable
        // replies at 'low' (0 hidden tokens). So the chat surface sends 'low':
        // as reasoning_effort on the OpenAI-compatible endpoint, and as
        // generationConfig.thinkingConfig.thinkingLevel on the native one (see
        // gemini_thinking_config()). Flash-Lite does not think and returns 400
        // on any reasoning parameter — it must send nothing.
        // Direct task M2: gemini-4 (Gemini 4 Argon) takes the same thinking
        // levels as 3.x — probed 2026-10-16, 'low' accepted on both endpoints,
        // 'minimal' 400s. Without this it fell through to null and thought at
        // its default level on every chat turn.
        if (strpos($model, 'gemini-3') === 0 || strpos($model, 'gemini-4') === 0) {
            if (strpos($model, 'flash-lite') !== false) {
                return null;
            }
            return ($context === 'chat') ? 'low' : null;
        }

        // Only the modern OpenAI family (gpt-5*, gpt-6*) carries
        // reasoning_effort. Everything else omits. plan 45c22d widened this
        // from a literal gpt-5 prefix: a gpt-6 id used to fall through all
        // three maps below and be sent legacy parameters.
        if (!self::is_modern_openai($model)) {
            return null;
        }

        switch ($context) {
            case 'websearch':
                // integrator web-search (Responses API). Explicit allowlist, no
                // "else" — anything not listed omits reasoning entirely.
                $map = array(
                    'gpt-6-astra'        => 'low', // plan 45c22d, probed 2026-09-27 on /v1/responses
                    'gpt-6.1-sol'        => 'low', // plan 6945f6, probed 2026-10-16 (rejects 'none')
                    'gpt-6-sol'          => 'low',
                    'gpt-6-luna'         => 'low',
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
                //
                // plan-25b972 (probed live 2026-08-13): supported reasoning_effort
                // VALUES are per-model. gpt-5.4-mini/nano never supported
                // 'minimal' (their floor is 'none' — the old 'minimal' entries
                // 400'd on every call); the original gpt-5/-mini/-nano still
                // REQUIRE 'minimal' and reject 'none'. Do not "harmonize" these
                // — the two generations genuinely differ.
                $map = array(
                    // plan 45c22d (probed 2026-09-27): gpt-6-astra accepts only
                    // low|medium|high; gpt-6-sol/-luna accept none|low|medium|high.
                    // 'low' for all three, as for their 5.6 siblings.
                    'gpt-6-astra'         => 'low',
                    'gpt-6.1-sol'         => 'low', // plan 6945f6 (probed 2026-10-16): low|medium|high|xhigh
                    'gpt-6-sol'           => 'low',
                    'gpt-6-luna'          => 'low',
                    'gpt-5.6-sol'         => 'low',
                    'gpt-5.6-terra'       => 'low',
                    'gpt-5.6-luna'        => 'low',
                    'gpt-5.5'             => 'low',
                    'gpt-5.4'             => 'low',
                    'gpt-5.1-2025-11-13'  => 'low',
                    'gpt-5.4-mini'        => 'none',
                    'gpt-5.4-nano'        => 'none',
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
                // plan 45c22d (probed 2026-09-27): gpt-6-astra accepts only
                // low|medium|high; -sol/-luna accept none|low|medium|high.
                // 'low' for all three, as for their 5.6 siblings.
                // plan 6945f6 (probed 2026-10-16): gpt-6.1-sol accepts only
                // low|medium|high|xhigh, so 'low' as for Astra.
                if (in_array($model, array('gpt-6-astra', 'gpt-6.1-sol', 'gpt-6-sol', 'gpt-6-luna'), true)) {
                    return 'low';
                }
                if (in_array($model, array('gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'), true)) {
                    return 'low';
                }
                // plan-25b972: the original gpt-5 generation REQUIRES 'minimal'
                // (probed 2026-08-13: 'none' is rejected for these three).
                if (in_array($model, array('gpt-5', 'gpt-5-mini', 'gpt-5-nano'), true)) {
                    return 'minimal';
                }
                // plan-25b972: allowlist fall-through (5621dd philosophy). An
                // unknown future gpt-5* id sends NO param — a guessed value is
                // exactly how the 5.4-mini/nano 400s shipped.
                return null;
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
    /**
     * generationConfig.thinkingConfig for the native Gemini endpoint, or null
     * to send none (plan a9774b). Derived from reasoning_effort_for() so the
     * two Gemini paths — native generateContent and the OpenAI-compatible
     * endpoint — always agree on the thinking level. Overridable via
     * mxchat_gemini_thinking_level (return '' to send none).
     *
     * @param string $model Chat model id.
     * @return array|null   e.g. array('thinkingLevel' => 'low').
     */
    public static function gemini_thinking_config($model) {
        $level = self::reasoning_effort_for($model, 'chat');
        if (strpos((string) $model, 'gemini-') !== 0) {
            return null;
        }
        $level = apply_filters('mxchat_gemini_thinking_level', $level, $model);
        if (!is_string($level) || $level === '' || !in_array($level, array('minimal', 'low', 'medium', 'high'), true)) {
            return null;
        }
        return array('thinkingLevel' => $level);
    }

    public static function supports_temperature($model) {
        $model = (string) $model;

        // gpt-5 / gpt-6 families accept only the default temperature (1).
        if (self::is_modern_openai($model)) {
            return false;
        }

        // Anthropic flagships that reject the temperature param outright.
        // TRAP: exact-match list — every NEW Anthropic flagship (4.7 and newer)
        // must be added here or it 400s on every chat message while looking
        // fine in the picker. A claude-opus-* prefix rule would wrongly catch
        // claude-opus-4-6 and older, which still accept temperature.
        // Direct task M2 (probed 2026-10-16): the 5.5 generation and Fable 5.1
        // all return 400 "temperature is deprecated for this model".
        $claude_no_temp = array('claude-opus-5', 'claude-opus-4-7', 'claude-opus-4-8', 'claude-fable-5', 'claude-sonnet-5', 'claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-1');
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
        return self::is_modern_openai($model) ? 'max_completion_tokens' : 'max_tokens';
    }

    /**
     * plan 45c22d: ONE predicate for "a modern OpenAI chat model" — the
     * generations that take max_completion_tokens, only the default
     * temperature, and reasoning_effort. Every gate that used to test the
     * literal prefix gpt-5 routes through here, so the next generation is a
     * one-line change instead of nine edits. Before this, gpt-6-* ids fell
     * through all nine and were sent legacy parameters: a 400 on the first
     * message while looking fine in the picker.
     *
     * The frozen partial-upgrade fallbacks in the integrator, the content
     * generator and the editor assistant carry the same two-prefix test
     * inline (they exist for the window where this class is not loaded).
     *
     * @param string $model Chat model id.
     * @return bool
     */
    public static function is_modern_openai($model) {
        $model = (string) $model;
        return strpos($model, 'gpt-5') === 0 || strpos($model, 'gpt-6') === 0;
    }
}
