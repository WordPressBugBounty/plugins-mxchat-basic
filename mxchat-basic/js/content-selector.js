jQuery(document).ready(function($) {
    // Modal elements
    const $modal = $('#mxchat-kb-content-selector-modal');
    const $openButton = $('#mxchat-open-content-selector');
    const $closeButtons = $('.mxchat-kb-modal-close');
    const $contentList = $('.mxchat-kb-content-list');
    const $loading = $('.mxchat-kb-loading');
    const $pagination = $('.mxchat-kb-pagination');
    const $processButton = $('#mxchat-kb-process-selected');
    const $selectAll = $('#mxchat-kb-select-all');
    const $selectionCount = $('.mxchat-kb-selection-count');
    // ACF→PDF extraction is an install-level setting (Knowledge → ACF Fields);
    // the server reads the stored option — nothing to collect in this modal.

    // Filter elements
    const $searchInput = $('#mxchat-kb-content-search');
    const $typeFilter = $('#mxchat-kb-content-type-filter');
    const $statusFilter = $('#mxchat-kb-content-status-filter');
    const $processedFilter = $('#mxchat-kb-processed-filter');
    
    // Current state - using let for variables that change
    let currentPage = 1;
    let totalPages = 1;
    let selectedItems = new Set();
    let allItems = [];
    
    // Open modal when WordPress import button is clicked
    $openButton.on('click', function() {
        $modal.show();
        // Reset to first page when opening the modal
        currentPage = 1;
        loadContent();
    });
    
    // Close modal
    $closeButtons.on('click', function() {
        $modal.hide();
    });
// Handle import option box clicks (for non-WordPress options)
$('.mxchat-import-box').on('click', function() {
    const $box = $(this);
    const option = $box.data('option');

    // Skip if this is the WordPress option (it has its own handler)
    if (option === 'wordpress') {
        return;
    }

    // Update active state
    $('.mxchat-import-box').removeClass('active');
    $box.addClass('active');

    // Hide all input areas
    $('#mxchat-url-input-area, #mxchat-content-input-area, #mxchat-pdf-upload-area, #mxchat-document-upload-area, #mxchat-youtube-input-area').hide();

    // Hide sitemap-specific sections (but NOT for sitemap option - let detection logic handle it)
    if (option !== 'sitemap') {
        $('#mxchat-detected-sitemaps, #mxchat-no-sitemaps, #mxchat-sitemaps-loading').hide();
    }

    // Handle different import options
    switch (option) {
        case 'pdf-url':
        case 'sitemap':
        case 'url':
            // Show URL input area with appropriate placeholder
            $('#mxchat-url-input-area').show();
            $('#sitemap_url').attr('placeholder', $box.data('placeholder'));
            $('#import_type').val(option === 'pdf-url' ? 'pdf' : option);

            // UPDATED: Add or update bot_id hidden field for URL forms
            updateBotIdInForm('#mxchat-url-form');

            // Update the description text based on the import type
            let descriptionText = '';
            if (option === 'pdf-url') {
                descriptionText = 'Import a PDF document by entering its URL above. PDFs are processed via cron job. If processing does not start, you can manually process batch 5 pages at a time.';
            } else if (option === 'sitemap') {
                descriptionText = 'Enter a content-specific sub-sitemap URL, not the sitemap index. Sitemaps are processed via cron job. If processing does not start, you can manually process batch 5 pages at a time.';
                // Re-show sitemap sections if they were previously loaded
                const $sitemapsList = $('#mxchat-sitemaps-list');
                if ($sitemapsList.children().length > 0) {
                    // Sitemaps were already loaded, just show the container
                    $('#mxchat-detected-sitemaps').show();
                } else if ($('#mxchat-no-sitemaps').data('was-shown')) {
                    // No sitemaps message was shown before
                    $('#mxchat-no-sitemaps').show();
                }
                // Note: If neither condition is true, initSitemapDetection will show loading state
            } else if (option === 'url') {
                descriptionText = 'Import content from any webpage by entering its URL.';
            }
            $('#url-description-text').text(descriptionText);
            break;

        case 'content':
            // Show content input area
            $('#mxchat-content-input-area').show();

            // UPDATED: Add or update bot_id hidden field for content forms
            updateBotIdInForm('#mxchat-content-form');
            break;

        case 'pdf-upload':
            // Show PDF file upload area
            $('#mxchat-pdf-upload-area').show();

            // Add or update bot_id hidden field for PDF upload form
            updateBotIdInForm('#mxchat-pdf-upload-form');
            break;

        case 'document-upload':
            // Show document (.docx/.txt/.md) upload area
            $('#mxchat-document-upload-area').show();

            // Add or update bot_id hidden field for the document upload form
            updateBotIdInForm('#mxchat-document-upload-form');
            break;

        case 'youtube':
            // Show YouTube import area
            $('#mxchat-youtube-input-area').show();

            // Add or update bot_id hidden field for YouTube form
            updateBotIdInForm('#mxchat-youtube-form');
            break;
    }
});

// YouTube import: toggle the manual-description box with the mode radios, and
// only require the textarea when "Write my own" is selected.
$(document).on('change', 'input[name="youtube_description_mode"]', function() {
    const manual = $('input[name="youtube_description_mode"]:checked').val() === 'manual';
    $('#mxchat-youtube-description-field').toggle(manual);
    $('#mxchat-youtube-description').prop('required', manual);
});

// If the server bounced back with prefill state (auto-import found no
// transcript), reopen the YouTube form in manual mode ready to augment.
if ($('#mxchat-youtube-form').data('yt-prefill')) {
    $('.mxchat-import-box[data-option="youtube"]').trigger('click');
    $('input[name="youtube_description_mode"]').trigger('change');
    $('#mxchat-youtube-description').trigger('focus');
}

// Helper function to add/update bot_id hidden field in forms
function updateBotIdInForm(formSelector) {
    const $form = $(formSelector);
    if ($form.length === 0) return;
    
    // Get current bot_id from the bot selector dropdown
    const currentBotId = $('#mxchat-bot-selector').val();
    
    // Only add bot_id field if multi-bot is active and bot is not 'default'
    if (currentBotId && currentBotId !== 'default') {
        // Remove existing bot_id field if it exists
        $form.find('input[name="bot_id"]').remove();
        
        // Add new bot_id field
        $form.append('<input type="hidden" name="bot_id" value="' + currentBotId + '">');
        
        console.log('Updated bot_id in form ' + formSelector + ' to: ' + currentBotId);
    } else {
        // Remove bot_id field if bot is default
        $form.find('input[name="bot_id"]').remove();
    }
}

// Load content via AJAX
function loadContent() {
    $loading.show();
    $contentList.find('.mxchat-kb-content-item').remove();
    
    const data = {
        action: 'mxchat_get_content_list',
        nonce: mxchatSelector.nonce,
        page: currentPage,
        per_page: 100,
        search: $searchInput.val(),
        post_type: $typeFilter.val(),
        post_status: $statusFilter.val(),
        processed_filter: $processedFilter.val()
    };
    
    //console.log('Loading content for page', currentPage, 'with filters:', data);
    
    $.ajax({
        url: mxchatSelector.ajaxurl,
        data: data,
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            $loading.hide();
            
            if (response.success && response.data.items && response.data.items.length > 0) {
                // Store the items directly
                let items = response.data.items;
                
                if (items.length > 0) {
                    renderContentItems(items);
                    renderPagination(parseInt(response.data.current_page), parseInt(response.data.total_pages));
                    
                    // Update state
                    allItems = items;
                    totalPages = parseInt(response.data.total_pages);
                    currentPage = parseInt(response.data.current_page);
                    
                    // Update select all checkbox based on current selection
                    updateSelectAllState();
                } else {
                    displayNoResults($processedFilter.val());
                }
            } else {
                displayNoResults($processedFilter.val());
            }
        },
        error: function(xhr, status, error) {
            $loading.hide();
            console.error('AJAX Error:', status, error);
            $contentList.html('<div class="mxchat-kb-error">Error loading content. Please try again.</div>');
            // Clear pagination on error
            $pagination.empty();
        }
    });
}

// Helper function to display appropriate "no results" message
function displayNoResults(processedStatus) {
    let message = 'No content found matching your criteria.';
    
    if (processedStatus === 'processed') {
        message = 'No content found in knowledge base.';
    } else if (processedStatus === 'unprocessed') {
        message = 'All content is already in knowledge base.';
    }
    
    $contentList.html('<div class="mxchat-kb-no-results">' + message + '</div>');
    // Clear pagination when no results
    $pagination.empty();
}
    
    // Render content items
    function renderContentItems(items) {
        let html = '';

        items.forEach(function(item) {
            const isSelected = selectedItems.has(item.id);
            const isProcessed = item.already_processed;
            const chunkCount = item.chunk_count || 0;

            // Updated badge text - include chunk count if > 1
            let badgeText = 'Not In Knowledge Base';
            if (isProcessed) {
                badgeText = chunkCount > 1 ? `In Knowledge Base (${chunkCount} chunks)` : 'In Knowledge Base';
            }
            const badgeClass = isProcessed ? 'mxchat-kb-processed-badge' : 'mxchat-kb-unprocessed-badge';


            html += `
                <div class="mxchat-kb-content-item ${isProcessed ? 'processed' : ''}" data-id="${item.id}">
                    <div class="mxchat-kb-content-checkbox">
                        <input type="checkbox" id="content-${item.id}" ${isSelected ? 'checked' : ''}>
                    </div>
                    <div class="mxchat-kb-content-details">
                        <div class="mxchat-kb-content-title">
                            <a href="${item.permalink}" target="_blank">${item.title}</a>
                            <span class="${badgeClass}">${badgeText}</span>
                            ${isProcessed ? '<span class="mxchat-kb-last-updated">Last updated: ' + item.processed_date + '</span>' : ''}
                        </div>
                        <div class="mxchat-kb-content-meta">
                            <span class="mxchat-kb-content-type">${item.type_label || item.type}</span>
                            <span class="mxchat-kb-content-date">${item.date}</span>
                            <span class="mxchat-kb-content-words">${item.size_label ? item.size_label : item.word_count + ' words'}</span>
                        </div>
                        <div class="mxchat-kb-content-excerpt">${item.excerpt}</div>
                    </div>
                </div>
            `;
        });

        $contentList.html(html);
        
        // Add event listeners for checkboxes using delegation for better performance
        $contentList.off('change', 'input[type="checkbox"]').on('change', 'input[type="checkbox"]', function() {
            const $checkbox = $(this);
            const itemId = parseInt($checkbox.closest('.mxchat-kb-content-item').data('id'));
            
            if ($checkbox.is(':checked')) {
                selectedItems.add(itemId);
            } else {
                selectedItems.delete(itemId);
            }
            
            updateSelection();
        });
    }
    
    // Render pagination - FIXED VERSION
    function renderPagination(currentPage, totalPages) {
        // Clear existing pagination first
        $pagination.empty();
        
        // Don't render pagination if only one page
        if (totalPages <= 1) {
            return;
        }
        
        let html = '<div class="mxchat-kb-pagination-links">';
        
        // Previous button
        if (currentPage > 1) {
            html += '<a href="#" class="mxchat-kb-page-link prev" data-page="' + (currentPage - 1) + '">&laquo; Previous</a>';
        }
        
        // Page numbers
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, startPage + 4);
        
        for (let i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                html += '<span class="mxchat-kb-page-current">' + i + '</span>';
            } else {
                html += '<a href="#" class="mxchat-kb-page-link" data-page="' + i + '">' + i + '</a>';
            }
        }
        
        // Next button
        if (currentPage < totalPages) {
            html += '<a href="#" class="mxchat-kb-page-link next" data-page="' + (currentPage + 1) + '">Next &raquo;</a>';
        }
        
        html += '</div>';
        
        $pagination.html(html);
    }
    
    // Handle pagination clicks directly on the document
    $(document).on('click', '.mxchat-kb-page-link', function(e) {
        e.preventDefault();
        const newPage = parseInt($(this).data('page'));
        //console.log('Pagination clicked: changing from page', currentPage, 'to', newPage);
        
        // Only reload if the page actually changed
        if (currentPage !== newPage) {
            currentPage = newPage;
            loadContent();
        }
    });
    
    // Update selection counts and button state
    function updateSelection() {
        const selectedCount = selectedItems.size;
        $selectionCount.text(selectedCount + ' ' + (selectedCount === 1 ? 'selected' : 'selected'));
        $('.mxchat-kb-selected-count').text('(' + selectedCount + ')');

        // Show/hide clear all selections link
        let $clearAllLink = $('.mxchat-kb-clear-all-selections');
        if (selectedCount > 0) {
            if ($clearAllLink.length === 0) {
                $clearAllLink = $('<a href="#" class="mxchat-kb-clear-all-selections" style="margin-left: 10px; font-size: 12px; color: var(--mxch-error, #dc2626);">Clear all</a>');
                $selectionCount.after($clearAllLink);
                $clearAllLink.on('click', function(e) {
                    e.preventDefault();
                    selectedItems.clear();
                    $('.mxchat-kb-content-item input[type="checkbox"]').prop('checked', false);
                    updateSelection();
                });
            }
            $clearAllLink.show();
        } else {
            $clearAllLink.hide();
        }

        // Determine if any selected items are already processed
        const hasProcessedItems = Array.from(selectedItems).some(id => {
            const item = allItems.find(item => item.id === id);
            return item && item.already_processed;
        });

        if (selectedCount > 0) {
            $processButton.prop('disabled', false);

            // Update button text based on selection
            if (hasProcessedItems && selectedCount === 1) {
                $processButton.text('Update Selected Content (1)').addClass('update-mode');
            } else if (hasProcessedItems && selectedCount > 1) {
                $processButton.text('Process/Update Selected (' + selectedCount + ')').addClass('mixed-mode');
            } else {
                $processButton.text('Process Selected Content (' + selectedCount + ')').removeClass('update-mode mixed-mode');
            }
        } else {
            $processButton.prop('disabled', true);
            $processButton.text('Process Selected Content').removeClass('update-mode mixed-mode');
            $('.mxchat-kb-selected-count').text('(0)');
        }

        updateSelectAllState();
    }
    
    // Update "Select All" checkbox state
    function updateSelectAllState() {
        const availableItems = allItems.length;
        const selectedAvailableItems = allItems.filter(item => selectedItems.has(item.id)).length;
        
        if (availableItems === 0) {
            $selectAll.prop('checked', false);
            $selectAll.prop('disabled', true);
        } else if (selectedAvailableItems === availableItems) {
            $selectAll.prop('checked', true);
        } else {
            $selectAll.prop('checked', false);
        }
    }
    
    // Handle Select All checkbox
    $selectAll.on('change', function() {
        const isChecked = $(this).is(':checked');
        
        $contentList.find('.mxchat-kb-content-item input[type="checkbox"]').each(function() {
            const $checkbox = $(this);
            const $item = $checkbox.closest('.mxchat-kb-content-item');
            const itemId = parseInt($item.data('id'));
            
            $checkbox.prop('checked', isChecked);
            
            if (isChecked) {
                selectedItems.add(itemId);
            } else {
                selectedItems.delete(itemId);
            }
        });
        
        updateSelection();
    });
    
    // Handle search input
    let searchTimer;
    $searchInput.on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            currentPage = 1; // Reset to first page on new search
            loadContent();
        }, 500);
    });
    
    // Handle filter changes
    $typeFilter.add($statusFilter).add($processedFilter).on('change', function() {
        currentPage = 1; // Reset to first page on filter change
        selectedItems.clear(); // Clear selection when filter changes
        updateMediaNote();
        loadContent();
    });

    // The Media note explains what that list deliberately leaves out; it is
    // meaningless next to any other type, so it only exists while Media is picked.
    function updateMediaNote() {
        const $note = $('#mxchat-kb-media-note');
        if (!$note.length) {
            return;
        }
        $note.prop('hidden', $typeFilter.val() !== 'attachment');
    }
    updateMediaNote();
    
// Process selected content
$processButton.on('click', function() {
    if (selectedItems.size === 0) {
        return;
    }
    
    const $button = $(this);
    $button.prop('disabled', true);
    
    // Update button text based on mode
    if ($button.hasClass('update-mode')) {
        $button.text('Updating...');
    } else if ($button.hasClass('mixed-mode')) {
        $button.text('Processing/Updating...');
    } else {
        $button.text('Processing...');
    }
    
    // Convert selected items to array
    const selectedPostIds = Array.from(selectedItems);
    const totalToProcess = selectedPostIds.length;
    let processed = 0;
    let updated = 0;
    let failed = 0;
    const results = {
        success: [],
        updated: [],
        failed: []
    };
    
    // UPDATED: Get current bot_id for WordPress content processing
    const currentBotId = $('#mxchat-bot-selector').val();
    
    // Flag to track if processing should be aborted
    let abortProcessing = false;
    let currentXHR = null;

    // Create a modal to show progress with stop button
    const $progressModal = $('<div class="mxchat-kb-processing-overlay">' +
        '<div class="mxchat-kb-processing-content">' +
        '<h3>Processing Content</h3>' +
        '<p class="mxchat-kb-processing-status">Processing 1 of ' + totalToProcess + '...</p>' +
        '<div class="mxchat-kb-progress-bar"><div class="mxchat-kb-progress-fill" style="width: 0%"></div></div>' +
        '<p class="mxchat-kb-current-item"></p>' +
        '<button type="button" class="mxchat-kb-stop-processing mxch-btn mxch-btn-secondary" style="margin-top: 15px;">' +
        '<span class="dashicons dashicons-controls-pause" style="margin-right: 5px;"></span>Stop Processing</button>' +
        '</div>' +
        '</div>');

    $('body').append($progressModal);

    // Handle stop button click
    $progressModal.find('.mxchat-kb-stop-processing').on('click', function() {
        abortProcessing = true;
        $(this).prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="margin-right: 5px;"></span>Stopping...');
        if (currentXHR) {
            currentXHR.abort();
        }
    });
    
    // Process posts one by one
    function processNext(index) {
        // Check if processing was aborted
        if (abortProcessing) {
            finishProcessing(true); // Pass true to indicate abort
            return;
        }

        if (index >= selectedPostIds.length) {
            // All done
            finishProcessing();
            return;
        }

        const postId = selectedPostIds[index];
        const percent = Math.round((index / totalToProcess) * 100);
        const item = allItems.find(item => item.id === postId);
        const isUpdate = item && item.already_processed;

        // Update progress UI
        $progressModal.find('.mxchat-kb-processing-status')
            .text((isUpdate ? 'Updating' : 'Processing') + ' ' + (index + 1) + ' of ' + totalToProcess + '...');
        $progressModal.find('.mxchat-kb-progress-fill').css('width', percent + '%');

        // UPDATED: Prepare AJAX data with bot_id
        const ajaxData = {
            action: 'mxchat_process_selected_content',
            nonce: mxchatSelector.nonce,
            post_ids: [postId],
            is_update: isUpdate
        };

        // Add bot_id if multi-bot is active and not default
        if (currentBotId && currentBotId !== 'default') {
            ajaxData.bot_id = currentBotId;
        }

        // Make AJAX request for this post (store reference for potential abort)
        currentXHR = $.ajax({
            url: mxchatSelector.ajaxurl,
            method: 'POST',
            data: ajaxData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    if (isUpdate) {
                        updated++;
                        results.updated.push({
                            id: postId,
                            title: response.data.title || ('ID: ' + postId)
                        });
                    } else {
                        processed++;
                        results.success.push({
                            id: postId,
                            title: response.data.title || ('ID: ' + postId)
                        });
                    }
                    
                    let successText = 'Successfully ' + (isUpdate ? 'updated' : 'processed') + ': ' + response.data.title;
                    const pdfCount = parseInt(response.data.pdf_extracted_count, 10) || 0;
                    if (pdfCount > 0) {
                        const suffixTpl = (mxchatSelector.i18n && mxchatSelector.i18n.pdfExtractedSuffix) || ' (%d PDF(s) extracted)';
                        successText += suffixTpl.replace('%d', pdfCount);
                    }
                    $progressModal.find('.mxchat-kb-current-item').text(successText);
                } else {
                    failed++;
                    results.failed.push({
                        id: postId,
                        error: response.data || 'Unknown error'
                    });
                    
                    $progressModal.find('.mxchat-kb-current-item')
                        .text('Failed to ' + (isUpdate ? 'update' : 'process') + ' ID: ' + postId);
                }
                
                // Process next post
                setTimeout(function() {
                    processNext(index + 1);
                }, 500); // Small delay between requests
            },
            error: function(xhr, status, error) {
                failed++;
                results.failed.push({
                    id: postId,
                    error: error || 'Server error'
                });
                
                $progressModal.find('.mxchat-kb-current-item')
                    .text('Error ' + (isUpdate ? 'updating' : 'processing') + ' ID: ' + postId);
                
                // Process next post
                setTimeout(function() {
                    processNext(index + 1);
                }, 500);
            }
        });
    }
    
    // Function to finish processing and show results
    function finishProcessing(wasAborted) {
        // Remove progress modal
        $progressModal.remove();

        // Determine notification type based on results
        let notificationClass = 'success';
        if (wasAborted) {
            notificationClass = processed > 0 || updated > 0 ? 'warning' : 'info';
        } else if (failed > 0) {
            notificationClass = processed > 0 || updated > 0 ? 'warning' : 'error';
        }

        // Create summary message
        let resultHTML = '<div class="mxchat-kb-notification ' + notificationClass + '">' +
                       '<h4>';

        if (wasAborted) {
            resultHTML += 'Processing stopped. ';
            if (processed > 0 || updated > 0) {
                resultHTML += 'Completed ' + (processed + updated) + ' of ' + totalToProcess + ' items before stopping';
            } else {
                resultHTML += 'No items were processed before stopping';
            }
        } else if (processed > 0 && updated > 0) {
            resultHTML += 'Processed ' + processed + ' new items and updated ' + updated + ' existing items';
        } else if (processed > 0) {
            resultHTML += 'Processed ' + processed + ' items successfully';
        } else if (updated > 0) {
            resultHTML += 'Updated ' + updated + ' items successfully';
        } else {
            resultHTML += 'No items were processed successfully';
        }

        if (failed > 0 && !wasAborted) {
            resultHTML += ' with ' + failed + ' failures';
        }
        
        resultHTML += '</h4>';
        
        // Add details if there were failures
        if (failed > 0) {
            resultHTML += '<div class="mxchat-kb-results-details">';
            resultHTML += '<h5>Failed Items:</h5><ul>';
            
            results.failed.forEach(function(item) {
                resultHTML += '<li><strong>ID: ' + item.id + '</strong>: ' + item.error + '</li>';
            });
            
            resultHTML += '</ul></div>';
        }
        
        resultHTML += '</div>';
        
        // Show results in modal
        $modal.find('.mxchat-kb-modal-content').prepend($(resultHTML));
        
        // Clear selection
        selectedItems.clear();
        updateSelection();
        
        // Enable button
        $button.prop('disabled', false)
               .text('Process Selected Content')
               .removeClass('update-mode mixed-mode');
        $('.mxchat-kb-selected-count').text('(0)');

        // Only reload if there were successful operations
        if (processed > 0 || updated > 0) {
            // Refresh the knowledge base table with properly grouped entries
            if (typeof window.refreshKnowledgeBaseTable === 'function') {
                window.refreshKnowledgeBaseTable();
            }

            // Reload content list to update "already processed" status
            setTimeout(function() {
                loadContent();
            }, 1000);
        }
    }
    
    // Start processing the first post
    processNext(0);
});

    // Initialize - Set WordPress as the active option by default
    $('.mxchat-import-box[data-option="wordpress"]').addClass('active');
});

// Navigation functionality for Knowledge Base page
jQuery(document).ready(function($) {

    // Hook into the new navigation system using .mxch-nav-link
    $(document).on('click', '.mxch-nav-link[data-target], .mxch-mobile-nav-link[data-target]', function() {
        var target = $(this).data('target');

        // Initialize Pinecone functionality when Pinecone section is activated
        if (target === 'pinecone') {
            setTimeout(function() {
                if (typeof initPineconeFeatures === 'function') {
                    initPineconeFeatures();
                }
            }, 100);
        }

        // Initialize OpenAI Vector Store functionality when Vector Store section is activated
        if (target === 'openai-vectorstore') {
            setTimeout(function() {
                if (typeof initVectorStoreFeatures === 'function') {
                    initVectorStoreFeatures();
                }
            }, 100);
        }

        // Check if Pinecone was changed and we're going to import section
        if (target === 'import' && sessionStorage.getItem('mxchat_pinecone_changed') === 'true') {
            sessionStorage.removeItem('mxchat_pinecone_changed');

            // Show refresh notice
            var $knowledgeCard = $('#import .mxch-card').eq(1);
            if ($knowledgeCard.length > 0 && $knowledgeCard.find('.notice-warning').length === 0) {
                var refreshNotice = $('<div class="notice notice-warning" style="margin: 15px 0; padding: 10px 15px;">' +
                    '<p style="margin: 0;">' +
                    '<span class="dashicons dashicons-info" style="color: #f0ad4e; margin-right: 5px;"></span>' +
                    'Database settings have changed. ' +
                    '<a href="#" onclick="location.reload(); return false;" style="font-weight: bold;">Click here to refresh</a> to see the updated knowledge base.' +
                    '</p></div>');

                $knowledgeCard.prepend(refreshNotice);
            }
        }
    });

    // Also check on page load if we're already on one of these sections
    setTimeout(function() {
        if ($('#pinecone').is(':visible') || $('#pinecone.active').length > 0) {
            if (typeof initPineconeFeatures === 'function') {
                initPineconeFeatures();
            }
        }

        if ($('#openai-vectorstore').is(':visible') || $('#openai-vectorstore.active').length > 0) {
            if (typeof initVectorStoreFeatures === 'function') {
                initVectorStoreFeatures();
            }
        }
    }, 200);
});

// Pinecone and Vector Store functionality - global functions
var initPineconeFeatures, initVectorStoreFeatures;

(function($) {

    // Helper function to update sidebar badge when integration is toggled
    function updateSidebarBadge(section, isActive) {
        // Find the nav item for this section (both desktop and mobile)
        var $desktopNavItem = $('.mxch-nav-item[data-section="' + section + '"] .mxch-nav-link');
        var $mobileNavItem = $('.mxch-mobile-nav-link[data-target="' + section + '"]');

        // Remove existing badge if any
        $desktopNavItem.find('.mxch-active-badge').remove();
        $mobileNavItem.find('.mxch-active-badge').remove();

        // Add badge if active
        if (isActive) {
            var badgeHtml = '<span class="mxch-nav-link-badge mxch-active-badge">Active</span>';
            $desktopNavItem.append(badgeHtml);
            $mobileNavItem.append(badgeHtml);
        }
    }

    // Pinecone functionality
    initPineconeFeatures = function() {
        // Check for either old or new section ID
        if ($('#pinecone').length === 0 && $('#mxchat-kb-tab-pinecone').length === 0) {
            return;
        }

        initPineconeToggle();
        initPineconeIndexType();
        initPineconeConnectionTest();
        checkPineconeCompatibility();
    };

    // Index type (plan 362c31): the document-index controls on the Pinecone
    // card — Create index for me, Check index, Migrate, Delete old index —
    // plus the save gate (a document host must be checked before it saves).
    function initPineconeIndexType() {
        var $type = $('input.mxchat-pinecone-index-type');
        if ($type.length === 0) {
            return;
        }
        var $docs = $('.mxchat-pinecone-docs-settings');
        var $form = $type.closest('form');
        var $submit = $form.find('input[type="submit"], button[type="submit"]');
        var $result = $('#mxchat-pinecone-docs-result');
        var stateEl = document.getElementById('mxchat-pinecone-docs-state');
        var state = {};
        try { state = stateEl ? JSON.parse(stateEl.textContent || '{}') : {}; } catch (e) { state = {}; }
        var verifiedHost = String(state.verified_host || '').toLowerCase();
        // 3e83e4: the LIVE chatbot's index type + host as saved, the migration
        // state as last known, and the index name the page loaded with (so a
        // suggested "-docs" name can be undone by switching back to Vector).
        var liveType = state.index_type === 'document' ? 'document' : 'vector';
        var storedHost = String(state.stored_host || '').toLowerCase();
        var storedIndex = String(state.stored_index || '');
        var originalIndexName = $.trim($('#mxchat_pinecone_index').val() || '');
        var migrationState = (state.migration && state.migration.status) ? state.migration : null;
        var ajaxUrl = (typeof mxchatAdmin !== 'undefined' && mxchatAdmin.ajax_url) ? mxchatAdmin.ajax_url : ajaxurl;
        var nonce = (typeof mxchatAdmin !== 'undefined' && mxchatAdmin.settings_nonce) ? mxchatAdmin.settings_nonce :
                    ((typeof mxchatPromptsAdmin !== 'undefined' && mxchatPromptsAdmin.prompts_setting_nonce) ? mxchatPromptsAdmin.prompts_setting_nonce : '');

        function escapeText(text) { return $('<div>').text(String(text == null ? '' : text)).html(); }
        function notice(kind, text) {
            $result.html('<div class="mxch-notice mxch-notice-' + kind + '" style="margin: 12px 0;"><span>' + escapeText(text) + '</span></div>').show();
        }
        function setStatus(ok, text) {
            $('#mxchat-pinecone-docs-status').removeClass('mxch-notice-success mxch-notice-warning').addClass(ok ? 'mxch-notice-success' : 'mxch-notice-warning');
            $('#mxchat-pinecone-docs-status-text').text(text);
        }
        function currentType() { return $type.filter(':checked').val() || 'vector'; }
        function hostValue() {
            return $.trim($('#mxchat_pinecone_host').val() || '').replace(/^https?:\/\//i, '').replace(/\/+$/, '').toLowerCase();
        }
        function fmt(n) { n = parseInt(n, 10) || 0; return n.toLocaleString(); }
        // Does saving Document right now point the chatbot at an index whose copy is unfinished?
        function switchBlockedBy() {
            if (currentType() !== 'document' || liveType === 'document' || !migrationState) { return null; }
            if (migrationState.status !== 'running') { return null; }
            if (String(migrationState.target_host || '').toLowerCase() !== hostValue()) { return null; }
            return migrationState;
        }
        function refreshGate() {
            var isDoc = currentType() === 'document';
            $docs.toggle(isDoc);
            var blocked = isDoc && (hostValue() === '' || hostValue() !== verifiedHost);
            $('#mxchat-pinecone-docs-gate').toggle(blocked);
            var running = blocked ? null : switchBlockedBy();
            var $switchGate = $('#mxchat-pinecone-docs-switch-gate');
            if (running) {
                $('#mxchat-pinecone-docs-switch-gate-text').text(
                    'The copy into ' + (running.target_name || running.target_host) + ' is still running (' + fmt(running.copied) + ' of ' + fmt(running.total) +
                    ' records). Let it finish before switching the chatbot to the document index.'
                );
                $switchGate.show();
                blocked = !$('#mxchat_pinecone_docs_switch_anyway').is(':checked');
            } else {
                $switchGate.hide();
                $('#mxchat_pinecone_docs_switch_anyway').prop('checked', false);
            }
            $submit.prop('disabled', blocked);
        }
        $('#mxchat_pinecone_docs_switch_anyway').off('change.pineconeDocs').on('change.pineconeDocs', refreshGate);
        $('#mxchat_pinecone_host').off('input.pineconeDocs change.pineconeDocs').on('input.pineconeDocs change.pineconeDocs', refreshGate);

        function post(data) {
            data.nonce = nonce;
            return $.post(ajaxUrl, data);
        }

        // ---- Index type change: a document index needs its own name, so when
        // the field still holds the vector index's name (or is empty) suggest a
        // free "<name>-docs" from the project; Vector restores the original.
        var suggestedName = '';
        function nameIsOurs() {
            var name = $.trim($('#mxchat_pinecone_index').val() || '');
            return name === '' || name === storedIndex || name === originalIndexName || name === suggestedName;
        }
        // When the host in the field is a checked document index, the name is
        // that index's own; otherwise a free "<name>-docs" for the create button.
        function suggestDocsName() {
            var name = $.trim($('#mxchat_pinecone_index').val() || '');
            var apiKey = $('#mxchat_pinecone_api_key').val();
            if (liveType === 'document' || !apiKey || currentType() !== 'document') { return; }
            if (!nameIsOurs()) { return; } // the owner typed their own
            var host = (hostValue() !== '' && hostValue() === verifiedHost) ? hostValue() : '';
            post({ action: 'mxchat_pinecone_docs_suggest_name', base: name || storedIndex, host: host, api_key: apiKey }).done(function (response) {
                if (response && response.success && response.data && response.data.name && currentType() === 'document' && nameIsOurs()) {
                    suggestedName = response.data.name;
                    $('#mxchat_pinecone_index').val(suggestedName);
                }
            });
        }
        $type.off('change.pineconeDocs').on('change.pineconeDocs', function () {
            if (currentType() === 'document') {
                suggestDocsName();
            } else if (suggestedName !== '' && $.trim($('#mxchat_pinecone_index').val() || '') === suggestedName) {
                $('#mxchat_pinecone_index').val(originalIndexName);
            }
            refreshGate();
        });
        // Pasting the checked document host resolves the name to that index's own.
        $('#mxchat_pinecone_host').on('change.pineconeDocsName', function () {
            if (currentType() === 'document' && hostValue() !== '' && hostValue() === verifiedHost) { suggestDocsName(); }
        });
        refreshGate();
        function failMessage(response, fallback) {
            if (response && response.data) {
                if (typeof response.data === 'string') { return response.data; }
                if (response.data.message) { return response.data.message; }
            }
            return fallback;
        }

        // ---- Create index for me
        $('#mxchat-pinecone-docs-create').off('click.pineconeDocs').on('click.pineconeDocs', function () {
            var $btn = $(this);
            var name = $.trim($('#mxchat_pinecone_index').val() || '');
            if (!name) {
                notice('error', 'Enter an Index Name first — the document index is created under that name.');
                return;
            }
            $btn.prop('disabled', true).text('Creating…');
            $result.hide();
            post({
                action: 'mxchat_pinecone_docs_create_index',
                index_name: name,
                api_key: $('#mxchat_pinecone_api_key').val(),
                cloud: $('#mxchat_pinecone_docs_cloud').val(),
                region: $('#mxchat_pinecone_docs_region').val(),
                language: $('#mxchat_pinecone_docs_language').val()
            }).done(function (response) {
                if (response && response.success) {
                    $('#mxchat_pinecone_host').val(response.data.host);
                    $('#mxchat_pinecone_index').val(response.data.name);
                    if (response.data.vector_host && !$.trim($('#mxchat_pinecone_vector_host').val() || '')) {
                        $('#mxchat_pinecone_vector_host').val(response.data.vector_host);
                    }
                    verifiedHost = String(response.data.host || '').toLowerCase();
                    setStatus(true, 'Document index checked: ' + response.data.host + ' (' + response.data.dimension + ' dimensions).');
                    notice(response.data.ready ? 'success' : 'info', response.data.message);
                    refreshGate();
                } else {
                    // A taken name comes back with a free suggestion — fill it in so the next click works.
                    if (response && response.data && response.data.suggested) {
                        suggestedName = response.data.suggested;
                        $('#mxchat_pinecone_index').val(response.data.suggested);
                    }
                    notice('error', failMessage(response, 'Could not create the index.'));
                }
            }).fail(function () {
                notice('error', 'Could not reach the server to create the index.');
            }).always(function () {
                $btn.prop('disabled', false).text('Create index for me');
            });
        });

        // ---- Check index
        $('#mxchat-pinecone-docs-check').off('click.pineconeDocs').on('click.pineconeDocs', function () {
            var $btn = $(this);
            var host = hostValue();
            if (!host) {
                notice('error', 'Enter the Pinecone Host of the document index first.');
                return;
            }
            $btn.prop('disabled', true).text('Checking…');
            $result.hide();
            post({
                action: 'mxchat_pinecone_docs_check_index',
                host: host,
                api_key: $('#mxchat_pinecone_api_key').val()
            }).done(function (response) {
                if (response && response.success) {
                    verifiedHost = String(response.data.host || host).toLowerCase();
                    if (response.data.name) { $('#mxchat_pinecone_index').val(response.data.name); }
                    if (response.data.vector_host && !$.trim($('#mxchat_pinecone_vector_host').val() || '')) {
                        $('#mxchat_pinecone_vector_host').val(response.data.vector_host);
                    }
                    setStatus(true, 'Document index checked: ' + response.data.host + ' (' + response.data.dimension + ' dimensions).');
                    notice('success', response.data.message);
                } else {
                    setStatus(false, 'Not checked: this host cannot be used as a document index.');
                    notice('error', failMessage(response, 'Check failed.'));
                }
                refreshGate();
            }).fail(function () {
                notice('error', 'Could not reach the server to check the index.');
            }).always(function () {
                $btn.prop('disabled', false).text('Check index');
            });
        });

        // ---- Migrate (copy, page by page, resumable). 3e83e4: the copy runs
        // against the document host in the form while the chatbot keeps
        // answering from the vector index; a dropped request is retried with
        // backoff, and a second click continues from the recorded position.
        var $migrateBtn = $('#mxchat-pinecone-docs-migrate');
        // e50d2e: "Copy every namespace" — offered once the start response
        // reports records in other namespaces; the copy then walks every
        // namespace of the source in one run, each into its own namespace on
        // the document side. Disabled while a copy runs in this tab (the
        // server extends a running copy on the next Migrate click, never
        // underneath a step that is in flight).
        var $allNs = $('#mxchat_pinecone_docs_all_namespaces');
        var $allNsRow = $('#mxchat-pinecone-docs-all-namespaces');
        var stepRetries = 0;
        var STEP_RETRY_DELAYS = [1000, 2000, 4000, 8000, 16000];
        function remainingOf(st) {
            var total = parseInt(st.total, 10) || 0, copied = parseInt(st.copied, 10) || 0;
            return Math.max(0, total - copied);
        }
        function otherNamespacesText(st) {
            var other = st.other_namespaces || {};
            var parts = [];
            $.each(other, function (name, count) { parts.push(name + ' (' + fmt(count) + ')'); });
            return parts.join(', ');
        }
        function otherNamespacesSummary(st) {
            var records = 0, n = 0;
            $.each(st.other_namespaces || {}, function (name, count) { records += parseInt(count, 10) || 0; n++; });
            return n ? { records: records, count: n } : null;
        }
        function namespaceEntries(st) {
            var out = [];
            $.each(st.per_namespace || {}, function (key, e) { out.push($.extend({ key: key }, e)); });
            return out;
        }
        function isMulti(st) { return !!st.all_namespaces || namespaceEntries(st).length > 1; }
        function namespaceLine(e, sep) {
            return e.key + ' ' + fmt(e.copied) + ' of ' + fmt(e.total) + (e.target_count ? (sep + 'the document index reports ' + fmt(e.target_count) + ')') : '');
        }
        function renderMigration(st) {
            migrationState = (st && st.status) ? st : null;
            var $progress = $('#mxchat-pinecone-docs-migrate-progress');
            if (!st || !st.status) {
                $progress.hide();
                $('#mxchat-pinecone-docs-migrate-counts').text('');
                $('#mxchat-pinecone-docs-delete-old').hide();
                $allNsRow.hide();
                refreshGate();
                return;
            }
            var total = parseInt(st.total, 10) || 0;
            var copied = parseInt(st.copied, 10) || 0;
            var pct = total > 0 ? Math.min(100, Math.round(copied * 100 / total)) : (st.status === 'done' ? 100 : 0);
            var multi = isMulti(st);
            var entries = namespaceEntries(st);
            var others = otherNamespacesSummary(st);
            var label, suffix;
            if (st.status === 'done') {
                suffix = ' — done' + (st.target_count ? ('; the document index reports ' + fmt(st.target_count) + ' records') : '');
            } else if (st.status === 'source_deleted') {
                suffix = ' — old index deleted';
            } else if (st.status === 'running') {
                suffix = st.error ? (' — stopped: ' + st.error) : (' — copying, ' + fmt(remainingOf(st)) + ' remaining…');
            } else {
                suffix = '';
            }
            if (multi && st.status === 'running') {
                var idx = 0, cur = null;
                $.each(entries, function (i, e) { if (e.key === (st.current === '' ? '__default__' : st.current)) { idx = i + 1; cur = e; } });
                label = (cur ? ('Namespace ' + idx + ' of ' + entries.length + ' (' + cur.key + ') — ' + fmt(cur.copied) + ' of ' + fmt(cur.total) + ' records copied; ') : '') +
                    fmt(copied) + ' of ' + fmt(total) + ' overall (' + pct + '%)' + (st.error ? (' — stopped: ' + st.error) : (', ' + fmt(remainingOf(st)) + ' remaining…'));
            } else if (multi) {
                label = fmt(copied) + ' of ' + fmt(total) + ' records copied across ' + entries.length + ' namespaces (' + pct + '%)' + suffix;
            } else {
                label = fmt(copied) + ' of ' + fmt(total) + ' records copied (' + pct + '%)' + suffix;
            }
            $progress.show().find('.mxch-progress-bar-fill').css('width', pct + '%');
            $progress.find('.mxch-progress-label').text(label);
            var counts;
            if (multi) {
                var lines = $.map(entries, function (e) { return namespaceLine(e, ' ('); });
                counts = (st.source_name ? ('Source: ' + st.source_name + ' (' + fmt(total) + ' records in ' + entries.length + ' namespaces). ') : '') + lines.join(' · ') + '.';
            } else {
                counts = st.source_name ? ('Source: ' + st.source_name + ' (' + fmt(total) + ' records' + (st.namespace ? (' in namespace ' + st.namespace) : '') + ')') : '';
                if (others) {
                    counts += (counts ? '. ' : '') + 'Not copied — ' + fmt(others.records) + ' records in ' + others.count + ' other namespace' + (others.count === 1 ? '' : 's') + ': ' + otherNamespacesText(st) + '. Tick Copy every namespace and click Migrate to include them.';
                }
            }
            $('#mxchat-pinecone-docs-migrate-counts').text(counts);
            // The toggle appears once the source is known to have other namespaces, and stays on for a copy of every namespace.
            $allNsRow.toggle(multi || !!others);
            if (st.all_namespaces) { $allNs.prop('checked', true); }
            $allNs.prop('disabled', $migrateBtn.prop('disabled'));
            // Delete old index is never offered while the copy is known to leave namespaces behind (the server refuses too).
            $('#mxchat-pinecone-docs-delete-old').toggle(st.status === 'done' && total > 0 && copied >= total && liveType === 'document' && !others);
            refreshGate();
        }
        function migrateFinished(st) {
            $migrateBtn.prop('disabled', false).text('Migrate');
            $allNs.prop('disabled', false);
            var total = parseInt(st.total, 10) || 0, copied = parseInt(st.copied, 10) || 0;
            var reported = st.target_count ? (' The document index reports ' + fmt(st.target_count) + ' records.') : '';
            var stillVector = liveType === 'document' ? '' : ' Your chatbot is still on the vector index — save the Pinecone settings to switch it over.';
            var entries = namespaceEntries(st);
            var others = otherNamespacesSummary(st);
            if (isMulti(st)) {
                var lines = $.map(entries, function (e) { return namespaceLine(e, ' ('); });
                if (copied >= total) {
                    notice('success', 'Copy finished: ' + fmt(copied) + ' of ' + fmt(total) + ' records across ' + entries.length + ' namespaces are in the document index — ' + lines.join(', ') + '.' + stillVector);
                } else {
                    var short = $.map(entries, function (e) { return (parseInt(e.copied, 10) || 0) < (parseInt(e.total, 10) || 0) ? namespaceLine(e, ' (') : null; });
                    notice('warning', 'The copy reached the end of every namespace after ' + fmt(copied) + ' records, but ' + fmt(total) + ' were expected. Short: ' + short.join(', ') + '.' + reported +
                        (parseInt(st.skipped, 10) ? (' ' + fmt(st.skipped) + ' records had no vector and were skipped.') : ''));
                }
                return;
            }
            if (copied >= total) {
                notice(others ? 'warning' : 'success', 'Copy finished: ' + fmt(copied) + ' of ' + fmt(total) + ' records are in the document index.' + reported +
                    (others ? (' ' + fmt(others.records) + ' records in ' + others.count + ' other namespace' + (others.count === 1 ? ' were' : 's were') + ' not copied: ' + otherNamespacesText(st) + '. Tick Copy every namespace and click Migrate to include them.') : '') + stillVector);
            } else {
                notice('warning', 'The copy reached the end of the source namespace after ' + fmt(copied) + ' records, but ' + fmt(total) + ' were expected.' + reported +
                    (others ? (' Records in other namespaces were not copied: ' + otherNamespacesText(st) + '.') : '') +
                    (parseInt(st.skipped, 10) ? (' ' + fmt(st.skipped) + ' records had no vector and were skipped.') : ''));
            }
        }
        function migrateStep() {
            post({ action: 'mxchat_pinecone_docs_migrate', mode: 'step', target_host: hostValue(), api_key: $('#mxchat_pinecone_api_key').val() }).done(function (response) {
                stepRetries = 0;
                if (response && response.success) {
                    renderMigration(response.data.state);
                    if (response.data.state && response.data.state.status === 'running') {
                        setTimeout(migrateStep, 250);
                    } else {
                        migrateFinished(response.data.state || {});
                    }
                } else {
                    var st = (response && response.data && response.data.state) ? response.data.state : null;
                    if (st) { renderMigration(st); }
                    notice('error', failMessage(response, 'The copy stopped.') + (st ? (' Click Migrate to continue from record ' + fmt(st.copied) + '.') : ' Click Migrate to resume.'));
                    $migrateBtn.prop('disabled', false).text('Migrate');
                    $allNs.prop('disabled', false);
                }
            }).fail(function (xhr) {
                // The server did not answer (timeout, 5xx, rate limit): wait and try the same step again.
                if (stepRetries < STEP_RETRY_DELAYS.length) {
                    var delay = STEP_RETRY_DELAYS[stepRetries++];
                    notice('info', 'No answer from the server (HTTP ' + (xhr && xhr.status ? xhr.status : 0) + '). Retrying in ' + Math.round(delay / 1000) + ' s… the copy continues from where it stopped.');
                    setTimeout(migrateStep, delay);
                    return;
                }
                stepRetries = 0;
                notice('error', 'The server stopped answering (HTTP ' + (xhr && xhr.status ? xhr.status : 0) + ') after several tries. Nothing is lost — click Migrate to continue from the last record copied.');
                $migrateBtn.prop('disabled', false).text('Migrate');
                $allNs.prop('disabled', false);
            });
        }
        $migrateBtn.off('click.pineconeDocs').on('click.pineconeDocs', function () {
            var source = $.trim($('#mxchat_pinecone_vector_host').val() || '');
            if (!source) {
                notice('error', 'Enter the host of the vector index to copy from.');
                return;
            }
            if (!hostValue()) {
                notice('error', 'Enter the host of the document index to copy into, or create one with Create index for me.');
                return;
            }
            $migrateBtn.prop('disabled', true).text('Copying…');
            $allNs.prop('disabled', true);
            $result.hide();
            stepRetries = 0;
            post({ action: 'mxchat_pinecone_docs_migrate', mode: 'start', source_host: source, target_host: hostValue(), api_key: $('#mxchat_pinecone_api_key').val(), all_namespaces: $allNs.is(':checked') ? 1 : 0 }).done(function (response) {
                if (response && response.success) {
                    var st = response.data.state || {};
                    if (st.target_host && !verifiedHost) { verifiedHost = String(st.target_host).toLowerCase(); setStatus(true, 'Document index checked: ' + st.target_host + '.'); }
                    renderMigration(st);
                    if (st.status === 'running') {
                        migrateStep();
                    } else {
                        migrateFinished(st);
                    }
                } else {
                    notice('error', failMessage(response, 'The copy could not start.'));
                    $migrateBtn.prop('disabled', false).text('Migrate');
                    $allNs.prop('disabled', false);
                }
            }).fail(function () {
                notice('error', 'Could not reach the server to start the copy.');
                $migrateBtn.prop('disabled', false).text('Migrate');
                $allNs.prop('disabled', false);
            });
        });
        renderMigration(state.migration || null);

        // ---- Delete old index (typed confirmation)
        $('#mxchat-pinecone-docs-delete-old-btn').off('click.pineconeDocs').on('click.pineconeDocs', function () {
            var $btn = $(this);
            var confirmName = $.trim($('#mxchat_pinecone_docs_delete_confirm').val() || '');
            if (!confirmName) {
                notice('error', 'Type the old index name to confirm.');
                return;
            }
            $btn.prop('disabled', true).text('Deleting…');
            post({ action: 'mxchat_pinecone_docs_delete_old_index', confirm_name: confirmName }).done(function (response) {
                if (response && response.success) {
                    notice('success', response.data.message);
                    $('#mxchat_pinecone_vector_host').val('');
                    $('#mxchat_pinecone_docs_delete_confirm').val('');
                    renderMigration(response.data.state);
                } else {
                    notice('error', failMessage(response, 'The old index was not deleted.'));
                }
            }).fail(function () {
                notice('error', 'Could not reach the server.');
            }).always(function () {
                $btn.prop('disabled', false).text('Delete old index');
            });
        });
    }

    function initPineconeToggle() {
        // Remove any existing handlers to prevent duplicates
        var $toggleInput = $('input[name="mxchat_pinecone_addon_options[mxchat_use_pinecone]"]');
        $toggleInput.off('change.pineconeToggle');

        // Ensure the success notice exists in the settings div (add if not present)
        // Check for both the JS-added class and any existing PHP-rendered success notice
        var settingsDiv = $('.mxchat-pinecone-settings');
        if (settingsDiv.length > 0 && settingsDiv.find('.mxch-notice-success').length === 0) {
            var successNotice = $('<div class="mxch-notice mxch-notice-success mxchat-pinecone-enabled-notice" style="margin-bottom: 20px; display: none;">' +
                '<svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
                '<span>Pinecone is enabled. All new knowledge base content will be stored in Pinecone.</span>' +
                '</div>');
            settingsDiv.prepend(successNotice);
        }

        // Add the toggle handler for UI only (auto-save will handle the actual saving)
        $toggleInput.on('change.pineconeToggle', function() {
            var $checkbox = $(this);
            var isChecked = $checkbox.is(':checked');
            var settingsDiv = $('.mxchat-pinecone-settings');
            var enabledNotice = settingsDiv.find('.mxchat-pinecone-enabled-notice, .mxch-notice-success');

            // Update the UI immediately
            if (isChecked) {
                settingsDiv.slideDown(300);
                enabledNotice.slideDown(300);
            } else {
                enabledNotice.slideUp(300);
                settingsDiv.slideUp(300);
            }

            // Update sidebar badge for Pinecone
            updateSidebarBadge('pinecone', isChecked);
        });

        // Set initial state based on current checkbox value
        var currentToggle = $('input[name="mxchat_pinecone_addon_options[mxchat_use_pinecone]"]');
        if (currentToggle.length > 0) {
            var settingsDiv = $('.mxchat-pinecone-settings');
            var enabledNotice = settingsDiv.find('.mxchat-pinecone-enabled-notice, .mxch-notice-success');
            if (currentToggle.is(':checked')) {
                settingsDiv.show();
                enabledNotice.show();
            } else {
                settingsDiv.hide();
                enabledNotice.hide();
            }
        }
    }
    
    function initPineconeConnectionTest() {
        $('#test-pinecone-connection').off('click.pinecone');
        
        $('#test-pinecone-connection').on('click.pinecone', function() {
            var button = $(this);
            var resultDiv = $('#connection-test-result');
            
            var apiKey = $('#mxchat_pinecone_api_key').val();
            var host = $('#mxchat_pinecone_host').val();
            var index = $('#mxchat_pinecone_index').val();
            
            if (!apiKey || !host || !index) {
                resultDiv.html('<div class="notice notice-error"><p>Please fill in all required fields first.</p></div>').show();
                return;
            }
            
            button.prop('disabled', true).text('Testing...');
            resultDiv.hide();
            
            var ajaxUrl = (typeof mxchatPromptsAdmin !== 'undefined') ? mxchatPromptsAdmin.ajax_url : ajaxurl;
            var nonce = (typeof mxchatPromptsAdmin !== 'undefined') ? mxchatPromptsAdmin.prompts_setting_nonce : 
                       (typeof mxchatAdmin !== 'undefined') ? mxchatAdmin.setting_nonce : '';
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mxchat_test_pinecone_connection',
                    _ajax_nonce: nonce,
                    api_key: apiKey,
                    host: host,
                    index_name: index
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p><span class="dashicons dashicons-warning"></span> ' + response.data.message + '</p></div>');
                    }
                    resultDiv.show();
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error"><p>Connection test failed. Please check your settings.</p></div>').show();
                },
                complete: function() {
                    button.prop('disabled', false).text('Test Connection');
                }
            });
        });
    }
    
    function checkPineconeCompatibility() {
        if ($('.mxchat-pinecone-compatibility-notice').length > 0) {
            return;
        }
        
        var hasOldAddon = $('body').hasClass('mxchat-pinecone-addon-active') || 
                         $('.pcm-card').length > 0;
        
        if (hasOldAddon) {
            var compatibilityNotice = $(`
                <div class="notice notice-info mxchat-pinecone-compatibility-notice">
                    <p><strong>Pinecone Integration Notice:</strong> We've detected you have the Pinecone add-on installed. 
                    Pinecone functionality is now built into the core plugin. You can safely deactivate the separate 
                    Pinecone add-on after confirming your settings are migrated below.</p>
                </div>
            `);
            
            $('#mxchat-kb-tab-pinecone .mxchat-card').prepend(compatibilityNotice);
            
            migratePineconeSettings();
        }
    }
    
    function migratePineconeSettings() {
        if (typeof ajaxurl !== 'undefined') {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mxchat_migrate_pinecone_settings',
                    _ajax_nonce: (typeof mxchatAdmin !== 'undefined') ? mxchatAdmin.setting_nonce : ''
                },
                success: function(response) {
                    if (response.success && response.data.migrated) {
                        location.reload();
                    }
                },
                error: function() {
                    //console.log('Pinecone settings migration not available');
                }
            });
        }
    }

    // ============================================
    // OpenAI Vector Store functionality
    // ============================================
    initVectorStoreFeatures = function() {
        if ($('#openai-vectorstore').length === 0) {
            return;
        }

        initVectorStoreToggle();
    };

    function initVectorStoreToggle() {
        // Remove any existing handlers to prevent duplicates
        var $toggleInput = $('input[name="mxchat_openai_vectorstore_options[mxchat_use_openai_vectorstore]"]');
        $toggleInput.off('change.vectorstoreToggle');

        // Ensure the success notice exists in the settings div (add if not present)
        // Check for both the JS-added class and any existing PHP-rendered success notice
        var settingsDiv = $('.mxchat-vectorstore-settings');
        if (settingsDiv.length > 0 && settingsDiv.find('.mxch-notice-success').length === 0) {
            var successNotice = $('<div class="mxch-notice mxch-notice-success mxchat-vectorstore-enabled-notice" style="margin-bottom: 20px; display: none;">' +
                '<svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
                '<span>OpenAI Vector Store is enabled. Queries will search your Vector Store for relevant content.</span>' +
                '</div>');
            settingsDiv.prepend(successNotice);
        }

        // Add the toggle handler for UI only (form submit will handle the actual saving)
        $toggleInput.on('change.vectorstoreToggle', function() {
            var $checkbox = $(this);
            var isChecked = $checkbox.is(':checked');
            var settingsDiv = $('.mxchat-vectorstore-settings');
            var enabledNotice = settingsDiv.find('.mxchat-vectorstore-enabled-notice, .mxch-notice-success');

            // Update the UI immediately
            if (isChecked) {
                settingsDiv.slideDown(300);
                enabledNotice.slideDown(300);
            } else {
                enabledNotice.slideUp(300);
                settingsDiv.slideUp(300);
            }

            // Update sidebar badge for OpenAI Vector Store
            updateSidebarBadge('openai-vectorstore', isChecked);
        });

        // Set initial state based on current checkbox value
        var currentToggle = $('input[name="mxchat_openai_vectorstore_options[mxchat_use_openai_vectorstore]"]');
        if (currentToggle.length > 0) {
            var settingsDiv = $('.mxchat-vectorstore-settings');
            var enabledNotice = settingsDiv.find('.mxchat-vectorstore-enabled-notice, .mxch-notice-success');
            if (currentToggle.is(':checked')) {
                settingsDiv.show();
                enabledNotice.show();
            } else {
                settingsDiv.hide();
                enabledNotice.hide();
            }
        }
    }

})(jQuery);