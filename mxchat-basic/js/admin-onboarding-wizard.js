/**
 * MxChat Onboarding Wizard — client-side step machine.
 *
 * Plan: plan-mxchat-20260527-905439 (initial 5-step wizard).
 * Plan: plan-mxchat-20260528-a2e4d6 (v2 polish — 6-step machine,
 *       clickable pill indicator, AI Behavior step, defensive
 *       confirmation-row fix on embedding step).
 *
 * Reads window.MxChatOnboardingWizard (populated by admin-onboarding-page.php)
 * for catalog/progress/nonce/urls/stepsMeta and drives the 6-step linear wizard.
 *
 * Step machine:
 *   1. chat provider + model + key      → POST save_step which=chat
 *   2. AI Behavior textarea             → POST save_step which=behavior  (NEW — a2e4d6)
 *   3. embedding provider + model + key → POST save_step which=embedding
 *   4. KB seed (polls kb_status every 5s) → POST mark_step
 *   5. Actions (optional skip)          → POST mark_step
 *   6. Congrats; POST auto_graduate on land. Has a "Review earlier steps" Back.
 *
 * One step visible at a time. Continue is gated until the step's completion
 * criteria are met. Back is available on every step except Step 1 (nothing
 * to go back to). The pill indicator at the top is a separate navigation
 * surface — completed pills are clickable and jump directly to that step.
 */
(function () {
    'use strict';

    var W = window.MxChatOnboardingWizard;
    if (!W || typeof W !== 'object') return;

    var root = document.getElementById('mxch-onboarding-wizard');
    if (!root) return;

    // --- Shorthand selectors (scoped to the wizard card) ----------------
    function $(sel)    { return root.querySelector(sel); }
    function $$(sel)   { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
    function pickStep(n) { return root.querySelector('.mxch-wizard-step[data-step="' + n + '"]'); }
    function whichSel(which, sel) {
        return root.querySelector(sel + '[data-mxch-which="' + which + '"]');
    }

    var TOTAL_STEPS = 6;

    // Map each step number to the progress flag that gates it (null = no flag,
    // i.e. Congrats which is "all flags true").
    var STEP_FLAG = {
        1: 'chat_model',
        2: 'behavior',
        3: 'embedding_model',
        4: 'knowledge_base',
        5: 'actions',
        6: null
    };

    var STEP_NAMES = {
        1: 'Choose your chat model',
        2: 'Tell your chatbot how to behave',
        3: 'Choose your embedding model',
        4: 'Add to your knowledge base',
        5: 'Try Actions (optional)',
        6: 'You\'re set up'
    };

    // --- Internal state -------------------------------------------------
    var state = {
        current:   W.initialStep || 1,
        progress:  Object.assign({}, W.progress || {}),
        chat: {
            provider: W.currentChatProvider || '',
            model:    W.currentChatModel || '',
            keyKnown: !!(W.currentChatProvider && W.catalog[W.currentChatProvider] && W.catalog[W.currentChatProvider].hasKey),
            keyFresh: ''
        },
        embedding: {
            provider: W.currentEmbedProvider || '',
            model:    W.currentEmbedModel || '',
            keyKnown: !!(W.currentEmbedProvider && W.catalog[W.currentEmbedProvider] && W.catalog[W.currentEmbedProvider].hasKey),
            keyFresh: ''
        },
        behavior: {
            value: (typeof W.currentInstructions === 'string') ? W.currentInstructions : ''
        },
        kbCount: W.kbCount || 0,
        kbPollTimer: null,
        graduated: false
    };

    // --- Generic AJAX helper -------------------------------------------
    function ajax(action, payload, cb) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', W.nonce);
        Object.keys(payload || {}).forEach(function (k) {
            if (payload[k] !== undefined && payload[k] !== null) {
                body.append(k, payload[k]);
            }
        });
        fetch(W.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return null; }); })
          .then(function (j) { cb(null, j); })
          .catch(function (e) { cb(e); });
    }

    // --- Pill indicator render (plan-a2e4d6) ----------------------------
    // Determines, per pill, which of three visual states it's in:
    //   - is-complete : the flag for that step is true (and it's not the current step)
    //   - is-current  : the wizard is on that step right now
    //   - is-future   : flag false AND not current → non-clickable, faded
    function renderPillNav() {
        var pills = $$('.mxch-wizard-pill');
        pills.forEach(function (pill) {
            var n = parseInt(pill.getAttribute('data-mxch-pill-step'), 10);
            var flag = STEP_FLAG[n];
            var done = (flag === null)
                ? Object.keys(STEP_FLAG).every(function (k) {
                    var f = STEP_FLAG[k]; return f === null ? true : !!state.progress[f];
                  })
                : !!state.progress[flag];
            var isCurrent = (n === state.current);

            pill.classList.toggle('is-complete', done && !isCurrent);
            pill.classList.toggle('is-current',  isCurrent);
            pill.classList.toggle('is-future',   !done && !isCurrent);

            if (isCurrent) {
                pill.setAttribute('aria-current', 'step');
            } else {
                pill.removeAttribute('aria-current');
            }
            // Clickable iff complete (jump back) OR current (no-op). Future is disabled.
            if (!done && !isCurrent) {
                pill.setAttribute('disabled', '');
                pill.setAttribute('aria-disabled', 'true');
            } else {
                pill.removeAttribute('disabled');
                pill.removeAttribute('aria-disabled');
            }
        });
    }

    // Pill click → jump to that step IFF complete or current.
    $$('.mxch-wizard-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            if (pill.hasAttribute('disabled')) return;
            var n = parseInt(pill.getAttribute('data-mxch-pill-step'), 10);
            if (!isNaN(n) && n >= 1 && n <= TOTAL_STEPS) showStep(n);
        });
    });

    // --- Step show/hide + progress chrome ------------------------------
    function showStep(n) {
        if (state.kbPollTimer) {
            clearInterval(state.kbPollTimer);
            state.kbPollTimer = null;
        }
        state.current = n;
        $$('.mxch-wizard-step').forEach(function (el) {
            el.hidden = (parseInt(el.getAttribute('data-step'), 10) !== n);
        });
        var lbl = $('[data-mxch-current-step]');
        if (lbl) lbl.textContent = String(n);
        var name = $('[data-mxch-step-name]');
        if (name) name.textContent = STEP_NAMES[n] || '';
        var fill = $('[data-mxch-progress-fill]');
        if (fill) fill.style.width = ((n - 1) / (TOTAL_STEPS - 1) * 100) + '%';
        var bar = $('.mxch-wizard-progress-bar');
        if (bar) bar.setAttribute('aria-valuenow', String((n - 1) / (TOTAL_STEPS - 1) * 100 | 0));

        renderPillNav();

        if (n === 1) hydrateStep1();
        if (n === 2) hydrateStep2_behavior();
        if (n === 3) hydrateStep3_embedding();
        // Step 4 (Knowledge) is optional — no hydration / no KB-status poll.
        if (n === 5) hydrateStep5_actions();
        if (n === 6) hydrateStep6_congrats();
    }

    // ====================================================================
    //                                STEP 1 (chat)
    // ====================================================================
    function hydrateStep1() {
        var providerSel = whichSel('chat', '.mxch-wiz-select[data-mxch-role="provider"]');
        if (state.chat.provider && providerSel.value !== state.chat.provider) {
            providerSel.value = state.chat.provider;
        }
        applyProviderUI('chat');
        refreshContinue(1);
    }

    // ====================================================================
    //                                STEP 2 (behavior — NEW)
    // ====================================================================
    function hydrateStep2_behavior() {
        var ta = document.getElementById('mxch-wiz-behavior-textarea');
        if (ta && ta.value === '' && state.behavior.value !== '') {
            ta.value = state.behavior.value;
        }
        refreshContinue(2);
    }

    // Wire the behavior textarea + example one-click-fill buttons.
    var behaviorTa = document.getElementById('mxch-wiz-behavior-textarea');
    if (behaviorTa) {
        behaviorTa.addEventListener('input', function () {
            state.behavior.value = behaviorTa.value;
        });
    }

    var BEHAVIOR_EXAMPLES = {
        helpful: "You are a helpful website assistant. Your goal is to answer visitor questions using the information available about this website. Keep replies under 3 sentences and direct. Don’t invent facts — if something isn’t in your knowledge base, say so honestly and suggest the visitor contact us. Stay focused on this site’s content; light conversation is fine but redirect toward useful answers. Respond in the visitor’s language. Hyperlink any URLs you reference.",
        sales:   "You are a friendly product expert for this store. Your goal is to help visitors find the right product, answer questions about features and pricing, and gently guide them toward making a purchase or adding items to their cart. Highlight benefits, mention popular choices, and suggest related items when relevant. Don’t pressure — be informative. If a visitor asks about something not in your knowledge base, say so and offer to connect them with a human. Keep replies concise (2–4 sentences) and warm.",
        support: "You are a patient and empathetic customer support agent. Your job is to help visitors troubleshoot issues, answer how-to questions, and resolve concerns based on our documentation and knowledge base. Ask clarifying questions when needed. Acknowledge the visitor’s frustration when appropriate. If you can’t resolve an issue or the information isn’t in your knowledge base, recommend they open a support ticket. Keep replies clear and step-by-step when troubleshooting. Always be respectful, never dismissive."
    };

    $$('.mxch-wiz-behavior-example').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.getAttribute('data-mxch-example');
            var text = BEHAVIOR_EXAMPLES[key];
            if (!text || !behaviorTa) return;
            behaviorTa.value = text;
            state.behavior.value = text;
            behaviorTa.focus();
            // Visual feedback: brief highlight on the picked example.
            $$('.mxch-wiz-behavior-example').forEach(function (b) { b.classList.remove('is-picked'); });
            btn.classList.add('is-picked');
        });
    });

    // --- Sample Instructions modal (mirrors mxchat-admin.js behavior) ---
    (function wireSampleModal() {
        var viewBtn = document.getElementById('mxchatViewSampleBtn');
        var modal   = document.getElementById('mxchatSampleModal');
        if (!viewBtn || !modal) return;
        var modalClose = document.getElementById('mxchatModalClose');
        var copyBtn    = document.getElementById('mxchatCopyBtn');
        var modalContent = modal.querySelector('.mxchat-instructions-modal-content');
        var instructionsContent = modal.querySelector('.mxchat-instructions-content');

        viewBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            modal.classList.add('mxchat-instructions-show');
        });
        function closeModal(e) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            modal.classList.remove('mxchat-instructions-show');
        }
        if (modalClose) modalClose.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal(e);
        });
        if (modalContent) {
            modalContent.addEventListener('click', function (e) { e.stopPropagation(); });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('mxchat-instructions-show')) closeModal();
        });
        if (copyBtn && instructionsContent) {
            copyBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var text = instructionsContent.textContent;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).catch(function () { fallbackCopy(text); });
                } else {
                    fallbackCopy(text);
                }
            });
        }
        function fallbackCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-999999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (_e) {}
            document.body.removeChild(ta);
        }
    })();

    // ====================================================================
    //                                STEP 3 (embedding)
    // ====================================================================
    function hydrateStep3_embedding() {
        var providerSel = whichSel('embedding', '.mxch-wiz-select[data-mxch-role="provider"]');
        if (state.embedding.provider && providerSel.value !== state.embedding.provider) {
            providerSel.value = state.embedding.provider;
        }
        applyProviderUI('embedding');
        refreshContinue(3);
    }

    /**
     * Render the model dropdown + key UI for the currently-selected provider
     * on the given step ('chat' or 'embedding').
     *
     * Plan-a2e4d6 fix for Issue 5 (double-confirmation): DEFENSIVELY hide
     * EVERY conditional UI row at the top of this function before deciding
     * which one to show. The previous attempt (d14e89) hid the dedup row
     * but conditionally hid keySavedRow only inside an else branch — a stale
     * keyKnown state plus a no-op early return could leave both visible.
     * Belt-and-suspenders: hide all four first, then show only the right one.
     */
    function applyProviderUI(which) {
        var slot         = state[which];
        var providerSlug = slot.provider;
        var modelField   = whichSel(which, '.mxch-wiz-model-field');
        var keyField     = whichSel(which, '.mxch-wiz-key-field');
        var keySavedRow  = whichSel(which, '.mxch-wiz-key-saved');
        var dedupRow     = whichSel(which, '.mxch-wiz-key-dedup');
        var errBox       = whichSel(which, '.mxch-wiz-error');

        // DEFENSIVE: hide ALL conditional rows before deciding which to show.
        // This is the fix Maxwell explicitly approved for plan-a2e4d6 Issue 5.
        if (errBox)      { errBox.hidden = true; errBox.textContent = ''; }
        if (dedupRow)    dedupRow.hidden = true;
        if (keySavedRow) keySavedRow.hidden = true;
        if (keyField)    keyField.hidden = true;
        if (modelField)  modelField.hidden = true;

        if (!providerSlug || !W.catalog[providerSlug]) {
            return;
        }

        var entry = W.catalog[providerSlug];
        var modelList = which === 'chat' ? entry.chatModels : entry.embeddingModels;

        // Populate the model dropdown.
        var modelSel = whichSel(which, '.mxch-wiz-select[data-mxch-role="model"]');
        modelSel.innerHTML = '';
        var placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = W.strings.selectModel;
        modelSel.appendChild(placeholderOpt);
        Object.keys(modelList).forEach(function (val) {
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = modelList[val];
            modelSel.appendChild(opt);
        });
        if (slot.model && modelList[slot.model]) {
            modelSel.value = slot.model;
        } else {
            slot.model = '';
        }
        if (modelField) modelField.hidden = false;

        // Dedup case (embedding only): same provider as chat AND key known.
        var isDedup = (which === 'embedding'
                       && state.chat.provider === providerSlug
                       && (state.chat.keyKnown || state.chat.keyFresh !== ''));
        if (isDedup) {
            if (dedupRow) {
                var label = entry.label;
                dedupRow.querySelector('[data-mxch-dedup-text]').textContent =
                    W.strings.embedKeyReused.replace('%s', label);
                dedupRow.hidden = false;
            }
            slot.keyKnown = true;
            slot.keyFresh = '';
            return;
        }

        // Non-dedup: show either the already-saved checkmark, or the input.
        // hasKey is read fresh from W.catalog[providerSlug] each call so we
        // never display a stale provider's confirmation row.
        var hasSavedKey = !!entry.hasKey || (slot.keyFresh !== '' && slot.provider === providerSlug);
        if (hasSavedKey) {
            if (keySavedRow) {
                keySavedRow.querySelector('[data-mxch-key-saved-text]').textContent =
                    W.strings.keyAlreadySaved.replace('%s', entry.label);
                keySavedRow.hidden = false;
            }
        } else {
            if (keyField) {
                keyField.hidden = false;
                var lbl = keyField.querySelector('[data-mxch-key-label]');
                if (lbl) lbl.textContent = entry.label + ' ' + 'API key';
                var input = keyField.querySelector('.mxch-wiz-key-input');
                if (input && slot.keyFresh === '') input.value = '';
            }
        }
    }

    // Provider/model dropdown change handlers.
    $$('.mxch-wiz-select[data-mxch-role="provider"]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var which = sel.getAttribute('data-mxch-which');
            state[which].provider = sel.value;
            state[which].model = '';
            state[which].keyFresh = '';
            state[which].keyKnown = !!(sel.value && W.catalog[sel.value] && W.catalog[sel.value].hasKey);
            applyProviderUI(which);
            refreshContinue(which === 'chat' ? 1 : 3);
        });
    });
    $$('.mxch-wiz-select[data-mxch-role="model"]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var which = sel.getAttribute('data-mxch-which');
            state[which].model = sel.value;
            refreshContinue(which === 'chat' ? 1 : 3);
        });
    });

    // Save-key handlers (Step 1 & Step 3 non-dedup).
    $$('.mxch-wiz-key-save').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var which = btn.getAttribute('data-mxch-which');
            var input = whichSel(which, '.mxch-wiz-key-field').querySelector('.mxch-wiz-key-input');
            var val = (input && input.value || '').trim();
            var errBox = whichSel(which, '.mxch-wiz-error');
            if (!val) {
                if (errBox) {
                    errBox.textContent = 'Enter a key, then click Save.';
                    errBox.hidden = false;
                }
                return;
            }
            var slot = state[which];
            slot.keyFresh = val;
            slot.keyKnown = true;
            if (W.catalog[slot.provider]) W.catalog[slot.provider].hasKey = true;
            applyProviderUI(which);
            refreshContinue(which === 'chat' ? 1 : 3);
        });
    });

    // Replace-key links.
    $$('.mxch-wiz-replace-key-link').forEach(function (link) {
        link.addEventListener('click', function () {
            var which = link.getAttribute('data-mxch-which');
            state[which].keyKnown = false;
            state[which].keyFresh = '';
            var keyField    = whichSel(which, '.mxch-wiz-key-field');
            var keySavedRow = whichSel(which, '.mxch-wiz-key-saved');
            if (keySavedRow) keySavedRow.hidden = true;
            if (keyField) {
                keyField.hidden = false;
                var lbl = keyField.querySelector('[data-mxch-key-label]');
                var entry = W.catalog[state[which].provider];
                if (lbl && entry) lbl.textContent = entry.label + ' ' + 'API key';
                var input = keyField.querySelector('.mxch-wiz-key-input');
                if (input) { input.value = ''; input.focus(); }
            }
            refreshContinue(which === 'chat' ? 1 : 3);
        });
    });

    // --- Continue-gating ----------------------------------------------
    function isStepReady(n) {
        if (n === 1) {
            var c = state.chat;
            return !!(c.provider && c.model && (c.keyKnown || c.keyFresh !== ''));
        }
        if (n === 2) return true;  // behavior is optional — empty is valid
        if (n === 3) {
            var e = state.embedding;
            return !!(e.provider && e.model && (e.keyKnown || e.keyFresh !== ''));
        }
        if (n === 4) return true;  // knowledge base is optional
        if (n === 5) return true;  // actions are optional
        return false;
    }

    function refreshContinue(n) {
        var btn = root.querySelector('.mxch-wiz-continue[data-mxch-from-step="' + n + '"]');
        if (!btn) return;
        btn.disabled = !isStepReady(n);
    }

    // --- Continue / Back handlers --------------------------------------
    $$('.mxch-wiz-continue').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var n = parseInt(btn.getAttribute('data-mxch-from-step'), 10);
            if (!isStepReady(n)) return;

            // Steps 1 (chat) and 3 (embedding) — save provider/model/key.
            if (n === 1 || n === 3) {
                var which = (n === 1) ? 'chat' : 'embedding';
                var slot  = state[which];
                btn.disabled = true;
                btn.textContent = W.strings.saving;
                ajax('mxchat_onboarding_save_step', {
                    which:    which,
                    provider: slot.provider,
                    model:    slot.model,
                    api_key:  slot.keyFresh
                }, function (err, j) {
                    btn.textContent = 'Continue';
                    if (err || !j || !j.success) {
                        var errBox = whichSel(which, '.mxch-wiz-error');
                        if (errBox) {
                            errBox.textContent = (j && j.data && j.data.message) ? j.data.message : W.strings.saveError;
                            errBox.hidden = false;
                        }
                        btn.disabled = false;
                        return;
                    }
                    if (j.data && j.data.progress) state.progress = j.data.progress;
                    slot.keyFresh = '';
                    slot.keyKnown = true;
                    showStep(n + 1);
                });
            } else if (n === 2) {
                // Behavior step — save the textarea value (or empty).
                btn.disabled = true;
                btn.textContent = W.strings.saving;
                ajax('mxchat_onboarding_save_step', {
                    which: 'behavior',
                    system_prompt_instructions: state.behavior.value
                }, function (err, j) {
                    btn.textContent = 'Continue';
                    btn.disabled = false;
                    if (err || !j || !j.success) {
                        var errBox = whichSel('behavior', '.mxch-wiz-error');
                        if (errBox) {
                            errBox.textContent = (j && j.data && j.data.message) ? j.data.message : W.strings.saveError;
                            errBox.hidden = false;
                        }
                        return;
                    }
                    if (j.data && j.data.progress) state.progress = j.data.progress;
                    showStep(3);
                });
            } else if (n === 4) {
                ajax('mxchat_onboarding_mark_step', { step: 'knowledge_base' }, function (err, j) {
                    if (j && j.data && j.data.progress) state.progress = j.data.progress;
                    showStep(5);
                });
            } else if (n === 5) {
                ajax('mxchat_onboarding_mark_step', { step: 'actions' }, function (err, j) {
                    if (j && j.data && j.data.progress) state.progress = j.data.progress;
                    showStep(6);
                });
            }
        });
    });

    $$('.mxch-wiz-back').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var n = parseInt(btn.getAttribute('data-mxch-from-step'), 10);
            if (n > 1) showStep(n - 1);
        });
    });

    // ====================================================================
    //                                STEP 4 (KB polling)
    // ====================================================================
    function hydrateStep4_kb() {
        updateKbStatus(state.kbCount);
        state.kbPollTimer = setInterval(function () {
            ajax('mxchat_onboarding_kb_status', {}, function (err, j) {
                if (j && j.success && j.data && typeof j.data.count === 'number') {
                    state.kbCount = j.data.count;
                    updateKbStatus(state.kbCount);
                    refreshContinue(4);
                }
            });
        }, 5000);
    }

    function updateKbStatus(count) {
        var dot  = $('[data-mxch-kb-dot]');
        var text = $('[data-mxch-kb-text]');
        var wrap = $('[data-mxch-kb-status]');
        if (!dot || !text || !wrap) return;
        if (count > 0) {
            wrap.classList.add('mxch-wiz-kb-ok');
            wrap.classList.remove('mxch-wiz-kb-empty');
            dot.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
            text.textContent = count === 1
                ? W.strings.kbFoundOne
                : W.strings.kbFoundMany.replace('%d', String(count));
        } else {
            wrap.classList.remove('mxch-wiz-kb-ok');
            wrap.classList.add('mxch-wiz-kb-empty');
            dot.innerHTML = '';
            text.textContent = W.strings.kbNone;
        }
    }

    // ====================================================================
    //                                STEP 5 (actions — optional)
    // ====================================================================
    function hydrateStep5_actions() {
        refreshContinue(5);
    }

    // ====================================================================
    //                                STEP 6 (congrats + auto-graduate)
    // ====================================================================
    function hydrateStep6_congrats() {
        if (state.graduated) return;
        state.graduated = true;
        ajax('mxchat_onboarding_auto_graduate', {}, function () {
            // Menu item disappears on next admin nav.
        });
    }

    // --- Shortcode copy buttons (Step 6 — plan-23987f) ---
    $$('.mxch-wiz-shortcode-copy').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var sc = btn.getAttribute('data-mxch-copy-shortcode') || '';
            if (!sc) return;
            var label = btn.querySelector('.mxch-wiz-shortcode-copy-text');
            var origLabel = label ? label.textContent : '';
            function flashCopied() {
                btn.classList.add('is-copied');
                if (label) {
                    label.textContent = (W.strings && W.strings.shortcodeCopied) || 'Copied!';
                }
                setTimeout(function () {
                    btn.classList.remove('is-copied');
                    if (label) label.textContent = origLabel;
                }, 1500);
            }
            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = sc;
                ta.style.position = 'fixed';
                ta.style.left = '-999999px';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); flashCopied(); } catch (_e) {}
                document.body.removeChild(ta);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(sc).then(flashCopied, fallback);
            } else {
                fallback();
            }
        });
    });

    // --- Dismiss button (preserved from f7c7d4) ---
    var dismissBtn = root.querySelector('.mxch-onboarding-dismiss');
    // Note: dismissBtn is rendered at PAGE level (outside the wizard card), so
    // `root.querySelector` (scoped to the wizard) will not find it. Fall back
    // to document-level so the existing behavior is preserved.
    if (!dismissBtn) dismissBtn = document.querySelector('.mxch-onboarding-dismiss');
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!window.confirm('Hide MxChat Onboarding from the menu? You can bring it back from Settings → Display.')) return;
            var nonce = dismissBtn.getAttribute('data-mxch-dismiss-nonce');
            if (!nonce) return;
            var body = new URLSearchParams();
            body.append('action', 'mxchat_dismiss_onboarding');
            body.append('nonce', nonce);
            fetch(W.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function () { window.location.reload(); })
              .catch(function () { window.location.reload(); });
        });
    }

    // --- Initial render -------------------------------------------------
    showStep(state.current);
})();
