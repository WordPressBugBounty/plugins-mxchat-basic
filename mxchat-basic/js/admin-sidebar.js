/**
 * Shared admin shell JS for the MxChat admin design system.
 *
 * Wires tab switching (with #hash deep-linking), mobile menu open/close,
 * and copy-to-clipboard inside every .mxch-admin-wrapper on the page.
 * Scoped to each wrapper so multiple admin shells could in theory coexist
 * (today we have one per page, but no global side effects).
 *
 * Deep-linking (plan 4ede16): a URL hash naming a .mxch-section id activates
 * that tab on load and on hashchange, clicking a tab writes the hash via
 * history.replaceState (replaceState, not pushState, so the browser Back
 * button leaves the page rather than walking back through every tab), and
 * plain href="#section-id" anchors work with no data-target attribute.
 *
 * Source of truth for the inline-script logic previously duplicated in
 * mxchat-basic/includes/admin-api-page.php and
 * mxchat-mcp/includes/admin-mcp-page.php.
 *
 * @package MxChat
 */
(function () {
    // Section ids are plain slugs; anything else (junk hashes, selector
    // metacharacters) is rejected before it reaches querySelector.
    var SECTION_ID = /^[A-Za-z][A-Za-z0-9_-]*$/;

    /**
     * Activate the tab whose .mxch-section id is targetId. Returns false when
     * the id does not name a section in THIS wrapper — an unrelated hash (or a
     * data-target pointing at a non-section element, e.g. the key-test
     * buttons' input ids) leaves the current tab alone instead of blanking
     * every section.
     */
    function activateTarget(wrapper, targetId) {
        if (!targetId || !SECTION_ID.test(targetId)) {
            return false;
        }
        var target = wrapper.querySelector('.mxch-section#' + targetId);
        if (!target) {
            return false;
        }

        wrapper.querySelectorAll('.mxch-section').forEach(function (s) {
            s.classList.remove('active');
        });
        target.classList.add('active');

        wrapper.querySelectorAll('.mxch-nav-link, .mxch-nav-sub-link, .mxch-mobile-nav-link, .mxch-mobile-nav-sub-link').forEach(function (l) {
            l.classList.remove('active');
        });
        wrapper.querySelectorAll('[data-target="' + targetId + '"]').forEach(function (l) {
            l.classList.add('active');
            // A sub-tab's parent nav group must open and highlight too, or the
            // visible panel sits under a sidebar that looks unrelated.
            if (l.classList.contains('mxch-nav-sub-link')) {
                var parentItem = l.closest('.mxch-nav-item');
                if (parentItem) {
                    parentItem.classList.add('expanded');
                    var parentLink = parentItem.querySelector('.mxch-nav-link');
                    if (parentLink) {
                        parentLink.classList.add('active');
                    }
                }
            }
        });

        var mobileMenu = wrapper.querySelector('.mxch-mobile-menu');
        if (mobileMenu) { mobileMenu.classList.remove('open'); }
        var mobileOverlay = wrapper.querySelector('.mxch-mobile-overlay');
        if (mobileOverlay) { mobileOverlay.classList.remove('open'); }
        return true;
    }

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
                if (activateTarget(wrapper, targetId) && window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + targetId);
                }
            });
        });

        // Deep-link: honour a section-naming hash on load…
        if (window.location.hash) {
            activateTarget(wrapper, window.location.hash.slice(1));
        }
        // …and on every hashchange, so plain href="#section-id" anchors (and a
        // second link to a different tab) switch without a reload.
        window.addEventListener('hashchange', function () {
            activateTarget(wrapper, window.location.hash.slice(1));
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
