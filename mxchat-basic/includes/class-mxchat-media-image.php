<?php
/**
 * Generate an image with the site's image model and save it to the Media
 * Library (plan d9da28).
 *
 * The public entry point is the function mxchat_generate_media_image() at the
 * bottom of this file, for add-ons (mxchat-mcp's mxchat.media.generate_image
 * tool first) and site code. It reuses the Content screen's image engines
 * (MxChat_Content_Generator::generate_image_to_library()), so the model
 * chosen under MxChat → Content → Image Model, the provider keys and the
 * retired-model handling are the same ones the content generator uses.
 *
 * @package MxChat
 * @since 3.2.26
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Media_Image {

    /** Aspect ratios a caller may ask for. */
    const ASPECTS = array('1:1', '16:9', '9:16', '4:3', '3:4');

    /** Size hints a caller may ask for. */
    const SIZE_HINTS = array('standard', 'large');

    /** Longest prompt accepted, in characters. */
    const PROMPT_MAX = 4000;

    /**
     * OpenAI ids that only accept the three fixed GPT Image 1 sizes. Every
     * other gpt-image id takes any width x height divisible by 16 (probed
     * 2026-10-16 on gpt-image-2, -2.5-flare and -2.5-sunburst).
     */
    const OPENAI_FIXED_SIZE = array('gpt-image-1', 'gpt-image-1.5', 'gpt-image-1-mini', 'chatgpt-image-latest');

    /** Gemini image models that accept imageSize 2K (Google's image generation guide). */
    const GEMINI_2K = array('gemini-nano-banana-2.1', 'gemini-3.1-flash-image', 'gemini-3-pro-image');

    /**
     * Generate one image and save it as an attachment. See
     * mxchat_generate_media_image() for the arguments.
     *
     * @param array $args
     * @return int|WP_Error Attachment id.
     */
    public static function generate(array $args) {
        if (!current_user_can('upload_files')) {
            return new WP_Error('mxchat_image_forbidden', __('You are not allowed to upload files to the Media Library.', 'mxchat'), array('status' => 403));
        }
        if (!class_exists('MxChat_Content_Generator') || !method_exists('MxChat_Content_Generator', 'generate_image_to_library')) {
            return new WP_Error('mxchat_image_unavailable', __('Image generation is not available in this version of MxChat.', 'mxchat'));
        }

        $prompt = isset($args['prompt']) && is_scalar($args['prompt']) ? trim((string) $args['prompt']) : '';
        if ($prompt === '') {
            return new WP_Error('mxchat_image_prompt', __('A prompt describing the image is required.', 'mxchat'), array('status' => 400));
        }
        if (mb_strlen($prompt) > self::PROMPT_MAX) {
            /* translators: %d: maximum number of characters */
            return new WP_Error('mxchat_image_prompt', sprintf(__('The prompt is longer than %d characters.', 'mxchat'), self::PROMPT_MAX), array('status' => 400));
        }

        $aspect = isset($args['aspect']) && is_scalar($args['aspect']) && (string) $args['aspect'] !== '' ? trim((string) $args['aspect']) : '1:1';
        if (!in_array($aspect, self::ASPECTS, true)) {
            /* translators: 1: requested aspect ratio, 2: supported aspect ratios */
            return new WP_Error('mxchat_image_aspect', sprintf(__('Aspect ratio %1$s is not supported. Use one of: %2$s.', 'mxchat'), $aspect, implode(', ', self::ASPECTS)), array('status' => 400));
        }
        $size_hint = isset($args['size_hint']) && is_scalar($args['size_hint']) && (string) $args['size_hint'] !== '' ? strtolower(trim((string) $args['size_hint'])) : 'standard';
        if (!in_array($size_hint, self::SIZE_HINTS, true)) {
            /* translators: %s: requested size */
            return new WP_Error('mxchat_image_size', sprintf(__('Size %s is not supported. Use standard or large.', 'mxchat'), $size_hint), array('status' => 400));
        }

        $parent = isset($args['attach_to_post']) ? (int) $args['attach_to_post'] : 0;
        if ($parent < 0) {
            $parent = 0;
        }
        if ($parent > 0) {
            $post = get_post($parent);
            if (!$post || $post->post_type === 'attachment') {
                /* translators: %d: post id */
                return new WP_Error('mxchat_image_parent', sprintf(__('There is no post with id %d to attach the image to.', 'mxchat'), $parent), array('status' => 404));
            }
            if (!current_user_can('edit_post', $parent)) {
                return new WP_Error('mxchat_image_parent', __('You are not allowed to edit the post the image would be attached to.', 'mxchat'), array('status' => 403));
            }
        }

        $options = get_option('mxchat_options', array());
        if (!is_array($options)) {
            $options = array();
        }
        $model = self::resolve_model(isset($args['model']) && is_scalar($args['model']) ? trim((string) $args['model']) : '', $options);
        if (is_wp_error($model)) {
            return $model;
        }

        $title = isset($args['title']) && is_scalar($args['title']) ? sanitize_text_field((string) $args['title']) : '';
        if ($title === '') {
            $title = sanitize_text_field(wp_html_excerpt($prompt, 80, '...'));
        }
        $alt = isset($args['alt']) && is_scalar($args['alt']) ? sanitize_text_field((string) $args['alt']) : '';
        if ($alt === '') {
            $alt = $title;
        }

        // Image engines can take a minute or two; only ever raise the limit.
        if (function_exists('set_time_limit') && (int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < 180) {
            @set_time_limit(180);
        }

        // _mxchat_generated holds the model id on an attachment (on a post it is
        // '1', which is what the generated-page code checks; nothing reads the
        // attachment value as a flag). meta_input is written by wp_insert_post()
        // inside media_handle_sideload(), before wp_generate_attachment_metadata().
        $save = array(
            'parent'    => $parent,
            'prefix'    => 'mxchat-image',
            'post_data' => array(
                'post_title' => $title,
                'meta_input' => array(
                    '_mxchat_generated'        => $model['sent'],
                    '_wp_attachment_image_alt' => $alt,
                    '_mxchat_image_prompt'     => $prompt,
                ),
            ),
        );
        $result = MxChat_Content_Generator::generate_image_to_library(
            $prompt,
            $model['model'],
            $options,
            self::shape_for($model['sent'], $model['provider'], $aspect, $size_hint),
            $save
        );
        if (is_wp_error($result)) {
            return $result;
        }
        if (!is_array($result) || empty($result['attachment_id'])) {
            return new WP_Error('mxchat_image_failed', __('The image could not be saved to the Media Library.', 'mxchat'));
        }
        return (int) $result['attachment_id'];
    }

    /**
     * The model to use: $requested when it is an image model this site offers
     * (a current model from the catalog, or the site's own saved image model)
     * and its provider key is set; the site's Content screen image model when
     * $requested is empty. A discontinued id resolves to its replacement
     * through the catalog's image lifecycle, so a retired id is never sent.
     *
     * @return array|WP_Error array('model' => id handed to the engine, 'sent' => id the provider receives, 'provider' => slug)
     */
    public static function resolve_model($requested, array $options) {
        $saved   = isset($options['content_image_model']) && (string) $options['content_image_model'] !== '' ? (string) $options['content_image_model'] : 'gpt-image-2.5-flare';
        $catalog = MxChat_Model_Catalog::image_generation_models();

        if ($requested !== '' && $requested !== $saved) {
            $offered = false;
            foreach ($catalog as $group) {
                if (isset($group['models'][$requested])) {
                    $offered = true;
                    break;
                }
            }
            if (!$offered) {
                $rows = MxChat_Model_Catalog::image_model_lifecycle();
                if (isset($rows[$requested])) {
                    /* translators: 1: model id, 2: replacement model id */
                    return new WP_Error('mxchat_image_model', sprintf(__('%1$s is retired or retiring and is no longer offered. Use %2$s instead.', 'mxchat'), $requested, $rows[$requested]['to']), array('status' => 400));
                }
                $usable = self::usable_models($options);
                /* translators: 1: model id, 2: comma-separated model ids */
                return new WP_Error('mxchat_image_model', sprintf(__('%1$s is not an image model this site offers. Use one of: %2$s.', 'mxchat'), $requested, $usable ? implode(', ', $usable) : __('none (no image provider key is set)', 'mxchat')), array('status' => 400));
            }
        }

        $model    = MxChat_Model_Catalog::resolve_retired_image($requested !== '' ? $requested : $saved);
        $provider = MxChat_Model_Catalog::image_model_provider($model);
        if ($provider === '' || !isset($catalog[$provider])) {
            /* translators: %s: model id */
            return new WP_Error('mxchat_image_model', sprintf(__('%s is not an image generation model.', 'mxchat'), $model), array('status' => 400));
        }
        $key = $catalog[$provider]['key'];
        if (empty($options[$key])) {
            /* translators: 1: model id, 2: provider name */
            return new WP_Error('mxchat_image_no_key', sprintf(__('%1$s needs your %2$s API key, which is not set under MxChat → Settings.', 'mxchat'), $model, $catalog[$provider]['label']), array('status' => 400));
        }

        if ($provider === 'openai') {
            $sent = MxChat_Content_Generator::openai_image_model($model);
        } elseif ($provider === 'gemini') {
            $sent = MxChat_Content_Generator::gemini_image_model($model);
        } else {
            $sent = $model;
        }
        return array('model' => $model, 'sent' => (string) $sent, 'provider' => $provider);
    }

    /** Current image model ids whose provider key is set on this site, plus the saved one. */
    public static function usable_models(array $options) {
        $out = array();
        foreach (MxChat_Model_Catalog::image_generation_models() as $group) {
            if (!empty($options[$group['key']])) {
                foreach ($group['models'] as $id => $label) {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }

    /**
     * The request shape for an aspect + size hint on the model the provider
     * receives: OpenAI a width x height, xAI its aspect_ratio + resolution,
     * Gemini its aspectRatio (+ imageSize 2K where the model has it).
     */
    public static function shape_for($sent, $provider, $aspect, $size_hint) {
        $large = ($size_hint === 'large');
        if ($provider === 'openai') {
            if (in_array($sent, self::OPENAI_FIXED_SIZE, true)) {
                $fixed = array('1:1' => '1024x1024', '16:9' => '1536x1024', '4:3' => '1536x1024', '9:16' => '1024x1536', '3:4' => '1024x1536');
                return array('size' => $fixed[$aspect]);
            }
            $standard = array('1:1' => '1024x1024', '16:9' => '1536x864', '9:16' => '864x1536', '4:3' => '1152x864', '3:4' => '864x1152');
            $big      = array('1:1' => '2048x2048', '16:9' => '2048x1152', '9:16' => '1152x2048', '4:3' => '2048x1536', '3:4' => '1536x2048');
            return array('size' => $large ? $big[$aspect] : $standard[$aspect]);
        }
        if ($provider === 'xai') {
            return array('aspect' => $aspect, 'resolution' => $large ? '2k' : '1k');
        }
        $shape = array('aspect' => $aspect);
        if ($large && in_array($sent, self::GEMINI_2K, true)) {
            $shape['image_size'] = '2K';
        }
        return $shape;
    }
}

if (!function_exists('mxchat_generate_media_image')) {
    /**
     * Generate an image with the site's configured image model and save it to
     * the Media Library (MxChat 3.2.26+, plan d9da28).
     *
     * Runs as the current user, who needs the upload_files capability (and
     * edit_post on attach_to_post). Each call is billed by the image
     * provider. The attachment carries _wp_attachment_image_alt, the prompt
     * in _mxchat_image_prompt, and _mxchat_generated = the model id the
     * provider received, all stored before wp_generate_attachment_metadata()
     * runs.
     *
     * @param array $args {
     *     @type string $prompt         Required. What the image should show.
     *     @type string $aspect         1:1 (default), 16:9, 9:16, 4:3 or 3:4, mapped to the
     *                                  nearest size the model supports.
     *     @type string $size_hint      standard (default, about 1 megapixel) or large (about
     *                                  2K where the model supports it).
     *     @type string $model          Optional image model id: a current model listed under
     *                                  MxChat → Content → Image Model, or the site's saved one,
     *                                  whose provider key is set. Default: the site's image model.
     *     @type string $title          Attachment title. Default: the start of the prompt.
     *     @type string $alt            Alt text. Default: the title.
     *     @type int    $attach_to_post Optional post id to attach the image to.
     * }
     * @return int|WP_Error Attachment id, or WP_Error saying what was wrong.
     */
    function mxchat_generate_media_image(array $args) {
        return MxChat_Media_Image::generate($args);
    }
}
