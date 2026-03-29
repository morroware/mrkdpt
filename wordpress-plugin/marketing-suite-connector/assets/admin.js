/**
 * Marketing Suite Connector - Admin JavaScript
 * Version 2.0.0
 */
(function ($) {
    'use strict';

    const API = mscData.restUrl;
    const NONCE = mscData.nonce;

    // =========================================================================
    //  Utilities
    // =========================================================================

    function apiRequest(endpoint, options = {}) {
        const opts = {
            url: API + endpoint,
            method: options.method || 'GET',
            contentType: 'application/json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', NONCE);
            },
        };
        if (options.body) {
            opts.data = JSON.stringify(options.body);
        }
        return $.ajax(opts);
    }

    function setLoading(btn, loading) {
        if (loading) {
            btn.addClass('msc-loading-btn').prop('disabled', true);
            btn.data('original-text', btn.html());
        } else {
            btn.removeClass('msc-loading-btn').prop('disabled', false);
            const originalText = btn.data('original-text');
            if (originalText) {
                btn.html(originalText);
            }
        }
    }

    function getErrorMessage(xhr, fallback) {
        return (
            xhr?.responseJSON?.message
            || xhr?.responseJSON?.error
            || xhr?.responseJSON?.data?.message
            || fallback
        );
    }

    function showNotice(container, message, type) {
        const cls = type === 'error' ? 'notice-error' : 'notice-success';
        const html = `<div class="notice ${cls} is-dismissible" style="margin:10px 0;"><p>${escHtml(message)}</p></div>`;
        container.prepend(html);
        setTimeout(() => container.find('.notice').first().fadeOut(300, function () { $(this).remove(); }), 5000);
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str || '');
        return div.innerHTML;
    }

    // =========================================================================
    //  Tab Navigation
    // =========================================================================

    $(document).on('click', '.msc-tabs .nav-tab', function (e) {
        e.preventDefault();
        const tab = $(this).data('tab');

        // Update tab buttons
        $('.msc-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        // Update tab panels
        $('.msc-tab-panel').hide().removeClass('msc-tab-active');
        $('#msc-tab-' + tab).show().addClass('msc-tab-active');
    });

    // =========================================================================
    //  Settings: Test Connection
    // =========================================================================

    $(document).on('click', '.msc-test-connection', function () {
        const btn = $(this);
        const result = btn.siblings('.msc-test-result');

        setLoading(btn, true);
        result.text('Testing...');

        apiRequest('test-connection', { method: 'POST' })
            .done(function (data) {
                result.css('color', '#166534').text(data.message || 'Connected!');
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Connection failed.');
                result.css('color', '#991b1b').text(msg);
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // =========================================================================
    //  Dashboard: Load metrics
    // =========================================================================

    function loadDashboard() {
        const grid = $('#msc-metrics-grid');
        const recent = $('#msc-recent-content');
        const campaigns = $('#msc-campaigns');

        if (!grid.length) return;

        apiRequest('analytics')
            .done(function (data) {
                // Metrics
                let metricsHtml = '';
                const metrics = [
                    { key: 'total_posts', label: 'Total Posts', icon: 'admin-post' },
                    { key: 'published_posts', label: 'Published', icon: 'yes-alt' },
                    { key: 'scheduled_posts', label: 'Scheduled', icon: 'clock' },
                    { key: 'draft_posts', label: 'Drafts', icon: 'edit' },
                    { key: 'campaigns', label: 'Campaigns', icon: 'megaphone' },
                    { key: 'contacts', label: 'Contacts', icon: 'groups' },
                    { key: 'synced_items', label: 'Synced', icon: 'update' },
                ];
                metrics.forEach(function (m) {
                    if (data[m.key] !== undefined) {
                        metricsHtml += `
                            <div class="msc-metric-card">
                                <span class="dashicons dashicons-${m.icon} msc-metric-icon"></span>
                                <span class="msc-metric-value">${escHtml(String(data[m.key]))}</span>
                                <span class="msc-metric-label">${escHtml(m.label)}</span>
                            </div>`;
                    }
                });
                grid.html(metricsHtml || '<p>No metrics available.</p>');

                // Recent content
                if (data.recent_posts && data.recent_posts.length > 0) {
                    let recentHtml = '<ul class="msc-recent-list">';
                    data.recent_posts.slice(0, 8).forEach(function (p) {
                        recentHtml += `
                            <li>
                                <strong>${escHtml(p.title || 'Untitled')}</strong>
                                <span class="msc-badge msc-badge-${escHtml(p.status || 'draft')}">${escHtml(p.status || 'draft')}</span>
                            </li>`;
                    });
                    recentHtml += '</ul>';
                    recent.html(recentHtml);
                } else {
                    recent.html('<p>No recent content.</p>');
                }

                // Campaigns
                if (data.campaigns_list && data.campaigns_list.length > 0) {
                    let campHtml = '<ul class="msc-campaigns-list">';
                    data.campaigns_list.slice(0, 5).forEach(function (c) {
                        campHtml += `
                            <li>
                                <span class="msc-campaign-name">${escHtml(c.name || 'Untitled')}</span>
                                <span class="msc-campaign-meta">${escHtml(c.status || '')}</span>
                            </li>`;
                    });
                    campHtml += '</ul>';
                    campaigns.html(campHtml);
                } else {
                    campaigns.html('<p>No active campaigns.</p>');
                }

                // Recent syncs
                if (data.recent_syncs && data.recent_syncs.length > 0) {
                    let syncHtml = '<ul class="msc-recent-list">';
                    data.recent_syncs.forEach(function (s) {
                        const dir = s.sync_direction === 'push' ? '&rarr; WP' : '&larr; WP';
                        syncHtml += `<li><span>${escHtml(s.local_type)} #${s.local_id} ${dir} #${s.wp_id}</span><small>${escHtml(s.last_synced_at || '')}</small></li>`;
                    });
                    syncHtml += '</ul>';
                    const syncEl = $('#msc-recent-syncs');
                    if (syncEl.length) syncEl.html(syncHtml);
                }
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Failed to load dashboard data.');
                grid.html(`<p class="msc-error">${escHtml(msg)}</p>`);
                recent.html('');
                campaigns.html('');
            });
    }

    $(document).on('click', '.msc-refresh-dashboard', function () {
        const btn = $(this);
        setLoading(btn, true);
        loadDashboard();
        setTimeout(() => setLoading(btn, false), 1000);
    });

    // =========================================================================
    //  Content Sync: Fetch remote posts (Pull tab)
    // =========================================================================

    $(document).on('click', '#msc-fetch-remote', function () {
        const btn = $(this);
        const container = $('#msc-remote-posts');
        const status = $('#msc-pull-status').val();
        const platform = $('#msc-pull-platform').val();

        setLoading(btn, true);

        let qs = 'pull-posts';
        const params = [];
        if (status) params.push('status=' + encodeURIComponent(status));
        if (platform) params.push('platform=' + encodeURIComponent(platform));
        if (params.length) qs += '?' + params.join('&');

        apiRequest(qs)
            .done(function (data) {
                const posts = data.items || [];
                if (posts.length === 0) {
                    container.html('<p>No content found matching your filters.</p>');
                    $('#msc-pull-bulk').hide();
                    return;
                }

                let html = '<table class="msc-remote-table">';
                html += '<thead><tr><th class="check-column"><input type="checkbox" class="msc-pull-check-all" /></th><th>Title</th><th>Platform</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
                posts.forEach(function (p) {
                    html += `
                        <tr>
                            <td><input type="checkbox" class="msc-pull-check" value="${parseInt(p.id)}" /></td>
                            <td>${escHtml(p.title || 'Untitled')}</td>
                            <td>${escHtml(p.platform || '-')}</td>
                            <td><span class="msc-badge msc-badge-${escHtml(p.status || 'draft')}">${escHtml(p.status || 'draft')}</span></td>
                            <td><small>${escHtml(p.created_at || '')}</small></td>
                            <td>
                                <button type="button" class="button button-small msc-import-post" data-remote-id="${parseInt(p.id)}">
                                    Import to WP
                                </button>
                            </td>
                        </tr>`;
                });
                html += '</tbody></table>';
                container.html(html);
                $('#msc-pull-bulk').show();
                updatePullSelectedCount();
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Failed to fetch content.');
                container.html(`<p class="msc-error">${escHtml(msg)}</p>`);
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // Select all for pull
    $(document).on('change', '.msc-pull-check-all, #msc-pull-select-all', function () {
        const checked = $(this).is(':checked');
        $('.msc-pull-check').prop('checked', checked);
        updatePullSelectedCount();
    });

    $(document).on('change', '.msc-pull-check', function () {
        updatePullSelectedCount();
    });

    function updatePullSelectedCount() {
        const count = $('.msc-pull-check:checked').length;
        $('#msc-pull-selected-count').text(count + ' selected');
    }

    // =========================================================================
    //  Content Sync: Import single remote post
    // =========================================================================

    $(document).on('click', '.msc-import-post', function () {
        const btn = $(this);
        const remoteId = btn.data('remote-id');
        const postType = $('#msc-pull-import-as').val() || 'post';

        setLoading(btn, true);

        apiRequest('import-post', {
            method: 'POST',
            body: { remote_id: remoteId, post_type: postType },
        })
            .done(function (data) {
                if (data.edit_url) {
                    btn.replaceWith(`<a href="${escHtml(data.edit_url)}" class="button button-small" target="_blank">Edit ${data.existing ? '(existing)' : 'Draft'}</a>`);
                } else {
                    btn.text('Imported!').prop('disabled', true);
                }
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Import failed.');
                alert(msg);
                setLoading(btn, false);
            });
    });

    // =========================================================================
    //  Content Sync: Bulk import
    // =========================================================================

    $(document).on('click', '#msc-bulk-import', function () {
        const btn = $(this);
        const remoteIds = [];
        const postType = $('#msc-pull-import-as').val() || 'post';

        $('.msc-pull-check:checked').each(function () {
            remoteIds.push(parseInt($(this).val()));
        });

        if (remoteIds.length === 0) {
            alert('Please select at least one post to import.');
            return;
        }

        setLoading(btn, true);

        apiRequest('bulk-import', {
            method: 'POST',
            body: { remote_ids: remoteIds, post_type: postType },
        })
            .done(function (data) {
                const msg = data.message || `${remoteIds.length} posts processed.`;
                showNotice($('#msc-tab-pull .msc-card'), msg, 'success');

                // Update buttons for successfully imported items
                if (data.results) {
                    data.results.forEach(function (r) {
                        if (r.success && r.edit_url) {
                            const btn = $(`.msc-import-post[data-remote-id="${r.remote_id}"]`);
                            btn.replaceWith(`<a href="${escHtml(r.edit_url)}" class="button button-small" target="_blank">Edit ${r.existing ? '(existing)' : 'Draft'}</a>`);
                        }
                    });
                }
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Bulk import failed.');
                alert(msg);
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // =========================================================================
    //  Push post to Marketing Suite
    // =========================================================================

    $(document).on('click', '.msc-push-post', function () {
        const btn = $(this);
        const postId = btn.data('post-id');

        setLoading(btn, true);

        apiRequest('push-post', {
            method: 'POST',
            body: { post_id: postId },
        })
            .done(function (data) {
                const msg = data.message || 'Post pushed successfully.';
                if (btn.closest('.msc-metabox').length) {
                    showNotice(btn.closest('.msc-metabox'), msg, 'success');
                    if (data.remote_id) {
                        btn.text('Update in Marketing Suite');
                    }
                } else {
                    btn.text('Pushed!');
                    setTimeout(() => btn.text('Re-push').prop('disabled', false), 2000);
                }
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Push failed.');
                alert(msg);
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // =========================================================================
    //  Bulk push
    // =========================================================================

    // Select all for push
    $(document).on('change', '.msc-push-check-all, #msc-push-select-all', function () {
        const checked = $(this).is(':checked');
        $('.msc-push-check').prop('checked', checked);
        updatePushSelectedCount();
    });

    $(document).on('change', '.msc-push-check', function () {
        updatePushSelectedCount();
    });

    function updatePushSelectedCount() {
        const count = $('.msc-push-check:checked').length;
        $('#msc-push-selected-count').text(count + ' selected');
    }

    $(document).on('click', '#msc-bulk-push', function () {
        const btn = $(this);
        const postIds = [];

        $('.msc-push-check:checked').each(function () {
            postIds.push(parseInt($(this).val()));
        });

        if (postIds.length === 0) {
            alert('Please select at least one post to push.');
            return;
        }

        setLoading(btn, true);

        apiRequest('bulk-push', {
            method: 'POST',
            body: { post_ids: postIds },
        })
            .done(function (data) {
                const msg = data.message || `${postIds.length} posts processed.`;
                showNotice($('#msc-tab-push .msc-card'), msg, 'success');
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Bulk push failed.');
                alert(msg);
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // =========================================================================
    //  WordPress Site Content Tab
    // =========================================================================

    let wpCurrentPage = 1;
    let wpTotalPages = 1;

    $(document).on('click', '#msc-fetch-wp-content', function () {
        wpCurrentPage = 1;
        fetchWpContent();
    });

    $(document).on('click', '#msc-wp-prev', function () {
        if (wpCurrentPage > 1) {
            wpCurrentPage--;
            fetchWpContent();
        }
    });

    $(document).on('click', '#msc-wp-next', function () {
        if (wpCurrentPage < wpTotalPages) {
            wpCurrentPage++;
            fetchWpContent();
        }
    });

    function fetchWpContent() {
        const container = $('#msc-wp-content');
        const contentType = $('#msc-wp-content-type').val();
        const status = $('#msc-wp-status').val();
        const search = $('#msc-wp-search').val();

        container.html('<p class="msc-loading">Loading...</p>');

        let qs = `wp-content?content_type=${encodeURIComponent(contentType)}&page=${wpCurrentPage}&per_page=15`;
        if (status && status !== 'any') qs += `&status=${encodeURIComponent(status)}`;
        if (search) qs += `&search=${encodeURIComponent(search)}`;

        apiRequest(qs)
            .done(function (data) {
                const items = data.items || [];
                wpTotalPages = data.total_pages || 1;

                if (items.length === 0) {
                    container.html('<p>No content found.</p>');
                    $('#msc-wp-pagination').hide();
                    return;
                }

                let html = '<table class="msc-remote-table">';
                html += '<thead><tr><th>Title</th><th>Status</th><th>Date</th><th>Link</th></tr></thead><tbody>';

                items.forEach(function (item) {
                    const title = item.title?.rendered || item.title || 'Untitled';
                    const status = item.status || 'unknown';
                    const date = item.date ? new Date(item.date).toLocaleDateString() : '-';
                    const link = item.link || '#';

                    html += `<tr>
                        <td>${escHtml(title.replace(/<[^>]*>/g, ''))}</td>
                        <td><span class="msc-badge msc-badge-${escHtml(status)}">${escHtml(status)}</span></td>
                        <td><small>${escHtml(date)}</small></td>
                        <td><a href="${escHtml(link)}" target="_blank" class="button button-small">View</a></td>
                    </tr>`;
                });

                html += '</tbody></table>';
                container.html(html);

                // Pagination
                $('#msc-wp-page-info').text(`Page ${wpCurrentPage} of ${wpTotalPages} (${data.total || items.length} total)`);
                $('#msc-wp-pagination').show();
                $('#msc-wp-prev').prop('disabled', wpCurrentPage <= 1);
                $('#msc-wp-next').prop('disabled', wpCurrentPage >= wpTotalPages);
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Failed to fetch WordPress content.');
                container.html(`<p class="msc-error">${escHtml(msg)}</p>`);
                $('#msc-wp-pagination').hide();
            });
    }

    // =========================================================================
    //  AI Content Generation
    // =========================================================================

    $(document).on('click', '#msc-ai-generate', function () {
        const btn = $(this);
        const topic = $('#msc-ai-topic').val().trim();
        const contentType = $('#msc-ai-type').val();
        const tone = $('#msc-ai-tone').val();

        if (!topic) {
            alert('Please enter a topic or description.');
            return;
        }

        setLoading(btn, true);
        $('#msc-ai-result').hide();

        apiRequest('ai-generate', {
            method: 'POST',
            body: { topic: topic, content_type: contentType, tone: tone },
        })
            .done(function (data) {
                const output = data.content || data.result || data.text || JSON.stringify(data, null, 2);
                $('#msc-ai-output').text(output);
                $('#msc-ai-result').show();
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'AI generation failed.');
                alert(msg);
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // AI Refine buttons
    $(document).on('click', '#msc-ai-refine-btn, #msc-ai-refine-expand, #msc-ai-refine-seo', function () {
        const btn = $(this);
        const action = btn.data('action');
        const content = $('#msc-ai-output').text();

        if (!content) return;

        setLoading(btn, true);

        apiRequest('ai-refine', {
            method: 'POST',
            body: { content: content, action: action },
        })
            .done(function (data) {
                const output = data.content || data.result || data.text || JSON.stringify(data, null, 2);
                $('#msc-ai-output').text(output);
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'AI refinement failed.');
                alert(msg);
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // Save AI result as WP draft
    $(document).on('click', '#msc-ai-save-draft', function () {
        const btn = $(this);
        const content = $('#msc-ai-output').text();

        if (!content) return;

        setLoading(btn, true);

        // Extract title from first line
        const lines = content.split('\n');
        let title = lines[0].replace(/^#+\s*/, '').trim() || 'AI Generated Post';
        const body = lines.slice(1).join('\n').trim();
        const postType = $('#msc-ai-save-as').val() || 'post';
        const category = $('#msc-ai-category').val() || 0;

        $.ajax({
            url: mscData.adminUrl + 'admin-ajax.php',
            method: 'POST',
            data: {
                action: 'msc_create_draft',
                _wpnonce: NONCE,
                title: title,
                content: body,
                post_type: postType,
                category: category,
            },
        })
            .done(function (data) {
                if (data.success && data.data.edit_url) {
                    window.location.href = data.data.edit_url;
                } else {
                    alert(data.data?.message || 'Draft created!');
                }
            })
            .fail(function () {
                alert('Failed to create draft.');
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // Copy AI result
    $(document).on('click', '#msc-ai-copy', function () {
        const text = $('#msc-ai-output').text();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(() => {
                $(this).text('Copied!');
                setTimeout(() => $(this).text('Copy to Clipboard'), 2000);
            });
        }
    });

    // =========================================================================
    //  Taxonomy Sync
    // =========================================================================

    $(document).on('click', '#msc-push-categories', function () {
        const btn = $(this);
        setLoading(btn, true);

        apiRequest('push-taxonomies', {
            method: 'POST',
            body: { taxonomy: 'category' },
        })
            .done(function (data) {
                const cats = data.results?.categories || [];
                const success = cats.filter(c => c.success).length;
                showNotice($('#msc-tab-taxonomy .msc-card'), `${success} of ${cats.length} categories synced.`, 'success');
            })
            .fail(function (xhr) {
                alert(getErrorMessage(xhr, 'Failed to push categories.'));
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    $(document).on('click', '#msc-push-tags', function () {
        const btn = $(this);
        setLoading(btn, true);

        apiRequest('push-taxonomies', {
            method: 'POST',
            body: { taxonomy: 'tag' },
        })
            .done(function (data) {
                const tags = data.results?.tags || [];
                const success = tags.filter(t => t.success).length;
                showNotice($('#msc-tab-taxonomy .msc-card'), `${success} of ${tags.length} tags synced.`, 'success');
            })
            .fail(function (xhr) {
                alert(getErrorMessage(xhr, 'Failed to push tags.'));
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    $(document).on('click', '#msc-refresh-taxonomy-map', function () {
        const btn = $(this);
        const container = $('#msc-taxonomy-map-result');
        setLoading(btn, true);

        apiRequest('taxonomy-map?taxonomy=category')
            .done(function (catData) {
                apiRequest('taxonomy-map?taxonomy=tag')
                    .done(function (tagData) {
                        const cats = catData.items || [];
                        const tags = tagData.items || [];

                        let html = '<h3>Category Mappings</h3>';
                        if (cats.length === 0) {
                            html += '<p>No category mappings yet. Push categories to create mappings.</p>';
                        } else {
                            html += '<table class="wp-list-table widefat striped"><thead><tr><th>Local Value</th><th>WP Term</th><th>Term ID</th></tr></thead><tbody>';
                            cats.forEach(function (m) {
                                html += `<tr><td>${escHtml(m.local_value)}</td><td>${escHtml(m.wp_term_name)}</td><td>${m.wp_term_id}</td></tr>`;
                            });
                            html += '</tbody></table>';
                        }

                        html += '<h3 style="margin-top:16px;">Tag Mappings</h3>';
                        if (tags.length === 0) {
                            html += '<p>No tag mappings yet. Push tags to create mappings.</p>';
                        } else {
                            html += '<table class="wp-list-table widefat striped"><thead><tr><th>Local Value</th><th>WP Term</th><th>Term ID</th></tr></thead><tbody>';
                            tags.forEach(function (m) {
                                html += `<tr><td>${escHtml(m.local_value)}</td><td>${escHtml(m.wp_term_name)}</td><td>${m.wp_term_id}</td></tr>`;
                            });
                            html += '</tbody></table>';
                        }

                        container.html(html);
                    })
                    .always(function () {
                        setLoading(btn, false);
                    });
            })
            .fail(function (xhr) {
                container.html(`<p class="msc-error">${escHtml(getErrorMessage(xhr, 'Failed to fetch mappings.'))}</p>`);
                setLoading(btn, false);
            });
    });

    // =========================================================================
    //  Post Metabox: AI actions
    // =========================================================================

    $(document).on('click', '.msc-ai-action', function () {
        const btn = $(this);
        const action = btn.data('action');
        const postId = btn.data('post-id');
        const resultBox = $('#msc-metabox-result');

        // Get post content from the editor
        let content = '';
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            // Block editor (Gutenberg)
            content = wp.data.select('core/editor').getEditedPostContent();
        } else if ($('#content').length) {
            // Classic editor
            content = $('#content').val();
        }

        if (!content && action !== 'headlines') {
            alert('No post content found to analyze.');
            return;
        }

        setLoading(btn, true);
        resultBox.hide();

        let endpoint, body;

        if (action === 'improve' || action === 'seo') {
            endpoint = 'ai-refine';
            body = { content: content, action: action };
        } else if (action === 'headlines') {
            endpoint = 'ai-generate';
            body = { topic: content.substring(0, 500), content_type: 'headlines', tone: 'professional' };
        } else if (action === 'score') {
            endpoint = 'ai-refine';
            body = { content: content, action: 'score' };
        } else {
            endpoint = 'ai-refine';
            body = { content: content, action: action };
        }

        apiRequest(endpoint, { method: 'POST', body: body })
            .done(function (data) {
                const output = data.content || data.result || data.text || JSON.stringify(data, null, 2);
                resultBox.css('color', '').text(output).show();
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'AI action failed.');
                resultBox.css('color', '#991b1b').text(msg).show();
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // =========================================================================
    //  Sync Status Bar
    // =========================================================================

    function loadSyncStatus() {
        const el = $('#msc-sync-count');
        if (!el.length) return;

        apiRequest('sync-status?local_type=post&limit=200')
            .done(function (data) {
                const items = data.items || [];
                el.text(items.length + ' items synced');
            })
            .fail(function () {
                el.text('Unable to load sync status');
            });
    }

    $(document).on('click', '#msc-refresh-sync-status', function () {
        loadSyncStatus();
    });

    // =========================================================================
    //  Init
    // =========================================================================

    $(document).ready(function () {
        // Auto-load dashboard if we're on that page
        if ($('#msc-dashboard').length) {
            loadDashboard();
        }

        // Load sync status on content sync page
        if ($('#msc-sync-status').length) {
            loadSyncStatus();
        }
    });

})(jQuery);
