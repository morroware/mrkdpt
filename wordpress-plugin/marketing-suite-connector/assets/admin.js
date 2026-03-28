/**
 * Marketing Suite Connector - Admin JavaScript
 */
(function ($) {
    'use strict';

    const API = mscData.restUrl;
    const NONCE = mscData.nonce;

    function apiRequest(endpoint, options = {}) {
        return $.ajax({
            url: API + endpoint,
            method: options.method || 'GET',
            contentType: 'application/json',
            data: options.body ? JSON.stringify(options.body) : undefined,
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', NONCE);
            },
        });
    }

    function setLoading(btn, loading) {
        if (loading) {
            btn.addClass('msc-loading-btn').prop('disabled', true);
            btn.data('original-text', btn.text());
        } else {
            btn.removeClass('msc-loading-btn').prop('disabled', false);
            const originalText = btn.data('original-text');
            if (originalText) {
                btn.text(originalText);
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
        div.textContent = str;
        return div.innerHTML;
    }

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
                    { key: 'total_posts', label: 'Total Posts' },
                    { key: 'published_posts', label: 'Published' },
                    { key: 'scheduled_posts', label: 'Scheduled' },
                    { key: 'draft_posts', label: 'Drafts' },
                    { key: 'campaigns', label: 'Campaigns' },
                    { key: 'contacts', label: 'Contacts' },
                ];
                metrics.forEach(function (m) {
                    if (data[m.key] !== undefined) {
                        metricsHtml += `
                            <div class="msc-metric-card">
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
    //  Content Sync: Fetch remote posts
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
                    return;
                }

                let html = '<table class="msc-remote-table">';
                html += '<tr><th>Title</th><th>Platform</th><th>Status</th><th>Actions</th></tr>';
                posts.forEach(function (p) {
                    html += `
                        <tr>
                            <td>${escHtml(p.title || 'Untitled')}</td>
                            <td>${escHtml(p.platform || '-')}</td>
                            <td><span class="msc-badge msc-badge-${escHtml(p.status || 'draft')}">${escHtml(p.status || 'draft')}</span></td>
                            <td>
                                <button type="button" class="button button-small msc-import-post" data-remote-id="${parseInt(p.id)}">
                                    Import to WP
                                </button>
                            </td>
                        </tr>`;
                });
                html += '</table>';
                container.html(html);
            })
            .fail(function (xhr) {
                const msg = getErrorMessage(xhr, 'Failed to fetch content.');
                container.html(`<p class="msc-error">${escHtml(msg)}</p>`);
            })
            .always(function () {
                setLoading(btn, false);
            });
    });

    // =========================================================================
    //  Content Sync: Import remote post
    // =========================================================================

    $(document).on('click', '.msc-import-post', function () {
        const btn = $(this);
        const remoteId = btn.data('remote-id');

        setLoading(btn, true);

        apiRequest('import-post', {
            method: 'POST',
            body: { remote_id: remoteId },
        })
            .done(function (data) {
                if (data.edit_url) {
                    btn.replaceWith(`<a href="${escHtml(data.edit_url)}" class="button button-small">Edit Draft</a>`);
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
                    // Update synced info
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

        $.ajax({
            url: mscData.adminUrl + 'admin-ajax.php',
            method: 'POST',
            data: {
                action: 'msc_create_draft',
                _wpnonce: NONCE,
                title: title,
                content: body,
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
                resultBox.text(output).show();
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
    //  Init
    // =========================================================================

    $(document).ready(function () {
        // Auto-load dashboard if we're on that page
        if ($('#msc-dashboard').length) {
            loadDashboard();
        }
    });

})(jQuery);
