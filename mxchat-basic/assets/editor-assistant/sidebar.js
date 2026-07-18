/**
 * MxChat Editor Assistant — Gutenberg sidebar (v0.2).
 *
 * No JSX, no build step. Uses wp.element.createElement directly and
 * wp.plugins.registerPlugin to mount a PluginSidebar in the block editor.
 *
 * v0.2 adds:
 *   1. Streaming — single-block transforms stream token-by-token into the
 *      result preview via a fetch ReadableStream reader against the admin-ajax
 *      SSE endpoint. Falls back to the non-streaming REST call where the
 *      browser lacks streaming fetch.
 *   2. Multi-block — when 2+ blocks are selected, each is transformed in
 *      parallel via the non-streaming /transform endpoint and shown as its own
 *      progress row; "Accept all" writes each result back to its block.
 *   3. Grok + custom-provider support is server-side (see class-editor-assistant-rest.php);
 *      no client change needed — the same action call now succeeds for those models.
 */
(function (wp) {
    if (!wp || !wp.plugins || !wp.editPost || !wp.element) {
        return;
    }

    var settings = window.MxChatEditorAssistant || {};
    var i18n = settings.i18n || {};
    var actions = settings.actions || [];
    var locales = settings.locales || [];
    var supportedBlocks = settings.supportedBlocks || [];

    // Streaming needs fetch + ReadableStream. Modern Chrome/Edge/Safari/Firefox
    // all have it; older webviews fall back to the non-streaming REST call.
    var canStream = !!(window.fetch && window.ReadableStream && settings.ajaxUrl && settings.streamNonce);

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var registerPlugin = wp.plugins.registerPlugin;
    var PluginSidebar = wp.editPost.PluginSidebar;
    var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
    var Button = wp.components.Button;
    var TextareaControl = wp.components.TextareaControl;
    var SelectControl = wp.components.SelectControl;
    var Notice = wp.components.Notice;
    var Spinner = wp.components.Spinner;
    var Panel = wp.components.Panel;
    var PanelBody = wp.components.PanelBody;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;

    var errorPrefix = i18n.errorPrefix || 'Error:';

    function sprintf1(tpl, a) { return (tpl || '').replace('%d', a).replace('%1$d', a); }
    function sprintf2(tpl, a, b) { return (tpl || '').replace('%1$d', a).replace('%2$d', b); }

    /**
     * Extract a block's editable text content. The supported block list keeps
     * the read/write surface simple: each block stores its main text in either
     * `content`, `value`, or `text` depending on type.
     */
    function readBlockText(block) {
        if (!block || !block.attributes) return '';
        var attrs = block.attributes;
        if (typeof attrs.content === 'string') return attrs.content;
        if (attrs.content && attrs.content.toString) return attrs.content.toString();
        if (typeof attrs.value === 'string') return attrs.value;
        if (typeof attrs.text === 'string') return attrs.text;
        return '';
    }

    function pickContentAttribute(block) {
        if (!block || !block.attributes) return null;
        var attrs = block.attributes;
        if (typeof attrs.content !== 'undefined') return 'content';
        if (typeof attrs.value !== 'undefined') return 'value';
        if (typeof attrs.text !== 'undefined') return 'text';
        return null;
    }

    function isSupported(block) {
        if (!block) return false;
        return supportedBlocks.indexOf(block.name) !== -1;
    }

    function MxChatSidebar() {
        var stateLocale = useState((locales[0] && locales[0].value) || 'en');
        var locale = stateLocale[0];
        var setLocale = stateLocale[1];

        var stateBusy = useState(false);
        var busy = stateBusy[0];
        var setBusy = stateBusy[1];

        // Single-block streaming state.
        var stateStreaming = useState(false);
        var streaming = stateStreaming[0];
        var setStreaming = stateStreaming[1];

        var stateStreamText = useState('');
        var streamText = stateStreamText[0];
        var setStreamText = stateStreamText[1];

        // Single-block final (editable) result.
        var stateResult = useState(null);
        var resultObj = stateResult[0];
        var setResult = stateResult[1];

        // Multi-block jobs.
        var stateJobs = useState(null);
        var multiJobs = stateJobs[0];
        var setMultiJobs = stateJobs[1];

        var stateError = useState('');
        var error = stateError[0];
        var setError = stateError[1];

        // Read both single + multi selection so 2+ highlighted blocks act together.
        var selection = useSelect(function (select) {
            var be = select('core/block-editor');
            var multi = (be.getMultiSelectedBlocks ? be.getMultiSelectedBlocks() : []) || [];
            return { multi: multi, single: be.getSelectedBlock() };
        }, []);

        var dispatch = useDispatch('core/block-editor');

        var targets = (selection.multi && selection.multi.length >= 2)
            ? selection.multi
            : (selection.single ? [selection.single] : []);
        var isMulti = targets.length >= 2;
        var primaryBlock = targets.length === 1 ? targets[0] : null;
        var hasAnySupported = targets.some(isSupported);

        // selection signature → reset transient state when the selection changes.
        var selectionKey = targets.map(function (b) { return b.clientId; }).join(',');
        useEffect(function () {
            setResult(null);
            setMultiJobs(null);
            setStreamText('');
            setStreaming(false);
            setError('');
        }, [selectionKey]);

        function preflight() {
            setError('');
            // Folded into core (plan-8cb0cb) as a free feature — no PRO license gate.
            if (!targets.length || !hasAnySupported) {
                setError(i18n.unsupportedNotice || 'Select a supported block.');
                return false;
            }
            return true;
        }

        // ── Single-block streaming ──────────────────────────────────────────
        function runStreamSingle(actionDef, block) {
            setResult(null);
            setMultiJobs(null);
            setStreamText('');
            var text = readBlockText(block);
            if (!text || !text.trim()) {
                setError(i18n.emptyContent || 'Block has no text content.');
                return;
            }

            // Browsers without streaming fetch → non-streaming one-shot.
            if (!canStream) {
                runNonStreamSingle(actionDef, block, text);
                return;
            }

            setStreaming(true);
            var acc = '';
            var streamErr = '';

            var params = new URLSearchParams();
            params.append('action', settings.streamAction);
            params.append('nonce', settings.streamNonce);
            params.append('action_slug', actionDef.slug);
            params.append('content', text);
            if (actionDef.needs_locale) { params.append('locale', locale); }

            fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(function (resp) {
                if (!resp.body || !resp.body.getReader) {
                    // Stream body unavailable — degrade to text parse.
                    return resp.text().then(function (full) { parseFrames(full, true); });
                }
                var reader = resp.body.getReader();
                var decoder = new TextDecoder();
                var buffer = '';

                function parseFrames(chunk, isFinal) {
                    buffer += chunk;
                    var parts = buffer.split('\n\n');
                    if (!isFinal) { buffer = parts.pop(); } else { buffer = ''; }
                    parts.forEach(function (part) {
                        var line = part.trim();
                        if (line.indexOf('data: ') !== 0) return;
                        var payload = line.slice(6);
                        if (payload === '[DONE]') return;
                        try {
                            var obj = JSON.parse(payload);
                            if (obj && obj.error) {
                                streamErr = obj.error;
                            } else if (obj && typeof obj.content === 'string') {
                                acc += obj.content;
                                setStreamText(acc);
                            }
                        } catch (e) { /* ignore partial */ }
                    });
                }

                function pump() {
                    return reader.read().then(function (r) {
                        if (r.done) { parseFrames('', true); finalize(); return; }
                        parseFrames(decoder.decode(r.value, { stream: true }), false);
                        return pump();
                    });
                }

                function finalize() {
                    setStreaming(false);
                    if (acc) {
                        setResult({ text: acc, actionLabel: actionDef.label, clientId: block.clientId, attr: pickContentAttribute(block) });
                    }
                    if (streamErr && !acc) {
                        setError(errorPrefix + ' ' + streamErr);
                    }
                }

                return pump();
            }).catch(function (err) {
                setStreaming(false);
                setError(errorPrefix + ' ' + ((err && err.message) ? err.message : 'stream failed'));
            });
        }

        function runNonStreamSingle(actionDef, block, text) {
            setBusy(true);
            var payload = { action: actionDef.slug, content: text };
            if (actionDef.needs_locale) { payload.locale = locale; }
            wp.apiFetch({ path: '/mxchat-editor/v1/transform', method: 'POST', data: payload })
                .then(function (response) {
                    setBusy(false);
                    if (response && response.result) {
                        setResult({ text: response.result, actionLabel: actionDef.label, clientId: block.clientId, attr: pickContentAttribute(block) });
                    } else {
                        setError(errorPrefix + ' empty response');
                    }
                }).catch(function (err) {
                    setBusy(false);
                    setError(errorPrefix + ' ' + ((err && err.message) ? err.message : 'request failed'));
                });
        }

        // ── Multi-block parallel transform ──────────────────────────────────
        function runMultiBlock(actionDef, blocks) {
            setResult(null);
            setStreamText('');
            var jobs = blocks.map(function (b, i) {
                var supported = isSupported(b);
                return {
                    clientId: b.clientId,
                    index: i,
                    blockName: b.name,
                    attr: pickContentAttribute(b),
                    supported: supported,
                    status: supported ? 'pending' : 'skipped',
                    text: '',
                    error: ''
                };
            });
            setMultiJobs(jobs.slice());
            setBusy(true);

            function updateJob(i, patch) {
                setMultiJobs(function (prev) {
                    if (!prev) return prev;
                    var next = prev.slice();
                    next[i] = Object.assign({}, next[i], patch);
                    return next;
                });
            }

            var promises = blocks.map(function (block, i) {
                if (!isSupported(block)) { return Promise.resolve(); }
                var text = readBlockText(block);
                if (!text || !text.trim()) {
                    updateJob(i, { status: 'failed', error: i18n.emptyContent || 'No text content.' });
                    return Promise.resolve();
                }
                updateJob(i, { status: 'working' });
                var payload = { action: actionDef.slug, content: text };
                if (actionDef.needs_locale) { payload.locale = locale; }
                return wp.apiFetch({ path: '/mxchat-editor/v1/transform', method: 'POST', data: payload })
                    .then(function (resp) {
                        if (resp && resp.result) {
                            updateJob(i, { status: 'done', text: resp.result });
                        } else {
                            updateJob(i, { status: 'failed', error: 'empty response' });
                        }
                    }).catch(function (err) {
                        updateJob(i, { status: 'failed', error: (err && err.message) ? err.message : 'request failed' });
                    });
            });

            Promise.all(promises).then(function () { setBusy(false); });
        }

        function runAction(actionDef) {
            if (!preflight()) { return; }
            if (isMulti) {
                runMultiBlock(actionDef, targets);
            } else {
                runStreamSingle(actionDef, primaryBlock);
            }
        }

        // ── Accept / discard ────────────────────────────────────────────────
        function acceptSingle() {
            if (!resultObj || !resultObj.clientId || !resultObj.attr) { return; }
            var update = {};
            update[resultObj.attr] = resultObj.text;
            dispatch.updateBlockAttributes(resultObj.clientId, update);
            setResult(null);
        }

        function acceptAll() {
            if (!multiJobs) { return; }
            multiJobs.forEach(function (job) {
                if (job.status === 'done' && job.attr && job.clientId) {
                    var update = {};
                    update[job.attr] = job.text;
                    dispatch.updateBlockAttributes(job.clientId, update);
                }
            });
            setMultiJobs(null);
        }

        function discardResult() {
            setResult(null);
            setMultiJobs(null);
            setStreamText('');
            setError('');
        }

        function editJobText(index, value) {
            setMultiJobs(function (prev) {
                if (!prev) return prev;
                var next = prev.slice();
                next[index] = Object.assign({}, next[index], { text: value });
                return next;
            });
        }

        // ── Render ──────────────────────────────────────────────────────────
        var children = [];

        if (!targets.length || !hasAnySupported) {
            children.push(
                el(Notice, { status: 'info', isDismissible: false, key: 'select-block' },
                    i18n.selectBlockNotice || 'Select a supported block.'
                )
            );
        } else if (isMulti) {
            children.push(
                el(Notice, { status: 'info', isDismissible: false, key: 'multi-info' },
                    sprintf1(i18n.blocksLabelSuffix || '(%d blocks)', targets.length) + ' '
                    + (i18n.sidebarHeading || 'selected')
                )
            );
        }

        var actionsDisabled = busy || streaming || !hasAnySupported;

        // Action buttons grid.
        var actionEls = actions.map(function (action) {
            var label = action.label;
            if (isMulti) {
                label = action.label + ' ' + sprintf1(i18n.blocksLabelSuffix || '(%d blocks)', targets.length);
            }
            return el(Button, {
                key: action.slug,
                variant: 'secondary',
                disabled: actionsDisabled,
                onClick: function () { runAction(action); },
                className: 'mxchea-action-button',
                title: action.description,
            }, label);
        });

        children.push(
            el('div', { className: 'mxchea-actions-grid', key: 'actions' }, actionEls)
        );

        // Locale picker.
        children.push(
            el(SelectControl, {
                key: 'locale',
                label: i18n.translateTo || 'Translate to',
                value: locale,
                options: locales,
                onChange: function (v) { setLocale(v); },
                __nextHasNoMarginBottom: true,
            })
        );

        if (busy) {
            children.push(
                el('div', { className: 'mxchea-busy', key: 'busy' },
                    el(Spinner, {}),
                    el('span', null, i18n.workingLabel || 'Working…')
                )
            );
        }

        if (error) {
            children.push(
                el(Notice, { status: 'error', isDismissible: false, key: 'err' }, error)
            );
        }

        // Single-block streaming preview (live).
        if (streaming) {
            children.push(
                el('div', { className: 'mxchea-result mxchea-streaming', key: 'streaming' },
                    el('div', { className: 'mxchea-result-heading' },
                        el(Spinner, {}),
                        el('span', null, i18n.streamingLabel || 'Streaming…')
                    ),
                    el('div', { className: 'mxchea-stream-text' }, streamText || ' ')
                )
            );
        }

        // Single-block final result.
        if (resultObj && !streaming) {
            children.push(
                el('div', { className: 'mxchea-result', key: 'result' },
                    el('div', { className: 'mxchea-result-heading' },
                        (i18n.resultHeading || 'Result') + ' — ' + resultObj.actionLabel
                    ),
                    el(TextareaControl, {
                        value: resultObj.text,
                        onChange: function (v) { setResult(Object.assign({}, resultObj, { text: v })); },
                        rows: 8,
                        __nextHasNoMarginBottom: true,
                    }),
                    el('div', { className: 'mxchea-result-actions' },
                        el(Button, { variant: 'primary', onClick: acceptSingle }, i18n.accept || 'Accept'),
                        el(Button, { variant: 'tertiary', onClick: discardResult }, i18n.discard || 'Discard')
                    )
                )
            );
        }

        // Multi-block result rows.
        if (multiJobs) {
            var doneCount = multiJobs.filter(function (j) { return j.status === 'done'; }).length;
            var rows = multiJobs.map(function (job) {
                var statusText;
                var statusClass;
                if (job.status === 'skipped') { statusText = i18n.skipped || 'Skipped'; statusClass = 'is-skipped'; }
                else if (job.status === 'failed') { statusText = (i18n.failed || 'Failed') + (job.error ? ': ' + job.error : ''); statusClass = 'is-failed'; }
                else if (job.status === 'done') { statusText = i18n.done || 'Done'; statusClass = 'is-done'; }
                else if (job.status === 'working') { statusText = i18n.workingLabel || 'Working…'; statusClass = 'is-working'; }
                else { statusText = i18n.pending || 'Waiting…'; statusClass = 'is-pending'; }

                var rowChildren = [
                    el('div', { className: 'mxchea-job-head', key: 'head' },
                        el('span', { className: 'mxchea-job-label' },
                            (i18n.blockLabel || 'Block') + ' ' + (job.index + 1) + ' '),
                        el('code', { className: 'mxchea-job-type' }, job.blockName),
                        el('span', { className: 'mxchea-job-status ' + statusClass }, statusText)
                    )
                ];
                if (job.status === 'done') {
                    rowChildren.push(
                        el(TextareaControl, {
                            key: 'ta',
                            value: job.text,
                            onChange: function (v) { editJobText(job.index, v); },
                            rows: 4,
                            __nextHasNoMarginBottom: true,
                        })
                    );
                }
                return el('div', { className: 'mxchea-job-row', key: 'job-' + job.clientId }, rowChildren);
            });

            var multiChildren = [
                el('div', { className: 'mxchea-result-heading', key: 'mh' },
                    sprintf2(i18n.appliedSummary || 'Applied to %1$d of %2$d selected blocks.', doneCount, multiJobs.length))
            ].concat(rows);

            if (!busy && doneCount > 0) {
                multiChildren.push(
                    el('div', { className: 'mxchea-result-actions', key: 'acts' },
                        el(Button, { variant: 'primary', onClick: acceptAll }, i18n.acceptAll || 'Accept all'),
                        el(Button, { variant: 'tertiary', onClick: discardResult }, i18n.discard || 'Discard')
                    )
                );
            }

            children.push(
                el('div', { className: 'mxchea-result mxchea-multi', key: 'multi' }, multiChildren)
            );
        }

        return el('div', { className: 'mxchea-sidebar' }, children);
    }

    var sidebarIcon = el('svg', {
        xmlns: 'http://www.w3.org/2000/svg',
        width: 20, height: 20, viewBox: '0 0 24 24',
        fill: 'none', stroke: 'currentColor', strokeWidth: 2,
        strokeLinecap: 'round', strokeLinejoin: 'round',
    },
        el('path', { d: 'M15 4V2' }),
        el('path', { d: 'M15 16v-2' }),
        el('path', { d: 'M8 9h2' }),
        el('path', { d: 'M20 9h2' }),
        el('path', { d: 'M17.8 11.8 19 13' }),
        el('path', { d: 'M15 9h.01' }),
        el('path', { d: 'M17.8 6.2 19 5' }),
        el('path', { d: 'm3 21 9-9' }),
        el('path', { d: 'M12.2 6.2 11 5' })
    );

    registerPlugin('mxchat-editor-assistant', {
        render: function () {
            return el(Fragment, null,
                el(PluginSidebarMoreMenuItem, {
                    target: 'mxchat-editor-assistant-sidebar',
                    icon: sidebarIcon,
                }, i18n.panelTitle || 'MxChat Editor Assistant'),
                el(PluginSidebar, {
                    name: 'mxchat-editor-assistant-sidebar',
                    title: i18n.panelTitle || 'MxChat Editor Assistant',
                    icon: sidebarIcon,
                },
                    el(Panel, null,
                        el(PanelBody, { title: i18n.sidebarHeading || 'Block actions', initialOpen: true },
                            el(MxChatSidebar)
                        )
                    )
                )
            );
        },
    });
})(window.wp);
