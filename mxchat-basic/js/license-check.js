/**
 * MxChat licence check — the "Check license now" button and the notice dismissal.
 *
 * Plan c93ede. Loaded on Pro & Extensions and on any admin screen where the
 * licence notice renders. One handler for every `.mxchat-license-check-now`
 * button: it asks the site to check with mxchat.ai right away, prints the
 * answer next to the button, and reloads the page when Pro switched on or off.
 */
(function ($) {
    'use strict';

    var cfg = window.MxChatLicenseCheck || {};

    function messageTarget($button) {
        var $scope = $button.closest('.mxchat-license-check-notice, .mxch-license-check-panel, .mxch-license-footer-actions, .mxch-card-body');
        var $msg = $scope.find('.mxchat-license-check-msg').first();
        if (!$msg.length) {
            $msg = $('#mxchat-license-check-msg');
        }
        return $msg;
    }

    $(document).on('click', '.mxchat-license-check-now', function () {
        var $button = $(this);
        if ($button.prop('disabled')) {
            return;
        }
        var $msg = messageTarget($button);
        var label = $button.text();

        $button.prop('disabled', true).attr('aria-busy', 'true');
        $msg.removeClass('is-error').text(cfg.checking || 'Checking…');

        $.post(cfg.ajaxUrl || window.ajaxurl, {
            action: 'mxchat_license_check_now',
            nonce: cfg.nonce
        }).done(function (response) {
            if (!response || !response.success) {
                var detail = response && response.data && response.data.message ? response.data.message : (cfg.failed || 'The check could not run.');
                $msg.addClass('is-error').text(detail);
                return;
            }
            var data = response.data || {};
            if (data.message) {
                $msg.text(data.message);
            }
            // Pro & Extensions shows a status pill beside the sentence; keep it in step.
            var $pill = $('#mxchat-license-check-pill');
            if ($pill.length && data.pillClass) {
                $pill.removeClass('mxch-status-pill-active mxch-status-pill-disabled mxch-status-pill-warning')
                     .addClass(data.pillClass)
                     .find('.mxchat-license-check-pill-text').text(data.pill || '');
            }
            if (data.reload) {
                $msg.text(cfg.reloading || 'Reloading…');
                window.setTimeout(function () { window.location.reload(); }, 1200);
            }
        }).fail(function () {
            $msg.addClass('is-error').text(cfg.failed || 'The check could not run.');
        }).always(function () {
            $button.prop('disabled', false).removeAttr('aria-busy').text(label);
        });
    });

    // The WordPress notice's X: remember the dismissal for a day.
    $(document).on('click', '.mxchat-license-check-notice[data-mxchat-license-notice] .notice-dismiss', function () {
        $.post(cfg.ajaxUrl || window.ajaxurl, {
            action: 'mxchat_license_notice_dismiss',
            nonce: cfg.dismissNonce
        });
    });
})(jQuery);
