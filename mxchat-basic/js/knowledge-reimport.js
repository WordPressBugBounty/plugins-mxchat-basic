/**
 * Knowledge screen: "Re-import affected entries" (plan 89739b).
 *
 * Check first, rewrite on confirm. Both run as short server steps that save
 * where they stopped, so this script only has to keep asking for the next
 * step and draw what comes back. A closed tab loses nothing: the next page
 * load picks the saved state up from the status call.
 */
(function () {
    'use strict';

    var card = document.getElementById('mxchat-kb-reimport');
    if (!card || typeof window.mxchatKbReimport === 'undefined') {
        return;
    }
    var L = window.mxchatKbReimport;
    var find = function (selector) { return card.querySelector(selector); };

    var before     = find('#mxchat-kb-reimport-before');
    var checkBtn   = find('#mxchat-kb-reimport-check');
    var applyBtn   = find('#mxchat-kb-reimport-apply');
    var clearBtn   = find('#mxchat-kb-reimport-clear');
    var progress   = find('.mxch-kb-reimport-progress');
    var bar        = find('.mxch-progress-bar');
    var fill       = find('.mxch-progress-bar-fill');
    var label      = find('.mxch-progress-label');
    var errorBox   = find('.mxch-kb-reimport-error');
    var errorText  = find('.mxch-kb-reimport-error span');
    var doneBox    = find('.mxch-kb-reimport-done');
    var doneText   = find('.mxch-kb-reimport-done span');
    var results    = find('.mxch-kb-reimport-results');
    var counts     = find('.mxch-kb-reimport-counts');
    var list       = find('.mxch-kb-reimport-entries');
    var more       = find('.mxch-kb-reimport-more');
    var others     = find('.mxch-kb-reimport-others');
    var othersText = find('.mxch-kb-reimport-others .mxch-disclosure-summary-text');
    var othersList = find('.mxch-kb-reimport-notes');
    var footer     = find('.mxch-kb-reimport-footer');
    var busy       = false;
    var last       = null;

    function fmt(template, a, b) {
        return String(template).replace('%1$s', a).replace('%2$s', b).replace('%s', a);
    }

    function post(params) {
        var body = new URLSearchParams();
        body.append('action', 'mxchat_kb_reimport');
        body.append('nonce', card.getAttribute('data-nonce'));
        body.append('bot_id', card.getAttribute('data-bot') || 'default');
        Object.keys(params).forEach(function (key) { body.append(key, params[key]); });
        return fetch(L.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) {
            return response.json();
        });
    }

    /** One step, retried a few times: every step is resumable on the server. */
    function step(params, attempt) {
        attempt = attempt || 0;
        return post(params).catch(function () {
            if (attempt >= 3) {
                throw new Error(L.requestFailed);
            }
            return new Promise(function (resolve) { setTimeout(resolve, 1500 * (attempt + 1)); }).then(function () {
                var again = Object.assign({}, params);
                delete again.restart;
                return step(again, attempt + 1);
            });
        });
    }

    function badge(text, variant) {
        var el = document.createElement('span');
        el.className = 'mxch-badge' + (variant ? ' mxch-badge-' + variant : '');
        el.textContent = text;
        return el;
    }

    function row(labelText, noteText, badges) {
        var li = document.createElement('li');
        var source = document.createElement('span');
        source.className = 'mxch-kb-reimport-source';
        source.textContent = labelText;
        if (noteText) {
            var note = document.createElement('span');
            note.className = 'mxch-kb-reimport-note';
            note.textContent = noteText;
            source.appendChild(note);
        }
        li.appendChild(source);
        if (badges && badges.length) {
            var wrap = document.createElement('span');
            wrap.className = 'mxch-kb-reimport-reasons';
            badges.forEach(function (b) { wrap.appendChild(b); });
            li.appendChild(wrap);
        }
        return li;
    }

    function showError(message) {
        errorText.textContent = message;
        errorBox.hidden = false;
    }

    function setBusy(state) {
        busy = state;
        checkBtn.disabled = state;
        applyBtn.disabled = state;
        clearBtn.disabled = state;
        before.disabled = state;
    }

    function render(state) {
        var phase = state.phase || 'idle';
        last = state;
        errorBox.hidden = true;
        doneBox.hidden = true;

        if (phase === 'idle') {
            progress.hidden = true;
            results.hidden = true;
            footer.hidden = true;
            return;
        }
        if (state.before && !busy) {
            before.value = state.before;
        }

        // Progress: a check has no total to measure against, a rewrite has.
        if (phase === 'scan') {
            progress.hidden = false;
            bar.hidden = true;
            label.textContent = fmt(L.checking, state.scanned);
        } else if (phase === 'apply') {
            progress.hidden = false;
            bar.hidden = false;
            fill.style.width = (state.to_repair ? Math.round(state.pos / state.to_repair * 100) : 0) + '%';
            label.textContent = fmt(L.rewriting, state.pos, state.to_repair);
        } else {
            progress.hidden = true;
        }

        if (phase === 'scan') {
            results.hidden = true;
            footer.hidden = true;
            return;
        }

        results.hidden = false;
        counts.textContent = '';
        if (phase === 'done') {
            counts.appendChild(badge(fmt(L.countRewritten, state.repaired), 'success'));
            if (state.failed > 0) {
                counts.appendChild(badge(fmt(L.countFailed, state.failed), 'error'));
            }
        } else {
            counts.appendChild(badge(fmt(L.countRepair, state.to_repair), state.to_repair > 0 ? 'warning' : ''));
        }
        counts.appendChild(badge(fmt(L.countCorrect, state.unchanged), phase === 'done' ? '' : 'success'));
        if (state.changed > 0) {
            counts.appendChild(badge(fmt(L.countChanged, state.changed)));
        }
        if (state.skipped > 0) {
            counts.appendChild(badge(fmt(L.countSkipped, state.skipped)));
        }

        list.textContent = '';
        (state.list || []).forEach(function (entry) {
            var badges = [];
            if (entry.result === 'repaired') {
                badges.push(badge(L.rewritten, 'success'));
            } else if (entry.result === 'failed') {
                badges.push(badge(L.notRewritten, 'error'));
            } else if (entry.result) {
                badges.push(badge(L.noLongerNeeded));
            } else {
                (entry.reasons || []).forEach(function (reason) { badges.push(badge(L.reasons[reason] || reason)); });
            }
            list.appendChild(row(entry.label, entry.result === 'failed' ? entry.note : '', badges));
        });
        list.hidden = !(state.list && state.list.length);
        var extra = state.to_repair - (state.list ? state.list.length : 0);
        more.hidden = extra <= 0;
        more.textContent = extra > 0 ? fmt(L.andMore, extra) : '';

        othersList.textContent = '';
        (state.notes || []).forEach(function (note) {
            othersList.appendChild(row(note.label, note.note, [badge(note.kind === 'changed' ? L.leftAlone : L.skipped)]));
        });
        others.hidden = !(state.notes && state.notes.length);
        othersText.textContent = fmt(L.othersTitle, state.changed + state.skipped);

        if (state.error) {
            showError(fmt(L.scanStopped, state.error));
        }
        if (phase === 'done') {
            doneText.textContent = state.failed > 0 ? fmt(L.doneWithFailures, state.repaired, state.failed) : fmt(L.done, state.repaired);
            doneBox.hidden = false;
        } else if (phase === 'scanned' && state.to_repair === 0 && !state.error) {
            doneText.textContent = L.nothingToDo;
            doneBox.hidden = false;
        }

        footer.hidden = false;
        var canApply = (phase === 'scanned' || phase === 'apply') && state.to_repair > 0 && !state.error;
        applyBtn.hidden = !canApply;
        find('.mxch-kb-reimport-cost').hidden = !canApply;
        applyBtn.textContent = phase === 'apply' && state.pos > 0 ? fmt(L.resume, state.to_repair - state.pos) : fmt(L.apply, state.to_repair);
    }

    function loop(params, running) {
        return step(params).then(function (res) {
            if (!res || !res.success) {
                throw new Error(res && res.data && res.data.message ? res.data.message : L.requestFailed);
            }
            render(res.data.state);
            if (res.data.state.phase === running) {
                return loop({ op: params.op }, running);
            }
            return res.data.state;
        });
    }

    function run(params, running) {
        if (busy) {
            return;
        }
        setBusy(true);
        errorBox.hidden = true;
        loop(params, running).catch(function (error) {
            progress.hidden = true;
            showError(error.message);
        }).then(function () {
            setBusy(false);
        });
    }

    checkBtn.addEventListener('click', function () {
        results.hidden = true;
        footer.hidden = true;
        doneBox.hidden = true;
        progress.hidden = false;
        bar.hidden = true;
        label.textContent = fmt(L.checking, 0);
        run({ op: 'scan', restart: '1', before: before.value }, 'scan');
    });

    applyBtn.addEventListener('click', function () {
        if (last && last.to_repair) {
            progress.hidden = false;
            bar.hidden = false;
            fill.style.width = Math.round(last.pos / last.to_repair * 100) + '%';
            label.textContent = fmt(L.rewriting, last.pos, last.to_repair);
        }
        run({ op: 'apply' }, 'apply');
    });

    clearBtn.addEventListener('click', function () {
        if (busy) {
            return;
        }
        post({ op: 'cancel' }).then(function () { render({ phase: 'idle' }); }).catch(function () { showError(L.requestFailed); });
    });

    // A check or a rewrite left unfinished by a closed tab is shown as it stands.
    post({ op: 'status' }).then(function (res) {
        if (!res || !res.success || !res.data.state || res.data.state.phase === 'idle') {
            return;
        }
        if (res.data.state.phase === 'scan') {
            run({ op: 'scan' }, 'scan');
        } else {
            render(res.data.state);
        }
    }).catch(function () {});
})();
