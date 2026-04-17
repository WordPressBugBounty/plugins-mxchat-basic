/**
 * MxChat Admin Testing Tab
 * Handles the in-admin testing interface with debug data display.
 * Ported from test-panel.js but adapted for inline admin use.
 */

(function($) {
    'use strict';

    var AdminTestingPanel = {
        initialized: false,
        lastQueryData: null,

        init: function() {
            if (this.initialized) return;

            // Only initialize when the testing section becomes visible
            this.bindTabActivation();
            this.bindActionButtons();
            this.interceptChatResponses();

            this.initialized = true;
            this.log('Admin testing panel initialized');
        },

        // =====================================================
        // Tab activation - load system info when testing tab is shown
        // =====================================================

        bindTabActivation: function() {
            var self = this;

            // Watch for the testing section becoming active
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                        var testingSection = document.getElementById('testing');
                        if (testingSection && testingSection.classList.contains('active')) {
                            self.loadSystemInfo();
                        }
                    }
                });
            });

            var testingSection = document.getElementById('testing');
            if (testingSection) {
                observer.observe(testingSection, { attributes: true });

                // If already active (e.g. direct URL hash)
                if (testingSection.classList.contains('active')) {
                    self.loadSystemInfo();
                }
            }
        },

        // =====================================================
        // Action buttons
        // =====================================================

        bindActionButtons: function() {
            var self = this;

            $('#mxch-testing-clear-session').on('click', function() {
                self.clearChatSession();
            });

            $('#mxch-testing-clear-debug').on('click', function() {
                self.clearDebugConsole();
            });
        },

        // =====================================================
        // AJAX Interception - capture testing_data from responses
        // =====================================================

        interceptChatResponses: function() {
            var self = this;

            // Store reference globally so interception can find us
            window.mxchatTestPanelInstance = self;

            // Intercept jQuery AJAX calls
            if (window.jQuery) {
                var originalAjax = jQuery.ajax;

                jQuery.ajax = function(options) {
                    var originalSuccess = options.success;

                    options.success = function(data, textStatus, jqXHR) {
                        // Check if this is a chat request
                        if (options.data &&
                            (options.data.action === 'mxchat_handle_chat_request' ||
                             options.data.action === 'mxchat_stream_chat')) {

                            if (self && data && data.testing_data) {
                                self.handleTestingData(data.testing_data);
                            } else if (self && data && data.data && data.data.testing_data) {
                                self.handleTestingData(data.data.testing_data);
                            }
                        }

                        if (originalSuccess) {
                            originalSuccess.call(this, data, textStatus, jqXHR);
                        }
                    };

                    return originalAjax.call(this, options);
                };
            }

            // Intercept fetch API calls (for both JSON and SSE streaming)
            var originalFetch = window.fetch;

            window.fetch = function() {
                var args = arguments;
                return originalFetch.apply(this, args).then(function(response) {
                    if (args[0] && typeof args[0] === 'string' &&
                        (args[0].includes('admin-ajax.php') || args[0].includes('mxchat'))) {
                        var contentType = response.headers.get('content-type') || '';

                        if (contentType.includes('application/json')) {
                            // Non-streaming: parse the full JSON response
                            response.clone().json().then(function(data) {
                                if (self && data && data.testing_data) {
                                    self.handleTestingData(data.testing_data);
                                } else if (self && data && data.data && data.data.testing_data) {
                                    self.handleTestingData(data.data.testing_data);
                                }
                            }).catch(function() {});
                        } else if (contentType.includes('text/event-stream') || contentType.includes('text/plain')) {
                            // Streaming (SSE): read just enough to find the testing_data event
                            // Using clone() so the original stream is unaffected for chat-script.js
                            try {
                                var clonedResponse = response.clone();
                                var reader = clonedResponse.body.getReader();
                                var decoder = new TextDecoder();
                                var sseBuffer = '';
                                var found = false;

                                function readChunk() {
                                    reader.read().then(function(result) {
                                        if (result.done || found) {
                                            reader.cancel().catch(function() {});
                                            return;
                                        }

                                        sseBuffer += decoder.decode(result.value, { stream: true });
                                        var sseLines = sseBuffer.split('\n');

                                        for (var i = 0; i < sseLines.length; i++) {
                                            var sseLine = sseLines[i];
                                            if (sseLine.indexOf('data: ') === 0) {
                                                var payload = sseLine.substring(6);
                                                try {
                                                    var parsed = JSON.parse(payload);
                                                    if (parsed && parsed.testing_data) {
                                                        self.handleTestingData(parsed.testing_data);
                                                        found = true;
                                                        reader.cancel().catch(function() {});
                                                        return;
                                                    }
                                                } catch (e) {}
                                            }
                                        }

                                        // Keep reading until we find testing_data or hit a limit
                                        if (sseBuffer.length < 50000) {
                                            readChunk();
                                        } else {
                                            reader.cancel().catch(function() {});
                                        }
                                    }).catch(function() {
                                        // Stream read error — safe to ignore
                                    });
                                }

                                readChunk();
                            } catch (e) {}
                        }
                    }
                    return response;
                });
            };

            this.log('Chat interception active');
        },

        // =====================================================
        // Handle incoming testing data
        // =====================================================

        handleTestingData: function(testingData) {
            this.log('Chat data captured');

            // Update query
            this.updateLastQuery(testingData.query || 'No query');

            // Update approved URLs
            this.updateApprovedUrls(testingData.approved_urls || []);

            // Update document matches
            this.updateTopMatches(
                testingData.top_matches || [],
                testingData.similarity_threshold || 0.75,
                testingData.sources_used || 0,
                testingData.total_chunks_used || 0
            );

            // Update action matches
            this.updateActionMatches(testingData.action_matches || []);

            // Log summary
            if (testingData.knowledge_base_type) {
                this.log('Knowledge Base: ' + testingData.knowledge_base_type);
            }
            if (testingData.similarity_threshold) {
                this.log('Similarity Threshold: ' + (testingData.similarity_threshold * 100) + '%');
            }
            if (testingData.top_matches && testingData.top_matches.length > 0) {
                var aboveThreshold = testingData.top_matches.filter(function(m) { return m.above_threshold; }).length;
                this.log(aboveThreshold + ' above threshold, ' + (testingData.top_matches.length - aboveThreshold) + ' below');
                this.log('Highest similarity: ' + testingData.top_matches[0].similarity_percentage + '%');
            } else {
                this.log('No document matches found');
            }
            if (testingData.action_matches && testingData.action_matches.length > 0) {
                var triggered = testingData.action_matches.find(function(a) { return a.triggered; });
                if (triggered) {
                    this.log('Action Triggered: ' + triggered.intent_label + ' (' + triggered.similarity_percentage + '%)');
                } else {
                    this.log('No actions triggered - Highest: ' + testingData.action_matches[0].intent_label + ' (' + testingData.action_matches[0].similarity_percentage + '%)');
                }
            }
        },

        // =====================================================
        // Update UI sections
        // =====================================================

        updateLastQuery: function(query) {
            $('#mxch-testing-last-query').text(query);
            this.lastQueryData = { query: query, timestamp: new Date() };
        },

        updateApprovedUrls: function(approvedUrls) {
            var el = $('#mxch-testing-approved-urls');

            if (!approvedUrls || !Array.isArray(approvedUrls) || approvedUrls.length === 0) {
                el.html('<div class="mxch-testing-no-data">No approved URLs (AI cannot cite links)</div>');
                return;
            }

            var html = '<div class="urls-header"><strong>' + approvedUrls.length + ' URL' + (approvedUrls.length !== 1 ? 's' : '') + ' approved for AI citations</strong></div>';

            approvedUrls.forEach(function(url) {
                var displayUrl = url;
                try {
                    var urlObj = new URL(url);
                    displayUrl = urlObj.hostname + urlObj.pathname;
                } catch (e) {}

                html += '<div class="url-card"><div class="url-line">' +
                    '<span class="url-icon">&#128279;</span>' +
                    '<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="url-link" title="' + url + '">' + displayUrl + '</a>' +
                    '</div></div>';
            });

            html += '<div class="urls-note">The AI can only cite these URLs. Others are automatically removed from responses.</div>';
            el.html(html);
        },

        updateActionMatches: function(actionMatches) {
            var el = $('#mxch-testing-action-scores');

            if (!actionMatches || actionMatches.length === 0) {
                el.html('<div class="mxch-testing-no-data">No actions checked</div>');
                return;
            }

            var html = '<div class="actions-header"><strong>Top ' + actionMatches.length + ' actions checked</strong></div>';

            actionMatches.forEach(function(action) {
                var isTriggered = action.triggered;
                var isAboveThreshold = action.above_threshold;
                var statusIcon = isTriggered ? '&#127919;' : (isAboveThreshold ? '&#9888;&#65039;' : '&#10060;');

                var statusLabel;
                if (isTriggered) {
                    statusLabel = 'TRIGGERED';
                } else if (isAboveThreshold) {
                    statusLabel = 'Above threshold';
                } else {
                    statusLabel = 'Below threshold';
                }

                var cardClass = isTriggered ? 'action-triggered' : (isAboveThreshold ? 'action-above-threshold' : 'action-below-threshold');

                html += '<div class="action-card ' + cardClass + '">' +
                    '<div class="action-line">' +
                        '<span class="action-icon">' + statusIcon + '</span>' +
                        '<span class="action-name">' + action.intent_label + '</span>' +
                        '<span class="action-score">' + action.similarity_percentage + '%</span>' +
                    '</div>' +
                    '<div class="action-details">' +
                        '<span class="action-status">' + statusLabel + '</span>' +
                        '<span class="action-threshold">Threshold: ' + action.threshold_percentage + '%</span>' +
                    '</div>' +
                '</div>';
            });

            el.html(html);
        },

        updateTopMatches: function(topMatches, threshold, sourcesUsed, totalChunksUsed) {
            var el = $('#mxch-testing-similarity-scores');

            if (!topMatches || topMatches.length === 0) {
                el.html('<div class="mxch-testing-no-data">No similarity data available</div>');
                return;
            }

            // Group matches by source URL
            var groupedByUrl = {};
            topMatches.forEach(function(match) {
                var url = match.source_display || 'Unknown';
                if (!groupedByUrl[url]) {
                    groupedByUrl[url] = {
                        url: url,
                        isUrl: url.indexOf('http') === 0,
                        bestScore: 0,
                        usedForContext: false,
                        totalChunks: match.total_chunks || 1,
                        matchedChunks: [],
                        isChunked: match.is_chunk || false
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
                    usedForContext: match.used_for_context,
                    aboveThreshold: match.above_threshold
                });
            });

            var urlGroups = Object.values(groupedByUrl).sort(function(a, b) { return b.bestScore - a.bestScore; });
            var usedUrlCount = sourcesUsed > 0 ? sourcesUsed : urlGroups.filter(function(g) { return g.usedForContext; }).length;
            var chunksInfo = totalChunksUsed > 0 ? totalChunksUsed + ' chunks sent to AI' : topMatches.length + ' chunk matches';

            var html = '<div class="matches-header">' +
                '<strong>' + usedUrlCount + ' source' + (usedUrlCount === 1 ? '' : 's') + ' used for AI context</strong>' +
                '<span class="matches-subheader">(' + chunksInfo + ')</span>' +
            '</div>';

            var self = this;

            urlGroups.forEach(function(group, groupIndex) {
                var cardClass = group.usedForContext ? 'above-threshold' : 'below-threshold';
                var statusIcon = group.usedForContext ? '&#10003;' : '&#10007;';
                var contextLabel = group.usedForContext ? 'Used for AI context' : 'Not used';

                var chunkSummary = '';
                if (group.isChunked && group.totalChunks > 1) {
                    var usedChunkCount = group.matchedChunks.filter(function(c) { return c.usedForContext; }).length;
                    chunkSummary = '<span class="chunk-summary">' + usedChunkCount + '/' + group.totalChunks + ' chunks matched</span>';
                }

                var hasMultipleChunks = group.matchedChunks.length > 1;
                var expandToggle = hasMultipleChunks
                    ? '<span class="chunk-expand-toggle" data-group="' + groupIndex + '">&#9654; Show chunks</span>'
                    : '';

                html += '<div class="match-card ' + cardClass + '">' +
                    '<div class="match-header">' +
                        '<div class="match-title">' +
                            '<span class="status-icon">' + statusIcon + '</span>' +
                            '<span class="similarity-score">' + group.bestScore + '%</span>' +
                            chunkSummary +
                        '</div>' +
                        '<span class="context-label">' + contextLabel + '</span>' +
                    '</div>' +
                    '<div class="match-source">' +
                        (group.isUrl ?
                            '<span class="source-icon link-icon">&#128279;</span> ' + group.url :
                            '<span class="source-icon doc-icon">&#128196;</span> ' + group.url
                        ) +
                    '</div>' +
                    expandToggle +
                    (hasMultipleChunks ? self.renderChunkDetails(group.matchedChunks, groupIndex) : '') +
                '</div>';
            });

            el.html(html);

            // Bind expand toggles
            el.find('.chunk-expand-toggle').on('click', function() {
                var groupId = $(this).data('group');
                var details = el.find('.chunk-details[data-group="' + groupId + '"]');
                var isExpanded = details.toggleClass('expanded').hasClass('expanded');
                $(this).html(isExpanded ? '&#9660; Hide chunks' : '&#9654; Show chunks');
            });
        },

        renderChunkDetails: function(chunks, groupIndex) {
            var sortedChunks = chunks.slice().sort(function(a, b) { return (a.chunkIndex || 0) - (b.chunkIndex || 0); });

            var html = '<div class="chunk-details" data-group="' + groupIndex + '">';

            sortedChunks.forEach(function(chunk) {
                var chunkNum = (chunk.chunkIndex !== null && chunk.chunkIndex !== undefined) ? chunk.chunkIndex + 1 : '?';
                var statusClass = chunk.usedForContext ? 'chunk-used' : 'chunk-not-used';
                var statusIcon = chunk.usedForContext ? '&#10003;' : '&#9675;';

                html += '<div class="chunk-detail-row ' + statusClass + '">' +
                    '<span class="chunk-detail-icon">' + statusIcon + '</span>' +
                    '<span class="chunk-detail-num">Chunk ' + chunkNum + '</span>' +
                    '<span class="chunk-detail-score">' + chunk.score + '%</span>' +
                '</div>';
            });

            html += '</div>';
            return html;
        },

        // =====================================================
        // System info loading
        // =====================================================

        loadSystemInfo: function() {
            this.updateSimilarityThreshold();
            this.updateSystemPrompt();
            this.updateKnowledgeBaseStatus();
        },

        updateSimilarityThreshold: function() {
            $.post(mxchatAdminTestData.ajaxUrl, {
                action: 'mxchat_get_similarity_threshold',
                nonce: mxchatAdminTestData.nonce
            }).done(function(data) {
                if (data.success) {
                    $('#mxch-testing-threshold').html('<code>' + data.data.threshold_percentage + '</code>');
                } else {
                    $('#mxch-testing-threshold').html('<span class="error-text">Error loading threshold</span>');
                }
            }).fail(function() {
                $('#mxch-testing-threshold').html('<span class="error-text">Connection error</span>');
            });
        },

        updateSystemPrompt: function() {
            var self = this;
            var el = $('#mxch-testing-system-prompt');
            el.text('Loading system prompt...');

            $.post(mxchatAdminTestData.ajaxUrl, {
                action: 'mxchat_get_system_info',
                nonce: mxchatAdminTestData.nonce
            }).done(function(data) {
                if (data.success) {
                    el.text(data.data.system_prompt || 'No system prompt configured');

                    if (data.data.is_openrouter) {
                        self.log('Model: OpenRouter - ' + data.data.openrouter_model);
                    } else {
                        self.log('Model: ' + data.data.selected_model);
                    }

                    var apiStatus = data.data.api_status;
                    var configuredApis = Object.keys(apiStatus).filter(function(key) { return apiStatus[key]; });
                    if (configuredApis.length > 0) {
                        self.log('Configured APIs: ' + configuredApis.join(', '));
                    }
                } else {
                    el.text('Error loading system prompt');
                }
            }).fail(function() {
                el.text('Connection error');
            });
        },

        updateKnowledgeBaseStatus: function() {
            var el = $('#mxch-testing-kb-status');
            el.html('Checking...');

            $.post(mxchatAdminTestData.ajaxUrl, {
                action: 'mxchat_get_kb_status',
                nonce: mxchatAdminTestData.nonce
            }).done(function(data) {
                if (data.success) {
                    var kbData = data.data;
                    el.html('<span class="success-text">&#10003; ' + kbData.status + '</span> (' + kbData.type + ' - ' + kbData.documents + ')');
                } else {
                    el.html('<span class="error-text">Error loading KB status</span>');
                }
            }).fail(function() {
                el.html('<span class="error-text">Connection error</span>');
            });
        },

        // =====================================================
        // Clear session
        // =====================================================

        clearChatSession: function() {
            var self = this;

            // Determine the bot ID for the testing chatbot
            var botId = 'testing';

            // Get current session ID
            var cookieName = 'mxchat_session_id_' + botId;
            var sessionId = this.getCookie(cookieName) || this.getCurrentSessionId(botId);

            if (!sessionId) {
                this.log('No active session found');
                return;
            }

            this.log('Clearing session: ' + sessionId);

            // Use MxChatInstances to properly reset
            if (typeof MxChatInstances !== 'undefined' && typeof MxChatInstances.resetChatSession === 'function') {
                MxChatInstances.resetChatSession(botId);
            }

            // Get new session ID
            var newSessionId = '';
            if (typeof MxChatInstances !== 'undefined' && typeof MxChatInstances.getChatSession === 'function') {
                newSessionId = MxChatInstances.getChatSession(botId);
            }

            if (!newSessionId) {
                newSessionId = 'mxchat_chat_' + Math.random().toString(36).substr(2, 9);
                document.cookie = cookieName + '=' + newSessionId + '; path=/; max-age=86400; SameSite=Lax';
            }

            // Call backend to clear old session
            $.post(mxchatAdminTestData.ajaxUrl, {
                action: 'mxchat_start_fresh_session',
                nonce: mxchatAdminTestData.nonce,
                old_session_id: sessionId,
                new_session_id: newSessionId
            }).done(function(data) {
                if (data.success) {
                    self.log('Session cleared successfully');

                    // Clear the chat UI for the testing bot
                    var chatBox = document.getElementById('chat-box-testing');
                    if (chatBox) {
                        var messages = chatBox.querySelectorAll('.bot-message, .user-message');
                        messages.forEach(function(msg, index) {
                            // Keep first welcome message
                            if (index === 0 && msg.classList.contains('bot-message')) return;
                            msg.remove();
                        });
                    }

                    // Clear input
                    var chatInput = document.getElementById('chat-input-testing');
                    if (chatInput) {
                        chatInput.value = '';
                    }

                    // Show popular questions again
                    var pq = document.getElementById('mxchat-popular-questions-testing');
                    if (pq) {
                        pq.style.display = 'block';
                    }

                    // Clear debug displays
                    self.updateLastQuery('New session started');
                    self.updateTopMatches([], 0, 0, 0);
                    self.updateApprovedUrls([]);
                    self.updateActionMatches([]);

                    self.log('Fresh session started: ' + newSessionId);
                } else {
                    self.log('Error: ' + (data.data && data.data.message ? data.data.message : 'Unknown error'));
                }
            }).fail(function() {
                self.log('Connection error when clearing session');
            });
        },

        // =====================================================
        // Debug console
        // =====================================================

        log: function(message) {
            var consoleEl = document.getElementById('mxch-testing-debug-console');
            if (!consoleEl) return;

            var timestamp = new Date().toLocaleTimeString();
            var entry = document.createElement('div');
            entry.className = 'mxch-testing-debug-entry';
            entry.innerHTML = '<span class="mxch-testing-debug-timestamp">[' + timestamp + ']</span> ' + message;
            consoleEl.appendChild(entry);
            consoleEl.scrollTop = consoleEl.scrollHeight;

            // Keep only last 50 entries
            var entries = consoleEl.querySelectorAll('.mxch-testing-debug-entry');
            if (entries.length > 50) {
                entries[0].remove();
            }
        },

        clearDebugConsole: function() {
            var consoleEl = document.getElementById('mxch-testing-debug-console');
            if (consoleEl) {
                consoleEl.innerHTML = '<div class="mxch-testing-debug-entry">Debug console cleared...</div>';
            }
        },

        // =====================================================
        // Helpers
        // =====================================================

        getCookie: function(name) {
            var value = '; ' + document.cookie;
            var parts = value.split('; ' + name + '=');
            if (parts.length === 2) return parts.pop().split(';').shift();
            return '';
        },

        getCurrentSessionId: function(botId) {
            if (typeof MxChatInstances !== 'undefined' && typeof MxChatInstances.getChatSession === 'function') {
                var session = MxChatInstances.getChatSession(botId);
                if (session) return session;
            }

            var cookieId = this.getCookie('mxchat_session_id_' + botId);
            if (cookieId) return cookieId;

            var chatInput = document.getElementById('chat-input-' + botId);
            if (chatInput && chatInput.dataset.sessionId) {
                return chatInput.dataset.sessionId;
            }

            return '';
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        // Only init if we're on the settings page and the testing section exists
        if (document.getElementById('testing')) {
            AdminTestingPanel.init();
        }
    });

})(jQuery);
