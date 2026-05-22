/**
 * Shared admin shell JS for the MxChat admin design system.
 *
 * Wires tab switching, mobile menu open/close, and copy-to-clipboard
 * inside every .mxch-admin-wrapper on the page. Scoped to each wrapper
 * so multiple admin shells could in theory coexist (today we have one
 * per page, but no global side effects).
 *
 * Source of truth for the inline-script logic previously duplicated in
 * mxchat-basic/includes/admin-api-page.php and
 * mxchat-mcp/includes/admin-mcp-page.php.
 *
 * @package MxChat
 */
(function () {
    function wire(wrapper) {
        if (!wrapper || wrapper.dataset.mxchAdminWired === '1') {
            return;
        }
        wrapper.dataset.mxchAdminWired = '1';

        var copiedLabel = (window.MxChatAdminSidebarI18n && window.MxChatAdminSidebarI18n.copied) || 'Copied';

        // Tab switcher: clicking any [data-target] button toggles .mxch-section.active.
        var navButtons = wrapper.querySelectorAll('[data-target]');
        navButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var targetId = btn.getAttribute('data-target');
                if (!targetId) {
                    return;
                }

                wrapper.querySelectorAll('.mxch-section').forEach(function (s) {
                    s.classList.remove('active');
                });
                var target = wrapper.querySelector('#' + targetId);
                if (target) {
                    target.classList.add('active');
                }

                wrapper.querySelectorAll('.mxch-nav-link, .mxch-mobile-nav-link').forEach(function (l) {
                    l.classList.remove('active');
                });
                wrapper.querySelectorAll('[data-target="' + targetId + '"]').forEach(function (l) {
                    l.classList.add('active');
                });

                var mobileMenu = wrapper.querySelector('.mxch-mobile-menu');
                if (mobileMenu) { mobileMenu.classList.remove('open'); }
                var mobileOverlay = wrapper.querySelector('.mxch-mobile-overlay');
                if (mobileOverlay) { mobileOverlay.classList.remove('open'); }
            });
        });

        // Mobile menu open/close.
        var mobileBtn = wrapper.querySelector('.mxch-mobile-menu-btn');
        var mobileMenu = wrapper.querySelector('.mxch-mobile-menu');
        var mobileOverlay = wrapper.querySelector('.mxch-mobile-overlay');
        var mobileClose = wrapper.querySelector('.mxch-mobile-menu-close');

        function openMenu() {
            if (mobileMenu) { mobileMenu.classList.add('open'); }
            if (mobileOverlay) { mobileOverlay.classList.add('open'); }
        }
        function closeMenu() {
            if (mobileMenu) { mobileMenu.classList.remove('open'); }
            if (mobileOverlay) { mobileOverlay.classList.remove('open'); }
        }
        if (mobileBtn) { mobileBtn.addEventListener('click', openMenu); }
        if (mobileClose) { mobileClose.addEventListener('click', closeMenu); }
        if (mobileOverlay) { mobileOverlay.addEventListener('click', closeMenu); }

        // Copy-to-clipboard.
        wrapper.querySelectorAll('[data-mxch-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var val = btn.getAttribute('data-mxch-copy');
                if (!val) {
                    return;
                }
                var label = btn.querySelector('span');
                var original = label ? label.textContent : '';
                var done = function () {
                    if (label) { label.textContent = copiedLabel; }
                    setTimeout(function () {
                        if (label) { label.textContent = original; }
                    }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(val).then(done).catch(function () { done(); });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = val;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch (e) {}
                    document.body.removeChild(ta);
                    done();
                }
            });
        });
    }

    function init() {
        document.querySelectorAll('.mxch-admin-wrapper').forEach(wire);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
