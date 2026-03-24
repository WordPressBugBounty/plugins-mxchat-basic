/**
 * MxChat Content Generator
 *
 * Handles modal generation flow, full-width preview with iframe scaling,
 * floating chat panel, sidebar navigation, and settings auto-save.
 *
 * @package MxChat
 * @since 3.1.0
 */
(function($) {
    'use strict';

    var state = {
        postId: null,
        previewUrl: null,
        editUrl: null,
        permalink: null,
        progressKey: null,
        progressPoll: null,
        isGenerating: false,
        isEditing: false,
        chatMessages: [],
        chatOpen: false,
        iframeScale: 1,
        historyLoaded: false,
        historyPage: 1,
        historyLoading: false,
        phraseTimer: null,
        phraseIndex: 0,
        pollStartTime: null,
        customSystemPrompt: '',
        lastProgressTime: null,
        postStatus: null
    };

    var loadingPhrases = [
        'Consulting our AI overlords...',
        'Teaching pixels to paint...',
        'Brewing a fresh pot of creativity...',
        'Convincing the robots to cooperate...',
        'Warming up the content engines...',
        'Negotiating with the algorithm...',
        'Sprinkling some digital magic...',
        'Asking ChatGPT to hold our beer...',
        'Running it through the vibe check...',
        'Assembling the word wizards...',
        'Translating brain waves to HTML...',
        'Polishing every last pixel...',
        'Taking a quick coffee break...',
        'Man, this is going to be good...',
        'Almost there... probably...',
        'Generating something awesome...',
        'Feeding the hamsters that power our servers...',
        'Doing that thing where we look busy...',
        'Hold tight, genius at work...',
        'Making the internet a little bit cooler...',
        'Crafting content so good it should be illegal...',
        'Our AI designer just said "trust the process"...'
    ];

    // ─── Sidebar Navigation ────────────────────────────────────────────

    function initNavigation() {
        $(document).on('click', '.mxch-nav-link[data-target], .mxch-nav-sub-link[data-target]', function(e) {
            e.preventDefault();
            var target = $(this).data('target');
            switchSection(target);
            $('.mxch-nav-link, .mxch-nav-sub-link').removeClass('active');
            $(this).addClass('active');
        });

        $(document).on('click', '.mxch-mobile-nav-link[data-target]', function(e) {
            e.preventDefault();
            var target = $(this).data('target');
            switchSection(target);
            $('.mxch-mobile-nav-link').removeClass('active');
            $(this).addClass('active');
            closeMobileMenu();
        });

        $(document).on('click', '.mxch-mobile-menu-btn', function() {
            $('.mxch-mobile-menu, .mxch-mobile-overlay').addClass('open');
        });
        $(document).on('click', '.mxch-mobile-menu-close, .mxch-mobile-overlay', function() {
            closeMobileMenu();
        });
    }

    function switchSection(target) {
        $('.mxch-section').removeClass('active');
        $('#' + target).addClass('active');
    }

    function closeMobileMenu() {
        $('.mxch-mobile-menu, .mxch-mobile-overlay').removeClass('open');
    }

    // ─── Inline Form ────────────────────────────────────────────────────

    function initInlineForm() {
        // "Create New" button in toolbar — resets to inline form
        $('#mxch-cg-new-btn').on('click', function() {
            resetToForm();
        });

        // On initial load: form is already visible, hide toolbar and preview-wrap chrome
        $('#mxch-cg-new-btn').hide();
        $('.mxch-cg-toolbar').addClass('mxch-cg-toolbar-minimal');
        $('.mxch-cg-preview-wrap').addClass('mxch-cg-preview-wrap-form');
    }

    function showInlineForm() {
        var $form = $('#mxch-cg-inline-form');
        $form.removeClass('mxch-cg-form-collapsing').show();

        // Hide preview and loading
        $('#mxch-cg-preview-iframe').hide();
        $('#mxch-cg-loading-indicator').hide();
        $('.mxch-cg-preview-wrap').css('height', '');

        // Toolbar: hidden; preview-wrap: transparent
        $('.mxch-cg-toolbar').addClass('mxch-cg-toolbar-minimal');
        $('.mxch-cg-preview-wrap').addClass('mxch-cg-preview-wrap-form');
        $('#mxch-cg-new-btn').hide();
        $('.mxch-cg-toolbar-right').hide();
        $('#mxch-cg-status-dropdown').hide();
        $('#mxch-cg-preview-title').text('Content Generator');

        setTimeout(function() { $('#mxch-cg-prompt').focus(); }, 100);
    }

    function hideInlineForm() {
        var $form = $('#mxch-cg-inline-form');
        $form.addClass('mxch-cg-form-collapsing');
        setTimeout(function() {
            $form.hide().removeClass('mxch-cg-form-collapsing');
        }, 300);

        $('.mxch-cg-toolbar').removeClass('mxch-cg-toolbar-minimal');
        $('.mxch-cg-preview-wrap').removeClass('mxch-cg-preview-wrap-form');
    }

    function resetToForm() {
        // Reset state
        state.postId = null;
        state.previewUrl = null;
        state.editUrl = null;
        state.permalink = null;
        state.postStatus = null;
        state.chatMessages = [];

        closeChatPanel();
        closeStatusDropdown();
        resetSeoPanel();
        $('#mxch-cg-prompt').val('');
        showInlineForm();
    }

    // ─── Generation Flow ───────────────────────────────────────────────

    function initGeneration() {
        // Show/hide schedule date picker
        $('#mxch-cg-status').on('change', function() {
            if ($(this).val() === 'future') {
                $('.mxch-cg-schedule-wrap').show();
                if (!$('#mxch-cg-schedule').val()) {
                    var tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    tomorrow.setHours(9, 0, 0, 0);
                    $('#mxch-cg-schedule').val(tomorrow.toISOString().slice(0, 16));
                }
            } else {
                $('.mxch-cg-schedule-wrap').hide();
            }
        });

        // Generate button
        $('#mxch-cg-generate-btn').on('click', function() {
            if (state.isGenerating) return;
            startGeneration();
        });
    }

    // ── Edit Default Prompt Modal ──────────────────────────────
    function initPromptModal() {
        var $modal = $('#mxch-cg-prompt-modal');
        var $editor = $('#mxch-cg-system-prompt-editor');
        var $btn = $('#mxch-cg-edit-prompt-btn');
        var currentDefault = '';

        function fetchPromptData(callback) {
            var contentType = $('#mxch-cg-type').val() || 'post';
            $.post(mxchatContent.ajaxUrl, {
                action: 'mxchat_get_default_prompt',
                nonce: mxchatContent.nonce,
                content_type: contentType
            }, function(response) {
                if (response.success) {
                    currentDefault = response.data.default_prompt;
                    var saved = response.data.saved_prompt || '';
                    state.customSystemPrompt = saved;
                    updateButtonState();
                    if (callback) callback(currentDefault, saved);
                }
            });
        }

        function updateButtonState() {
            if (state.customSystemPrompt) {
                $btn.addClass('mxch-cg-prompt-modified');
            } else {
                $btn.removeClass('mxch-cg-prompt-modified');
            }
        }

        function openModal() {
            fetchPromptData(function(def, saved) {
                $editor.val(saved || def);
                $modal.fadeIn(200);
            });
        }

        function closeModal() {
            $modal.fadeOut(200);
        }

        function saveToServer(promptText, callback) {
            var contentType = $('#mxch-cg-type').val() || 'post';
            $.post(mxchatContent.ajaxUrl, {
                action: 'mxchat_save_custom_prompt',
                nonce: mxchatContent.nonce,
                content_type: contentType,
                custom_prompt: promptText
            }, function(response) {
                if (callback) callback(response.success);
            });
        }

        $btn.on('click', openModal);
        $('#mxch-cg-prompt-modal-close, #mxch-cg-prompt-cancel, .mxch-cg-prompt-modal-overlay').on('click', closeModal);

        $('#mxch-cg-prompt-save').on('click', function() {
            var edited = $editor.val().trim();
            var customValue = (edited && edited !== currentDefault) ? edited : '';
            state.customSystemPrompt = customValue;
            saveToServer(customValue);
            updateButtonState();
            closeModal();
        });

        $('#mxch-cg-prompt-reset').on('click', function() {
            $editor.val(currentDefault);
            state.customSystemPrompt = '';
            saveToServer('');
            updateButtonState();
        });

        // Load saved state for initial content type on page load
        fetchPromptData();

        // When content type changes, load the saved prompt for that type
        $('#mxch-cg-type').on('change', function() {
            fetchPromptData();
        });
    }

    function startGeneration() {
        var prompt = $('#mxch-cg-prompt').val().trim();
        if (!prompt) {
            showNotice('Please enter a prompt describing the content you want to generate.', 'error');
            return;
        }

        state.isGenerating = true;

        // Immediately hide form and show loading indicator
        $('#mxch-cg-inline-form').hide().removeClass('mxch-cg-form-collapsing');
        $('.mxch-cg-toolbar').removeClass('mxch-cg-toolbar-minimal');
        $('.mxch-cg-preview-wrap').removeClass('mxch-cg-preview-wrap-form');
        $('#mxch-cg-preview-title').text('Generating...');
        $('#mxch-cg-new-btn').hide();
        $('.mxch-cg-toolbar-right').hide();
        $('#mxch-cg-status-dropdown').hide();
        closeStatusDropdown();
        closeChatPanel();
        showLoadingIndicator();

        var data = {
            action: 'mxchat_generate_content',
            nonce: mxchatContent.nonce,
            prompt: prompt,
            content_type: $('#mxch-cg-type').val(),
            post_status: $('#mxch-cg-status').val(),
            schedule_date: $('#mxch-cg-schedule').val() || '',
            layout: $('#mxch-cg-layout').val() || 'fullwidth',
            title_display: $('#mxch-cg-title-display').val() || 'hide',
            template_mode: $('#mxch-cg-template-mode').val() || 'off',
            custom_system_prompt: state.customSystemPrompt || ''
        };

        $.ajax({
            url: mxchatContent.ajaxUrl,
            type: 'POST',
            data: data,
            timeout: 60000,
            success: function(response) {
                if (response.success && response.data.progress_key) {
                    // Async mode — loading indicator already showing
                    state.progressKey = response.data.progress_key;
                    startProgressPoll();
                } else if (response.success) {
                    // Sync fallback — full result returned directly
                    onGenerationSuccess(response.data);
                } else {
                    onGenerationError(response.data && response.data.message ? response.data.message : 'Generation failed.');
                }
            },
            error: function(xhr, status, error) {
                onGenerationError('Request failed: ' + (error || status));
            }
        });
    }

    function onGenerationSuccess(data) {
        state.isGenerating = false;
        state.postId = data.post_id;
        state.previewUrl = data.preview_url;
        state.editUrl = data.edit_url;
        state.permalink = data.permalink;
        state.postStatus = data.status;
        state.chatMessages = [];
        state.historyLoaded = false;

        // Ensure form and its chrome are hidden
        hideInlineForm();

        // Show success state on loading indicator briefly before showing preview
        var $loadingIndicator = $('#mxch-cg-loading-indicator');
        if ($loadingIndicator.is(':visible')) {
            stopPhraseRotation();
            $('#mxch-cg-loading-phrase').text('Your content is ready!');
            updateLoadingProgress(100, 'Complete!');
            $loadingIndicator.addClass('mxch-cg-loading-success');

            setTimeout(function() {
                hideLoadingIndicator();
                finishPreviewLoad(data);
            }, 1200);
        } else {
            // Direct/sync flow — no loading indicator was shown
            finishPreviewLoad(data);
        }
    }

    function finishPreviewLoad(data) {
        // Update toolbar title
        $('#mxch-cg-preview-title').text(data.title || 'Preview');

        // Show status dropdown
        var statusLabels = { draft: 'Draft', publish: 'Published', future: 'Scheduled' };
        var $dropdown = $('#mxch-cg-status-dropdown');
        var $badge = $('#mxch-cg-status-badge');
        $badge.find('.mxch-cg-status-badge-text').text(statusLabels[data.status] || data.status);
        $badge.removeClass('mxch-cg-badge-draft mxch-cg-badge-publish mxch-cg-badge-future')
              .addClass('mxch-cg-badge-' + data.status);
        $dropdown.show();
        closeStatusDropdown();
        $('.mxch-cg-status-option').removeClass('mxch-cg-status-active');
        $('.mxch-cg-status-option[data-status="' + data.status + '"]').addClass('mxch-cg-status-active');
        state.postStatus = data.status;

        // Show toolbar actions
        $('.mxch-cg-toolbar-right').show();
        $('#mxch-cg-view-post').attr('href', data.permalink);

        // Show "Create New" button in toolbar
        var $newBtn = $('#mxch-cg-new-btn');
        $newBtn.find('span').text('Create New');
        $newBtn.show();

        // Load preview
        loadPreview(data.preview_url);

        // Store post ID on the chat panel for add-on access
        $('#mxch-cg-chat').attr('data-post-id', data.post_id);

        // Populate image panel with generated images
        populateImagePanel(data.images || []);

        // Populate meta panel with SEO data
        populateMetaPanel(data);

        // Auto-run SEO analysis
        resetSeoPanel();
        setTimeout(function() { runSeoAnalysis(); }, 500);

        // Pre-populate chat
        $('#mxch-cg-chat-messages').empty();
        addChatMessage('assistant', 'Content generated! Request edits like "change the heading to..." or "make the background blue".');
    }

    function onGenerationError(message) {
        state.isGenerating = false;

        var $btn = $('#mxch-cg-generate-btn');
        $btn.prop('disabled', false).removeClass('mxch-cg-loading');

        var $loadingIndicator = $('#mxch-cg-loading-indicator');

        if ($loadingIndicator.is(':visible')) {
            // Error while loading indicator is showing (async flow)
            stopPhraseRotation();
            $loadingIndicator.addClass('mxch-cg-loading-error');
            $('#mxch-cg-loading-phrase').text('Oops! Something went wrong.');
            updateLoadingProgress(0, message);
            $('#mxch-cg-loading-progress-fill').css('background', '#ef4444');

            // Add retry and dismiss buttons
            if (!$loadingIndicator.find('.mxch-cg-loading-error-actions').length) {
                var $actions = $(
                    '<div class="mxch-cg-loading-error-actions">' +
                        '<button type="button" class="mxch-cg-generate-btn" id="mxch-cg-loading-retry">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>' +
                            ' Try Again' +
                        '</button>' +
                        '<button type="button" class="mxch-cg-action-btn" id="mxch-cg-loading-dismiss">Dismiss</button>' +
                    '</div>'
                );
                $loadingIndicator.append($actions);

                $actions.find('#mxch-cg-loading-retry').on('click', function() {
                    hideLoadingIndicator();
                    showInlineForm();
                });
                $actions.find('#mxch-cg-loading-dismiss').on('click', function() {
                    hideLoadingIndicator();
                    showInlineForm();
                });
            }
        } else {
            // Error while modal is still open (pre-async or sync flow)
            updateProgress(0, 'Error: ' + message);
            $('#mxch-cg-progress .mxch-cg-progress-fill').css('background', '#ef4444');

            setTimeout(function() {
                $('#mxch-cg-progress').fadeOut(300);
                $('#mxch-cg-progress .mxch-cg-progress-fill').css('background', '');
            }, 4000);
        }
    }

    function updateProgress(percent, message) {
        $('#mxch-cg-progress .mxch-cg-progress-fill').css('width', percent + '%');
        $('#mxch-cg-progress .mxch-cg-progress-text').text(message);
    }

    function startProgressPoll() {
        // Clear any existing poll interval (but preserve progressKey — it was just set)
        if (state.progressPoll) {
            clearInterval(state.progressPoll);
            state.progressPoll = null;
        }
        state.pollStartTime = Date.now();
        state.lastProgressTime = Date.now();
        state.progressPoll = setInterval(pollProgress, 2500);
    }

    function pollProgress() {
        if (!state.progressKey) return;

        // Activity-based timeout: if no progress update received for 3 minutes, stop.
        // This allows long generations (many images + long content) to run as long as
        // the backend is still making progress, while still catching truly stalled jobs.
        var inactiveMs = Date.now() - (state.lastProgressTime || state.pollStartTime);
        if (inactiveMs > 180000) {
            stopProgressPoll();
            onGenerationError('Generation is taking longer than expected. Check your History tab — the post may have been created.');
            return;
        }

        $.ajax({
            url: mxchatContent.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mxchat_content_progress',
                nonce: mxchatContent.nonce,
                progress_key: state.progressKey
            },
            timeout: 10000,
            success: function(response) {
                if (!response.success) return;

                var d = response.data;

                // Any non-waiting response means the backend is alive — reset inactivity timer
                if (d.step && d.step !== 'waiting') {
                    state.lastProgressTime = Date.now();
                }

                updateProgress(d.percent || 0, d.message || 'Processing...');
                updateLoadingProgress(d.percent || 0, d.message || 'Processing...');

                if (d.step === 'done' && d.result) {
                    stopProgressPoll();
                    onGenerationSuccess(d.result);
                } else if (d.step === 'error') {
                    stopProgressPoll();
                    onGenerationError(d.message || 'Generation failed.');
                }
            },
            error: function() {
                // Silently retry on poll failure — don't stop polling
            }
        });
    }

    function stopProgressPoll() {
        if (state.progressPoll) {
            clearInterval(state.progressPoll);
            state.progressPoll = null;
        }
        state.progressKey = null;
        state.pollStartTime = null;
        state.lastProgressTime = null;
    }

    // ─── Loading Indicator ────────────────────────────────────────────

    function showLoadingIndicator() {
        $('#mxch-cg-inline-form').hide().removeClass('mxch-cg-form-collapsing');
        $('#mxch-cg-preview-iframe').hide();
        $('.mxch-cg-preview-wrap').css('height', '');

        var $loading = $('#mxch-cg-loading-indicator');
        $loading
            .removeClass('mxch-cg-loading-error mxch-cg-loading-success')
            .show();

        // Reset mini progress
        $('#mxch-cg-loading-progress-fill').css({ 'width': '0%', 'background': '' });
        $('#mxch-cg-loading-progress-text').text('Starting...');

        // Remove any leftover error actions
        $loading.find('.mxch-cg-loading-error-actions').remove();

        startPhraseRotation();
    }

    function hideLoadingIndicator() {
        stopPhraseRotation();
        $('#mxch-cg-loading-indicator').hide();
    }

    function startPhraseRotation() {
        stopPhraseRotation();

        // Fisher-Yates shuffle for variety
        var shuffled = loadingPhrases.slice();
        for (var i = shuffled.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var temp = shuffled[i];
            shuffled[i] = shuffled[j];
            shuffled[j] = temp;
        }

        state.phraseIndex = 0;
        var $phrase = $('#mxch-cg-loading-phrase');

        // Show first phrase immediately
        $phrase.text(shuffled[0]).removeClass('mxch-cg-phrase-exit mxch-cg-phrase-enter');

        state.phraseTimer = setInterval(function() {
            state.phraseIndex = (state.phraseIndex + 1) % shuffled.length;
            var nextText = shuffled[state.phraseIndex];

            // Fade out (slide up)
            $phrase.addClass('mxch-cg-phrase-exit');

            setTimeout(function() {
                // Swap text and prepare enter state (below)
                $phrase
                    .text(nextText)
                    .removeClass('mxch-cg-phrase-exit')
                    .addClass('mxch-cg-phrase-enter');

                // Force reflow then remove enter class to trigger transition
                $phrase[0].offsetHeight;
                $phrase.removeClass('mxch-cg-phrase-enter');
            }, 400); // matches CSS transition duration

        }, 4500);
    }

    function stopPhraseRotation() {
        if (state.phraseTimer) {
            clearInterval(state.phraseTimer);
            state.phraseTimer = null;
        }
    }

    function updateLoadingProgress(percent, message) {
        $('#mxch-cg-loading-progress-fill').css('width', percent + '%');
        if (message) {
            $('#mxch-cg-loading-progress-text').text(message);
        }
    }

    // ─── Preview ───────────────────────────────────────────────────────

    function initPreview() {
        // Viewport toggle
        $(document).on('click', '.mxch-cg-viewport-btn', function() {
            var viewport = $(this).data('viewport');
            $('.mxch-cg-viewport-btn').removeClass('active');
            $(this).addClass('active');

            var $container = $('#mxch-cg-preview-container');
            if (viewport === 'mobile') {
                $container.addClass('mxch-cg-viewport-mobile');
                // Reset iframe to natural size for mobile
                $('#mxch-cg-preview-iframe').css({
                    width: '375px',
                    transform: 'none'
                });
            } else {
                $container.removeClass('mxch-cg-viewport-mobile');
                scaleIframe();
            }
        });

        // Recalculate scale on window resize
        $(window).on('resize', function() {
            if (!$('#mxch-cg-preview-container').hasClass('mxch-cg-viewport-mobile')) {
                scaleIframe();
            }
        });
    }

    function scaleIframe() {
        var $iframe = $('#mxch-cg-preview-iframe');
        if (!$iframe.is(':visible')) return;

        var $wrap = $('.mxch-cg-preview-wrap');
        var containerWidth = $wrap.innerWidth();
        var iframeNativeWidth = 1400;

        if (containerWidth < iframeNativeWidth) {
            var scale = containerWidth / iframeNativeWidth;
            state.iframeScale = scale;
            $iframe.css({
                width: iframeNativeWidth + 'px',
                transform: 'scale(' + scale + ')',
                height: (Math.max(700, $(window).height() - 220) / scale) + 'px'
            });
            // Set container height to match scaled iframe
            $wrap.css('height', ($iframe.outerHeight() * scale) + 'px');
        } else {
            state.iframeScale = 1;
            $iframe.css({
                width: '100%',
                transform: 'none',
                height: Math.max(700, $(window).height() - 220) + 'px'
            });
            $wrap.css('height', '');
        }
    }

    function loadPreview(url) {
        var $iframe = $('#mxch-cg-preview-iframe');
        var $empty = $('.mxch-cg-preview-empty');

        $empty.hide();
        $iframe.show();

        // Attach load handler BEFORE setting src to avoid race condition
        $iframe.off('load.scale').on('load.scale', function() {
            scaleIframe();
        });

        // Add mxchat_preview param so PHP hides admin bar in <head> before render
        var separator = url.indexOf('?') !== -1 ? '&' : '?';
        $iframe.attr('src', url + separator + 'mxchat_preview=1&_t=' + Date.now());

        // Also scale immediately for initial sizing
        setTimeout(scaleIframe, 100);
    }

    function refreshPreview() {
        if (state.previewUrl) {
            loadPreview(state.previewUrl);
        }
    }

    function showPreviewEmpty() {
        showInlineForm();
    }

    // ─── Chat Panel ────────────────────────────────────────────────────

    function initChat() {
        // Toggle chat panel
        $('#mxch-cg-chat-toggle').on('click', function() {
            if (state.chatOpen) {
                closeChatPanel();
            } else {
                openChatPanel();
            }
        });

        // Close chat panel
        $('#mxch-cg-chat-close').on('click', function() {
            closeChatPanel();
        });

        // Enable/disable send button + auto-resize textarea
        $('#mxch-cg-chat-input').on('input', function() {
            var hasText = $(this).val().trim().length > 0;
            $('#mxch-cg-chat-send').prop('disabled', !hasText || state.isEditing);
            // Auto-resize
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });

        // Send on Enter, Shift+Enter for newline
        $('#mxch-cg-chat-input').on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (!$(this).val().trim() || state.isEditing) return;
                sendEdit();
            }
        });

        // Send button click
        $('#mxch-cg-chat-send').on('click', function() {
            if (state.isEditing) return;
            sendEdit();
        });
    }

    function openChatPanel() {
        state.chatOpen = true;
        // Use flex display for two-column layout
        $('#mxch-cg-chat').css('display', 'flex');
        $('#mxch-cg-chat-input').focus();
        scrollChatToBottom();
    }

    function closeChatPanel() {
        state.chatOpen = false;
        $('#mxch-cg-chat').hide();
    }

    function sendEdit() {
        var input = $('#mxch-cg-chat-input').val().trim();
        if (!input || !state.postId) return;

        state.isEditing = true;
        $('#mxch-cg-chat-input').val('').css('height', 'auto');
        $('#mxch-cg-chat-send').prop('disabled', true);

        addChatMessage('user', input);

        var $loading = $('<div class="mxch-cg-chat-msg mxch-cg-chat-assistant"><div class="mxch-cg-chat-bubble mxch-cg-chat-loading"><span></span><span></span><span></span></div></div>');
        $('#mxch-cg-chat-messages').append($loading);
        scrollChatToBottom();

        $.ajax({
            url: mxchatContent.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mxchat_content_edit',
                nonce: mxchatContent.nonce,
                post_id: state.postId,
                edit_instruction: input
            },
            timeout: 120000,
            success: function(response) {
                $loading.remove();
                state.isEditing = false;

                if (response.success) {
                    addChatMessage('assistant', response.data.message || 'Content updated.');
                    if (response.data.preview_url) {
                        state.previewUrl = response.data.preview_url;
                    }
                    refreshPreview();
                    if (response.data.title) {
                        $('#mxch-cg-preview-title').text(response.data.title);
                    }
                    if (response.data.meta) {
                        populateMetaPanel(response.data);
                    }
                    if (response.data.images) {
                        populateImagePanel(response.data.images);
                    }
                } else {
                    addChatMessage('assistant', 'Error: ' + (response.data && response.data.message ? response.data.message : 'Edit failed.'));
                }
            },
            error: function() {
                $loading.remove();
                state.isEditing = false;
                addChatMessage('assistant', 'Error: Request failed. Please try again.');
            }
        });
    }

    function addChatMessage(role, content) {
        state.chatMessages.push({ role: role, content: content });
        var roleClass = role === 'user' ? 'mxch-cg-chat-user' : 'mxch-cg-chat-assistant';
        var $msg = $('<div class="mxch-cg-chat-msg ' + roleClass + '">' +
                     '<div class="mxch-cg-chat-bubble">' + escapeHtml(content) + '</div>' +
                     '</div>');
        $('#mxch-cg-chat-messages').append($msg);
        scrollChatToBottom();
    }

    function scrollChatToBottom() {
        var el = document.getElementById('mxch-cg-chat-messages');
        if (el) el.scrollTop = el.scrollHeight;
    }

    // ─── Settings Auto-Save ────────────────────────────────────────────

    function initSettingsAutoSave() {
        // Use event delegation so dynamically-enabled fields (e.g. pro toggles
        // unlocked by add-ons after page load) still trigger saves.
        $('#content-settings').on('change', '[data-field]', function() {
            var $field = $(this);
            var field = $field.data('field');
            var value;

            if ($field.is(':checkbox')) {
                value = $field.is(':checked') ? 'on' : 'off';
            } else {
                value = $field.val();
            }

            saveContentSetting(field, value, $field);

            // Keep seoOptimize prefs in sync without page reload
            var seoMap = {
                seo_optimize_meta_desc: 'meta_description',
                seo_optimize_seo_title: 'seo_title',
                seo_optimize_slug: 'slug',
                seo_optimize_readability: 'readability',
                seo_optimize_internal_links: 'internal_links',
                seo_optimize_img_alt: 'img_alt',
                seo_optimize_featured_img: 'featured_img'
            };
            if (seoMap[field] && mxchatContent.seoOptimize) {
                mxchatContent.seoOptimize[seoMap[field]] = (value === 'on');
            }
        });
    }

    function saveContentSetting(field, value, $field) {
        var $label = $field.closest('.mxch-field').find('.mxch-field-label');
        if (!$label.length) {
            $label = $field.closest('.mxch-field').find('.mxch-toggle-label');
        }

        // Show saving spinner
        if ($label.length) {
            $label.removeClass('mxch-saved').addClass('mxch-saving');
        }

        $.ajax({
            url: mxchatContent.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mxchat_save_content_setting',
                nonce: mxchatContent.nonce,
                field: field,
                value: value
            },
            success: function(response) {
                if ($label.length) {
                    $label.removeClass('mxch-saving');
                    if (response.success) {
                        $label.addClass('mxch-saved');
                        setTimeout(function() {
                            $label.removeClass('mxch-saved');
                        }, 1500);
                    }
                }
            },
            error: function(xhr, status, error) {
                if ($label.length) {
                    $label.removeClass('mxch-saving');
                }
                if (window.console) {
                    console.warn('MxChat content setting save failed:', field, status, error);
                }
            }
        });
    }

    // ─── Image Panel ────────────────────────────────────────────────────

    function populateImagePanel(images) {
        var $grid = $('#mxch-cg-images-grid');
        var $empty = $('#mxch-cg-images-empty');
        var isLocked = $('.mxch-cg-images-col').hasClass('mxch-cg-pro-locked');

        // Clear any previous images (keep the empty state element)
        $grid.find('.mxch-cg-image-thumb').remove();

        if (!images || images.length === 0) {
            $empty.show();
            return;
        }

        $empty.hide();

        $.each(images, function(i, img) {
            var $thumb = $(
                '<div class="mxch-cg-image-thumb">' +
                    '<img src="' + escapeAttr(img.thumbnail) + '" alt="Image ' + (i + 1) + '">' +
                    '<div class="mxch-cg-image-actions' + (isLocked ? ' mxch-cg-image-actions-locked' : '') + '">' +
                        '<button type="button" class="mxch-cg-image-action-btn mxch-cg-image-upload-btn"' + (isLocked ? ' disabled' : '') + ' data-attachment-id="' + (img.attachment_id || '') + '">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                            ' Upload' +
                        '</button>' +
                        '<button type="button" class="mxch-cg-image-action-btn mxch-cg-image-regen-btn"' + (isLocked ? ' disabled' : '') + ' data-attachment-id="' + (img.attachment_id || '') + '">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>' +
                            ' Regenerate' +
                        '</button>' +
                    '</div>' +
                    (isLocked ? '<div class="mxch-cg-image-lock-badge"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> PRO</div>' : '') +
                '</div>'
            );
            $grid.append($thumb);
        });
    }

    function escapeAttr(str) {
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ─── SEO Tab ────────────────────────────────────────────────────

    var seoState = { analyzed: false, analyzing: false, fixing: false, score: null, checks: null };

    function initSeo() {
        $('#mxch-seo-analyze').on('click', function() {
            if (state.postId && !seoState.analyzing) runSeoAnalysis();
        });
        $('#mxch-seo-ai-optimize').on('click', function() {
            if (state.postId && !seoState.fixing) runAiOptimize();
        });
        // Per-check AI fix buttons (content editor panel only, not dashboard modal)
        $(document).on('click', '.mxch-seo-check-fix:not(.mxch-seod-check-fix-btn)', function(e) {
            e.stopPropagation();
            var $btn = $(this);
            if ($btn.hasClass('mxch-seo-check-fixing') || !state.postId) return;
            runSeoFixSingle($btn.data('field'), $btn);
        });
        // Auto-analyze when switching to SEO tab if content exists
        $(document).on('click', '.mxch-cg-left-tab[data-tab="seo"]', function() {
            if (state.postId && !seoState.analyzed && !seoState.analyzing) runSeoAnalysis();
        });
    }

    function runSeoAnalysis() {
        if (!state.postId) return;
        seoState.analyzing = true;
        $('#mxch-seo-analyze').addClass('mxch-spinning');
        $.post(ajaxurl, {
            action: 'mxchat_seo_analyze',
            nonce: mxchatContent.nonce,
            post_id: state.postId,
        }).done(function(res) {
            if (res.success) {
                seoState.analyzed = true;
                seoState.score = res.data.score;
                seoState.checks = res.data.checks;
                renderSeoResults(res.data);
            } else {
                renderSeoError(res.data || 'Analysis failed');
            }
        }).fail(function() {
            renderSeoError('Connection error');
        }).always(function() {
            seoState.analyzing = false;
            $('#mxch-seo-analyze').removeClass('mxch-spinning');
        });
    }

    function renderSeoResults(data) {
        var score = data.score, checks = data.checks, summary = data.summary;
        // Score ring
        var offset = 163.36 - (score / 100) * 163.36;
        var $ring = $('#mxch-seo-ring');
        $ring.css('stroke-dashoffset', offset).removeClass('mxch-seo-good mxch-seo-ok mxch-seo-bad');
        if (score >= 80) $ring.addClass('mxch-seo-good');
        else if (score >= 50) $ring.addClass('mxch-seo-ok');
        else $ring.addClass('mxch-seo-bad');
        $('#mxch-seo-score').text(score);
        $('#mxch-seo-score-label').text(score >= 80 ? 'Great' : score >= 60 ? 'Good' : score >= 40 ? 'Needs Work' : 'Poor');
        var parts = [];
        if (summary.pass) parts.push(summary.pass + ' passed');
        if (summary.warn) parts.push(summary.warn + ' warnings');
        if (summary.fail) parts.push(summary.fail + ' issues');
        $('#mxch-seo-score-summary').text(parts.join(' \u00b7 '));
        // Checklist
        var $list = $('#mxch-seo-checklist').empty();
        var icons = {
            pass: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            warn: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            fail: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        };
        // Checks that require the Advanced Content Editor add-on to fix
        var addonChecks = { readability: true, internal_links: true, img_alt: true, featured_img: true };
        // Map check key → optimize field name for per-check fix buttons
        var fixableMap = { meta_desc: 'meta_description', title_length: 'seo_title', slug: 'slug', readability: 'readability', internal_links: 'internal_links', img_alt: 'img_alt', featured_img: 'featured_img' };
        var sparkleIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.287 1.288L3 12l5.8 1.9a2 2 0 0 1 1.288 1.287L12 21l1.9-5.8a2 2 0 0 1 1.287-1.288L21 12l-5.8-1.9a2 2 0 0 1-1.288-1.287Z"/></svg>';
        var sorted = Object.keys(checks).sort(function(a, b) {
            var o = { fail: 0, warn: 1, pass: 2 };
            return (o[checks[a].status] || 2) - (o[checks[b].status] || 2);
        });
        var last = null;
        sorted.forEach(function(key) {
            var c = checks[key];
            if (last && last !== 'pass' && c.status === 'pass') {
                $list.append('<div class="mxch-seo-separator"></div>');
            }
            last = c.status;

            // Show addon/pro badge for gated checks that aren't passing
            var badge = '';
            if (addonChecks[key] && c.status !== 'pass' && !mxchatContent.hasAdvancedContent) {
                if (mxchatContent.isActivated) {
                    badge = ' <a href="https://mxchat.ai/advanced-content-editor/" target="_blank" class="mxch-seod-addon-badge">ADD-ON</a>';
                } else {
                    badge = ' <a href="https://mxchat.ai/" target="_blank" class="mxch-seod-addon-badge mxch-seod-pro-badge">PRO</a>';
                }
            }

            // Per-check AI fix button for non-passing, fixable checks
            var fixBtn = '';
            if (c.status !== 'pass' && fixableMap[key]) {
                var canFix = !addonChecks[key] || mxchatContent.hasAdvancedContent;
                if (canFix) {
                    fixBtn = '<button type="button" class="mxch-seo-check-fix" data-field="' + fixableMap[key] + '" title="AI Fix">' + sparkleIcon + '</button>';
                }
            }

            $list.append(
                '<div class="mxch-seo-check" data-check="' + key + '">' +
                    '<div class="mxch-seo-check-icon mxch-seo-' + c.status + '">' + icons[c.status] + '</div>' +
                    '<div class="mxch-seo-check-content">' +
                        '<span class="mxch-seo-check-label">' + escapeHtml(c.label) + badge + '</span>' +
                        '<span class="mxch-seo-check-detail">' + escapeHtml(c.detail) + '</span>' +
                    '</div>' +
                    fixBtn +
                '</div>'
            );
        });
        $('#mxch-seo-actions').toggle(summary.fail > 0 || summary.warn > 0);
    }

    function renderSeoError(msg) {
        $('#mxch-seo-checklist').html('<div class="mxch-seo-empty"><span style="color:#ef4444;">' + escapeHtml(msg) + '</span></div>');
    }

    function runSeoFixSingle(field, $btn) {
        $btn.addClass('mxch-seo-check-fixing').prop('disabled', true);
        $.post(ajaxurl, {
            action: 'mxchat_seo_suggest',
            nonce: mxchatContent.nonce,
            post_id: state.postId,
            field: field,
        }).done(function(res) {
            if (res.success) {
                if (field === 'meta_description') $('#mxch-cg-meta-description').val(res.data.suggestion).trigger('input');
                else if (field === 'seo_title') $('#mxch-cg-meta-title').val(res.data.suggestion);
                $('.mxch-cg-left-tab[data-tab="meta"]').addClass('mxch-cg-tab-flash');
                setTimeout(function() { $('.mxch-cg-left-tab[data-tab="meta"]').removeClass('mxch-cg-tab-flash'); }, 2000);
            }
        }).always(function() {
            $btn.removeClass('mxch-seo-check-fixing').prop('disabled', false);
            runSeoAnalysis();
        });
    }

    function runAiOptimize() {
        if (!state.postId || seoState.fixing) return;
        seoState.fixing = true;
        var $btn = $('#mxch-seo-ai-optimize'), origHtml = $btn.html();
        $btn.addClass('mxch-seo-fixing').prop('disabled', true)
            .html('<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.287 1.288L3 12l5.8 1.9a2 2 0 0 1 1.288 1.287L12 21l1.9-5.8a2 2 0 0 1 1.287-1.288L21 12l-5.8-1.9a2 2 0 0 1-1.288-1.287Z"/></svg> Optimizing\u2026');
        var prefs = mxchatContent.seoOptimize || {};
        var fields = [];
        if (seoState.checks) {
            if (prefs.meta_description !== false && seoState.checks.meta_desc && seoState.checks.meta_desc.status !== 'pass') fields.push('meta_description');
            if (prefs.seo_title !== false && seoState.checks.title_length && seoState.checks.title_length.status !== 'pass') fields.push('seo_title');
            if (prefs.slug !== false && seoState.checks.slug && seoState.checks.slug.status !== 'pass') fields.push('slug');
            // Readability, internal links, images require Advanced Content Editor add-on
            if (mxchatContent.hasAdvancedContent) {
                if (prefs.readability !== false && seoState.checks.readability && seoState.checks.readability.status !== 'pass') fields.push('readability');
                if (prefs.internal_links !== false && seoState.checks.internal_links && seoState.checks.internal_links.status !== 'pass') fields.push('internal_links');
                if (prefs.img_alt !== false && seoState.checks.img_alt && seoState.checks.img_alt.status !== 'pass') fields.push('img_alt');
                if (prefs.featured_img !== false && seoState.checks.featured_img && seoState.checks.featured_img.status !== 'pass') fields.push('featured_img');
            }
        }
        if (!fields.length) fields.push('meta_description');
        // Run fields sequentially to avoid race conditions
        // (multiple optimizers read/write post_content)
        var idx = 0;
        function runNext() {
            if (idx >= fields.length) {
                seoState.fixing = false;
                $btn.removeClass('mxch-seo-fixing').prop('disabled', false).html(origHtml);
                runSeoAnalysis();
                return;
            }
            var field = fields[idx];
            $.post(ajaxurl, {
                action: 'mxchat_seo_suggest',
                nonce: mxchatContent.nonce,
                post_id: state.postId,
                field: field,
            }).done(function(res) {
                if (res.success) {
                    if (field === 'meta_description') $('#mxch-cg-meta-description').val(res.data.suggestion).trigger('input');
                    else if (field === 'seo_title') $('#mxch-cg-meta-title').val(res.data.suggestion);
                    else if (field === 'excerpt') $('#mxch-cg-meta-excerpt').val(res.data.suggestion);
                    $('.mxch-cg-left-tab[data-tab="meta"]').addClass('mxch-cg-tab-flash');
                    setTimeout(function() { $('.mxch-cg-left-tab[data-tab="meta"]').removeClass('mxch-cg-tab-flash'); }, 2000);
                }
            }).always(function() {
                idx++;
                runNext();
            });
        }
        runNext();
    }

    function resetSeoPanel() {
        seoState = { analyzed: false, analyzing: false, fixing: false, score: null, checks: null };
        $('#mxch-seo-score').text('\u2014');
        $('#mxch-seo-score-label').text('SEO Score');
        $('#mxch-seo-score-summary').text('Generate content to analyze');
        $('#mxch-seo-ring').css('stroke-dashoffset', '163.36').removeClass('mxch-seo-good mxch-seo-ok mxch-seo-bad');
        $('#mxch-seo-checklist').html(
            '<div class="mxch-seo-empty" id="mxch-seo-empty">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
            '<span>SEO analysis will appear here after content is generated</span></div>'
        );
        $('#mxch-seo-actions').hide();
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    // ─── Left Column Tabs ──────────────────────────────────────────────

    function initLeftTabs() {
        $(document).on('click', '.mxch-cg-left-tab', function() {
            var tab = $(this).data('tab');
            $('.mxch-cg-left-tab').removeClass('active');
            $(this).addClass('active');
            $('.mxch-cg-left-panel').removeClass('active');
            $('#mxch-cg-panel-' + tab).addClass('active');
        });

        // Character counter for meta description
        $(document).on('input', '#mxch-cg-meta-description', updateCharCount);
    }

    function populateMetaPanel(data) {
        $('#mxch-cg-meta-title').val(data.title || '');
        if (data.meta) {
            $('#mxch-cg-meta-description').val(data.meta.description || '');
            $('#mxch-cg-meta-keyword').val(data.meta.keyword || '');
            $('#mxch-cg-meta-excerpt').val(data.meta.excerpt || '');
        }
        updateCharCount();
    }

    function updateCharCount() {
        var len = ($('#mxch-cg-meta-description').val() || '').length;
        var $counter = $('.mxch-cg-meta-charcount');
        $counter.text(len + ' / 160');
        if (len > 160) {
            $counter.addClass('mxch-cg-meta-charcount-over');
        } else {
            $counter.removeClass('mxch-cg-meta-charcount-over');
        }
    }

    // ─── History Tab ──────────────────────────────────────────────────

    function initHistory() {
        $(document).on('click', '[data-target="content-history"]', function() {
            if (!state.historyLoaded) {
                loadHistory(1);
            }
        });

        $(document).on('click', '.mxch-cg-history-page-btn[data-page]', function() {
            var page = $(this).data('page');
            if (page && !state.historyLoading) {
                loadHistory(page);
            }
        });

        $(document).on('click', '.mxch-cg-history-edit-btn', function() {
            var postId = $(this).data('post-id');
            if (postId) {
                loadPostForEdit(postId, $(this));
            }
        });

        $(document).on('click', '.mxch-cg-history-delete-btn', function() {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var $item = $btn.closest('.mxch-cg-history-item');
            var title = $item.find('.mxch-cg-history-title').text();

            if (!confirm('Move "' + title + '" to trash?')) return;

            $btn.prop('disabled', true);
            $.ajax({
                url: mxchatContent.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mxchat_delete_content',
                    nonce: mxchatContent.nonce,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        $item.slideUp(200, function() { $(this).remove(); });
                    } else {
                        alert(response.data.message || 'Failed to delete.');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Request failed. Please try again.');
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    function loadHistory(page) {
        state.historyLoading = true;
        state.historyPage = page;

        var $loading = $('#mxch-cg-history-loading');
        var $empty   = $('#mxch-cg-history-empty');
        var $list    = $('#mxch-cg-history-list');
        var $pag     = $('#mxch-cg-history-pagination');

        $loading.show();
        $empty.hide();
        $list.hide();
        $pag.hide();

        $.ajax({
            url: mxchatContent.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mxchat_content_history',
                nonce: mxchatContent.nonce,
                page: page
            },
            success: function(response) {
                state.historyLoading = false;
                state.historyLoaded = true;
                $loading.hide();

                if (!response.success || !response.data.items.length) {
                    $empty.show();
                    return;
                }

                renderHistoryList(response.data.items);
                renderHistoryPagination(response.data.current_page, response.data.total_pages);
                $list.show();

                if (response.data.total_pages > 1) {
                    $pag.show();
                }
            },
            error: function() {
                state.historyLoading = false;
                $loading.hide();
                $empty.show();
            }
        });
    }

    function renderHistoryList(items) {
        var $list = $('#mxch-cg-history-list');
        $list.empty();

        var statusLabels = {
            draft: 'Draft',
            publish: 'Published',
            future: 'Scheduled',
            pending: 'Pending',
            'private': 'Private'
        };

        $.each(items, function(i, item) {
            var thumbHtml = item.thumbnail
                ? '<img src="' + escapeAttr(item.thumbnail) + '" alt="" class="mxch-cg-history-thumb-img">'
                : '<div class="mxch-cg-history-thumb-placeholder">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>' +
                  '</div>';

            var statusClass = 'mxch-cg-badge-' + item.status;
            var statusText = statusLabels[item.status] || item.status;
            var typeLabel = item.post_type === 'page' ? 'Page' : 'Post';

            var $row = $(
                '<div class="mxch-cg-history-item">' +
                    '<div class="mxch-cg-history-thumb">' + thumbHtml + '</div>' +
                    '<div class="mxch-cg-history-info">' +
                        '<div class="mxch-cg-history-title">' + escapeHtml(item.title) + '</div>' +
                        '<div class="mxch-cg-history-meta">' +
                            '<span class="mxch-cg-status-badge ' + statusClass + '">' + escapeHtml(statusText) + '</span>' +
                            '<span class="mxch-cg-history-type">' + escapeHtml(typeLabel) + '</span>' +
                            '<span class="mxch-cg-history-date">' + escapeHtml(item.date) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="mxch-cg-history-actions">' +
                        '<a href="' + escapeAttr(item.permalink) + '" target="_blank" class="mxch-cg-history-action-btn mxch-cg-history-view-btn" title="View">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
                        '</a>' +
                        '<button type="button" class="mxch-cg-history-action-btn mxch-cg-history-edit-btn" data-post-id="' + item.post_id + '" title="Edit">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>' +
                        '</button>' +
                        '<button type="button" class="mxch-cg-history-action-btn mxch-cg-history-delete-btn" data-post-id="' + item.post_id + '" title="Delete">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                        '</button>' +
                    '</div>' +
                '</div>'
            );

            $list.append($row);
        });
    }

    function renderHistoryPagination(current, total) {
        var $pag = $('#mxch-cg-history-pagination');
        $pag.empty();

        if (total <= 1) return;

        var html = '';

        if (current > 1) {
            html += '<button type="button" class="mxch-cg-history-page-btn mxch-cg-history-page-prev" data-page="' + (current - 1) + '">&laquo; Prev</button>';
        }

        for (var p = 1; p <= total; p++) {
            if (p === current) {
                html += '<span class="mxch-cg-history-page-btn mxch-cg-history-page-current">' + p + '</span>';
            } else if (p === 1 || p === total || (p >= current - 1 && p <= current + 1)) {
                html += '<button type="button" class="mxch-cg-history-page-btn" data-page="' + p + '">' + p + '</button>';
            } else if (p === current - 2 || p === current + 2) {
                html += '<span class="mxch-cg-history-page-ellipsis">&hellip;</span>';
            }
        }

        if (current < total) {
            html += '<button type="button" class="mxch-cg-history-page-btn mxch-cg-history-page-next" data-page="' + (current + 1) + '">Next &raquo;</button>';
        }

        $pag.html(html);
    }

    function loadPostForEdit(postId, $btn) {
        var editBtnHtml = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Edit';

        $btn.prop('disabled', true).text('Loading...');

        $.ajax({
            url: mxchatContent.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mxchat_load_post_for_edit',
                nonce: mxchatContent.nonce,
                post_id: postId
            },
            success: function(response) {
                $btn.prop('disabled', false).html(editBtnHtml);

                if (response.success) {
                    // Switch to Generate tab
                    switchSection('content-generate');
                    $('.mxch-nav-link, .mxch-nav-sub-link').removeClass('active');
                    $('[data-target="content-generate"]').addClass('active');
                    $('.mxch-mobile-nav-link').removeClass('active');
                    $('.mxch-mobile-nav-link[data-target="content-generate"]').addClass('active');

                    // Load post into the same editor state as fresh generation
                    onGenerationSuccess(response.data);
                } else {
                    alert(response.data && response.data.message ? response.data.message : 'Failed to load post.');
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(editBtnHtml);
                alert('Request failed. Please try again.');
            }
        });
    }

    // ─── Status Dropdown ──────────────────────────────────────────────

    function initStatusDropdown() {
        // Toggle dropdown on badge click
        $(document).on('click', '#mxch-cg-status-badge', function(e) {
            e.stopPropagation();
            var $dropdown = $('#mxch-cg-status-dropdown');
            if ($dropdown.hasClass('mxch-cg-dropdown-open')) {
                closeStatusDropdown();
            } else {
                openStatusDropdown();
            }
        });

        // Close on outside click
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#mxch-cg-status-dropdown').length) {
                closeStatusDropdown();
            }
        });

        // Close on Escape
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStatusDropdown();
            }
        });

        // Draft / Publish — immediate status change
        $(document).on('click', '.mxch-cg-status-option[data-status="draft"], .mxch-cg-status-option[data-status="publish"]', function() {
            var newStatus = $(this).data('status');
            if (newStatus === state.postStatus) {
                closeStatusDropdown();
                return;
            }
            updatePostStatus(newStatus, '');
        });

        // Scheduled — show datetime picker
        $(document).on('click', '.mxch-cg-status-option[data-status="future"]', function() {
            var $scheduleRow = $('.mxch-cg-status-schedule-row');
            if ($scheduleRow.is(':visible')) {
                $scheduleRow.hide();
                return;
            }
            // Pre-fill with tomorrow at 9am if empty
            var $input = $('#mxch-cg-status-schedule-input');
            if (!$input.val()) {
                var tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                tomorrow.setHours(9, 0, 0, 0);
                $input.val(tomorrow.toISOString().slice(0, 16));
            }
            $scheduleRow.show();
            $input.focus();
            // Highlight scheduled option
            $('.mxch-cg-status-option').removeClass('mxch-cg-status-active');
            $(this).addClass('mxch-cg-status-active');
        });

        // Confirm schedule
        $(document).on('click', '#mxch-cg-status-schedule-confirm', function() {
            var scheduleDate = $('#mxch-cg-status-schedule-input').val();
            if (!scheduleDate) {
                $('#mxch-cg-status-schedule-input').focus();
                return;
            }
            // Convert datetime-local value to WordPress format (Y-m-d H:i:s)
            var wpDate = scheduleDate.replace('T', ' ') + ':00';
            updatePostStatus('future', wpDate);
        });

        // Enter key on datetime input confirms
        $(document).on('keydown', '#mxch-cg-status-schedule-input', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#mxch-cg-status-schedule-confirm').trigger('click');
            }
        });
    }

    function openStatusDropdown() {
        var $dropdown = $('#mxch-cg-status-dropdown');
        $dropdown.addClass('mxch-cg-dropdown-open');
        $('.mxch-cg-status-menu').show();
        // Highlight current status
        $('.mxch-cg-status-option').removeClass('mxch-cg-status-active');
        $('.mxch-cg-status-option[data-status="' + state.postStatus + '"]').addClass('mxch-cg-status-active');
        // Hide schedule row unless current status is future
        if (state.postStatus !== 'future') {
            $('.mxch-cg-status-schedule-row').hide();
        }
    }

    function closeStatusDropdown() {
        $('#mxch-cg-status-dropdown').removeClass('mxch-cg-dropdown-open');
        $('.mxch-cg-status-menu').hide();
        $('.mxch-cg-status-schedule-row').hide();
    }

    function updatePostStatus(newStatus, scheduleDate) {
        var $badge = $('#mxch-cg-status-badge');
        $badge.addClass('mxch-cg-status-updating');
        closeStatusDropdown();

        $.ajax({
            url: mxchatContent.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mxchat_update_post_status',
                nonce: mxchatContent.nonce,
                post_id: state.postId,
                new_status: newStatus,
                schedule_date: scheduleDate || ''
            },
            success: function(response) {
                $badge.removeClass('mxch-cg-status-updating');

                if (response.success) {
                    var confirmedStatus = response.data.status;
                    state.postStatus = confirmedStatus;

                    // Update badge appearance
                    var statusLabels = { draft: 'Draft', publish: 'Published', future: 'Scheduled' };
                    $badge.find('.mxch-cg-status-badge-text').text(statusLabels[confirmedStatus] || confirmedStatus);
                    $badge.removeClass('mxch-cg-badge-draft mxch-cg-badge-publish mxch-cg-badge-future')
                          .addClass('mxch-cg-badge-' + confirmedStatus);

                    // Mark history as stale so it reloads on next visit
                    state.historyLoaded = false;

                    // Refresh preview (URL may differ between draft/published)
                    refreshPreview();
                } else {
                    alert(response.data && response.data.message ? response.data.message : 'Failed to update status.');
                }
            },
            error: function() {
                $badge.removeClass('mxch-cg-status-updating');
                alert('Request failed. Please try again.');
            }
        });
    }

    // ─── Utilities ─────────────────────────────────────────────────────

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ─── SEO Dashboard (Site-wide) ─────────────────────────────────────

    var seodState = {
        loaded: false,
        loading: false,
        page: 1,
        pages: 1,
        total: 0,
        filter: 'all',
        postType: 'any',
        search: '',
        searchTimer: null,
        scanning: false,
        expandedId: null,
        expandAnalyzing: false,
        sortBy: 'date',
        sortOrder: 'DESC',
    };

    function initSeoSection() {
        // Lazy-load: fetch posts when user first visits SEO section
        $(document).on('click', '.mxch-nav-link[data-target="content-seo"], .mxch-nav-sub-link[data-target="content-seo"], .mxch-mobile-nav-link[data-target="content-seo"]', function() {
            if (!seodState.loaded && !seodState.loading) {
                loadSeoPosts();
            }
        });

        // Filter pills
        $(document).on('click', '.mxch-seod-pill', function() {
            $('.mxch-seod-pill').removeClass('active');
            $(this).addClass('active');
            seodState.filter = $(this).data('filter');
            seodState.page = 1;
            loadSeoPosts();
        });

        // Post type dropdown
        $(document).on('change', '#mxch-seod-post-type', function() {
            seodState.postType = $(this).val();
            seodState.page = 1;
            loadSeoPosts();
        });

        // Search with debounce
        $(document).on('input', '#mxch-seod-search', function() {
            var val = $(this).val();
            clearTimeout(seodState.searchTimer);
            seodState.searchTimer = setTimeout(function() {
                seodState.search = val;
                seodState.page = 1;
                loadSeoPosts();
            }, 400);
        });

        // Pagination
        $(document).on('click', '.mxch-seod-page-btn', function() {
            var p = $(this).data('page');
            if (p && p !== seodState.page) {
                seodState.page = p;
                loadSeoPosts();
            }
        });

        // Open detail modal on row click
        $(document).on('click', '.mxch-seod-row', function() {
            var postId = $(this).data('post-id');
            openSeoModal(postId);
        });

        // Close modal
        $(document).on('click', '.mxch-seod-modal-overlay', function(e) {
            if ($(e.target).hasClass('mxch-seod-modal-overlay')) closeSeoModal();
        });
        $(document).on('click', '.mxch-seod-modal-close', function() {
            closeSeoModal();
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && seodState.expandedId) closeSeoModal();
        });

        // Scan Unscored button
        $(document).on('click', '#mxch-seod-scan-all', function() {
            if (!seodState.scanning) bulkSeoScan();
        });
        // Stop scan button
        $(document).on('click', '#mxch-seod-scan-stop', function() {
            seodState.scanAborted = true;
            $(this).prop('disabled', true).find('span').text('Stopping...');
        });

        // Sortable column headers (all columns sort server-side)
        $(document).on('click', '.mxch-seod-header-cell[data-sort]', function() {
            var col = $(this).data('sort');
            if (seodState.sortBy === col) {
                seodState.sortOrder = seodState.sortOrder === 'DESC' ? 'ASC' : 'DESC';
            } else {
                seodState.sortBy = col;
                seodState.sortOrder = col === 'title' ? 'ASC' : 'DESC';
            }
            seodState.page = 1;
            loadSeoPosts();
        });

        // AI Optimize within detail modal
        $(document).on('click', '.mxch-seod-optimize-btn', function(e) {
            e.stopPropagation();
            var postId = $(this).closest('.mxch-seod-detail').data('post-id');
            runSeodOptimize(postId, $(this));
        });

        // Per-check AI fix buttons in detail modal
        $(document).on('click', '.mxch-seod-check-fix-btn', function(e) {
            e.stopPropagation();
            var $btn = $(this);
            if ($btn.hasClass('mxch-seo-check-fixing')) return;
            var field = $btn.data('field');
            var postId = $btn.data('post-id');
            $btn.addClass('mxch-seo-check-fixing').prop('disabled', true);
            $.post(ajaxurl, {
                action: 'mxchat_seo_suggest',
                nonce: mxchatContent.nonce,
                post_id: postId,
                field: field,
            }).always(function() {
                $btn.removeClass('mxch-seo-check-fixing').prop('disabled', false);
                if (seodState.expandedId === postId) {
                    openSeoModal(postId);
                }
            });
        });

        // Checkbox: prevent row click when clicking checkbox
        $(document).on('click', '.mxch-seod-cell-check, .mxch-seod-header-check', function(e) {
            e.stopPropagation();
        });

        // Select all checkbox
        $(document).on('change', '.mxch-seod-check-all', function() {
            var checked = $(this).prop('checked');
            $('.mxch-seod-row-check').prop('checked', checked);
            updateOptimizeSelectedBtn();
        });

        // Individual row checkbox
        $(document).on('change', '.mxch-seod-row-check', function() {
            var allChecked = $('.mxch-seod-row-check').length === $('.mxch-seod-row-check:checked').length;
            $('.mxch-seod-check-all').prop('checked', allChecked);
            updateOptimizeSelectedBtn();
        });
    }

    function updateOptimizeSelectedBtn() {
        var count = $('.mxch-seod-row-check:checked').length;
        var $btn = $('#mxch-seod-optimize-selected');
        var $note = $('#mxch-seod-bulk-note');
        var isLocked = $btn.hasClass('mxch-seod-bulk-locked');
        if (count > 0) {
            $btn.find('span').first().text(isLocked ? 'Bulk Optimize' : 'Optimize Selected (' + count + ')');
            $btn.show();
            if (isLocked) $note.show();
        } else {
            $btn.hide();
            $note.hide();
        }
    }

    function loadSeoPosts() {
        seodState.loading = true;
        $('#mxch-seod-loading').show();
        $('#mxch-seod-empty').hide();
        $('#mxch-seod-table').empty();

        $.post(ajaxurl, {
            action: 'mxchat_seo_list_posts',
            nonce: mxchatContent.nonce,
            page: seodState.page,
            post_type: seodState.postType,
            filter: seodState.filter,
            search: seodState.search,
            sort_by: seodState.sortBy,
            sort_order: seodState.sortOrder,
        }).done(function(res) {
            if (res.success) {
                seodState.loaded = true;
                seodState.page = res.data.page;
                seodState.pages = res.data.pages;
                seodState.total = res.data.total;
                renderSeodTable(res.data.posts);
                renderSeodPagination();
                updateSeodScanBtn(res.data.unscored_count);
                $('#mxch-seod-footer').show();
                if (!res.data.posts.length) {
                    $('#mxch-seod-empty').show();
                }
            }
        }).fail(function() {
            $('#mxch-seod-table').html('<div class="mxch-seod-error">Failed to load posts. Please try again.</div>');
        }).always(function() {
            seodState.loading = false;
            $('#mxch-seod-loading').hide();
        });
    }

    function seodFormatNum(n) {
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
        return n;
    }

    function seodSortArrow(col) {
        if (seodState.sortBy !== col) return '';
        return ' <span class="mxch-seod-sort-arrow">' + (seodState.sortOrder === 'ASC' ? '&#9650;' : '&#9660;') + '</span>';
    }

    function renderSeodTable(posts) {
        var $table = $('#mxch-seod-table');
        $table.empty();
        seodState.expandedId = null;

        // Column headers
        var activeClass = function(col) { return seodState.sortBy === col ? ' mxch-seod-header-active' : ''; };
        $table.append(
            '<div class="mxch-seod-header">' +
                '<div class="mxch-seod-header-cell mxch-seod-header-check"><input type="checkbox" class="mxch-seod-check-all" title="Select all"></div>' +
                '<div class="mxch-seod-header-cell mxch-seod-header-title' + activeClass('title') + '" data-sort="title">Title' + seodSortArrow('title') + '</div>' +
                '<div class="mxch-seod-header-cell mxch-seod-header-date' + activeClass('date') + '" data-sort="date">Date' + seodSortArrow('date') + '</div>' +
                '<div class="mxch-seod-header-cell mxch-seod-header-score' + activeClass('score') + '" data-sort="score">Score' + seodSortArrow('score') + '</div>' +
                '<div class="mxch-seod-header-cell mxch-seod-header-clicks' + (!mxchatContent.hasGSC ? ' mxch-seod-header-locked' : '') + '"' + (mxchatContent.hasGSC ? ' data-sort="clicks"' : '') + '>Clicks' + (mxchatContent.hasGSC ? seodSortArrow('clicks') : ' <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>') + '</div>' +
                '<div class="mxch-seod-header-cell mxch-seod-header-impressions' + (!mxchatContent.hasGSC ? ' mxch-seod-header-locked' : '') + '"' + (mxchatContent.hasGSC ? ' data-sort="impressions"' : '') + '>Impr.' + (mxchatContent.hasGSC ? seodSortArrow('impressions') : ' <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>') + '</div>' +
            '</div>'
        );

        posts.forEach(function(p) {
            var scoreHtml;
            if (p.score !== null) {
                var cls = p.score >= 80 ? 'mxch-seod-good' : p.score >= 50 ? 'mxch-seod-ok' : 'mxch-seod-bad';
                scoreHtml = '<div class="mxch-seod-score-badge ' + cls + '">' + p.score + '</div>';
            } else {
                scoreHtml = '<div class="mxch-seod-score-badge mxch-seod-unscored">&mdash;</div>';
            }

            var typeLabel = p.type.charAt(0).toUpperCase() + p.type.slice(1);

            $table.append(
                '<div class="mxch-seod-row" data-post-id="' + p.id + '" data-score="' + (p.score !== null ? p.score : -1) + '" data-permalink="' + escapeAttr(p.permalink) + '">' +
                    '<div class="mxch-seod-row-main">' +
                        '<div class="mxch-seod-cell mxch-seod-cell-check"><input type="checkbox" class="mxch-seod-row-check" data-post-id="' + p.id + '"></div>' +
                        '<div class="mxch-seod-cell mxch-seod-cell-title">' +
                            '<span class="mxch-seod-title">' + escapeHtml(p.title) + '</span>' +
                            '<span class="mxch-seod-meta">' + typeLabel + '</span>' +
                        '</div>' +
                        '<div class="mxch-seod-cell mxch-seod-cell-date">' +
                            '<span class="mxch-seod-date">' + escapeHtml(p.date) + '</span>' +
                        '</div>' +
                        '<div class="mxch-seod-cell mxch-seod-cell-score">' + scoreHtml + '</div>' +
                        '<div class="mxch-seod-cell mxch-seod-cell-clicks' + (!mxchatContent.hasGSC ? ' mxch-seod-cell-locked' : '') + '" data-clicks="' + (p.clicks !== null ? p.clicks : 0) + '">' + (!mxchatContent.hasGSC ? '—' : (p.clicks !== null ? p.clicks : '—')) + '</div>' +
                        '<div class="mxch-seod-cell mxch-seod-cell-impressions' + (!mxchatContent.hasGSC ? ' mxch-seod-cell-locked' : '') + '" data-impressions="' + (p.impressions !== null ? p.impressions : 0) + '">' + (!mxchatContent.hasGSC ? '—' : (p.impressions !== null ? seodFormatNum(p.impressions) : '—')) + '</div>' +
                    '</div>' +
                '</div>'
            );
        });
    }

    function renderSeodPagination() {
        var $pag = $('#mxch-seod-pagination');
        $pag.empty();

        if (seodState.pages <= 1) return;

        var p = seodState.page, total = seodState.pages;

        if (p > 1) {
            $pag.append('<button type="button" class="mxch-seod-page-btn" data-page="' + (p - 1) + '">&larr; Prev</button>');
        }
        $pag.append('<span class="mxch-seod-page-info">Page ' + p + ' of ' + total + '</span>');
        if (p < total) {
            $pag.append('<button type="button" class="mxch-seod-page-btn" data-page="' + (p + 1) + '">Next &rarr;</button>');
        }
    }

    function updateSeodScanBtn(unscoredCount) {
        var $btn = $('#mxch-seod-scan-all');
        if (unscoredCount > 0) {
            $btn.show().text('Scan Unscored (' + unscoredCount + ')');
        } else {
            $btn.hide();
        }
        $('#mxch-seod-scan-status').text('');
    }

    function openSeoModal(postId) {
        closeSeoModal(); // close any existing modal
        seodState.expandedId = postId;

        var $row = $('.mxch-seod-row[data-post-id="' + postId + '"]');
        var title = $row.find('.mxch-seod-title').text() || 'Post #' + postId;
        var permalink = $row.attr('data-permalink') || '';

        var $overlay = $(
            '<div class="mxch-seod-modal-overlay">' +
                '<div class="mxch-seod-modal">' +
                    '<div class="mxch-seod-modal-header">' +
                        '<h3 class="mxch-seod-modal-title">' + escapeHtml(title) + '</h3>' +
                        (permalink ? '<a href="' + escapeAttr(permalink) + '" target="_blank" class="mxch-seod-modal-view-page" title="View Page">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
                            ' View Page</a>' : '') +
                        '<button type="button" class="mxch-seod-modal-close" title="Close">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
                        '</button>' +
                    '</div>' +
                    '<div class="mxch-seod-modal-body">' +
                        '<div class="mxch-seod-detail" data-post-id="' + postId + '">' +
                            '<div class="mxch-seod-detail-loading">' +
                                '<div class="mxch-seod-spinner"></div>' +
                                '<span>Analyzing&hellip;</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        $('body').append($overlay);
        // Trigger reflow then add visible class for animation
        $overlay[0].offsetHeight;
        $overlay.addClass('mxch-seod-modal-visible');

        var $detail = $overlay.find('.mxch-seod-detail');

        // Run analysis
        seodState.expandAnalyzing = true;
        $.post(ajaxurl, {
            action: 'mxchat_seo_analyze',
            nonce: mxchatContent.nonce,
            post_id: postId,
        }).done(function(res) {
            if (res.success) {
                renderSeodDetail($detail, res.data, postId);
                // Update the row's score badge in the table too
                var score = res.data.score;
                var cls = score >= 80 ? 'mxch-seod-good' : score >= 50 ? 'mxch-seod-ok' : 'mxch-seod-bad';
                $row.find('.mxch-seod-score-badge')
                    .removeClass('mxch-seod-good mxch-seod-ok mxch-seod-bad mxch-seod-unscored')
                    .addClass(cls).text(score);
            } else {
                $detail.html('<div class="mxch-seod-detail-error">Analysis failed. Please try again.</div>');
            }
        }).fail(function() {
            $detail.html('<div class="mxch-seod-detail-error">Connection error. Please try again.</div>');
        }).always(function() {
            seodState.expandAnalyzing = false;
        });
    }

    function renderSeodDetail($detail, data, postId) {
        var checks = data.checks, score = data.score, summary = data.summary;
        var icons = {
            pass: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            warn: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            fail: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        };

        // Checks that require the Advanced Content Editor add-on to fix
        var addonChecks = { readability: true, internal_links: true, img_alt: true, featured_img: true };
        var fixableMap = { meta_desc: 'meta_description', title_length: 'seo_title', slug: 'slug', readability: 'readability', internal_links: 'internal_links', img_alt: 'img_alt', featured_img: 'featured_img' };
        var sparkleIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.287 1.288L3 12l5.8 1.9a2 2 0 0 1 1.288 1.287L12 21l1.9-5.8a2 2 0 0 1 1.287-1.288L21 12l-5.8-1.9a2 2 0 0 1-1.288-1.287Z"/></svg>';

        var sorted = Object.keys(checks).sort(function(a, b) {
            var o = { fail: 0, warn: 1, pass: 2 };
            return (o[checks[a].status] || 2) - (o[checks[b].status] || 2);
        });

        var html = '<div class="mxch-seod-checks">';
        var last = null;
        sorted.forEach(function(key) {
            var c = checks[key];
            if (last && last !== 'pass' && c.status === 'pass') {
                html += '<div class="mxch-seod-check-sep"></div>';
            }
            last = c.status;

            // Show addon/pro badge for gated checks that aren't passing
            var badge = '';
            if (addonChecks[key] && c.status !== 'pass' && !mxchatContent.hasAdvancedContent) {
                if (mxchatContent.isActivated) {
                    badge = ' <a href="https://mxchat.ai/advanced-content-editor/" target="_blank" class="mxch-seod-addon-badge">ADD-ON</a>';
                } else {
                    badge = ' <a href="https://mxchat.ai/" target="_blank" class="mxch-seod-addon-badge mxch-seod-pro-badge">PRO</a>';
                }
            }

            // Per-check AI fix button
            var fixBtn = '';
            if (c.status !== 'pass' && fixableMap[key]) {
                var canFix = !addonChecks[key] || mxchatContent.hasAdvancedContent;
                if (canFix) {
                    fixBtn = '<button type="button" class="mxch-seo-check-fix mxch-seod-check-fix-btn" data-field="' + fixableMap[key] + '" data-post-id="' + postId + '" title="AI Fix">' + sparkleIcon + '</button>';
                }
            }

            html += '<div class="mxch-seod-check mxch-seod-check-' + c.status + '">' +
                '<div class="mxch-seod-check-icon">' + icons[c.status] + '</div>' +
                '<div class="mxch-seod-check-text">' +
                    '<span class="mxch-seod-check-label">' + escapeHtml(c.label) + badge + '</span>' +
                    '<span class="mxch-seod-check-detail">' + escapeHtml(c.detail) + '</span>' +
                '</div>' +
                fixBtn +
            '</div>';
        });
        html += '</div>';

        // Optimize All button (only if there are issues)
        if (summary.fail > 0 || summary.warn > 0) {
            html += '<div class="mxch-seod-detail-actions">' +
                '<button type="button" class="mxch-seod-optimize-btn">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.287 1.288L3 12l5.8 1.9a2 2 0 0 1 1.288 1.287L12 21l1.9-5.8a2 2 0 0 1 1.287-1.288L21 12l-5.8-1.9a2 2 0 0 1-1.288-1.287Z"/></svg>' +
                    ' Optimize All' +
                '</button>' +
            '</div>';
        }

        $detail.html(html);

        // GSC placeholder for free/non-addon users
        if (!mxchatContent.hasGSC) {
            var badgeLabel = mxchatContent.isActivated ? 'ADD-ON' : 'PRO';
            var badgeClass = mxchatContent.isActivated ? '' : ' mxch-seod-pro-badge';
            var upgradeText = mxchatContent.isActivated ? 'Install Add-on' : 'Upgrade to Pro';
            var gscHtml =
                '<div class="mxch-gsc-placeholder">' +
                    '<h4 class="mxch-gsc-placeholder-title">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' +
                        ' Search Performance' +
                    '</h4>' +
                    '<div class="mxch-gsc-placeholder-content">' +
                        '<div class="mxch-gsc-stats">' +
                            '<div class="mxch-gsc-stat"><span class="mxch-gsc-stat-value">42</span><span class="mxch-gsc-stat-label">Clicks</span></div>' +
                            '<div class="mxch-gsc-stat"><span class="mxch-gsc-stat-value">1.2K</span><span class="mxch-gsc-stat-label">Impressions</span></div>' +
                            '<div class="mxch-gsc-stat"><span class="mxch-gsc-stat-value">3.5%</span><span class="mxch-gsc-stat-label">CTR</span></div>' +
                            '<div class="mxch-gsc-stat"><span class="mxch-gsc-stat-value">8.2</span><span class="mxch-gsc-stat-label">Avg Position</span></div>' +
                        '</div>' +
                        '<table class="mxch-gsc-table">' +
                            '<thead><tr><th>Keyword</th><th>Clicks</th><th>Impr.</th><th>Position</th></tr></thead>' +
                            '<tbody>' +
                                '<tr><td>example keyword one</td><td>18</td><td>420</td><td>5.3</td></tr>' +
                                '<tr><td>sample search term</td><td>14</td><td>380</td><td>7.1</td></tr>' +
                                '<tr><td>another query phrase</td><td>10</td><td>290</td><td>12.4</td></tr>' +
                            '</tbody>' +
                        '</table>' +
                    '</div>' +
                    '<div class="mxch-gsc-placeholder-overlay">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' +
                        '<a href="https://mxchat.ai/" target="_blank" class="mxch-seod-addon-badge' + badgeClass + '">' + badgeLabel + '</a>' +
                        '<a href="https://mxchat.ai/" target="_blank" class="mxch-cg-pro-upgrade-link">' + upgradeText + '</a>' +
                    '</div>' +
                '</div>';
            $detail.append(gscHtml);
        }
    }

    function closeSeoModal() {
        seodState.expandedId = null;
        var $overlay = $('.mxch-seod-modal-overlay');
        if ($overlay.length) {
            $overlay.removeClass('mxch-seod-modal-visible');
            setTimeout(function() { $overlay.remove(); }, 200);
        }
    }

    function bulkSeoScan() {
        seodState.scanning = true;
        seodState.scanAborted = false;
        var $btn = $('#mxch-seod-scan-all');
        var $status = $('#mxch-seod-scan-status');
        $btn.hide();

        // Show stop button
        if (!$('#mxch-seod-scan-stop').length) {
            $btn.after('<button type="button" class="mxch-seod-scan-btn mxch-seod-scan-stop-btn" id="mxch-seod-scan-stop"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="6" width="12" height="12" rx="2"/></svg> <span>Stop</span></button>');
        }
        $('#mxch-seod-scan-stop').show();
        $status.text('Loading unscored posts...');

        // Fetch ALL unscored post IDs across all pages
        var allIds = [];
        function fetchPage(page) {
            $.post(ajaxurl, {
                action: 'mxchat_seo_list_posts',
                nonce: mxchatContent.nonce,
                page: page,
                post_type: 'any',
                filter: 'unscored',
                search: '',
            }).done(function(res) {
                if (!res.success || !res.data.posts.length) {
                    if (allIds.length === 0) {
                        finishScan('All posts have been scanned.');
                        return;
                    }
                    startScanning(allIds);
                    return;
                }
                res.data.posts.forEach(function(p) { allIds.push(p.id); });
                if (page < res.data.pages) {
                    $status.text('Loading unscored posts... (' + allIds.length + ' found)');
                    fetchPage(page + 1);
                } else {
                    startScanning(allIds);
                }
            }).fail(function() {
                finishScan('Error loading posts.');
            });
        }

        function startScanning(ids) {
            var total = ids.length;
            var scanned = 0;
            var batchSize = 10;
            $status.html('<span class="mxch-seod-scan-progress">0 / ' + total + '</span>');

            function updateRows(results) {
                $.each(results, function(pid, data) {
                    var $row = $('.mxch-seod-row[data-post-id="' + pid + '"]');
                    if ($row.length) {
                        var score = data.score;
                        var cls = score >= 80 ? 'mxch-seod-good' : score >= 50 ? 'mxch-seod-ok' : 'mxch-seod-bad';
                        $row.find('.mxch-seod-score-badge')
                            .removeClass('mxch-seod-unscored').addClass(cls).text(score);
                    }
                });
            }

            function scanNextBatch() {
                if (seodState.scanAborted) {
                    finishScan('Stopped — ' + scanned + ' of ' + total + ' scanned.');
                    loadSeoPosts();
                    return;
                }
                if (scanned >= total) {
                    finishScan('Done! ' + total + ' posts scanned.');
                    loadSeoPosts();
                    return;
                }
                var batch = ids.slice(scanned, scanned + batchSize);
                $status.html('<span class="mxch-seod-scan-progress">' + (scanned + 1) + ' / ' + total + '</span>');
                $.post(ajaxurl, {
                    action: 'mxchat_seo_analyze_batch',
                    nonce: mxchatContent.nonce,
                    'post_ids[]': batch,
                }).done(function(res) {
                    if (res.success && res.data.results) {
                        updateRows(res.data.results);
                    }
                }).always(function() {
                    scanned += batch.length;
                    $status.html('<span class="mxch-seod-scan-progress">' + scanned + ' / ' + total + '</span>');
                    scanNextBatch();
                });
            }
            scanNextBatch();
        }

        function finishScan(msg) {
            seodState.scanning = false;
            seodState.scanAborted = false;
            $('#mxch-seod-scan-stop').hide();
            $btn.show().prop('disabled', false);
            $status.text(msg);
        }

        fetchPage(1);
    }

    function runSeodOptimize(postId, $btn) {
        var origHtml = $btn.html();
        $btn.prop('disabled', true).html(
            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.287 1.288L3 12l5.8 1.9a2 2 0 0 1 1.288 1.287L12 21l1.9-5.8a2 2 0 0 1 1.287-1.288L21 12l-5.8-1.9a2 2 0 0 1-1.288-1.287Z"/></svg>' +
            ' Optimizing&hellip;'
        );

        // Get the current checks to find what needs fixing
        $.post(ajaxurl, {
            action: 'mxchat_seo_analyze',
            nonce: mxchatContent.nonce,
            post_id: postId,
        }).done(function(res) {
            if (!res.success) {
                $btn.prop('disabled', false).html(origHtml);
                return;
            }
            var checks = res.data.checks;
            var prefs = mxchatContent.seoOptimize || {};
            var fields = [];
            if (prefs.meta_description !== false && checks.meta_desc && checks.meta_desc.status !== 'pass') fields.push('meta_description');
            if (prefs.seo_title !== false && checks.title_length && checks.title_length.status !== 'pass') fields.push('seo_title');
            if (prefs.slug !== false && checks.slug && checks.slug.status !== 'pass') fields.push('slug');
            // Readability, internal links, images require Advanced Content Editor add-on
            if (mxchatContent.hasAdvancedContent) {
                if (prefs.readability !== false && checks.readability && checks.readability.status !== 'pass') fields.push('readability');
                if (prefs.internal_links !== false && checks.internal_links && checks.internal_links.status !== 'pass') fields.push('internal_links');
                if (prefs.img_alt !== false && checks.img_alt && checks.img_alt.status !== 'pass') fields.push('img_alt');
                if (prefs.featured_img !== false && checks.featured_img && checks.featured_img.status !== 'pass') fields.push('featured_img');
            }
            if (!fields.length) fields.push('meta_description');

            // Run fields sequentially to avoid race conditions
            // (multiple optimizers read/write post_content)
            var idx = 0;
            function runNext() {
                if (idx >= fields.length) {
                    if (seodState.expandedId === postId) {
                        openSeoModal(postId);
                    }
                    $btn.prop('disabled', false).html(origHtml);
                    return;
                }
                $.post(ajaxurl, {
                    action: 'mxchat_seo_suggest',
                    nonce: mxchatContent.nonce,
                    post_id: postId,
                    field: fields[idx],
                }).always(function() {
                    idx++;
                    runNext();
                });
            }
            runNext();
        }).fail(function() {
            $btn.prop('disabled', false).html(origHtml);
        });
    }

    function showNotice(message, type) {
        $('.mxch-cg-notice').remove();
        var typeClass = type === 'error' ? 'mxch-cg-notice-error' : 'mxch-cg-notice-success';
        var $notice = $('<div class="mxch-cg-notice ' + typeClass + '">' + escapeHtml(message) + '</div>');
        $('#mxch-cg-inline-form .mxch-cg-form').prepend($notice);
        setTimeout(function() { $notice.fadeOut(300, function() { $(this).remove(); }); }, 4000);
    }

    // ─── Initialize ────────────────────────────────────────────────────

    $(document).ready(function() {
        initNavigation();
        initInlineForm();
        initGeneration();
        initPromptModal();
        initPreview();
        initChat();
        initSettingsAutoSave();
        initLeftTabs();
        initSeo();
        initSeoSection();
        initHistory();
        initStatusDropdown();

        // Prevent interaction with locked pro feature toggles
        $('.mxch-cg-pro-locked .mxch-toggle-input').on('click', function(e) {
            e.preventDefault();
            return false;
        });
    });

})(jQuery);
