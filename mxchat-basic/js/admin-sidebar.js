/**
 * Shared admin shell JS for the MxChat admin design system.
 *
 * Wires tab switching (with #hash deep-linking and ?tab= precedence),
 * parent-group accordion navigation, mobile menu open/close (with body
 * scroll lock + Escape), and copy-to-clipboard inside every
 * .mxch-admin-wrapper on the page. Scoped to each wrapper so multiple
 * admin shells could in theory coexist (today we have one per page, but
 * no global side effects beyond the mobile body scroll lock).
 *
 * Deep-linking (plan 4ede16): a URL hash naming a .mxch-section id activates
 * that tab on load and on hashchange, clicking a tab writes the hash via
 * history.replaceState (replaceState, not pushState, so the browser Back
 * button leaves the page rather than walking back through every tab), and
 * plain href="#section-id" anchors work with no data-target attribute.
 * A ?tab=<section-id> query param takes precedence over the #hash on load
 * (the Onboarding setup-step CTAs land on settings sub-tabs this way).
 *
 * Accordion (plan c192a4): a .mxch-nav-item containing a .mxch-nav-sub whose
 * .mxch-nav-link has no data-target is an expandable group — clicking it
 * collapses sibling groups and toggles this one, activating the first
 * sub-tab on expand. Mobile menus get the equivalent via
 * .mxch-mobile-nav-link[data-parent] / .mxch-mobile-nav-sub[data-parent].
 * All of it is feature-detected: pages with flat navs (API Access,
 * Knowledge, mcp) never enter these branches.
 *
 * Source of truth for the inline-script logic previously duplicated in
 * mxchat-basic/includes/admin-api-page.php, admin-settings-page.php,
 * admin-knowledge-page.php and mxchat-mcp/includes/admin-mcp-page.php.
 *
 * @package MxChat
 */
(function () {
    // Section ids are plain slugs; anything else (junk hashes, selector
    // metacharacters) is rejected before it reaches querySelector.
    var SECTION_ID = /^[A-Za-z][A-Za-z0-9_-]*$/;

    function closeMobileMenu(wrapper) {
        var mobileMenu = wrapper.querySelector('.mxch-mobile-menu');
        var mobileOverlay = wrapper.querySelector('.mxch-mobile-overlay');
        if (mobileMenu && mobileMenu.classList.contains('open')) {
            document.body.style.overflow = '';
        }
        if (mobileMenu) { mobileMenu.classList.remove('open'); }
        if (mobileOverlay) { mobileOverlay.classList.remove('open'); }
    }

    // Collapse every expandable nav group in the wrapper except `keep`.
    // No-op on pages without .mxch-nav-sub groups.
    function collapseNavGroups(wrapper, keep) {
        wrapper.querySelectorAll('.mxch-nav-item.expanded').forEach(function (item) {
            if (item !== keep && item.querySelector('.mxch-nav-sub')) {
                item.classList.remove('expanded');
            }
        });
    }

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

        // Start the newly shown section at the top of the content area.
        var content = wrapper.querySelector('.mxch-content');
        if (content) { content.scrollTop = 0; }

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

        closeMobileMenu(wrapper);
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
                if (activateTarget(wrapper, targetId)) {
                    // A direct (no-submenu) sidebar link closes any open
                    // accordion group; sub-links keep their parent open.
                    if (btn.classList.contains('mxch-nav-link')) {
                        collapseNavGroups(wrapper, null);
                    }
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', '#' + targetId);
                    }
                }
            });
        });

        // Accordion groups: a .mxch-nav-link with a .mxch-nav-sub sibling and
        // no data-target toggles its group. Expanding activates the first
        // sub-tab (so the panel always matches the highlighted group).
        wrapper.querySelectorAll('.mxch-nav-item').forEach(function (item) {
            if (!item.querySelector('.mxch-nav-sub')) {
                return;
            }
            var parentLink = item.querySelector('.mxch-nav-link');
            if (!parentLink || parentLink.hasAttribute('data-target')) {
                return;
            }
            parentLink.addEventListener('click', function (e) {
                e.preventDefault();
                var wasExpanded = item.classList.contains('expanded');
                collapseNavGroups(wrapper, item);
                item.classList.toggle('expanded');
                if (!wasExpanded) {
                    var firstSub = item.querySelector('.mxch-nav-sub-link');
                    var firstTarget = firstSub ? firstSub.getAttribute('data-target') : null;
                    if (firstTarget && activateTarget(wrapper, firstTarget) && window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', '#' + firstTarget);
                    }
                }
            });
        });

        // Mobile accordion groups (settings-page pattern): a
        // .mxch-mobile-nav-link[data-parent] with no data-target toggles the
        // matching .mxch-mobile-nav-sub[data-parent], collapsing siblings.
        wrapper.querySelectorAll('.mxch-mobile-nav-link[data-parent]').forEach(function (btn) {
            if (btn.hasAttribute('data-target')) {
                return;
            }
            var parentId = btn.getAttribute('data-parent');
            if (!parentId || !SECTION_ID.test(parentId)) {
                return;
            }
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var subNav = wrapper.querySelector('.mxch-mobile-nav-sub[data-parent="' + parentId + '"]');
                if (!subNav) {
                    return;
                }
                var wasExpanded = subNav.classList.contains('expanded');
                wrapper.querySelectorAll('.mxch-mobile-nav-sub').forEach(function (nav) {
                    nav.classList.remove('expanded');
                });
                wrapper.querySelectorAll('.mxch-mobile-nav-link').forEach(function (l) {
                    l.classList.remove('expanded');
                });
                if (!wasExpanded) {
                    subNav.classList.add('expanded');
                    btn.classList.add('expanded');
                }
            });
        });

        // Deep-link on load: ?tab=<section-id> wins over the #hash (Onboarding
        // CTAs), then a section-naming hash. Neither writes the hash back.
        var tabParam = null;
        try {
            tabParam = new URLSearchParams(window.location.search).get('tab');
        } catch (err) {
            tabParam = null;
        }
        var loadActivated = tabParam ? activateTarget(wrapper, tabParam) : false;
        if (!loadActivated && window.location.hash) {
            activateTarget(wrapper, window.location.hash.slice(1));
        }
        // …and on every hashchange, so plain href="#section-id" anchors (and a
        // second link to a different tab) switch without a reload.
        window.addEventListener('hashchange', function () {
            activateTarget(wrapper, window.location.hash.slice(1));
        });

        // Mobile menu open/close. Opening locks body scroll; every close path
        // (button, overlay, Escape, tab activation) releases it.
        var mobileBtn = wrapper.querySelector('.mxch-mobile-menu-btn');
        var mobileMenu = wrapper.querySelector('.mxch-mobile-menu');
        var mobileOverlay = wrapper.querySelector('.mxch-mobile-overlay');
        var mobileClose = wrapper.querySelector('.mxch-mobile-menu-close');

        function openMenu() {
            if (!mobileMenu) { return; }
            mobileMenu.classList.add('open');
            if (mobileOverlay) { mobileOverlay.classList.add('open'); }
            document.body.style.overflow = 'hidden';
        }
        function closeMenu() {
            closeMobileMenu(wrapper);
        }
        if (mobileBtn) { mobileBtn.addEventListener('click', openMenu); }
        if (mobileClose) { mobileClose.addEventListener('click', closeMenu); }
        if (mobileOverlay) { mobileOverlay.addEventListener('click', closeMenu); }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && mobileMenu && mobileMenu.classList.contains('open')) {
                closeMenu();
            }
        });

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
