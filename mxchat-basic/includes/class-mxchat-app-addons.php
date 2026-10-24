<?php
/**
 * MxChat add-ons as the app sees them: which are installed, active and licensed, the features that turns on
 * in the app, and the settings section each one contributes to the app's Settings.
 *
 * The list comes from core's add-on registry (MxChat_Addons, the Add-ons screen), so a new add-on listed
 * there shows up in the app without an app update. Settings sections for add-ons live in add_on_sections();
 * an add-on can also add or replace its own section through `mxchat_app_settings_sections`.
 *
 * @package MxChat
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_App_Addons {

    /** Registry slug → the short feature key the app checks. */
    const FEATURES = array(
        'mxchat-admin-assistant'  => 'assistant',
        'mxchat-mcp'              => 'site_tools',
        'mxchat-forms'            => 'forms',
        'mxchat-multi-bot'        => 'multi_bot',
        'mxchat-moderation'       => 'moderation',
        'mxchat-woo'              => 'woocommerce',
        'mxchat-perplexity'       => 'web_search',
        'mxchat-ai-search'        => 'ai_search',
        'mxchat-image-analysis'   => 'vision',
        'mxchat-theme'            => 'themes',
        'mxchat-trigger'          => 'triggers',
        'mxchat-veo'              => 'video',
        'mxchat-anywhere'         => 'embed',
        'mxchat-advanced-content' => 'advanced_content',
        'mxchat-migration-tool'   => 'migration',
    );

    private static function registry() {
        static $config = null;
        if ($config === null) {
            // The registry is only loaded on wp-admin screens; the app's REST requests load it here.
            if (!class_exists('MxChat_Addons') && file_exists(__DIR__ . '/class-mxchat-addons.php')) {
                require_once __DIR__ . '/class-mxchat-addons.php';
            }
            $config = (class_exists('MxChat_Addons') && method_exists('MxChat_Addons', 'get_addons_config'))
                ? (array) (new MxChat_Addons())->get_addons_config()
                : array();
        }
        return $config;
    }

    /** Every registry add-on with its state on this site. */
    public static function all() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = get_plugins();
        $pro       = get_option('mxchat_license_status', 'inactive') === 'active';
        $sections  = wp_list_pluck(self::add_on_sections(), 'id', 'source');
        $out       = array();
        foreach (self::registry() as $slug => $addon) {
            $file   = (string) ($addon['plugin_file'] ?? '');
            $is_in  = $file !== '' && isset($installed[$file]);
            $active = $is_in && is_plugin_active($file);
            $out[]  = array(
                'slug'        => (string) $slug,
                'name'        => (string) ($addon['title'] ?? $slug),
                'description' => wp_html_excerpt((string) ($addon['description'] ?? ''), 160, '…'),
                'installed'   => $is_in,
                'active'      => $active,
                'version'     => $is_in ? (string) ($installed[$file]['Version'] ?? '') : '',
                'licensed'    => $pro,
                'feature'     => self::FEATURES[$slug] ?? null,
                'section'     => $sections[$slug] ?? null,
                'admin_url'   => !empty($addon['config_page']) ? admin_url('admin.php?page=' . $addon['config_page']) : '',
                'url'         => (string) ($addon['url'] ?? ''),
            );
        }
        return $out;
    }

    /** Feature key → true when its add-on is active and licensed (add-ons only load with Pro). */
    public static function features() {
        $flags = array();
        foreach (self::all() as $addon) {
            if (!empty($addon['feature'])) {
                $flags[$addon['feature']] = $addon['active'] && $addon['licensed'];
            }
        }
        return $flags;
    }

    public static function active($slug) {
        foreach (self::all() as $addon) {
            if ($addon['slug'] === $slug) {
                return $addon['active'] && $addon['licensed'];
            }
        }
        return false;
    }

    /** Settings sections for the add-ons that are active (MxChat_App_Settings::sections() adds these). */
    public static function sections() {
        $out = array();
        foreach (self::add_on_sections() as $section) {
            if (self::active($section['source'])) {
                $out[] = $section;
            }
        }
        return $out;
    }

    /**
     * A save callable that writes one value straight into an add-on's option, the way its own settings
     * screen stores it. `$map` turns the app's on/off into what that add-on keeps ('1'/'', '1'/'0', ...).
     * Array options keep their other keys. Used for add-ons whose screens save through options.php or a
     * whole-form post, where running the screen's handler would reset the fields the app did not send.
     */
    private static function store($option, $key = null, array $map = array()) {
        return static function ($value) use ($option, $key, $map) {
            $stored = array_key_exists($value, $map) ? $map[$value] : $value;
            if ($key === null) {
                update_option($option, $stored);
                return $stored;
            }
            $current         = get_option($option, array());
            $current         = is_array($current) ? $current : array();
            $current[$key]   = $stored;
            update_option($option, $current);
            return $stored;
        };
    }

    private static function f($slug, $name, $label, $type, array $extra = array()) {
        return array_merge(array('id' => 'addon:' . $slug . ':' . $name, 'name' => $name, 'label' => $label, 'type' => $type), $extra);
    }

    private static function choices(array $pairs) {
        $out = array();
        foreach ($pairs as $value => $label) {
            $out[] = array('value' => (string) $value, 'label' => $label);
        }
        return $out;
    }

    /** Every add-on section this core knows how to describe, whether or not its add-on is active. */
    private static function add_on_sections() {
        $on1   = array('on' => '1', 'off' => '');
        $on10  = array('on' => '1', 'off' => '0');
        $onoff = array('on' => 'on', 'off' => 'off');

        $woo_stats = static function () {
            if (!class_exists('MxChat_Woo_Attribution_Handler') || !method_exists('MxChat_Woo_Attribution_Handler', 'get_stats')) {
                return '';
            }
            $s = MxChat_Woo_Attribution_Handler::get_stats(30);
            if (!is_array($s)) {
                return '';
            }
            /* translators: 1: orders, 2: revenue */
            return sprintf(__('%1$d orders, %2$s revenue from chats in the last 30 days', 'mxchat'), (int) ($s['orders'] ?? 0), html_entity_decode(wp_strip_all_tags(function_exists('wc_price') ? wc_price((float) ($s['revenue'] ?? 0)) : (string) ($s['revenue'] ?? 0))));
        };

        return array(
            array(
                'id' => 'addon-woo', 'source' => 'mxchat-woo', 'title' => __('WooCommerce', 'mxchat'), 'icon' => 'cart',
                'summary' => __('Product cards, prices, add to cart and chat sales', 'mxchat'),
                'groups' => array(
                    array('title' => __('Sales from chat', 'mxchat'), 'fields' => array(
                        array('id' => 'addon:mxchat-woo:stats', 'label' => __('Last 30 days', 'mxchat'), 'type' => 'info', 'read' => $woo_stats),
                        self::f('mxchat-woo', 'mxchat_woo_attribution_settings[enabled]', __('Track orders that came from a chat', 'mxchat'), 'toggle', array('save' => self::store('mxchat_woo_attribution_settings', 'enabled', $onoff), 'default' => 'on')),
                    )),
                    array('title' => __('In the chat', 'mxchat'), 'fields' => array(
                        array('id' => 'addon:mxchat-woo:integration', 'name' => 'mxchat_options[enable_woocommerce_integration]', 'label' => __('Let the chatbot use your store', 'mxchat'), 'type' => 'toggle'),
                        self::f('mxchat-woo', 'mxchat_woo_options[auto_product_display]', __('Show product cards', 'mxchat'), 'select', array('options' => self::choices(array('off' => __('Only when asked', 'mxchat'), 'all_sources' => __('For every product found', 'mxchat'), 'ai_mentioned' => __('For products the AI mentions', 'mxchat'))), 'save' => self::store('mxchat_woo_options', 'auto_product_display'), 'default' => 'off')),
                        self::f('mxchat-woo', 'mxchat_woo_options[show_product_prices]', __('Show prices', 'mxchat'), 'toggle', array('save' => self::store('mxchat_woo_options', 'show_product_prices', $onoff), 'default' => 'on')),
                        self::f('mxchat-woo', 'mxchat_woo_options[show_add_to_cart_button]', __('Add to cart button', 'mxchat'), 'toggle', array('save' => self::store('mxchat_woo_options', 'show_add_to_cart_button', $onoff), 'default' => 'on')),
                        self::f('mxchat-woo', 'mxchat_woo_options[show_variation_selectors]', __('Variation pickers', 'mxchat'), 'toggle', array('save' => self::store('mxchat_woo_options', 'show_variation_selectors', $onoff), 'default' => 'on')),
                        self::f('mxchat-woo', 'mxchat_woo_options[add_to_cart_button_text]', __('Add to cart button text', 'mxchat'), 'text', array('save' => self::store('mxchat_woo_options', 'add_to_cart_button_text'), 'default' => __('Add to Cart', 'mxchat'))),
                    )),
                    array('title' => __('Filtered product search', 'mxchat'), 'fields' => array(
                        self::f('mxchat-woo', 'mxchat_woo_filtered_search_settings[enabled]', __('Search by price, category and attributes', 'mxchat'), 'toggle', array('save' => self::store('mxchat_woo_filtered_search_settings', 'enabled', $onoff))),
                        self::f('mxchat-woo', 'mxchat_woo_filtered_search_settings[results_limit]', __('Products per answer', 'mxchat'), 'number', array('min' => 1, 'max' => 20, 'default' => 6, 'save' => self::store('mxchat_woo_filtered_search_settings', 'results_limit'))),
                    )),
                ),
            ),
            array(
                'id' => 'addon-search', 'source' => 'mxchat-ai-search', 'title' => __('AI Search', 'mxchat'), 'icon' => 'search',
                'summary' => __('An AI answer on your site\'s search results', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-ai-search', 'mxchat_search_settings[enabled]', __('Show an AI answer on search results', 'mxchat'), 'toggle', array('save' => self::store('mxchat_search_settings', 'enabled', $on1))),
                    self::f('mxchat-ai-search', 'mxchat_search_settings[display_mode]', __('Where it appears', 'mxchat'), 'select', array('options' => self::choices(array('overlay' => __('Above the results', 'mxchat'), 'replace' => __('Instead of the results', 'mxchat'))), 'default' => 'overlay', 'save' => self::store('mxchat_search_settings', 'display_mode'))),
                    self::f('mxchat-ai-search', 'mxchat_search_settings[confidence_threshold]', __('Match threshold', 'mxchat'), 'number', array('min' => 20, 'max' => 85, 'suffix' => '%', 'default' => 35, 'save' => self::store('mxchat_search_settings', 'confidence_threshold'))),
                    self::f('mxchat-ai-search', 'mxchat_search_settings[show_sources]', __('Show sources', 'mxchat'), 'toggle', array('default' => '1', 'save' => self::store('mxchat_search_settings', 'show_sources', $on1))),
                    self::f('mxchat-ai-search', 'mxchat_search_settings[sources_count]', __('Number of sources', 'mxchat'), 'number', array('min' => 1, 'max' => 10, 'default' => 4, 'save' => self::store('mxchat_search_settings', 'sources_count'))),
                ))),
            ),
            array(
                'id' => 'addon-perplexity', 'source' => 'mxchat-perplexity', 'title' => __('Perplexity web search', 'mxchat'), 'icon' => 'globe',
                'summary' => __('Up-to-date answers from the web', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-perplexity', 'mxchat_perplexity_model', __('Model', 'mxchat'), 'select', array('options' => self::choices(array('sonar' => 'Sonar', 'sonar-pro' => 'Sonar Pro', 'sonar-reasoning-pro' => 'Sonar Reasoning Pro', 'sonar-deep-research' => 'Sonar Deep Research')), 'default' => 'sonar', 'save' => self::store('mxchat_perplexity_model'))),
                    self::f('mxchat-perplexity', 'mxchat_perplexity_response_mode', __('Answer style', 'mxchat'), 'select', array('options' => self::choices(array('conversational' => __('Conversational', 'mxchat'), 'structured' => __('Structured', 'mxchat'))), 'default' => 'conversational', 'save' => self::store('mxchat_perplexity_response_mode'))),
                    self::f('mxchat-perplexity', 'mxchat_perplexity_show_logo', __('Show the Perplexity logo', 'mxchat'), 'toggle', array('default' => '1', 'save' => self::store('mxchat_perplexity_show_logo', null, $on1))),
                    self::f('mxchat-perplexity', 'mxchat_perplexity_api_key', __('Perplexity API key', 'mxchat'), 'secret', array('save' => self::store('mxchat_perplexity_api_key'))),
                ))),
            ),
            array(
                'id' => 'addon-vision', 'source' => 'mxchat-image-analysis', 'title' => __('Image analysis', 'mxchat'), 'icon' => 'image',
                'summary' => __('Let visitors send photos to the chatbot', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-image-analysis', 'mxchat_vision_enabled', __('Accept images in the chat', 'mxchat'), 'toggle', array('save' => self::store('mxchat_vision_enabled', null, $on1))),
                    self::f('mxchat-image-analysis', 'mxchat_vision_mode', __('What it does with them', 'mxchat'), 'select', array('options' => self::choices(array('analysis' => __('Describe and answer', 'mxchat'), 'editing' => __('Edit the image', 'mxchat'))), 'default' => 'analysis', 'save' => self::store('mxchat_vision_mode'))),
                    self::f('mxchat-image-analysis', 'mxchat_vision_model', __('Vision model', 'mxchat'), 'select', array('options' => self::choices(array('openai' => 'OpenAI', 'grok' => 'xAI Grok', 'google_imagen' => 'Google', 'google_imagen_pro' => 'Google Pro')), 'default' => 'openai', 'save' => self::store('mxchat_vision_model'))),
                    self::f('mxchat-image-analysis', 'mxchat_vision_max_images', __('Images per message', 'mxchat'), 'number', array('min' => 1, 'max' => 10, 'default' => 5, 'save' => self::store('mxchat_vision_max_images'))),
                    self::f('mxchat-image-analysis', 'mxchat_vision_max_file_size', __('Largest image', 'mxchat'), 'number', array('min' => 1, 'max' => 20, 'suffix' => 'MB', 'default' => 10, 'save' => self::store('mxchat_vision_max_file_size'))),
                ))),
            ),
            array(
                'id' => 'addon-embed', 'source' => 'mxchat-anywhere', 'title' => __('MxChat Anywhere', 'mxchat'), 'icon' => 'globe',
                'summary' => __('Your chatbot on sites that are not WordPress', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-anywhere', 'mxchat_embed_enabled', __('Allow the chatbot on other sites', 'mxchat'), 'toggle', array('save' => self::store('mxchat_embed_enabled', null, $onoff))),
                    self::f('mxchat-anywhere', 'mxchat_embed_allowed_domains', __('Allowed domains', 'mxchat'), 'textarea', array('help' => __('One per line. Empty allows any site with the embed code.', 'mxchat'), 'save' => self::store('mxchat_embed_allowed_domains'))),
                ))),
            ),
            array(
                'id' => 'addon-trigger', 'source' => 'mxchat-trigger', 'title' => __('Trigger', 'mxchat'), 'icon' => 'bolt',
                'summary' => __('Buttons and links on your pages that open the chat', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-trigger', 'mxchat_trigger_options[enabled]', __('Open the chat from page elements', 'mxchat'), 'toggle', array('default' => 'on', 'save' => self::store('mxchat_trigger_options', 'enabled', $onoff))),
                ))),
            ),
            array(
                'id' => 'addon-mcp', 'source' => 'mxchat-mcp', 'title' => __('MCP server', 'mxchat'), 'icon' => 'shield',
                'summary' => __('What Claude, ChatGPT and other AI apps may do on your site', 'mxchat'),
                'groups' => array(array('help' => __('These apply to AI apps you connect to the site. The app\'s Assistant always asks you before changing anything.', 'mxchat'), 'fields' => array(
                    self::f('mxchat-mcp', 'mxchat_mcp_role', __('Access', 'mxchat'), 'select', array('options' => self::choices(array('read-only' => __('Read only', 'mxchat'), 'admin' => __('Read and change', 'mxchat'))), 'default' => 'read-only', 'save' => self::store('mxchat_mcp_role'))),
                    self::f('mxchat-mcp', 'mxchat_mcp_content_tools_enabled', __('Posts, pages and media tools', 'mxchat'), 'toggle', array('save' => self::store('mxchat_mcp_content_tools_enabled', null, $on10))),
                    self::f('mxchat-mcp', 'mxchat_mcp_site_manage_enabled', __('Install and update plugins and themes', 'mxchat'), 'toggle', array('save' => self::store('mxchat_mcp_site_manage_enabled', null, $on10))),
                    self::f('mxchat-mcp', 'mxchat_mcp_editor_access', __('Let editors connect', 'mxchat'), 'toggle', array('save' => self::store('mxchat_mcp_editor_access', null, $on10))),
                ))),
            ),
            array(
                'id' => 'addon-admin-chat', 'source' => 'mxchat-admin-assistant', 'title' => __('Admin Assistant', 'mxchat'), 'icon' => 'spark',
                'summary' => __('The AI assistant for running your site', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-admin-assistant', 'mxchat_admin_chat_retention_days', __('Keep assistant chats for', 'mxchat'), 'number', array('min' => 1, 'max' => 3650, 'suffix' => __('days', 'mxchat'), 'default' => 90, 'save' => self::store('mxchat_admin_chat_retention_days'))),
                ))),
            ),
            array(
                'id' => 'addon-advanced-content', 'source' => 'mxchat-advanced-content', 'title' => __('Advanced Content', 'mxchat'), 'icon' => 'book',
                'summary' => __('Editor assistant and FAQ block', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-advanced-content', 'mxchat_adv_editor_assist[enabled]', __('AI help in the post editor', 'mxchat'), 'toggle', array('default' => '1', 'save' => self::store('mxchat_adv_editor_assist', 'enabled', $on10))),
                    self::f('mxchat-advanced-content', 'mxchat_adv_faq_block_enabled', __('FAQ block', 'mxchat'), 'toggle', array('save' => self::store('mxchat_adv_faq_block_enabled', null, $on10))),
                ))),
            ),
            array(
                'id' => 'addon-veo', 'source' => 'mxchat-veo', 'title' => __('Video studio', 'mxchat'), 'icon' => 'image',
                'summary' => __('Models for generated images and video', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-veo', 'mxchat_veo_studio_settings[image_model]', __('Image model', 'mxchat'), 'select', array('options' => self::choices(array('gemini-nano-banana-2.1' => 'Nano Banana 2.1', 'gemini-2.5-flash-image' => 'Gemini 2.5 Flash Image', 'gemini-3.1-flash-image' => 'Gemini 3.1 Flash Image', 'gemini-3-pro-image' => 'Gemini 3 Pro Image')), 'default' => 'gemini-nano-banana-2.1', 'save' => self::store('mxchat_veo_studio_settings', 'image_model'))),
                    self::f('mxchat-veo', 'mxchat_veo_studio_settings[video_model]', __('Video model', 'mxchat'), 'select', array('options' => self::choices(array('seedance-2.0' => 'Seedance 2.0', 'grok-imagine-video' => 'Grok Imagine Video')), 'default' => 'seedance-2.0', 'save' => self::store('mxchat_veo_studio_settings', 'video_model'))),
                ))),
            ),
            array(
                'id' => 'addon-forms', 'source' => 'mxchat-forms', 'title' => __('Forms', 'mxchat'), 'icon' => 'form',
                'summary' => __('Forms and wizards inside the chat', 'mxchat'),
                'groups' => array(array('fields' => array(
                    self::f('mxchat-forms', 'mxchat_forms_retention_days', __('Delete submissions after', 'mxchat'), 'number', array('min' => 0, 'max' => 3650, 'suffix' => __('days', 'mxchat'), 'default' => 0, 'help' => __('0 keeps them.', 'mxchat'), 'save' => self::store('mxchat_forms_retention_days'))),
                ))),
            ),
        );
    }
}
