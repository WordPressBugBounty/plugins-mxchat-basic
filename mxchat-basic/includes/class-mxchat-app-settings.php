<?php
/**
 * The settings the MxChat app shows, described by the site so the app only has to draw them.
 *
 * A schema is a list of sections (Chatbot, AI & answers, Provider keys, ...), each with groups of fields.
 * The app renders whatever arrives, so a new setting, or a whole add-on's section, needs no app update:
 * add-ons append their sections through the `mxchat_app_settings_sections` filter.
 *
 * Section: id, title, summary, icon (a name from the app's small icon set: chat, spark, key, person,
 *          headset, bolt, puzzle, book, palette, shield, cart, form, image, search, globe), source
 *          ('core' or an add-on slug), optional link (an app screen the section opens instead of fields:
 *          'actions'), and groups: [{title, help?, fields}].
 * Field:   id (unique; for core the admin autosave field name, e.g. mxchat_options[intro_message]), label,
 *          type (text, textarea, toggle, select, number, secret, info), help?, placeholder?, options?
 *          [{value,label,group?,available?}], min?/max?/suffix?.
 *          Server-only keys, never sent: `save` (callable($value) returning the stored value or WP_Error)
 *          and `read` (callable returning the current value). Without them the field is read from and
 *          saved through its autosave name, the same code path as the WordPress settings screen.
 *
 * @package MxChat
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_App_Settings {

    /** Every section, with server-only callables still attached. */
    public static function sections() {
        $sections = array_merge(self::core_sections(), MxChat_App_Addons::sections());
        $sections = apply_filters('mxchat_app_settings_sections', $sections);
        return is_array($sections) ? array_values($sections) : array();
    }

    /** What the app receives: values filled in, callables removed. */
    public static function schema() {
        $out = array();
        foreach (self::sections() as $section) {
            $groups = array();
            foreach ((array) ($section['groups'] ?? array()) as $group) {
                $fields = array();
                foreach ((array) ($group['fields'] ?? array()) as $field) {
                    $field['value'] = self::value($field);
                    unset($field['save'], $field['read'], $field['name']);
                    $fields[] = $field;
                }
                unset($group['fields']);
                $group['fields'] = $fields;
                $groups[]        = $group;
            }
            $section['groups'] = $groups;
            $out[]             = $section;
        }
        return $out;
    }

    /** One field by id, with its server-only keys, or null. */
    public static function field($id) {
        foreach (self::sections() as $section) {
            foreach ((array) ($section['groups'] ?? array()) as $group) {
                foreach ((array) ($group['fields'] ?? array()) as $field) {
                    if (($field['id'] ?? '') === $id) {
                        return $field;
                    }
                }
            }
        }
        return null;
    }

    /** "mxchat_options[intro_message]" → [mxchat_options, intro_message]; a standalone option → [name, null]. */
    public static function split_name($name) {
        if (preg_match('/^([A-Za-z0-9_]+)\[([A-Za-z0-9_]+)\]$/', (string) $name, $m)) {
            return array($m[1], $m[2]);
        }
        return array((string) $name, null);
    }

    /** The current value as the app shows it: toggles on/off, numbers as ints, secrets as {set, ending}. */
    public static function value(array $field) {
        if (isset($field['read']) && is_callable($field['read'])) {
            $raw = call_user_func($field['read']);
        } elseif (!empty($field['name'])) {
            list($option, $key) = self::split_name($field['name']);
            $stored = get_option($option, null);
            $raw    = $key === null ? $stored : (is_array($stored) && array_key_exists($key, $stored) ? $stored[$key] : null);
        } else {
            $raw = null;
        }
        if ($raw === null && array_key_exists('default', $field)) {
            $raw = $field['default'];
        }
        return self::display($field, $raw);
    }

    public static function display(array $field, $raw) {
        switch ($field['type'] ?? 'text') {
            case 'toggle':
                return in_array($raw, array('on', '1', 1, true, 'yes', 'true'), true) ? 'on' : 'off';
            case 'number':
                $n = is_numeric($raw) ? (float) $raw : (float) ($field['min'] ?? 0);
                if (!empty($field['percent_fraction']) && $n > 0 && $n < 1) {
                    $n = $n * 100; // stored as a fraction by an older save
                }
                return (int) round($n);
            case 'secret':
                $s = trim((string) (is_scalar($raw) ? $raw : ''));
                return array('set' => $s !== '', 'ending' => $s === '' ? '' : substr($s, -4));
            case 'info':
                return is_scalar($raw) ? (string) $raw : '';
            case 'select':
                $value   = is_scalar($raw) ? (string) $raw : '';
                $allowed = array_map('strval', wp_list_pluck((array) ($field['options'] ?? array()), 'value'));
                // A stored value that is not one of the choices (an old format, a hand edit) shows the way the
                // WordPress screen shows it: as the default.
                if ($allowed && !in_array($value, $allowed, true) && array_key_exists('default', $field)) {
                    return (string) $field['default'];
                }
                return $value;
            default:
                return is_scalar($raw) ? (string) $raw : '';
        }
    }

    /**
     * Clean a value by type before it is saved. The admin autosave stores most fields as given and leaves
     * cleaning to the options.php sanitizer, which is not attached outside wp-admin.
     */
    public static function clean(array $field, $value) {
        $value = is_scalar($value) ? (string) $value : '';
        switch ($field['type'] ?? 'text') {
            case 'toggle':
                return in_array($value, array('on', '1', 'true', 'yes'), true) ? 'on' : 'off';
            case 'number':
                $n = (int) $value;
                if (isset($field['min'])) { $n = max((int) $field['min'], $n); }
                if (isset($field['max'])) { $n = min((int) $field['max'], $n); }
                return (string) $n;
            case 'select':
                $allowed = wp_list_pluck((array) ($field['options'] ?? array()), 'value');
                return in_array($value, array_map('strval', $allowed), true) ? $value : new WP_Error('mxchat_app_bad_choice', __('That choice is not available.', 'mxchat'), array('status' => 400));
            case 'textarea':
                return !empty($field['html']) ? wp_kses_post($value) : sanitize_textarea_field($value);
            case 'secret':
            default:
                return sanitize_text_field($value);
        }
    }

    // ------------------------------------------------------------------
    // Core sections
    // ------------------------------------------------------------------

    private static function o($key, $label, $type, array $extra = array()) {
        return array_merge(array('id' => 'mxchat_options[' . $key . ']', 'name' => 'mxchat_options[' . $key . ']', 'label' => $label, 'type' => $type), $extra);
    }

    private static function core_sections() {
        $options = get_option('mxchat_options', array());
        $options = is_array($options) ? $options : array();
        $current = (string) ($options['model'] ?? '');

        $keys = array();
        foreach (array(
            'api_key'            => 'OpenAI',
            'claude_api_key'     => 'Anthropic (Claude)',
            'gemini_api_key'     => 'Google Gemini',
            'xai_api_key'        => 'xAI (Grok)',
            'deepseek_api_key'   => 'DeepSeek',
            'openrouter_api_key' => 'OpenRouter',
            'voyage_api_key'     => 'Voyage AI (embeddings)',
        ) as $key => $label) {
            $keys[] = self::o($key, $label, 'secret');
        }

        return array(
            array(
                'id' => 'chatbot', 'title' => __('Chatbot', 'mxchat'), 'icon' => 'chat', 'source' => 'core',
                'summary' => __('Name, welcome message and quick questions', 'mxchat'),
                'groups' => array(
                    array('title' => __('What visitors see', 'mxchat'), 'fields' => array(
                        self::o('top_bar_title', __('Chat title', 'mxchat'), 'text', array('help' => __('Shown at the top of the chat window.', 'mxchat'))),
                        self::o('intro_message', __('Welcome message', 'mxchat'), 'textarea', array('html' => true)),
                        self::o('input_copy', __('Message box placeholder', 'mxchat'), 'text'),
                        self::o('pre_chat_message', __('Pre-chat bubble', 'mxchat'), 'text', array('help' => __('Shown beside the chat button before it is opened.', 'mxchat'))),
                    )),
                    array('title' => __('Quick questions', 'mxchat'), 'help' => __('Buttons visitors can tap instead of typing.', 'mxchat'), 'fields' => array(
                        self::o('popular_question_1', __('Question 1', 'mxchat'), 'text'),
                        self::o('popular_question_2', __('Question 2', 'mxchat'), 'text'),
                        self::o('popular_question_3', __('Question 3', 'mxchat'), 'text'),
                    )),
                ),
            ),
            array(
                'id' => 'ai', 'title' => __('AI & answers', 'mxchat'), 'icon' => 'spark', 'source' => 'core',
                'summary' => __('Model, instructions and how it uses knowledge', 'mxchat'),
                'groups' => array(
                    array('title' => __('Model', 'mxchat'), 'fields' => array(
                        self::o('model', __('AI model', 'mxchat'), 'select', array('options' => self::model_options($current, $options))),
                    )),
                    array('title' => __('Instructions', 'mxchat'), 'fields' => array(
                        self::o('system_prompt_instructions', __('Instructions', 'mxchat'), 'textarea', array('help' => __('How the chatbot behaves and answers.', 'mxchat'))),
                    )),
                    array('title' => __('Answers', 'mxchat'), 'fields' => array(
                        self::o('enable_streaming_toggle', __('Stream replies as they are written', 'mxchat'), 'toggle'),
                        self::o('citation_links_toggle', __('Link to sources in answers', 'mxchat'), 'toggle'),
                        self::o('contextual_awareness_toggle', __('Read the page the visitor is on', 'mxchat'), 'toggle'),
                        self::o('chat_persistence_toggle', __('Keep the conversation across pages', 'mxchat'), 'toggle'),
                        self::o('similarity_threshold', __('Knowledge match threshold', 'mxchat'), 'number', array('min' => 20, 'max' => 85, 'suffix' => '%', 'percent_fraction' => true, 'help' => __('Lower finds more knowledge, higher only close matches.', 'mxchat'))),
                    )),
                ),
            ),
            array(
                'id' => 'keys', 'title' => __('Provider keys', 'mxchat'), 'icon' => 'key', 'source' => 'core',
                'summary' => __('Which AI keys are set. Keys never leave your site.', 'mxchat'),
                'groups' => array(
                    array('title' => __('AI providers', 'mxchat'), 'help' => __('Only the last 4 characters are ever shown. Paste a new key to replace one.', 'mxchat'), 'fields' => $keys),
                    array('title' => __('Other services', 'mxchat'), 'fields' => array(
                        self::o('brave_api_key', __('Brave Search', 'mxchat'), 'secret'),
                        self::o('loops_api_key', __('Loops (email)', 'mxchat'), 'secret'),
                    )),
                ),
            ),
            array(
                'id' => 'leads', 'title' => __('Leads & notifications', 'mxchat'), 'icon' => 'person', 'source' => 'core',
                'summary' => __('Email capture, ratings and new-chat emails', 'mxchat'),
                'groups' => array(
                    array('title' => __('Lead capture', 'mxchat'), 'fields' => array(
                        self::o('enable_email_block', __('Ask for an email before chatting', 'mxchat'), 'toggle'),
                        self::o('enable_name_field', __('Ask for a name too', 'mxchat'), 'toggle'),
                    )),
                    array('title' => __('Feedback', 'mxchat'), 'fields' => array(
                        self::o('satisfaction_rating_enabled', __('Ask visitors to rate the chat', 'mxchat'), 'toggle'),
                    )),
                    array('title' => __('Email me about new chats', 'mxchat'), 'fields' => array(
                        array('id' => 'mxchat_transcripts_options[mxchat_enable_notifications]', 'name' => 'mxchat_transcripts_options[mxchat_enable_notifications]', 'label' => __('Email on every new chat', 'mxchat'), 'type' => 'toggle'),
                        array('id' => 'mxchat_transcripts_options[mxchat_notification_email]', 'name' => 'mxchat_transcripts_options[mxchat_notification_email]', 'label' => __('Send to', 'mxchat'), 'type' => 'text', 'default' => get_option('admin_email'), 'help' => __('One address, or several separated by commas.', 'mxchat')),
                    )),
                ),
            ),
            array(
                'id' => 'live_agent', 'title' => __('Live agent', 'mxchat'), 'icon' => 'headset', 'source' => 'core',
                'summary' => __('Hand chats to a person on Slack, Telegram or a webhook', 'mxchat'),
                'groups' => array(
                    array('title' => __('Channels', 'mxchat'), 'help' => __('Connect each channel in WordPress first; these switch it on or off.', 'mxchat'), 'fields' => array(
                        self::o('live_agent_status', __('Slack', 'mxchat'), 'toggle'),
                        self::o('telegram_status', __('Telegram', 'mxchat'), 'toggle'),
                        self::o('webhook_handoff_status', __('Webhook', 'mxchat'), 'toggle'),
                    )),
                    array('title' => __('Messages', 'mxchat'), 'fields' => array(
                        self::o('live_agent_away_message', __('Away message', 'mxchat'), 'textarea'),
                    )),
                ),
            ),
            array(
                'id' => 'privacy', 'title' => __('Privacy & data', 'mxchat'), 'icon' => 'shield', 'source' => 'core',
                'summary' => __('How long chats and leads are kept', 'mxchat'),
                'groups' => array(
                    array('title' => __('Keep data for', 'mxchat'), 'fields' => array(
                        array('id' => 'mxchat_transcripts_options[mxchat_auto_delete_transcripts]', 'name' => 'mxchat_transcripts_options[mxchat_auto_delete_transcripts]', 'label' => __('Chats', 'mxchat'), 'type' => 'select', 'default' => 'never', 'options' => array(
                            array('value' => 'never', 'label' => __('Keep them', 'mxchat')),
                            array('value' => '1week', 'label' => __('1 week', 'mxchat')),
                            array('value' => '2weeks', 'label' => __('2 weeks', 'mxchat')),
                            array('value' => '1month', 'label' => __('1 month', 'mxchat')),
                        )),
                        array('id' => 'mxchat_lead_retention', 'name' => 'mxchat_lead_retention', 'label' => __('Leads', 'mxchat'), 'type' => 'select', 'default' => 'never', 'options' => array(
                            array('value' => 'never', 'label' => __('Keep them', 'mxchat')),
                            array('value' => 'same', 'label' => __('Same as chats', 'mxchat')),
                            array('value' => '30', 'label' => __('30 days', 'mxchat')),
                            array('value' => '90', 'label' => __('90 days', 'mxchat')),
                            array('value' => '180', 'label' => __('180 days', 'mxchat')),
                            array('value' => '365', 'label' => __('1 year', 'mxchat')),
                        )),
                    )),
                    array('title' => __('Transcript emails', 'mxchat'), 'fields' => array(
                        array('id' => 'mxchat_transcripts_options[mxchat_auto_email_transcript_enabled]', 'name' => 'mxchat_transcripts_options[mxchat_auto_email_transcript_enabled]', 'label' => __('Email me each finished chat', 'mxchat'), 'type' => 'toggle'),
                        array('id' => 'mxchat_transcripts_options[mxchat_auto_email_transcript_delay]', 'name' => 'mxchat_transcripts_options[mxchat_auto_email_transcript_delay]', 'label' => __('After the chat goes quiet for', 'mxchat'), 'type' => 'select', 'default' => '30', 'options' => array(
                            array('value' => '15', 'label' => __('15 minutes', 'mxchat')),
                            array('value' => '30', 'label' => __('30 minutes', 'mxchat')),
                            array('value' => '60', 'label' => __('1 hour', 'mxchat')),
                        )),
                    )),
                ),
            ),
            array(
                'id' => 'site', 'title' => __('Site & performance', 'mxchat'), 'icon' => 'puzzle', 'source' => 'core',
                'summary' => __('Video cards, editor assistant, loading and caching', 'mxchat'),
                'groups' => array(
                    array('fields' => array(
                        array('id' => 'mxchat_video_embed_enabled', 'name' => 'mxchat_video_embed_enabled', 'label' => __('Show YouTube videos in answers', 'mxchat'), 'type' => 'toggle', 'default' => 'on'),
                        array('id' => 'mxchat_editor_assistant_enabled', 'name' => 'mxchat_editor_assistant_enabled', 'label' => __('AI writing help in the post editor', 'mxchat'), 'type' => 'toggle', 'default' => 'off'),
                        array('id' => 'mxchat_smart_asset_loading', 'name' => 'mxchat_smart_asset_loading', 'label' => __('Load the chat only on pages that show it', 'mxchat'), 'type' => 'toggle', 'default' => 'off'),
                        array('id' => 'mxchat_cache_purge_enabled', 'name' => 'mxchat_cache_purge_enabled', 'label' => __('Clear the page cache after a settings change', 'mxchat'), 'type' => 'toggle', 'default' => 'on'),
                    )),
                ),
            ),
            array(
                'id' => 'actions', 'title' => __('Actions', 'mxchat'), 'icon' => 'bolt', 'source' => 'core', 'link' => 'actions',
                'summary' => __('Forms, handoffs, product cards: turn each on or off', 'mxchat'),
                'groups' => array(),
            ),
        );
    }

    /** Chat models from the catalog, flagged when their provider has no key on this site. */
    public static function model_options($current, array $options) {
        $out = array();
        if (!class_exists('MxChat_Model_Catalog')) {
            return $out;
        }
        foreach (MxChat_Model_Catalog::chat_models() as $provider_id => $provider) {
            if ($provider_id === 'openrouter') {
                continue; // picked from OpenRouter's live list in WordPress, not a fixed id
            }
            $key_option = (string) ($provider['key_option'] ?? '');
            $available  = $key_option === '' || trim((string) ($options[$key_option] ?? '')) !== '';
            foreach ((array) ($provider['models'] ?? array()) as $model_id => $model) {
                if (!empty($model['legacy']) && $model_id !== $current) {
                    continue;
                }
                $out[] = array(
                    'value'     => (string) $model_id,
                    'label'     => (string) ($model['label'] ?? $model_id),
                    'group'     => (string) ($provider['label'] ?? $provider_id),
                    'available' => $available,
                );
            }
        }
        if ($current === 'openrouter') {
            $out[] = array('value' => 'openrouter', 'label' => (string) ($options['openrouter_selected_model_name'] ?? 'OpenRouter'), 'group' => 'OpenRouter', 'available' => true);
        }
        return $out;
    }
}
