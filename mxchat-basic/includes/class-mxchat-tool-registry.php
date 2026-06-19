<?php
/**
 * MxChat Tool Registry — adapter from the callback registry to model-callable tools.
 *
 * Plan: plan-mxchat-20260617-a41dee (native function-calling loop).
 *
 * This is the SINGLE SOURCE that turns MxChat's registered callbacks into
 * provider-agnostic tool schemas. BOTH surfaces read from it so they can never
 * drift apart (the "synced in several places, broken in one" trap from
 * CLAUDE.md's model-registry checklist):
 *   - the admin "AI Tools" per-tool checklist (class-mxchat-admin.php Actions page)
 *   - the chat-time function-calling loop (class-mxchat-integrator.php)
 *
 * Tool sources (both used by available_tools()):
 *   - CORE: a curated, model-facing catalog of the core mxchat-basic callbacks
 *     that make sensible model tools (core_tool_catalog()). Curated rather than
 *     reusing every intent callback because some core callbacks (e.g. the
 *     chatbot-mode toggle) are not meaningful as model tools.
 *   - ADD-ONS: whatever active+licensed add-ons register via the existing
 *     `mxchat_available_callbacks` filter (woo / perplexity / forms). Reading the
 *     live filter means the tool list tracks the active add-ons with zero
 *     duplication.
 *
 * The registry is intentionally self-contained (no dependency on MxChat_Admin,
 * which is admin-only) so the front-end integrator can build the same tool list
 * the admin checklist shows.
 *
 * @package MxChat
 * @since   3.2.10
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Tool_Registry {

    /** Standalone options (bypass the mxchat_options sanitize traps). */
    const OPT_ENABLED = 'mxchat_function_calling_enabled';
    const OPT_TOOLS   = 'mxchat_function_calling_tools';

    /**
     * Register the AJAX autosave handler for the AI Tools section.
     * Called once from the plugin bootstrap. The section saves on every toggle/
     * checkbox change (matching MxChat's autosave UX) — no Save button.
     */
    public static function init() {
        add_action('wp_ajax_mxchat_fc_autosave', array(__CLASS__, 'handle_ajax_save'));
    }

    /* ------------------------------------------------------------------ *
     *  Settings accessors
     * ------------------------------------------------------------------ */

    /**
     * Master gate — a tool in the list = active (plan d450a7). Derived purely
     * from tool-presence so the function-calling loop runs exactly when the
     * owner has >=1 enabled tool; an empty list is off (the safe default that
     * preserves "nothing happens until you add a tool"). The OPT_ENABLED option
     * is still written by persist() for back-compat, but presence is the source
     * of truth — there is no longer a separate global on/off toggle to drift.
     */
    public static function is_enabled() {
        return self::count_enabled(self::enabled_map()) > 0;
    }

    /**
     * Saved per-tool settings map: [ callback_fn => entry ].
     *
     * An entry is EITHER the legacy bare-bool shape (`true`/`false`) OR the
     * richer object shape `[ 'enabled' => bool, 'usage_hint' => string ]`
     * introduced by plan 27d3e6. Both shapes are accepted on read (see
     * resolve_tool_setting()) so existing installs are never migrated
     * destructively — a row only takes the object shape the next time the
     * owner saves the AI Tools section.
     */
    public static function enabled_map() {
        $map = get_option(self::OPT_TOOLS, array());
        return is_array($map) ? $map : array();
    }

    /** Max stored length of a per-tool "when to use" hint. */
    const HINT_MAX = 500;

    /**
     * Normalize a stored OPT_TOOLS entry into [ enabled(bool), usage_hint(string) ].
     * Accepts BOTH the legacy bare-bool shape AND the new
     * { enabled, usage_hint } object shape (back-compat, HARD — plan 27d3e6).
     *
     * @param mixed $saved       The stored entry, or null when the tool is unset.
     * @param bool  $is_cautious Whether the tool defaults off (sensitive).
     * @return array{0:bool,1:string}
     */
    public static function resolve_tool_setting($saved, $is_cautious) {
        if (is_array($saved)) {
            // Object shape. An absent 'enabled' key means the owner never turned
            // this tool on → OFF. AI Tools default to ZERO active on a fresh
            // install (plan d450a7: presence = active; nothing fires until the
            // owner adds a tool). $is_cautious no longer affects the default —
            // EVERY tool is off until explicitly enabled. (Regression fix: 3.2.10
            // shipped with this defaulting to !$is_cautious, which showed ~10
            // tools "active" on a fresh install and was a footgun for turning FC
            // on by an accidental save.)
            $enabled = array_key_exists('enabled', $saved)
                ? (bool) $saved['enabled']
                : false;
            $hint = isset($saved['usage_hint']) ? (string) $saved['usage_hint'] : '';
            return array($enabled, $hint);
        }
        if ($saved === null) {
            return array(false, ''); // unset → OFF (no tool active until added)
        }
        // Legacy bare-bool: a plain true/false enables/disables with no hint.
        return array((bool) $saved, '');
    }

    /** Trim + length-bound a usage hint before it is stored or sent to the model. */
    private static function clip_hint($s) {
        $s = trim((string) $s);
        return function_exists('mb_substr')
            ? mb_substr($s, 0, self::HINT_MAX)
            : substr($s, 0, self::HINT_MAX);
    }

    /** Bounded multi-step depth (mirrors AI Engine's mwai_function_call_max_depth). */
    public static function max_depth() {
        $depth = (int) apply_filters('mxchat_function_call_max_depth', 5);
        return max(1, min(10, $depth));
    }

    /** Hard ceiling on tool executions in a single user turn (loop-safety). */
    public static function max_tool_calls_per_turn() {
        $n = (int) apply_filters('mxchat_function_call_max_per_turn', 8);
        return max(1, min(20, $n));
    }

    /* ------------------------------------------------------------------ *
     *  Curation policy
     * ------------------------------------------------------------------ */

    /**
     * Callbacks that default to OFF (opt-in) even when function calling is on:
     * anything that spends money, mutates a cart, exposes customer PII, hands the
     * conversation to a human, or starts a data-collection flow. An admin can
     * still enable them deliberately on the AI Tools checklist.
     */
    public static function cautious_callbacks() {
        return apply_filters('mxchat_function_calling_cautious_callbacks', array(
            // money / cart mutation
            'mxchat_handle_add_to_cart_intent', 'mxchat_add_to_cart',
            'mxchat_handle_checkout_intent', 'mxchat_checkout_redirect',
            // customer PII
            'mxchat_handle_order_history',
            // disruptive handoff / data-collection flows
            'mxchat_live_agent_handover', 'mxchat_telegram_live_agent_handover',
            'mxchat_handle_email_capture', 'mxchat_handle_form_collection',
        ));
    }

    /** Callbacks that are never offered as model tools (not meaningful as a tool). */
    public static function excluded_callbacks() {
        return apply_filters('mxchat_function_calling_excluded_callbacks', array(
            'mxchat_handle_switch_to_chatbot_intent', // chatbot/agent mode toggle
        ));
    }

    /**
     * Curated core callbacks eligible as model tools, with MODEL-FACING
     * descriptions (written for the LLM to reason over, distinct from the
     * admin-facing labels in the Actions registry).
     *
     * Each value: [ 'label' => admin label, 'description' => model-facing ].
     */
    public static function core_tool_catalog() {
        return apply_filters('mxchat_function_calling_core_tools', array(
            'mxchat_handle_search_request' => array(
                'label'       => __('Web Search', 'mxchat'),
                'description' => __('Search the public web (via Brave Search) for current, real-time information to answer the user. Use when the answer may be recent or is not in the site content.', 'mxchat'),
                // Admin-facing setup requirement (plan 183856): this tool runs on the
                // Brave Search API and silently no-ops without a key. requires_key
                // drives the "Brave key not set" notice; setup_note is shown in the
                // tool's detail panel so the admin knows where to add the key.
                'requires_key' => 'brave_api_key',
                'setup_note'   => __('Powered by Brave Search — requires a Brave Search API key. Add it under Settings → Brave Search.', 'mxchat'),
            ),
            'mxchat_handle_image_search_request' => array(
                'label'       => __('Image Search', 'mxchat'),
                'description' => __('Search the web (via Brave Search) for images relevant to the user request and show them in the chat.', 'mxchat'),
                // Output is a rendered image gallery (html), not text — the FC loop
                // must SURFACE it to the frontend, not strip it (plan 48a57a). This
                // callback self-saves its own bot messages (text + html).
                'emits_ui'      => true,
                'ui_self_saves' => true,
                // Brave-powered like Web Search (plan 183856).
                'requires_key' => 'brave_api_key',
                'setup_note'   => __('Powered by Brave Search — requires a Brave Search API key. Add it under Settings → Brave Search.', 'mxchat'),
            ),
            'mxchat_handle_pdf_discussion' => array(
                'label'       => __('Ask the Uploaded PDF', 'mxchat'),
                'description' => __('Answer a question using the PDF document the visitor uploaded earlier in this conversation.', 'mxchat'),
            ),
            'mxchat_generate_image' => array(
                'label'       => __('Generate Image (OpenAI)', 'mxchat'),
                'description' => __('Create an image from a text description using OpenAI image generation. The query should describe the image to make.', 'mxchat'),
                // Output is a rendered <img> (html) — surface it, don't strip it
                // (plan 48a57a). Self-saves its own bot messages (text + html).
                'emits_ui'      => true,
                'ui_self_saves' => true,
            ),
            'mxchat_generate_gemini_image' => array(
                'label'       => __('Generate Image (Gemini)', 'mxchat'),
                'description' => __('Create an image from a text description using Google Imagen. The query should describe the image to make.', 'mxchat'),
                // Output is a rendered <img> (html) — surface it, don't strip it
                // (plan 48a57a). Self-saves its own bot messages (text + html).
                'emits_ui'      => true,
                'ui_self_saves' => true,
            ),
            // Cautious (default-off) core callbacks — selectable but opt-in:
            'mxchat_handle_email_capture' => array(
                'label'       => __('Collect Email', 'mxchat'),
                'description' => __('Begin collecting the visitor\'s email address for the mailing list.', 'mxchat'),
            ),
            'mxchat_live_agent_handover' => array(
                'label'       => __('Hand Off to Live Agent (Slack)', 'mxchat'),
                'description' => __('Transfer the conversation to a human support agent on Slack.', 'mxchat'),
            ),
            'mxchat_telegram_live_agent_handover' => array(
                'label'       => __('Hand Off to Live Agent (Telegram)', 'mxchat'),
                'description' => __('Transfer the conversation to a human support agent on Telegram.', 'mxchat'),
            ),
        ));
    }

    /* ------------------------------------------------------------------ *
     *  Tool list construction (the single source for both surfaces)
     * ------------------------------------------------------------------ */

    /**
     * Build the full provider-agnostic tool list, each annotated with its
     * current enabled state. Used by the admin checklist AND the chat loop.
     *
     * @param array|null $enabled_map Override the saved enable map (for previews).
     * @return array[] each: [
     *   'name'        => API tool name (sanitized callback fn),
     *   'callback'    => the callback function name to invoke,
     *   'label'       => admin label,
     *   'description' => model-facing description,
     *   'group'       => grouping label,
     *   'is_addon'    => bool (true → invoke via apply_filters),
     *   'addon'       => add-on slug or '',
     *   'cautious'    => bool (defaults off),
     *   'enabled'     => bool (effective enabled state),
     *   'parameters'  => JSON-Schema object for the tool arguments,
     * ]
     */
    public static function available_tools($enabled_map = null) {
        if ($enabled_map === null) {
            $enabled_map = self::enabled_map();
        }
        $excluded = self::excluded_callbacks();
        $cautious = self::cautious_callbacks();

        $entries = array();

        // 1) Core tools.
        foreach (self::core_tool_catalog() as $fn => $meta) {
            if (in_array($fn, $excluded, true)) {
                continue;
            }
            $core_desc = (isset($meta['fc_description']) && $meta['fc_description'] !== '')
                ? $meta['fc_description']
                : (isset($meta['description']) ? $meta['description'] : '');
            $entries[$fn] = array(
                'callback'      => $fn,
                'label'         => isset($meta['label']) ? $meta['label'] : $fn,
                'description'   => $core_desc,
                'group'         => __('Core', 'mxchat'),
                'is_addon'      => false,
                'addon'         => '',
                'fc_parameters' => (isset($meta['fc_parameters']) && is_array($meta['fc_parameters'])) ? $meta['fc_parameters'] : null,
                // plan 48a57a — UI-bearing tool metadata. emits_ui marks a tool
                // whose output is a rendered element (image/card/gallery) that the
                // FC loop must surface to the frontend; ui_self_saves notes the
                // callback persists its own bot messages (core image/search do).
                'emits_ui'      => !empty($meta['emits_ui']),
                'ui_self_saves' => array_key_exists('ui_self_saves', $meta) ? !empty($meta['ui_self_saves']) : true,
                // Admin-facing setup metadata (plan 183856) — core tools only.
                'requires_key'  => isset($meta['requires_key']) ? $meta['requires_key'] : '',
                'setup_note'    => isset($meta['setup_note']) ? $meta['setup_note'] : '',
            );
        }

        // 2) Active add-on callbacks (canonical: the live filter).
        $addon_callbacks = apply_filters('mxchat_available_callbacks', array());
        if (is_array($addon_callbacks)) {
            foreach ($addon_callbacks as $fn => $data) {
                if (in_array($fn, $excluded, true)) {
                    continue;
                }
                if (!is_array($data)) {
                    $data = array();
                }
                $label = isset($data['label']) ? $data['label'] : self::humanize($fn);
                // Prefer an explicit model-facing description (fc_description) so the
                // admin-facing `description` shown in the legacy Actions registry can
                // stay short while the model gets richer when-to-call guidance.
                $desc  = (isset($data['fc_description']) && $data['fc_description'] !== '')
                    ? $data['fc_description']
                    : (isset($data['description']) && $data['description'] !== ''
                        ? $data['description']
                        : sprintf(__('Use the %s capability.', 'mxchat'), $label));
                $entries[$fn] = array(
                    'callback'      => $fn,
                    'label'         => $label,
                    'description'   => $desc,
                    'group'         => isset($data['group']) ? $data['group'] : __('Add-ons', 'mxchat'),
                    'is_addon'      => true,
                    'addon'         => isset($data['addon']) ? $data['addon'] : '',
                    'fc_parameters' => (isset($data['fc_parameters']) && is_array($data['fc_parameters'])) ? $data['fc_parameters'] : null,
                    // plan 48a57a — an add-on declares emits_ui when its callback
                    // returns a rendered element (e.g. woo product cards). Add-on
                    // callbacks default to NOT self-saving (the integrator persists
                    // their html), so ui_self_saves defaults false.
                    'emits_ui'      => !empty($data['emits_ui']),
                    'ui_self_saves' => !empty($data['ui_self_saves']),
                );
            }
        }

        // 2b) Per-INSTANCE tools injected directly by an add-on (plan 60bccc).
        //     Kept SEPARATE from `mxchat_available_callbacks` on purpose: an add-on
        //     that exposes one tool per stored record (e.g. mxchat-forms surfaces
        //     each opted-in form as its own "Collect: <Form>" tool) must not push N
        //     entries into the intent/Actions admin registry, which also reads the
        //     callbacks filter. Each entry mirrors the add-on entry shape; is_addon
        //     is forced true so the chat loop invokes it via apply_filters($fn,...),
        //     exactly like a normal add-on callback. Additive: a no-op until an
        //     add-on hooks the filter, so existing installs see no change.
        $extra_tools = apply_filters('mxchat_function_calling_extra_tools', array());
        if (is_array($extra_tools)) {
            foreach ($extra_tools as $fn => $data) {
                if (in_array($fn, $excluded, true) || isset($entries[$fn]) || !is_array($data)) {
                    continue;
                }
                $label = isset($data['label']) ? $data['label'] : self::humanize($fn);
                $desc  = (isset($data['fc_description']) && $data['fc_description'] !== '')
                    ? $data['fc_description']
                    : (isset($data['description']) && $data['description'] !== ''
                        ? $data['description']
                        : sprintf(__('Use the %s capability.', 'mxchat'), $label));
                $entries[$fn] = array(
                    'callback'      => $fn,
                    'label'         => $label,
                    'description'   => $desc,
                    'group'         => isset($data['group']) ? $data['group'] : __('Add-ons', 'mxchat'),
                    'is_addon'      => true,
                    'addon'         => isset($data['addon']) ? $data['addon'] : '',
                    'fc_parameters' => (isset($data['fc_parameters']) && is_array($data['fc_parameters'])) ? $data['fc_parameters'] : null,
                    'emits_ui'      => !empty($data['emits_ui']),
                    'ui_self_saves' => !empty($data['ui_self_saves']),
                );
            }
        }

        // 3) Finalize: name, parameters, cautious flag, effective enabled state,
        //    plus the owner's optional per-tool "when to use" hint (plan 27d3e6).
        $tools = array();
        foreach ($entries as $fn => $e) {
            $is_cautious = in_array($fn, $cautious, true);
            $saved = array_key_exists($fn, $enabled_map) ? $enabled_map[$fn] : null;
            list($enabled, $usage_hint) = self::resolve_tool_setting($saved, $is_cautious);
            $tools[] = array(
                'name'        => self::tool_name($fn),
                'callback'    => $fn,
                'label'       => $e['label'],
                'description' => $e['description'],
                'usage_hint'  => $usage_hint,
                'group'       => $e['group'],
                'is_addon'    => $e['is_addon'],
                'addon'       => $e['addon'],
                'cautious'    => $is_cautious,
                'enabled'     => $enabled,
                'parameters'  => self::parameters_for($fn, $e),
                // plan 48a57a — carried through so the chat loop can detect
                // UI-bearing tool output and decide whether to persist its html.
                'emits_ui'      => !empty($e['emits_ui']),
                'ui_self_saves' => !empty($e['ui_self_saves']),
                // plan 183856 — admin-facing setup hint surfaced on the AI Tools
                // page; requires_key lets the page flag a missing dependency key.
                'requires_key'  => isset($e['requires_key']) ? $e['requires_key'] : '',
                'setup_note'    => isset($e['setup_note']) ? $e['setup_note'] : '',
            );
        }
        return $tools;
    }

    /** Just the enabled tools — what the model actually sees. */
    public static function enabled_tools($enabled_map = null) {
        $tools = array();
        foreach (self::available_tools($enabled_map) as $t) {
            if (!empty($t['enabled'])) {
                $tools[] = $t;
            }
        }
        return $tools;
    }

    /** Resolve a model-emitted tool name back to its tool entry. */
    public static function tool_by_name($name, $enabled_only = true) {
        $list = $enabled_only ? self::enabled_tools() : self::available_tools();
        foreach ($list as $t) {
            if ($t['name'] === $name || $t['callback'] === $name) {
                return $t;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ *
     *  Schema helpers
     * ------------------------------------------------------------------ */

    /** Sanitize a callback fn into a valid API tool name (^[A-Za-z0-9_-]{1,64}$). */
    public static function tool_name($fn) {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $fn);
        return substr($name, 0, 64);
    }

    /** Turn a function name into a readable label as a fallback. */
    private static function humanize($fn) {
        $s = preg_replace('/^mxchat_(handle_)?/', '', (string) $fn);
        $s = str_replace('_', ' ', $s);
        return ucwords(trim($s));
    }

    /**
     * JSON-Schema for a tool's arguments. MxChat callbacks are message-driven, so
     * the default is a single natural-language `query` string (handed to the
     * callback as the user message). A registry entry may declare richer params
     * via an additive `fc_parameters` key (used by the woo tools plan c2c9b5).
     */
    public static function parameters_for($fn, $entry = array()) {
        if (is_array($entry) && isset($entry['fc_parameters']) && is_array($entry['fc_parameters'])) {
            return $entry['fc_parameters'];
        }
        return array(
            'type'       => 'object',
            'properties' => array(
                'query' => array(
                    'type'        => 'string',
                    'description' => __('The natural-language request, question, or search query to act on.', 'mxchat'),
                ),
            ),
            'required'   => array('query'),
        );
    }

    /* ------------------------------------------------------------------ *
     *  Provider-native tool-schema transforms (pure)
     * ------------------------------------------------------------------ */

    /** OpenAI / xAI / DeepSeek / OpenRouter / Custom (OpenAI-compatible). */
    public static function to_openai_tools($tools) {
        $out = array();
        foreach ($tools as $t) {
            $out[] = array(
                'type'     => 'function',
                'function' => array(
                    'name'        => $t['name'],
                    'description' => self::clip(self::model_description($t)),
                    'parameters'  => $t['parameters'],
                ),
            );
        }
        return $out;
    }

    /** Anthropic Claude. */
    public static function to_anthropic_tools($tools) {
        $out = array();
        foreach ($tools as $t) {
            $out[] = array(
                'name'         => $t['name'],
                'description'  => self::clip(self::model_description($t)),
                'input_schema' => $t['parameters'],
            );
        }
        return $out;
    }

    /** Google Gemini (functionDeclarations under a single tools entry). */
    public static function to_gemini_tools($tools) {
        $decls = array();
        foreach ($tools as $t) {
            $decls[] = array(
                'name'        => $t['name'],
                'description' => self::clip(self::model_description($t)),
                'parameters'  => $t['parameters'],
            );
        }
        return $decls ? array(array('functionDeclarations' => $decls)) : array();
    }

    private static function clip($s, $max = 1024) {
        $s = (string) $s;
        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }

    /**
     * The description actually sent to the model (plan 27d3e6): the fixed
     * dev-authored base description PLUS the site owner's optional
     * "when to use" hint. The hint STEERS the model's decision to call the
     * tool; the base description is additive-only and never replaced. When the
     * hint is empty the description is unchanged, so existing installs send the
     * exact same schema as before.
     */
    private static function model_description($t) {
        $base = isset($t['description']) ? (string) $t['description'] : '';
        $hint = isset($t['usage_hint']) ? trim((string) $t['usage_hint']) : '';
        if ($hint === '') {
            return $base;
        }
        return ($base !== '' ? $base . "\n\n" : '') . 'When to use: ' . $hint;
    }

    /* ------------------------------------------------------------------ *
     *  AJAX autosave for the AI Tools section
     * ------------------------------------------------------------------ */

    /**
     * Persist the master toggle + the per-tool settings map. $all is every tool
     * shown; $checked is the subset that is on; $hints is [ fn => string ] of
     * the owner's "when to use" notes. We store an explicit object entry for
     * EVERY shown tool so unchecking one persists as enabled:false (not
     * "absent → default") and so the hint survives an enable/disable toggle.
     * Returns the stored map.
     */
    private static function persist($enabled, $all, $checked, $hints = array()) {
        update_option(self::OPT_ENABLED, $enabled ? 1 : 0);
        $checked_lookup = array_fill_keys($checked, true);
        $map = array();
        foreach ($all as $fn) {
            $map[$fn] = array(
                'enabled'    => isset($checked_lookup[$fn]),
                'usage_hint' => isset($hints[$fn]) ? self::clip_hint($hints[$fn]) : '',
            );
        }
        update_option(self::OPT_TOOLS, $map);
        return $map;
    }

    /** Count tools that are effectively enabled across both stored shapes. */
    private static function count_enabled($map) {
        $n = 0;
        foreach ($map as $v) {
            if (is_array($v) ? !empty($v['enabled']) : (bool) $v) {
                $n++;
            }
        }
        return $n;
    }

    /** AJAX: autosave on every toggle/checkbox change in the AI Tools section. */
    public static function handle_ajax_save() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }
        check_ajax_referer('mxchat_fc_autosave', 'nonce');

        // Presence = active (plan d450a7): the global on/off toggle is gone, so
        // the gate is derived server-side from whether any tool is checked — not
        // a client-sent flag. Empty list = off (safe default).
        $all = isset($_POST['all_tools']) && is_array($_POST['all_tools'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['all_tools']))
            : array();
        $checked = isset($_POST['tools']) && is_array($_POST['tools'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['tools']))
            : array();
        // Per-tool "when to use" hints: [ callback_fn => free text ]. Keyed by
        // callback, so sanitize the key as a text field and the value as a
        // textarea (preserves newlines, strips tags).
        $hints = array();
        if (isset($_POST['hints']) && is_array($_POST['hints'])) {
            foreach (wp_unslash($_POST['hints']) as $fn => $hint) {
                $hints[sanitize_text_field($fn)] = sanitize_textarea_field($hint);
            }
        }

        $enabled = !empty($checked);
        $map = self::persist($enabled, $all, $checked, $hints);
        wp_send_json_success(array('enabled' => $enabled, 'enabled_count' => self::count_enabled($map)));
    }
}
