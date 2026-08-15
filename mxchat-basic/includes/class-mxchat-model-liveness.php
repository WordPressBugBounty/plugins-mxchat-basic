<?php
/**
 * MxChat Model Liveness — daily check that the configured chat / content
 * model still appears in its provider's public model listing.
 *
 * Plan: plan-mxchat-20260811-b65e8d. Claude Opus 4.1 retired 2026-08-05 and
 * sat selectable for five days — any site configured on it had a silently
 * dead chatbot until a human noticed. The hardcoded deprecation list +
 * migration guard only help AFTER a release ships; this closes the notice
 * gap itself by asking the provider directly, once a day.
 *
 * TWO STAGES, and the second one is why this works at all.
 *
 * The plan specified a listing-only comparison ("live listing beats a dated
 * field"). Probed against real providers on 2026-08-13, that premise does not
 * hold — a model listing is not authoritative about retirement in EITHER
 * direction:
 *   - Anthropic DROPS live aliases: claude-opus-4-5 is absent from /v1/models
 *     and answers HTTP 200 perfectly.
 *   - xAI's listing is partial: grok-4-0709 is absent and answers 200.
 *   - OpenAI KEEPS dead ids: gpt-5.3-chat-latest is listed and 404s
 *     "model_deprecated" on use.
 * A listing-only check would therefore have bannered every Anthropic-alias and
 * xAI install on the planet — the exact false alarm the plan forbids.
 *
 * So:
 *   STAGE 1 (free)  — is the id in the provider's listing? If yes, healthy;
 *                     stop. This is the common case and costs zero tokens.
 *   STAGE 2 (rare)  — if absent, that is a SUSPICION. Confirm with one 1-token
 *                     inference call and flag ONLY on an explicit
 *                     model-not-found. Anything else changes nothing.
 *
 * Known limitation, deliberately accepted: OpenAI's habit of keeping retired
 * ids listed means stage 1 short-circuits and this check will NOT catch that
 * shape. The reactive mxchat_model_access_notice (armed by the integrator when
 * a real request fails) covers it, one visitor later.
 *
 * Design constraints (from the plan, all preserved):
 *  - One listing request per provider per day, only for providers actually
 *    in use, skipped entirely when no key is stored.
 *  - Fail-open EVERYWHERE: HTTP error, timeout, non-200, unrecognized JSON,
 *    empty listing, a truncated listing, or an inconclusive probe — all keep
 *    the last known state. This feature must never produce a false "your bot
 *    is broken" banner on network noise.
 *  - Inform only. No auto-switching — changing the model stays a human
 *    action (the e46b8f migration guard owns known retirements at upgrade).
 *
 * Complementary to mxchat_model_access_notice (the REACTIVE one-shot error
 * notice the integrator arms when a live chat request already failed): this
 * check is PROACTIVE — it warns before a visitor ever hits the dead model.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Model_Liveness {

    const CRON_HOOK = 'mxchat_model_liveness_check';
    const OPTION    = 'mxchat_model_liveness';

    /** Providers with a supported public model-listing endpoint. */
    private static $checkable_providers = array('openai', 'claude', 'gemini', 'xai');

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_check'));

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }

        if (is_admin()) {
            add_action('admin_notices', array(__CLASS__, 'render_notice'));
        }
    }

    /* ---------------------------------------------------------------------
     * The daily check
     * ------------------------------------------------------------------ */

    public static function run_check() {
        $options = get_option('mxchat_options', array());
        if (!is_array($options)) {
            $options = array();
        }

        $configured = self::configured_models($options);

        $state   = get_option(self::OPTION, array());
        $missing = (is_array($state) && isset($state['missing']) && is_array($state['missing']))
            ? $state['missing']
            : array();

        // A flag for a model that's no longer configured is moot — drop it.
        foreach (array_keys($missing) as $flagged) {
            if (!in_array((string) $flagged, $configured, true)) {
                unset($missing[$flagged]);
            }
        }

        // Group by provider — one listing request per provider per run.
        $by_provider = array();
        foreach ($configured as $model) {
            $provider = self::provider_for_model($model);
            if ($provider === '') {
                continue; // no listing endpoint for this provider — skip
            }
            $by_provider[$provider][] = $model;
        }

        foreach ($by_provider as $provider => $models) {
            $key_option = MxChat_Model_Catalog::key_option_for_provider($provider);
            $api_key    = ($key_option !== '' && isset($options[$key_option]))
                ? trim((string) $options[$key_option])
                : '';
            if ($api_key === '') {
                continue; // no key stored → zero HTTP calls for this provider
            }

            $request = MxChat_Model_Catalog::models_listing_request($provider, $api_key);
            if ($request === null) {
                continue;
            }

            $response = wp_remote_get($request['url'], array(
                'timeout' => 15,
                'headers' => $request['headers'],
            ));
            if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
                continue; // fail open — keep last known state
            }

            $parsed = self::extract_ids($provider, wp_remote_retrieve_body($response));
            if ($parsed === null) {
                continue; // unrecognized shape / empty listing — fail open
            }

            foreach ($models as $model) {
                if (in_array($model, $parsed['ids'], true)) {
                    unset($missing[$model]); // listed → healthy, no probe needed
                    continue;
                }
                // Absent from a TRUNCATED listing is inconclusive — the model
                // could be on a page we didn't fetch. Keep the previous state.
                if (!empty($parsed['truncated'])) {
                    continue;
                }
                // STAGE 2. Absent from the listing is only a SUSPICION (see
                // inference_probe_request() for the live evidence). Confirm with
                // one 1-token call before saying anything to the user.
                $verdict = self::confirm_retired($provider, $api_key, $model);
                if ($verdict === true) {
                    if (!isset($missing[$model]) || !is_array($missing[$model])) {
                        $missing[$model] = array(
                            'provider'         => $provider,
                            'first_missing_at' => time(),
                        );
                    }
                    $missing[$model]['checked_at'] = time();
                } elseif ($verdict === false) {
                    unset($missing[$model]); // answered fine — unlisted alias
                }
                // null = inconclusive (transport error, rate limit, auth
                // problem): keep whatever state we already had.
            }
        }

        update_option(self::OPTION, array(
            'checked_at' => time(),
            'missing'    => $missing,
        ), false);
    }

    /**
     * Does this model still exist? One 1-token call; the answer text is
     * discarded. Deliberately asymmetric — the ONLY outcome that may raise a
     * user-facing warning is an explicit "this model does not exist".
     *
     * @return bool|null true  = provider says the model is gone (flag it)
     *                   false = the model answered (definitely alive)
     *                   null  = inconclusive; change nothing (fail open)
     */
    private static function confirm_retired($provider, $api_key, $model) {
        $probe = MxChat_Model_Catalog::inference_probe_request($provider, $api_key, $model);
        if ($probe === null) {
            return null;
        }

        $response = wp_remote_post($probe['url'], array(
            'timeout' => 20,
            'headers' => $probe['headers'],
            'body'    => wp_json_encode($probe['body']),
        ));
        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return false; // answered — alive, whatever the listing said
        }
        // A 404 is necessary but not sufficient: a wrong URL would also 404.
        // Require the provider's own not-found/deprecated vocabulary too.
        if ($code !== 404) {
            return null; // 401/403/429/5xx → tells us nothing about the model
        }

        $body = strtolower((string) wp_remote_retrieve_body($response));
        $markers = array(
            'model_not_found',
            'not_found_error',
            'model_deprecated',
            'does not exist',
            'is not found',
            'unknown model',
        );
        foreach ($markers as $marker) {
            if (strpos($body, $marker) !== false) {
                return true;
            }
        }

        return null; // 404 we don't recognize — stay quiet
    }

    /**
     * The chat model plus the content model when different, non-empty only.
     */
    private static function configured_models($options) {
        $configured = array();
        foreach (array('model', 'content_model') as $key) {
            $model = isset($options[$key]) ? trim((string) $options[$key]) : '';
            if ($model !== '') {
                $configured[$model] = true;
            }
        }
        return array_keys($configured);
    }

    /**
     * Which checkable provider owns this model id. Catalog first; ids the
     * catalog no longer lists (already-retired entries still saved on old
     * installs — exactly the ones this check exists for) fall back to the
     * provider family prefix. '' = not checkable (openrouter / deepseek /
     * custom / unknown) — skip.
     */
    private static function provider_for_model($model) {
        $provider = MxChat_Model_Catalog::provider_for_chat_model($model);

        if ($provider === '') {
            $prefix_map = array(
                'gpt-'    => 'openai',
                'o1-'     => 'openai',
                'o3-'     => 'openai',
                'o4-'     => 'openai',
                'claude-' => 'claude',
                'gemini-' => 'gemini',
                'grok-'   => 'xai',
            );
            foreach ($prefix_map as $prefix => $slug) {
                if (strpos($model, $prefix) === 0) {
                    $provider = $slug;
                    break;
                }
            }
        }

        return in_array($provider, self::$checkable_providers, true) ? $provider : '';
    }

    /**
     * Minimal per-provider parse: the flat list of listed model ids, plus
     * whether the listing was truncated (more pages exist). No schema
     * ambitions beyond "does this id appear anywhere".
     *
     * @return array|null array('ids' => string[], 'truncated' => bool),
     *                    or null when the body isn't a recognizable listing.
     */
    private static function extract_ids($provider, $body) {
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return null;
        }

        $ids = array();

        if ('gemini' === $provider) {
            if (!isset($json['models']) || !is_array($json['models'])) {
                return null;
            }
            foreach ($json['models'] as $entry) {
                if (!empty($entry['name']) && is_string($entry['name'])) {
                    // models.list names entries "models/<id>".
                    $ids[] = preg_replace('#^models/#', '', $entry['name']);
                }
            }
            $truncated = !empty($json['nextPageToken']);
        } else {
            // OpenAI / xAI / Anthropic all wrap the list in data[].id.
            if (!isset($json['data']) || !is_array($json['data'])) {
                return null;
            }
            foreach ($json['data'] as $entry) {
                if (!empty($entry['id']) && is_string($entry['id'])) {
                    $ids[] = $entry['id'];
                }
            }
            // Anthropic paginates with has_more; OpenAI / xAI never set it.
            $truncated = !empty($json['has_more']);
        }

        if (empty($ids)) {
            return null; // an "empty" provider listing is noise, not signal
        }

        return array('ids' => $ids, 'truncated' => $truncated);
    }

    /* ---------------------------------------------------------------------
     * The notice
     * ------------------------------------------------------------------ */

    /**
     * The models currently flagged AND still configured. Empty array = nothing
     * to say. Shared by both rendering surfaces.
     */
    private static function flagged_models() {
        $state = get_option(self::OPTION);
        if (!is_array($state) || empty($state['missing']) || !is_array($state['missing'])) {
            return array();
        }

        // Self-clearing: only surface models that are STILL configured now —
        // switching models hides the notice immediately, the next cron run
        // prunes the stored flag.
        $options = get_option('mxchat_options', array());
        if (!is_array($options)) {
            $options = array();
        }
        $configured = self::configured_models($options);

        $flagged = array();
        foreach ($state['missing'] as $model => $info) {
            if (in_array((string) $model, $configured, true)) {
                $flagged[(string) $model] = is_array($info) ? $info : array();
            }
        }
        return $flagged;
    }

    /**
     * Deep link to the Settings screen with the model picker already open.
     *
     * TRAP: link to mxchat-SETTINGS, not the mxchat-max parent slug. Hitting
     * ?page=mxchat-max wp_safe_redirect()s to ?page=mxchat-settings once
     * onboarding is dismissed, and the redirect DROPS every extra query arg —
     * so a picker deep link hung off mxchat-max silently arrives with no param
     * and the modal never opens (caught by the b65e8d browser rig).
     */
    private static function picker_url() {
        return admin_url('admin.php?page=mxchat-settings&mxchat_open_model_picker=1');
    }

    /** One sentence per flagged model, already escaped for output. */
    private static function sentence($model, $info) {
        $provider_label = MxChat_Model_Catalog::provider_label(isset($info['provider']) ? $info['provider'] : '');
        return sprintf(
            /* translators: 1: model id, 2: provider name */
            esc_html__('Your configured model %1$s no longer appears in the %2$s model list — it may be retired or retiring. Pick a current model before the chatbot stops responding.', 'mxchat'),
            '<code>' . esc_html($model) . '</code>',
            esc_html($provider_label !== '' ? $provider_label : __('provider', 'mxchat'))
        );
    }

    /**
     * Standard WP admin notice — for admin screens OUTSIDE MxChat's own pages.
     *
     * WHY THE SCOPING IS INVERTED from the obvious "only on our pages": every
     * MxChat admin screen renders the branded .mxch-admin-wrapper shell, which
     * paints over the whole #wpbody-content notice region. A core-style notice
     * there is present in the DOM, passes a marker grep, and is INVISIBLE to
     * the user — WP's own update-nag is buried the same way (proven by
     * screenshot during this build; the same failure class as the invisible
     * settings toggle). So MxChat pages get the in-shell renderer below, and
     * the core notice is left to do its job on Dashboard / Plugins / Updates,
     * where it renders normally and where an owner who never opens MxChat will
     * still see that their chatbot is about to stop answering.
     */
    public static function render_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'mxchat') === 0) {
            return; // branded shell covers it — handled by render_inline_notice()
        }

        $flagged = self::flagged_models();
        if (empty($flagged)) {
            return;
        }
        ?>
        <div class="notice notice-warning mxchat-model-liveness-notice">
            <p><strong><?php esc_html_e('MxChat: a configured AI model may be retiring', 'mxchat'); ?></strong></p>
            <?php foreach ($flagged as $model => $info) : ?>
                <p><?php echo wp_kses(self::sentence($model, $info), array('code' => array())); ?></p>
            <?php endforeach; ?>
            <p><a href="<?php echo esc_url(self::picker_url()); ?>"><?php esc_html_e('Choose a current model in MxChat Settings', 'mxchat'); ?></a></p>
        </div>
        <?php
    }

    /**
     * In-shell notice for MxChat's branded admin pages, in the design system's
     * own .mxch-notice component. Called from the AI Models section of
     * includes/admin-settings-page.php — directly above the Chat Model field,
     * which is both where the warning is relevant and where it gets fixed.
     */
    public static function render_inline_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $flagged = self::flagged_models();
        if (empty($flagged)) {
            return;
        }
        ?>
        <div class="mxch-notice mxch-notice-warning mxch-notice-block mxchat-model-liveness-notice-inline">
            <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div>
                <strong><?php esc_html_e('A configured AI model may be retiring', 'mxchat'); ?></strong>
                <?php foreach ($flagged as $model => $info) : ?>
                    <p><?php echo wp_kses(self::sentence($model, $info), array('code' => array())); ?></p>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
