/**
 * MxChat Transcripts Page JavaScript - v3.0
 * Split-panel layout with chat list and conversation view
 */
jQuery(document).ready(function($) {
    // ==========================================================================
    // Sidebar Navigation
    // ==========================================================================

    // Desktop sidebar navigation
    $('.mxch-nav-link').on('click', function(e) {
        e.preventDefault();
        const target = $(this).data('target');

        // Update active states
        $('.mxch-nav-link').removeClass('active');
        $(this).addClass('active');

        // Show target section
        $('.mxch-section').removeClass('active');
        $('#' + target).addClass('active');

        // Reset scroll position of content area
        $('.mxch-content').scrollTop(0);

        // Also update mobile nav if open
        $('.mxch-mobile-nav-link').removeClass('active');
        $('.mxch-mobile-nav-link[data-target="' + target + '"]').addClass('active');

        // Load transcripts when switching to all-chats
        if (target === 'all-chats' && !transcriptsLoaded) {
            loadChatList(1, '');
        }
    });

    // Mobile menu toggle
    $('.mxch-mobile-menu-btn').on('click', function() {
        $('.mxch-mobile-menu').addClass('open');
        $('.mxch-mobile-overlay').addClass('open');
    });

    // Close mobile menu
    $('.mxch-mobile-menu-close, .mxch-mobile-overlay').on('click', function() {
        $('.mxch-mobile-menu').removeClass('open');
        $('.mxch-mobile-overlay').removeClass('open');
    });

    // Mobile navigation
    $('.mxch-mobile-nav-link').on('click', function(e) {
        e.preventDefault();
        const target = $(this).data('target');

        $('.mxch-mobile-nav-link').removeClass('active');
        $(this).addClass('active');

        $('.mxch-section').removeClass('active');
        $('#' + target).addClass('active');

        // Reset scroll position of content area
        $('.mxch-content').scrollTop(0);

        $('.mxch-nav-link').removeClass('active');
        $('.mxch-nav-link[data-target="' + target + '"]').addClass('active');

        $('.mxch-mobile-menu').removeClass('open');
        $('.mxch-mobile-overlay').removeClass('open');
    });

    // Quick action buttons
    $('.mxch-quick-action-btn[data-action="view-chats"]').on('click', function() {
        $('.mxch-nav-link[data-target="all-chats"]').trigger('click');
    });

    $('.mxch-quick-action-btn[data-action="settings"]').on('click', function() {
        $('.mxch-nav-link[data-target="notifications"]').trigger('click');
    });

    // ==========================================================================
    // Mobile Panel Management
    // ==========================================================================

    function isMobile() {
        return window.innerWidth <= 782;
    }

    function showMobileConversationPanel() {
        if (isMobile()) {
            $('.mxch-chat-list-panel').addClass('panel-hidden');
            $('#mxch-conversation-panel').addClass('panel-active');
            $('#mxch-transcript-back-btn').show();
        }
    }

    function hideMobileConversationPanel() {
        if (isMobile()) {
            $('#mxch-conversation-panel').removeClass('panel-active');
            $('.mxch-chat-list-panel').removeClass('panel-hidden');
            $('#mxch-transcript-back-btn').hide();
        }
    }

    // Mobile back button handler
    $('#mxch-transcript-back-btn').on('click', function(e) {
        e.preventDefault();
        hideMobileConversationPanel();
        $('.mxch-chat-item').removeClass('active');
        currentSessionId = null;
    });

    // Handle window resize
    $(window).on('resize', function() {
        if (!isMobile()) {
            // Reset panel states when switching to desktop
            $('.mxch-chat-list-panel').removeClass('panel-hidden');
            $('#mxch-conversation-panel').removeClass('panel-active');
            $('#mxch-transcript-back-btn').hide();
        }
        updateMobileViewportHeight();
    });

    // Fix for mobile browser address bar - sets CSS custom property for accurate viewport height
    function updateMobileViewportHeight() {
        if (isMobile()) {
            // Use visualViewport if available (most reliable for mobile)
            const vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;
            document.documentElement.style.setProperty('--mxch-mobile-vh', vh + 'px');
        }
    }

    // Update on load and viewport changes
    updateMobileViewportHeight();
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', updateMobileViewportHeight);
    }

    // ==========================================================================
    // Chat List - Split Panel
    // ==========================================================================

    let currentPage = 1;
    const perPage = 50;
    let totalPages = 1;
    let currentSessionId = null;
    let transcriptsLoaded = false;
    let selectedSessions = new Set();
    let currentSortOrder = 'desc'; // newest first

    // Load chat list on page load
    loadChatList(1, '');

    // Search functionality with debounce
    let searchTimeout;
    $('#mxch-search-transcripts').on('input', function() {
        clearTimeout(searchTimeout);
        const searchTerm = $(this).val().toLowerCase();

        searchTimeout = setTimeout(function() {
            currentPage = 1;
            loadChatList(currentPage, searchTerm);
        }, 300);
    });

    // Refresh button
    $('#mxch-refresh-list').on('click', function() {
        const $btn = $(this);
        $btn.addClass('spinning');
        loadChatList(currentPage, $('#mxch-search-transcripts').val());
        setTimeout(() => $btn.removeClass('spinning'), 500);
    });

    // ==========================================================================
    // Bulk Selection & Actions
    // ==========================================================================

    // Select all checkbox
    $('#mxch-select-all').on('change', function() {
        const isChecked = $(this).is(':checked');
        $('.mxch-chat-checkbox').prop('checked', isChecked);

        if (isChecked) {
            $('.mxch-chat-item').each(function() {
                // Use .attr() — jQuery's .data() coerces "null"/"true"/numeric strings to JS types,
                // which causes fetch/delete of those sessions to silently fail.
                selectedSessions.add($(this).attr('data-session-id'));
                $(this).addClass('selected');
            });
            $('#mxch-chat-list').addClass('selection-mode');
        } else {
            selectedSessions.clear();
            $('.mxch-chat-item').removeClass('selected');
            $('#mxch-chat-list').removeClass('selection-mode');
        }

        updateSelectionUI();
    });

    // Update selection UI
    function updateSelectionUI() {
        const count = selectedSessions.size;
        const $countEl = $('#mxch-selected-count');
        const $deleteBtn = $('#mxch-delete-selected');

        if (count > 0) {
            $countEl.text(count + ' selected').addClass('has-selection');
            $deleteBtn.prop('disabled', false);
            $('#mxch-chat-list').addClass('selection-mode');
        } else {
            $countEl.removeClass('has-selection');
            $deleteBtn.prop('disabled', true);
            $('#mxch-chat-list').removeClass('selection-mode');
        }

        // Update select all checkbox state
        const totalItems = $('.mxch-chat-checkbox').length;
        const checkedItems = $('.mxch-chat-checkbox:checked').length;
        $('#mxch-select-all').prop('checked', totalItems > 0 && checkedItems === totalItems);
        $('#mxch-select-all').prop('indeterminate', checkedItems > 0 && checkedItems < totalItems);
    }

    // Sort button
    $('#mxch-sort-btn').on('click', function() {
        currentSortOrder = currentSortOrder === 'desc' ? 'asc' : 'desc';
        $(this).find('svg').css('transform', currentSortOrder === 'asc' ? 'rotate(180deg)' : 'rotate(0deg)');
        loadChatList(currentPage, $('#mxch-search-transcripts').val());
    });

    // Delete selected button — opens the shared confirm modal with the "also delete lead" checkbox.
    $('#mxch-delete-selected').on('click', function() {
        const count = selectedSessions.size;
        if (count === 0) return;
        openTranscriptConfirm(Array.from(selectedSessions), count);
    });

    // Transcript delete confirm (shared by bulk + individual) ---------------------------
    let transcriptConfirmSessionIds = [];

    function openTranscriptConfirm(sessionIds, count) {
        transcriptConfirmSessionIds = sessionIds.slice();
        const n = count || sessionIds.length;
        $('#mxch-transcript-confirm-title').text(n === 1 ? 'Delete conversation?' : 'Delete ' + n + ' conversations?');
        $('#mxch-transcript-confirm-body').text(
            n === 1
                ? 'This removes the conversation and its messages.'
                : 'This removes ' + n + ' conversations and their messages.'
        );
        $('#mxch-transcript-also-delete-lead').prop('checked', false);
        $('#mxch-transcript-confirm').fadeIn(120);
    }

    function closeTranscriptConfirm() {
        $('#mxch-transcript-confirm').fadeOut(120);
        transcriptConfirmSessionIds = [];
    }

    $(document).on('click', '[data-mxch-transcript-close]', closeTranscriptConfirm);

    $('#mxch-transcript-confirm-go').on('click', function() {
        const ids = transcriptConfirmSessionIds.slice();
        if (!ids.length) { closeTranscriptConfirm(); return; }
        const alsoDeleteLead = $('#mxch-transcript-also-delete-lead').is(':checked');
        closeTranscriptConfirm();
        deleteMultipleSessions(ids, alsoDeleteLead);
    });

    // Delete multiple sessions
    function deleteMultipleSessions(sessionIds, alsoDeleteLead) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_delete_chat_history',
                delete_session_ids: sessionIds,
                also_delete_lead: alsoDeleteLead ? '1' : '0',
                security: $('#mxchat_delete_chat_nonce').val()
            },
            success: function(response) {
                try {
                    const jsonResponse = typeof response === 'object' ? response : JSON.parse(response);

                    if (jsonResponse.success) {
                        // Clear selection
                        selectedSessions.clear();
                        $('#mxch-select-all').prop('checked', false);
                        updateSelectionUI();

                        // If current conversation was deleted, reset panel
                        if (sessionIds.includes(currentSessionId)) {
                            currentSessionId = null;
                            $('#mxch-conversation-content').hide();
                            $('#mxch-conversation-empty').show();
                            $('#mxch-details-drawer').hide();
                        }

                        // Reload list
                        loadChatList(currentPage, $('#mxch-search-transcripts').val());

                        // The Leads tab shares this data (Chat deleted pill, nav badge,
                        // stats) — invalidate it so switching tabs re-fetches instead of
                        // showing stale "active lead" rows.
                        invalidateLeadsData();
                    } else if (jsonResponse.error) {
                        alert('Error: ' + jsonResponse.error);
                    }
                } catch (e) {
                    alert('An error occurred while processing the response.');
                }
            },
            error: function() {
                alert('An error occurred while deleting conversations.');
            }
        });
    }

    // Load chat list function
    function loadChatList(page, searchTerm) {
        const $container = $('#mxch-chat-list');
        $container.html('<div class="mxch-list-loading"><span class="spinner is-active"></span></div>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_fetch_chat_history',
                page: page,
                per_page: perPage,
                search: searchTerm,
                sort_order: currentSortOrder
            },
            success: function(response) {
                transcriptsLoaded = true;

                if (response.success && response.sessions && response.sessions.length > 0) {
                    renderChatList(response.sessions);
                    currentPage = response.page;
                    totalPages = response.total_pages;
                    updateChatCount(response.showing_start, response.showing_end, response.total_sessions);
                    renderPagination(response.page, response.total_pages, searchTerm);
                } else {
                    $container.html('<div class="mxch-list-empty"><p>No chats found</p></div>');
                    updateChatCount(0, 0, 0);
                    $('#mxch-pagination').html('');
                }
            },
            error: function() {
                $container.html('<div class="mxch-list-empty"><p>Error loading chats</p></div>');
            }
        });
    }

    // Render chat list items
    function renderChatList(sessions) {
        const $container = $('#mxch-chat-list');
        let html = '';

        sessions.forEach(function(session) {
            const isActive = session.session_id === currentSessionId ? ' active' : '';
            const isSelected = selectedSessions.has(session.session_id) ? ' selected' : '';
            const isChecked = selectedSessions.has(session.session_id) ? ' checked' : '';
            html += `
                <div class="mxch-chat-item${isActive}${isSelected}" data-session-id="${escapeHtml(session.session_id)}">
                    <input type="checkbox" class="mxch-chat-checkbox"${isChecked}>
                    <div class="mxch-chat-avatar">
                        <span>${escapeHtml(session.initials)}</span>
                    </div>
                    <div class="mxch-chat-info">
                        <div class="mxch-chat-name">${escapeHtml(session.display_name)}</div>
                        <div class="mxch-chat-preview">${escapeHtml(session.preview)}</div>
                    </div>
                    <div class="mxch-chat-meta">
                        <span class="mxch-chat-time">${escapeHtml(session.time_display)}</span>
                        <span class="mxch-chat-count">${session.message_count}</span>
                    </div>
                </div>
            `;
        });

        $container.html(html);

        // Attach checkbox handlers
        $('.mxch-chat-checkbox').on('click', function(e) {
            e.stopPropagation(); // Prevent triggering chat item click
            const $item = $(this).closest('.mxch-chat-item');
            const sessionId = $item.attr('data-session-id');

            if ($(this).is(':checked')) {
                selectedSessions.add(sessionId);
                $item.addClass('selected');
            } else {
                selectedSessions.delete(sessionId);
                $item.removeClass('selected');
            }

            updateSelectionUI();
        });

        // Attach click handlers for selecting chat
        $('.mxch-chat-item').on('click', function(e) {
            // Don't trigger if clicking on checkbox
            if ($(e.target).is('.mxch-chat-checkbox')) return;

            const sessionId = $(this).attr('data-session-id');
            selectChat(sessionId);

            // Update active state
            $('.mxch-chat-item').removeClass('active');
            $(this).addClass('active');

            // Show conversation panel on mobile
            showMobileConversationPanel();
        });

        // Update selection UI after render
        updateSelectionUI();
    }

    // Update chat count display
    function updateChatCount(start, end, total) {
        if (total === 0) {
            $('#mxch-chat-count').text('0 chats');
        } else {
            $('#mxch-chat-count').text(`${start}-${end} / ${total} chats`);
        }
    }

    // Render pagination
    function renderPagination(currentPage, totalPages, searchTerm) {
        const $container = $('#mxch-pagination');

        if (totalPages <= 1) {
            $container.html('');
            return;
        }

        let html = '<div class="mxch-pagination-btns">';

        if (currentPage > 1) {
            html += `<button class="mxch-page-btn" data-page="${currentPage - 1}">&laquo;</button>`;
        }

        html += `<span class="mxch-page-info">${currentPage} / ${totalPages}</span>`;

        if (currentPage < totalPages) {
            html += `<button class="mxch-page-btn" data-page="${currentPage + 1}">&raquo;</button>`;
        }

        html += '</div>';
        $container.html(html);

        // Pagination click handlers
        $('.mxch-page-btn').on('click', function() {
            const pageNum = $(this).data('page');
            loadChatList(pageNum, searchTerm);
        });
    }

    // ==========================================================================
    // Conversation Panel
    // ==========================================================================

    // Select and load a chat conversation
    function selectChat(sessionId) {
        currentSessionId = sessionId;

        // Reset translation state when selecting new chat
        if (typeof resetTranslationState === 'function') {
            resetTranslationState();
        }

        // Show loading in conversation panel
        $('#mxch-conversation-empty').hide();
        $('#mxch-conversation-content').show();
        $('#mxch-messages-area').html('<div class="mxch-messages-loading"><span class="spinner is-active"></span> Loading conversation...</div>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_fetch_conversation',
                session_id: sessionId
            },
            success: function(response) {
                if (response.success) {
                    renderConversation(response);
                    // Load saved translation after rendering
                    if (typeof loadSavedTranslation === 'function') {
                        setTimeout(function() {
                            loadSavedTranslation(sessionId);
                        }, 100);
                    }
                } else {
                    $('#mxch-messages-area').html('<div class="mxch-messages-error">Failed to load conversation</div>');
                }
            },
            error: function() {
                $('#mxch-messages-area').html('<div class="mxch-messages-error">Error loading conversation</div>');
            }
        });
    }

    // Render conversation content
    function renderConversation(data) {
        // Update header
        $('#mxch-user-avatar span').text(data.user.initials);
        $('#mxch-user-name').text(data.user.name);
        $('#mxch-user-meta').text(data.user.sub);

        // Update details drawer
        $('#mxch-detail-messages').text(data.message_count);
        $('#mxch-detail-started').text(data.started);

        if (data.page.url) {
            $('#mxch-detail-page').html(`<a href="${escapeHtml(data.page.url)}" target="_blank">${escapeHtml(data.page.title || data.page.url)}</a>`);
        } else {
            $('#mxch-detail-page').text('-');
        }

        if (data.user.email) {
            $('#mxch-detail-email').text(data.user.email);
            $('#mxch-detail-email-row').show();
        } else {
            $('#mxch-detail-email-row').hide();
        }

        // Clicked links
        if (data.clicked_urls && data.clicked_urls.length > 0) {
            let linksHtml = '';
            data.clicked_urls.forEach(function(url) {
                linksHtml += `<a href="${escapeHtml(url)}" target="_blank" class="mxch-clicked-link">${escapeHtml(url)}</a>`;
            });
            $('#mxch-clicked-links').html(linksHtml);
            $('#mxch-clicked-section').show();
        } else {
            $('#mxch-clicked-section').hide();
        }

        // Render messages
        let messagesHtml = '';
        data.messages.forEach(function(msg) {
            if (msg.is_user) {
                messagesHtml += `
                    <div class="mxch-message mxch-message-user" data-message-id="${msg.id}">
                        <div class="mxch-message-row">
                            <div class="mxch-message-bubble">
                                ${msg.content}
                            </div>
                        </div>
                        <div class="mxch-message-time">${escapeHtml(msg.timestamp)}</div>
                    </div>
                `;
            } else {
                const ragLink = msg.has_rag ? `<a href="#" class="mxch-rag-link" data-message-id="${msg.id}">Sources</a>` : '';
                messagesHtml += `
                    <div class="mxch-message mxch-message-bot" data-message-id="${msg.id}">
                        <div class="mxch-message-header">
                            <span class="mxch-bot-label">AI Assistant</span>
                            ${ragLink}
                        </div>
                        <div class="mxch-message-row">
                            <div class="mxch-message-bubble">
                                ${msg.content}
                            </div>
                        </div>
                        <div class="mxch-message-time">${escapeHtml(msg.timestamp)}</div>
                    </div>
                `;
            }
        });

        $('#mxch-messages-area').html(messagesHtml);

        // Scroll to bottom
        const $area = $('#mxch-messages-area');
        $area.scrollTop($area[0].scrollHeight);

        // Attach RAG link handlers
        $('.mxch-rag-link').on('click', function(e) {
            e.preventDefault();
            const messageId = $(this).data('message-id');
            if (messageId) {
                openRagContextModal(messageId);
            }
        });
    }

    // Toggle details drawer
    $('#mxch-toggle-details').on('click', function() {
        const $drawer = $('#mxch-details-drawer');
        const $btn = $(this);

        if ($drawer.is(':visible')) {
            $drawer.slideUp(200);
            $btn.removeClass('active');
        } else {
            $drawer.slideDown(200);
            $btn.addClass('active');
        }
    });

    // Delete current chat — opens the shared confirm modal.
    $('#mxch-delete-current').on('click', function() {
        if (!currentSessionId) return;
        openTranscriptConfirm([currentSessionId], 1);
    });

    // Delete session function
    function deleteSession(sessionId, alsoDeleteLead) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_delete_chat_history',
                delete_session_ids: [sessionId],
                also_delete_lead: alsoDeleteLead ? '1' : '0',
                security: $('#mxchat_delete_chat_nonce').val()
            },
            success: function(response) {
                try {
                    const jsonResponse = typeof response === 'object' ? response : JSON.parse(response);

                    if (jsonResponse.success) {
                        // Reset conversation panel
                        currentSessionId = null;
                        $('#mxch-conversation-content').hide();
                        $('#mxch-conversation-empty').show();
                        $('#mxch-details-drawer').hide();

                        // Reload list
                        loadChatList(currentPage, $('#mxch-search-transcripts').val());
                    } else if (jsonResponse.error) {
                        alert('Error: ' + jsonResponse.error);
                    }
                } catch (e) {
                    alert('An error occurred while processing the response.');
                }
            },
            error: function() {
                alert('An error occurred while deleting the conversation.');
            }
        });
    }

    // ==========================================================================
    // Export Functionality
    // ==========================================================================

    $('#mxch-export-btn, #mxch-export-current').on('click', function() {
        const $button = $(this);
        $button.prop('disabled', true).addClass('loading');

        const $form = $('<form>', {
            method: 'post',
            action: ajaxurl
        });

        $form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'mxchat_export_transcripts'
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'security',
            value: mxchatAdmin.export_nonce
        }));

        $form.appendTo('body').submit();

        setTimeout(function() {
            $button.prop('disabled', false).removeClass('loading');
        }, 2000);
    });

    // ==========================================================================
    // Translation Functionality
    // ==========================================================================

    // Store original messages for reverting
    let originalMessages = null;
    let isTranslated = false;
    let currentTranslationLang = null;

    // Load saved language preference from localStorage
    const savedLang = localStorage.getItem('mxch_translate_lang');
    if (savedLang) {
        $('#mxch-translate-lang').val(savedLang);
    }

    // Save language preference when changed
    $('#mxch-translate-lang').on('change', function() {
        localStorage.setItem('mxch_translate_lang', $(this).val());
    });

    // Apply translations to messages
    function applyTranslations(translations) {
        // Store original messages if not already stored
        if (!originalMessages) {
            originalMessages = [];
            $('#mxch-messages-area .mxch-message-bubble').each(function() {
                originalMessages.push($(this).html());
            });
        }

        // Apply translations
        translations.forEach(function(item) {
            const $bubble = $('#mxch-messages-area .mxch-message-bubble').eq(item.index);
            if ($bubble.length) {
                $bubble.html(item.translated);
                $bubble.addClass('translated');
            }
        });

        isTranslated = true;
        $('#mxch-show-original-btn').show();
    }

    // Load saved translation for current session
    function loadSavedTranslation(sessionId) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_get_transcript_translation',
                session_id: sessionId
            },
            success: function(response) {
                if (response.success && response.has_translation) {
                    currentTranslationLang = response.language;
                    applyTranslations(response.translations);
                    // Update language selector to show saved language
                    $('#mxch-translate-lang').val(response.language);
                }
            }
        });
    }

    // Translate button click handler
    $('#mxch-translate-btn').on('click', function() {
        if (!currentSessionId) return;

        const $btn = $(this);
        const targetLang = $('#mxch-translate-lang').val();

        // Disable button and show loading state
        $btn.prop('disabled', true);
        $btn.find('.mxch-translate-text').text('Translating...');
        $btn.find('svg').addClass('mxch-translate-spinner');

        // Store original messages before translation
        if (!originalMessages) {
            originalMessages = [];
            $('#mxch-messages-area .mxch-message-bubble').each(function() {
                originalMessages.push($(this).html());
            });
        }

        // If already translated, restore originals first before re-translating
        if (isTranslated) {
            $('#mxch-messages-area .mxch-message-bubble').each(function(index) {
                if (originalMessages[index]) {
                    $(this).html(originalMessages[index]);
                    $(this).removeClass('translated');
                }
            });
        }

        // Collect all message content (from originals)
        const messages = [];
        originalMessages.forEach(function(html, index) {
            // Create temp element to get text content
            const $temp = $('<div>').html(html);
            messages.push({
                index: index,
                content: $temp.text().trim()
            });
        });

        // Send translation request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_translate_messages',
                session_id: currentSessionId,
                target_lang: targetLang,
                messages: JSON.stringify(messages),
                security: mxchatAdmin.translate_nonce || ''
            },
            success: function(response) {
                if (response.success && response.translations) {
                    currentTranslationLang = response.language;
                    applyTranslations(response.translations);
                    $btn.find('.mxch-translate-text').text('Translate');
                } else {
                    alert(response.error || 'Translation failed. Please try again.');
                    $btn.find('.mxch-translate-text').text('Translate');
                }
            },
            error: function() {
                alert('Translation request failed. Please try again.');
                $btn.find('.mxch-translate-text').text('Translate');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btn.find('svg').removeClass('mxch-translate-spinner');
            }
        });
    });

    // Show original button click handler
    $('#mxch-show-original-btn').on('click', function() {
        if (!originalMessages) return;

        // Restore original messages
        $('#mxch-messages-area .mxch-message-bubble').each(function(index) {
            if (originalMessages[index]) {
                $(this).html(originalMessages[index]);
                $(this).removeClass('translated');
            }
        });

        isTranslated = false;
        $(this).hide();
    });

    // Reset translation state (called when selecting new chat)
    function resetTranslationState() {
        originalMessages = null;
        isTranslated = false;
        currentTranslationLang = null;
        $('#mxch-show-original-btn').hide();
    }

    // Make functions available to selectChat
    window.resetTranslationState = resetTranslationState;
    window.loadSavedTranslation = loadSavedTranslation;

    // ==========================================================================
    // RAG Context Modal (Sources & Actions Tabs)
    // ==========================================================================

    function openRagContextModal(messageId) {
        const $modal = $('#mxch-rag-modal');
        const $loading = $modal.find('.mxch-rag-loading');
        const $sourcesContent = $modal.find('.mxch-rag-content');
        const $actionsContent = $modal.find('.mxch-actions-content');

        // Reset to Sources tab
        $modal.find('.mxch-context-tab').removeClass('active');
        $modal.find('.mxch-context-tab[data-tab="sources"]').addClass('active');
        $('#mxch-tab-sources').show();
        $('#mxch-tab-actions').hide();

        // Reset badge counts
        $('#mxch-sources-count, #mxch-actions-count').hide().text('0');

        $modal.fadeIn(200);
        $loading.show();
        $sourcesContent.html('');
        $actionsContent.html('');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_get_rag_context',
                message_id: messageId
            },
            success: function(response) {
                $loading.hide();

                if (response.success && response.data) {
                    // Render sources tab
                    renderRagContext(response.data, $sourcesContent);

                    // Render actions tab
                    renderActionsContext(response.data, $actionsContent);

                    // Update badge counts
                    const sourcesCount = response.data.top_matches ? response.data.top_matches.length : 0;
                    const actionsCount = response.data.action_analysis ? response.data.action_analysis.length : 0;

                    if (sourcesCount > 0) {
                        $('#mxch-sources-count').text(sourcesCount).show();
                    }
                    if (actionsCount > 0) {
                        $('#mxch-actions-count').text(actionsCount).show();
                    }
                } else {
                    $sourcesContent.html('<div class="mxch-rag-error">Unable to load document context.</div>');
                    $actionsContent.html('<div class="mxch-rag-error">No action data available.</div>');
                }
            },
            error: function() {
                $loading.hide();
                $sourcesContent.html('<div class="mxch-rag-error">Error loading context. Please try again.</div>');
                $actionsContent.html('<div class="mxch-rag-error">Error loading context. Please try again.</div>');
            }
        });
    }

    // Tab switching
    $(document).on('click', '.mxch-context-tab', function() {
        const $tab = $(this);
        const tabName = $tab.data('tab');

        // Update active tab
        $('.mxch-context-tab').removeClass('active');
        $tab.addClass('active');

        // Show/hide content
        $('.mxch-tab-content').hide();
        $('#mxch-tab-' + tabName).show();
    });

    function renderRagContext(data, $container) {
        let html = '';

        // Check if we have any source data
        if (!data.top_matches || data.top_matches.length === 0) {
            html += '<div class="mxch-no-results"><p>No document matches found for this response.</p></div>';
            $container.html(html);
            return;
        }

        html += '<div class="mxch-rag-summary">';
        html += '<div class="mxch-rag-summary-item"><span class="mxch-rag-label">Knowledge Base:</span> <span class="mxch-rag-value">' + escapeHtml(data.knowledge_base_type || 'WordPress Database') + '</span></div>';
        html += '<div class="mxch-rag-summary-item"><span class="mxch-rag-label">Similarity Threshold:</span> <span class="mxch-rag-value">' + Math.round((data.similarity_threshold || 0.35) * 100) + '%</span></div>';
        html += '<div class="mxch-rag-summary-item"><span class="mxch-rag-label">Documents Checked:</span> <span class="mxch-rag-value">' + (data.total_documents_checked || 0) + '</span></div>';
        html += '</div>';

        const groupedByUrl = {};

        data.top_matches.forEach(function(match) {
            const url = match.source_display || 'Unknown';
            if (!groupedByUrl[url]) {
                groupedByUrl[url] = {
                    url: url,
                    isUrl: url.startsWith('http'),
                    bestScore: 0,
                    usedForContext: false,
                    matchedChunks: []
                };
            }

            if (match.similarity_percentage > groupedByUrl[url].bestScore) {
                groupedByUrl[url].bestScore = match.similarity_percentage;
            }

            if (match.used_for_context) {
                groupedByUrl[url].usedForContext = true;
            }

            groupedByUrl[url].matchedChunks.push({
                chunkIndex: match.chunk_index,
                score: match.similarity_percentage,
                usedForContext: match.used_for_context
            });
        });

        const urlGroups = Object.values(groupedByUrl).sort((a, b) => b.bestScore - a.bestScore);
        const usedUrlCount = data.sources_used > 0 ? data.sources_used : urlGroups.filter(g => g.usedForContext).length;
        const chunksInfo = data.total_chunks_used > 0 ? data.total_chunks_used + ' chunks sent to AI' : '';

        html += '<div class="mxch-rag-matches">';
        html += '<h3>Retrieved Documents</h3>';
        html += '<p style="color: var(--mxch-text-secondary); font-size: 13px; margin-bottom: 16px;">' + usedUrlCount + ' source' + (usedUrlCount === 1 ? '' : 's') + ' used for response' + (chunksInfo ? ' &middot; ' + chunksInfo : '') + '</p>';

        urlGroups.forEach(function(group) {
            const cardClass = group.usedForContext ? 'mxch-rag-match-used' : 'mxch-rag-match-below';
            const statusIcon = group.usedForContext ? '&#10003;' : '&#10007;';
            const statusLabel = group.usedForContext ? 'Used' : 'Not Used';

            html += '<div class="mxch-rag-match-card ' + cardClass + '">';
            html += '<div class="mxch-rag-match-header">';
            html += '<span class="mxch-rag-match-score">' + group.bestScore + '%</span>';

            if (group.matchedChunks.length > 1) {
                html += '<span class="mxch-rag-chunk-badge">' + group.matchedChunks.length + ' chunks</span>';
            }

            html += '<span class="mxch-rag-match-status ' + (group.usedForContext ? 'status-used' : 'status-below') + '">' + statusIcon + ' ' + statusLabel + '</span>';
            html += '</div>';

            html += '<div class="mxch-rag-match-source">';
            if (group.isUrl) {
                html += '<a href="' + escapeHtml(group.url) + '" target="_blank">' + escapeHtml(group.url) + '</a>';
            } else {
                html += escapeHtml(group.url);
            }
            html += '</div>';
            html += '</div>';
        });

        html += '</div>';
        $container.html(html);
    }

    function renderActionsContext(data, $container) {
        let html = '';

        // Check if we have action analysis data
        if (!data.action_analysis || data.action_analysis.length === 0) {
            html += '<div class="mxch-no-results"><p>No action analysis available for this message.</p><p style="color: var(--mxch-text-secondary); font-size: 13px; margin-top: 8px;">Actions are only evaluated when enabled in your bot configuration.</p></div>';
            $container.html(html);
            return;
        }

        const actions = data.action_analysis;
        const triggeredAction = actions.find(a => a.triggered);
        const actionsAboveThreshold = actions.filter(a => a.above_threshold).length;

        // Summary section
        html += '<div class="mxch-rag-summary">';
        html += '<div class="mxch-rag-summary-item"><span class="mxch-rag-label">Actions Evaluated:</span> <span class="mxch-rag-value">' + actions.length + '</span></div>';
        html += '<div class="mxch-rag-summary-item"><span class="mxch-rag-label">Above Threshold:</span> <span class="mxch-rag-value">' + actionsAboveThreshold + '</span></div>';
        if (triggeredAction) {
            html += '<div class="mxch-rag-summary-item"><span class="mxch-rag-label">Triggered:</span> <span class="mxch-rag-value" style="color: #10b981; font-weight: 600;">' + escapeHtml(triggeredAction.intent_label) + '</span></div>';
        }
        html += '</div>';

        // Actions list
        html += '<div class="mxch-rag-matches">';
        html += '<h3>Action Scores</h3>';
        html += '<p style="color: var(--mxch-text-secondary); font-size: 13px; margin-bottom: 16px;">Showing all evaluated actions sorted by similarity score</p>';

        actions.forEach(function(action) {
            let cardClass = 'mxch-rag-match-below';
            let statusIcon = '&#10007;';
            let statusLabel = 'Below Threshold';

            if (action.triggered) {
                cardClass = 'mxch-action-triggered';
                statusIcon = '&#9889;';
                statusLabel = 'Triggered';
            } else if (action.above_threshold) {
                cardClass = 'mxch-rag-match-used';
                statusIcon = '&#10003;';
                statusLabel = 'Above Threshold';
            }

            html += '<div class="mxch-rag-match-card ' + cardClass + '">';
            html += '<div class="mxch-rag-match-header">';
            html += '<span class="mxch-rag-match-score">' + action.similarity_percentage + '%</span>';
            html += '<span class="mxch-action-threshold-badge">Threshold: ' + action.threshold_percentage + '%</span>';
            html += '<span class="mxch-rag-match-status ' + (action.triggered ? 'status-triggered' : (action.above_threshold ? 'status-used' : 'status-below')) + '">' + statusIcon + ' ' + statusLabel + '</span>';
            html += '</div>';

            html += '<div class="mxch-action-details">';
            html += '<div class="mxch-action-label">' + escapeHtml(action.intent_label) + '</div>';
            html += '<div class="mxch-action-callback"><span class="mxch-action-callback-label">Callback:</span> ' + escapeHtml(action.callback_function) + '</div>';
            html += '</div>';

            // Score bar visualization
            const scoreBarWidth = Math.min(action.similarity_percentage, 100);
            const thresholdPos = Math.min(action.threshold_percentage, 100);
            html += '<div class="mxch-action-score-bar">';
            html += '<div class="mxch-action-score-fill" style="width: ' + scoreBarWidth + '%;"></div>';
            html += '<div class="mxch-action-threshold-marker" style="left: ' + thresholdPos + '%;"></div>';
            html += '</div>';

            html += '</div>';
        });

        html += '</div>';
        $container.html(html);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Close RAG modal
    $('.mxch-modal-close').on('click', function() {
        $(this).closest('.mxch-modal-overlay').fadeOut(200);
    });

    $('.mxch-modal-overlay').on('click', function(e) {
        if ($(e.target).is('.mxch-modal-overlay')) {
            $(this).fadeOut(200);
        }
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('.mxch-modal-overlay').fadeOut(200);
        }
    });

    // ==========================================================================
    // Activity Chart
    // ==========================================================================

    // Simple chart implementation (no external dependencies)
    class SimpleChart {
        constructor(canvas, config) {
            this.canvas = canvas;
            this.ctx = canvas.getContext('2d');
            this.config = config;
            this.padding = { top: 20, right: 20, bottom: 40, left: 50 };
            this.render();
        }

        destroy() {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        }

        render() {
            const dpr = window.devicePixelRatio || 1;
            const rect = this.canvas.getBoundingClientRect();

            this.canvas.width = rect.width * dpr;
            this.canvas.height = rect.height * dpr;
            this.ctx.scale(dpr, dpr);

            this.canvas.style.width = rect.width + 'px';
            this.canvas.style.height = rect.height + 'px';

            const width = rect.width - this.padding.left - this.padding.right;
            const height = rect.height - this.padding.top - this.padding.bottom;

            // Find max value
            let maxValue = 0;
            this.config.datasets.forEach(dataset => {
                const max = Math.max(...dataset.data);
                if (max > maxValue) maxValue = max;
            });

            // Add some padding to max value
            maxValue = Math.ceil(maxValue * 1.1);
            if (maxValue === 0) maxValue = 10;

            // Draw grid lines
            this.ctx.strokeStyle = '#e5e7eb';
            this.ctx.lineWidth = 1;
            const gridLines = 5;

            for (let i = 0; i <= gridLines; i++) {
                const y = this.padding.top + (height / gridLines) * i;
                this.ctx.beginPath();
                this.ctx.moveTo(this.padding.left, y);
                this.ctx.lineTo(this.padding.left + width, y);
                this.ctx.stroke();

                // Draw y-axis labels
                const value = maxValue - (maxValue / gridLines) * i;
                this.ctx.fillStyle = '#6b7280';
                this.ctx.font = '12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                this.ctx.textAlign = 'right';
                this.ctx.fillText(Math.round(value), this.padding.left - 10, y + 4);
            }

            // Draw datasets
            this.config.datasets.forEach(dataset => {
                const points = [];
                const xStep = width / (this.config.labels.length - 1 || 1);

                dataset.data.forEach((value, index) => {
                    const x = this.padding.left + (xStep * index);
                    const y = this.padding.top + height - (value / maxValue * height);
                    points.push({ x, y, value });
                });

                // Draw filled area
                if (dataset.fill && dataset.backgroundColor) {
                    this.ctx.fillStyle = dataset.backgroundColor;
                    this.ctx.beginPath();
                    this.ctx.moveTo(points[0].x, this.padding.top + height);
                    points.forEach(point => {
                        this.ctx.lineTo(point.x, point.y);
                    });
                    this.ctx.lineTo(points[points.length - 1].x, this.padding.top + height);
                    this.ctx.closePath();
                    this.ctx.fill();
                }

                // Draw line
                this.ctx.strokeStyle = dataset.borderColor;
                this.ctx.lineWidth = 3;
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';

                this.ctx.beginPath();
                points.forEach((point, index) => {
                    if (index === 0) {
                        this.ctx.moveTo(point.x, point.y);
                    } else {
                        this.ctx.lineTo(point.x, point.y);
                    }
                });
                this.ctx.stroke();

                // Draw points
                points.forEach(point => {
                    this.ctx.fillStyle = '#ffffff';
                    this.ctx.beginPath();
                    this.ctx.arc(point.x, point.y, 5, 0, Math.PI * 2);
                    this.ctx.fill();
                    this.ctx.strokeStyle = dataset.borderColor;
                    this.ctx.lineWidth = 2;
                    this.ctx.stroke();
                });
            });

            // Draw x-axis labels
            const xStep = width / (this.config.labels.length - 1 || 1);
            this.ctx.fillStyle = '#6b7280';
            this.ctx.font = '12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            this.ctx.textAlign = 'center';

            this.config.labels.forEach((label, index) => {
                const x = this.padding.left + (xStep * index);
                this.ctx.fillText(label, x, this.padding.top + height + 20);
            });
        }
    }

    // Initialize activity chart
    function initActivityChart() {
        console.log('[MxChat Chart] initActivityChart called');

        const canvas = document.getElementById('mxchat-activity-chart');
        console.log('[MxChat Chart] Canvas element:', canvas);

        if (!canvas) {
            console.log('[MxChat Chart] Canvas not found, aborting');
            return;
        }

        console.log('[MxChat Chart] mxchatChartData exists:', typeof mxchatChartData !== 'undefined');
        if (typeof mxchatChartData === 'undefined') {
            console.log('[MxChat Chart] mxchatChartData is undefined, aborting');
            return;
        }

        console.log('[MxChat Chart] Raw mxchatChartData:', mxchatChartData);

        // Check if chart already exists and destroy it
        if (canvas.chartInstance) {
            canvas.chartInstance.destroy();
        }

        const ctx = canvas.getContext('2d');
        console.log('[MxChat Chart] Canvas context:', ctx);
        console.log('[MxChat Chart] Canvas dimensions:', canvas.getBoundingClientRect());

        // Create gradient for chats line
        const chatsGradient = ctx.createLinearGradient(0, 0, 0, 300);
        chatsGradient.addColorStop(0, 'rgba(102, 126, 234, 0.3)');
        chatsGradient.addColorStop(1, 'rgba(102, 126, 234, 0.05)');

        // Create gradient for messages line
        const messagesGradient = ctx.createLinearGradient(0, 0, 0, 300);
        messagesGradient.addColorStop(0, 'rgba(118, 75, 162, 0.3)');
        messagesGradient.addColorStop(1, 'rgba(118, 75, 162, 0.05)');

        // Convert wp_localize_script objects to arrays (WordPress converts indexed arrays to objects)
        const labels = Object.values(mxchatChartData.labels);
        const chatsData = Object.values(mxchatChartData.chats).map(Number);
        const messagesData = Object.values(mxchatChartData.messages).map(Number);

        console.log('[MxChat Chart] Processed labels:', labels);
        console.log('[MxChat Chart] Processed chatsData:', chatsData);
        console.log('[MxChat Chart] Processed messagesData:', messagesData);

        // Create chart
        try {
            canvas.chartInstance = new SimpleChart(canvas, {
                labels: labels,
                datasets: [
                    {
                        label: 'Chats',
                        data: chatsData,
                        borderColor: '#667eea',
                        backgroundColor: chatsGradient,
                        fill: true
                    },
                    {
                        label: 'Messages',
                        data: messagesData,
                        borderColor: '#764ba2',
                        backgroundColor: messagesGradient,
                        fill: true
                    }
                ]
            });
            console.log('[MxChat Chart] Chart created successfully');
        } catch (error) {
            console.error('[MxChat Chart] Error creating chart:', error);
        }
    }

    // Initialize chart on page load (dashboard is shown by default)
    setTimeout(function() {
        initActivityChart();
    }, 100);

    // Reinitialize chart on window resize
    let resizeTimeout;
    $(window).on('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            initActivityChart();
        }, 250);
    });

    // ==========================================================================
    // Leads Tab
    // ==========================================================================

    const leadsState = {
        loaded: false,
        page: 1,
        perPage: 25,
        totalPages: 1,
        totalCount: 0,
        selected: new Set(),
        filters: {
            search: '',
            dateRange: 'all',
            status: 'all',
            pageUrl: '',
            pageTitle: ''
        },
        pendingDelete: [],
        leadsRows: [] // last-rendered rows for quick lookup
    };

    function $leads() { return $('#leads'); }

    // Called after a transcript delete from the All Chats side. Marks the Leads tab
    // data stale so the next tab visit re-fetches, and refreshes immediately if the
    // Leads tab happens to already be visible.
    function invalidateLeadsData() {
        leadsState.loaded = false;
        if ($('#leads').hasClass('active')) {
            loadLeads(1);
        }
    }

    function escapeHtmlLeads(s) {
        if (s === null || typeof s === 'undefined') return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function leadsFiltersActive() {
        const f = leadsState.filters;
        return f.search !== '' || f.dateRange !== 'all' || f.status !== 'all' || f.pageUrl !== '';
    }

    function updateClearFiltersButton() {
        if (leadsFiltersActive()) {
            $('#mxch-leads-clear-filters').show();
        } else {
            $('#mxch-leads-clear-filters').hide();
        }
    }

    function setPageFilterChip(url, title) {
        leadsState.filters.pageUrl = url || '';
        leadsState.filters.pageTitle = title || url || '';
        const $chip = $('#mxch-leads-active-page-filter');
        if (url) {
            $chip.find('.mxch-leads-page-chip-label').text('Page: ' + (title || url));
            $chip.show();
        } else {
            $chip.hide();
        }
        updateClearFiltersButton();
    }

    function loadLeads(page) {
        if (typeof page === 'number') leadsState.page = page;

        const $tbody = $('#mxch-leads-tbody');
        $tbody.html('<tr><td colspan="6" class="mxch-leads-loading"><span class="spinner is-active"></span></td></tr>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_fetch_leads',
                page: leadsState.page,
                per_page: leadsState.perPage,
                search: leadsState.filters.search,
                date_range: leadsState.filters.dateRange,
                status: leadsState.filters.status,
                page_url: leadsState.filters.pageUrl
            },
            success: function(response) {
                leadsState.loaded = true;
                if (!response || !response.success) {
                    $tbody.html('<tr><td colspan="7" class="mxch-leads-empty">Error loading leads</td></tr>');
                    return;
                }
                leadsState.totalPages = response.total_pages || 1;
                leadsState.totalCount = response.total_count || 0;
                leadsState.leadsRows = response.leads || [];

                renderLeadsStats(response.stats || {});
                renderLeadsTopPages(response.top_pages || []);
                renderLeadsTable(response.leads || []);
                renderLeadsCount(response.showing_start, response.showing_end, response.total_count);
                renderLeadsPagination(response.page, response.total_pages);

                // Nav badge
                if (response.stats && typeof response.stats.total_leads === 'number') {
                    const $badge = $('#mxch-leads-nav-badge');
                    if (response.stats.total_leads > 0) {
                        $badge.text(response.stats.total_leads).show();
                    } else {
                        $badge.hide();
                    }
                }
            },
            error: function() {
                $tbody.html('<tr><td colspan="7" class="mxch-leads-empty">Error loading leads</td></tr>');
            }
        });
    }

    function renderLeadsStats(stats) {
        $('#mxch-leads-stat-total').text(stats.total_leads || 0);
        $('#mxch-leads-stat-new').text(stats.new_this_week || 0);
        $('#mxch-leads-stat-avg').text(stats.avg_convos || 0);
        const pct = stats.orphan_pct || 0;
        $('#mxch-leads-stat-orphan').text(pct + '%');
        const orphanCount = stats.orphan_count || 0;
        $('#mxch-leads-stat-orphan-sub').text(orphanCount + (orphanCount === 1 ? ' lead captured but never chatted' : ' leads captured but never chatted'));
    }

    function renderLeadsTopPages(pages) {
        const $wrap = $('#mxch-leads-toppages-list');
        if (!pages || pages.length === 0) {
            $wrap.html('<div class="mxch-leads-empty-mini">No page data yet.</div>');
            return;
        }
        let html = '';
        pages.forEach(function(p) {
            const isActive = leadsState.filters.pageUrl === p.url ? ' is-active' : '';
            html += `
                <button type="button" class="mxch-leads-toppage-row${isActive}" data-url="${escapeHtmlLeads(p.url)}" data-title="${escapeHtmlLeads(p.title)}">
                    <span class="mxch-leads-toppage-title">${escapeHtmlLeads(p.title || p.url)}</span>
                    <span class="mxch-leads-toppage-count">${p.lead_count}</span>
                </button>
            `;
        });
        $wrap.html(html);
    }

    function renderLeadsTable(rows) {
        const $tbody = $('#mxch-leads-tbody');
        if (!rows || rows.length === 0) {
            $tbody.html(`
                <tr><td colspan="6" class="mxch-leads-empty">
                    <div class="mxch-leads-empty-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <p>No leads match the current filters.</p>
                    </div>
                </td></tr>
            `);
            return;
        }

        let html = '';
        rows.forEach(function(r) {
            const emailKey = (r.email || '').toLowerCase();
            const isChecked = leadsState.selected.has(emailKey) ? ' checked' : '';
            // Status: 'active' (has conversations), 'chat_deleted' (admin removed the chat), 'orphan' (no chat ever).
            const status = r.status || (r.is_orphan ? 'orphan' : 'active');
            const isOrphan = (status === 'orphan');
            const isChatDeleted = (status === 'chat_deleted');
            const nameLine = r.name
                ? `<span class="mxch-leads-lead-name">${escapeHtmlLeads(r.name)}</span>`
                : '';
            const leadCell = `
                <div class="mxch-leads-lead-cell">
                    <span class="mxch-leads-lead-email" title="${escapeHtmlLeads(r.email)}">${escapeHtmlLeads(r.email)}</span>
                    ${nameLine}
                </div>`;
            let countCell;
            if (isOrphan) {
                countCell = `<span class="mxch-leads-pill mxch-leads-pill-orphan">Orphan</span>`;
            } else if (isChatDeleted) {
                countCell = `<span class="mxch-leads-pill mxch-leads-pill-deleted" title="Chat was deleted by an admin">Chat deleted</span>`;
            } else {
                countCell = `<span class="mxch-leads-pill">${r.conversation_count}</span>`;
            }
            const lastCell = escapeHtmlLeads(r.last_seen_display || (isOrphan ? 'No conversation yet' : ''));
            const pageCell = r.top_page_url
                ? `<a href="${escapeHtmlLeads(r.top_page_url)}" target="_blank" rel="noopener" class="mxch-leads-page-link" title="${escapeHtmlLeads(r.top_page_url)}">${escapeHtmlLeads(r.top_page_title || r.top_page_url)}</a>`
                : '<span class="mxch-leads-muted">—</span>';
            // View Convo only for active leads (orphans and chat_deleted have no viewable session).
            const viewBtn = (status === 'active' && r.latest_session_id)
                ? `<button type="button" class="mxch-btn mxch-btn-ghost mxch-btn-sm mxch-leads-view" data-session-id="${escapeHtmlLeads(r.latest_session_id)}" title="View latest conversation">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span>View convo</span>
                    </button>`
                : '';
            const deleteBtn = `<button type="button" class="mxch-btn mxch-btn-ghost mxch-btn-sm mxch-btn-danger-ghost mxch-leads-delete-row" data-email="${escapeHtmlLeads(r.email)}" title="Delete lead">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>`;

            const rowStateClass = isOrphan ? ' is-orphan' : (isChatDeleted ? ' is-chat-deleted' : '');
            html += `
                <tr class="mxch-leads-row${rowStateClass}" data-email="${escapeHtmlLeads(r.email)}">
                    <td class="mxch-leads-col-check"><input type="checkbox" class="mxch-leads-rowcheck"${isChecked}></td>
                    <td class="mxch-leads-col-lead">${leadCell}</td>
                    <td class="mxch-leads-col-count">${countCell}</td>
                    <td class="mxch-leads-col-last">${lastCell}</td>
                    <td class="mxch-leads-col-page">${pageCell}</td>
                    <td class="mxch-leads-col-actions">${viewBtn}${deleteBtn}</td>
                </tr>
            `;
        });

        $tbody.html(html);
        updateLeadsSelectionUI();
    }

    function renderLeadsCount(start, end, total) {
        if (!total) {
            $('#mxch-leads-count').text('0 leads');
        } else {
            $('#mxch-leads-count').text(start + '-' + end + ' / ' + total + ' leads');
        }
    }

    function renderLeadsPagination(currentPage, totalPages) {
        const $c = $('#mxch-leads-pagination');
        if (!totalPages || totalPages <= 1) { $c.html(''); return; }
        let html = '<div class="mxch-pagination-btns">';
        if (currentPage > 1) {
            html += `<button class="mxch-page-btn" data-page="${currentPage - 1}">&laquo;</button>`;
        }
        html += `<span class="mxch-page-info">${currentPage} / ${totalPages}</span>`;
        if (currentPage < totalPages) {
            html += `<button class="mxch-page-btn" data-page="${currentPage + 1}">&raquo;</button>`;
        }
        html += '</div>';
        $c.html(html);
    }

    function updateLeadsSelectionUI() {
        const count = leadsState.selected.size;
        const $countEl = $('#mxch-leads-selected-count');
        const $del = $('#mxch-leads-delete-selected');
        if (count > 0) {
            $countEl.text(count + ' selected').addClass('has-selection');
            $del.prop('disabled', false);
        } else {
            $countEl.text('0').removeClass('has-selection');
            $del.prop('disabled', true);
        }
        // Selected-scope export menu items
        $('#mxch-leads-export-menu button[data-scope="selected"]').prop('disabled', count === 0);

        // Select-all checkbox state
        const $checks = $('.mxch-leads-rowcheck');
        const checked = $checks.filter(':checked').length;
        const total = $checks.length;
        $('#mxch-leads-select-all').prop('checked', total > 0 && checked === total);
        $('#mxch-leads-select-all').prop('indeterminate', checked > 0 && checked < total);
    }

    // Trigger leads load when switching to the tab (works alongside the main nav handler above).
    $('.mxch-nav-link[data-target="leads"], .mxch-mobile-nav-link[data-target="leads"]').on('click', function() {
        if (!leadsState.loaded) {
            loadLeads(1);
        }
    });

    // Filter: search (debounced)
    let leadsSearchTimer;
    $('#mxch-leads-search').on('input', function() {
        clearTimeout(leadsSearchTimer);
        const val = $(this).val();
        leadsSearchTimer = setTimeout(function() {
            leadsState.filters.search = (val || '').trim();
            updateClearFiltersButton();
            loadLeads(1);
        }, 300);
    });

    // Filter: date range
    $('#mxch-leads-date-range').on('change', function() {
        leadsState.filters.dateRange = $(this).val();
        updateClearFiltersButton();
        loadLeads(1);
    });

    // Filter: status
    $('#mxch-leads-status').on('change', function() {
        leadsState.filters.status = $(this).val();
        updateClearFiltersButton();
        loadLeads(1);
    });

    // Clear filters
    $('#mxch-leads-clear-filters').on('click', function() {
        leadsState.filters = { search: '', dateRange: 'all', status: 'all', pageUrl: '', pageTitle: '' };
        $('#mxch-leads-search').val('');
        $('#mxch-leads-date-range').val('all');
        $('#mxch-leads-status').val('all');
        setPageFilterChip('', '');
        loadLeads(1);
    });

    // Remove page chip
    $leads().on('click', '.mxch-leads-page-chip-remove', function() {
        setPageFilterChip('', '');
        loadLeads(1);
    });

    // Top Pages click -> set filter
    $leads().on('click', '.mxch-leads-toppage-row', function() {
        const url = $(this).data('url') || '';
        const title = $(this).data('title') || '';
        setPageFilterChip(url, title);
        loadLeads(1);
    });

    // Pagination click
    $leads().on('click', '#mxch-leads-pagination .mxch-page-btn', function() {
        const p = parseInt($(this).data('page'), 10);
        if (p > 0) loadLeads(p);
    });

    // Select-all
    $('#mxch-leads-select-all').on('change', function() {
        const on = $(this).is(':checked');
        $('.mxch-leads-rowcheck').prop('checked', on);
        $('.mxch-leads-row').each(function() {
            const email = ($(this).data('email') || '').toString().toLowerCase();
            if (on) {
                leadsState.selected.add(email);
            } else {
                leadsState.selected.delete(email);
            }
        });
        updateLeadsSelectionUI();
    });

    // Row checkbox
    $leads().on('change', '.mxch-leads-rowcheck', function() {
        const email = ($(this).closest('.mxch-leads-row').data('email') || '').toString().toLowerCase();
        if ($(this).is(':checked')) {
            leadsState.selected.add(email);
        } else {
            leadsState.selected.delete(email);
        }
        updateLeadsSelectionUI();
    });

    // View convo -> jump to All Chats tab and open the session
    $leads().on('click', '.mxch-leads-view', function() {
        const sid = $(this).attr('data-session-id');
        if (!sid) return;
        $('.mxch-nav-link[data-target="all-chats"]').trigger('click');
        // selectChat is defined earlier in this closure
        if (typeof selectChat === 'function') {
            setTimeout(function() { selectChat(sid); }, 30);
        }
    });

    // Row delete -> confirm for one
    $leads().on('click', '.mxch-leads-delete-row', function() {
        const email = $(this).data('email');
        if (!email) return;
        openLeadsConfirm([String(email)]);
    });

    // Bulk delete -> confirm for N
    $('#mxch-leads-delete-selected').on('click', function() {
        if (leadsState.selected.size === 0) return;
        openLeadsConfirm(Array.from(leadsState.selected));
    });

    function openLeadsConfirm(emails) {
        leadsState.pendingDelete = emails;
        const count = emails.length;
        const msg = count === 1
            ? 'Delete lead "' + emails[0] + '" and all of their conversations?'
            : 'Delete ' + count + ' leads and all of their conversations?';
        $('#mxch-leads-confirm-body').text(msg);
        $('#mxch-leads-confirm').fadeIn(120);
    }

    function closeLeadsConfirm() {
        $('#mxch-leads-confirm').fadeOut(120);
        leadsState.pendingDelete = [];
    }

    $leads().on('click', '[data-mxch-leads-close]', closeLeadsConfirm);

    $('#mxch-leads-confirm-go').on('click', function() {
        const emails = leadsState.pendingDelete.slice();
        if (!emails.length) { closeLeadsConfirm(); return; }

        const $btn = $(this).prop('disabled', true).text('Deleting...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_delete_leads',
                security: $('#mxchat_leads_delete_nonce').val(),
                emails: emails
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Delete permanently');
                closeLeadsConfirm();
                if (response && response.success) {
                    emails.forEach(function(e) { leadsState.selected.delete(e.toLowerCase()); });
                    loadLeads(leadsState.page);
                } else {
                    alert((response && response.data && response.data.message) || 'Failed to delete leads.');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Delete permanently');
                alert('Network error while deleting.');
            }
        });
    });

    // Export dropdown
    $('#mxch-leads-export-btn').on('click', function(e) {
        e.stopPropagation();
        $('#mxch-leads-export-menu').toggleClass('is-open');
    });

    $(document).on('click', function() {
        $('#mxch-leads-export-menu').removeClass('is-open');
    });

    $('#mxch-leads-export-menu').on('click', function(e) { e.stopPropagation(); });

    $('#mxch-leads-export-menu button').on('click', function() {
        if ($(this).prop('disabled')) return;
        const scope = $(this).data('scope') || 'all';
        const fields = $(this).data('fields') || 'email_and_name';
        submitLeadsExport(scope, fields);
        $('#mxch-leads-export-menu').removeClass('is-open');
    });

    function submitLeadsExport(scope, fields) {
        const $form = $('<form>', { method: 'POST', action: ajaxurl, style: 'display:none;' });
        $form.append($('<input>', { type: 'hidden', name: 'action', value: 'mxchat_export_leads' }));
        $form.append($('<input>', { type: 'hidden', name: 'security', value: $('#mxchat_leads_export_nonce').val() }));
        $form.append($('<input>', { type: 'hidden', name: 'scope', value: scope }));
        $form.append($('<input>', { type: 'hidden', name: 'fields', value: fields }));
        if (scope === 'selected') {
            Array.from(leadsState.selected).forEach(function(e) {
                $form.append($('<input>', { type: 'hidden', name: 'emails[]', value: e }));
            });
        }
        $form.appendTo('body').submit().remove();
    }

    // Preload leads metadata on page load (for the nav badge count only) without rendering.
    // We keep this light — the full fetch only runs when the tab is clicked.
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: { action: 'mxchat_fetch_leads', page: 1, per_page: 1 },
        success: function(response) {
            if (response && response.success && response.stats) {
                const total = response.stats.total_leads || 0;
                const $badge = $('#mxch-leads-nav-badge');
                if (total > 0) $badge.text(total).show();
            }
        }
    });
});
