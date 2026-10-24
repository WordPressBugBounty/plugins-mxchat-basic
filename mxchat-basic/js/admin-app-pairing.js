/**
 * API Access → Mobile app: show a single-use pairing code as a QR code, count it down, and keep the
 * Paired phones list current (a new phone appears as soon as it pairs). Uses qrcode-generator
 * (js/vendor/qrcode-generator.js, MIT) and the mxchat_app_* AJAX actions in class-mxchat-app-auth.php.
 */
(function () {
    'use strict';
    var cfg = window.mxchAppPairing;
    if (!cfg || typeof window.qrcode !== 'function') {
        return;
    }
    var btn = document.getElementById('mxch-app-pair-btn');
    var box = document.getElementById('mxch-app-pair');
    var qrEl = document.getElementById('mxch-app-qr');
    var link = document.getElementById('mxch-app-pair-link');
    var timerEl = document.getElementById('mxch-app-pair-timer');
    var pairedEl = document.getElementById('mxch-app-paired');
    var tbody = document.getElementById('mxch-app-devices');
    if (!btn || !tbody) {
        return;
    }
    var countdown = null;
    var watch = null;
    var known = null;

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function ago(ts) {
        if (!ts) { return '—'; }
        var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (s < 90) { return cfg.i18n.justNow; }
        if (s < 3600) { return cfg.i18n.minutes.replace('%d', Math.floor(s / 60)); }
        if (s < 86400) { return cfg.i18n.hours.replace('%d', Math.floor(s / 3600)); }
        return cfg.i18n.days.replace('%d', Math.floor(s / 86400));
    }

    function cell(row, text) {
        var td = document.createElement('td');
        td.textContent = text;
        row.appendChild(td);
        return td;
    }

    // Built with textContent only: device names come from the phones.
    function draw(devices) {
        tbody.textContent = '';
        if (!devices.length) {
            var empty = document.createElement('tr');
            var td = cell(empty, cfg.i18n.none);
            td.colSpan = 4;
            tbody.appendChild(empty);
            return;
        }
        devices.sort(function (a, b) { return b.created - a.created; }).forEach(function (d) {
            var row = document.createElement('tr');
            cell(row, d.name + (d.platform ? ' (' + d.platform + ')' : ''));
            cell(row, d.user || '—');
            cell(row, d.active === false ? cfg.i18n.notAdmin : ago(d.last_seen));
            var actions = cell(row, '');
            var revoke = document.createElement('button');
            revoke.type = 'button';
            revoke.className = 'mxch-btn mxch-btn-secondary mxch-btn-sm';
            revoke.textContent = cfg.i18n.revoke;
            revoke.addEventListener('click', function () {
                if (!window.confirm(cfg.i18n.confirmRevoke.replace('%s', d.name))) { return; }
                revoke.disabled = true;
                post('mxchat_app_revoke_device', { device_id: d.id }).then(load);
            });
            actions.appendChild(revoke);
            tbody.appendChild(row);
        });
    }

    function load() {
        return post('mxchat_app_devices').then(function (r) {
            if (!r || !r.success) { return; }
            var devices = r.data.devices || [];
            if (known !== null && box && !box.hidden) {
                var fresh = devices.filter(function (d) { return known.indexOf(d.id) === -1; });
                if (fresh.length) {
                    pairedEl.textContent = cfg.i18n.paired.replace('%s', fresh[0].name);
                    pairedEl.hidden = false;
                    qrEl.classList.add('is-expired');
                    stop();
                }
            }
            known = devices.map(function (d) { return d.id; });
            draw(devices);
        });
    }

    function stop() {
        clearInterval(countdown);
        clearInterval(watch);
        countdown = watch = null;
    }

    function show(code) {
        var qr = window.qrcode(0, 'M');
        qr.addData(code.link);
        qr.make();
        qrEl.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
        qrEl.classList.remove('is-expired');
        link.href = code.link;
        pairedEl.hidden = true;
        box.hidden = false;
        var until = Math.floor(Date.now() / 1000) + (code.ttl || 300);
        stop();
        var tick = function () {
            var left = until - Math.floor(Date.now() / 1000);
            if (left <= 0) {
                stop();
                timerEl.textContent = cfg.i18n.expired;
                qrEl.classList.add('is-expired');
                return;
            }
            timerEl.textContent = cfg.i18n.expires.replace('%s', Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2));
        };
        tick();
        countdown = setInterval(tick, 1000);
        // While the code is live, look for the phone that uses it.
        watch = setInterval(load, 4000);
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        post('mxchat_app_pair_code').then(function (r) {
            btn.disabled = false;
            if (r && r.success) {
                btn.textContent = cfg.i18n.newCode;
                show(r.data);
            }
        }, function () { btn.disabled = false; });
    });

    load();
})();
