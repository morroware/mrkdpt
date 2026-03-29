/**
 * Content Studio — post list, create form, calendar view, bulk actions, filters.
 */

import { api } from '../core/api.js';
import { $, $$, escapeHtml, formatDateTime, onSubmit, formData, statusBadge, onClick, tableEmpty, emptyState, debounce, copyToClipboard, confirm, infoModal } from '../core/utils.js';
import { success, error } from '../core/toast.js';

let selectedIds = new Set();
let calendarDate = new Date();
let editingPostId = null;

/* ---- Post editing ---- */

async function loadPostForEditing(id) {
  try {
    const { item } = await api(`/api/posts/${id}`);
    if (!item) { error('Post not found'); return; }

    editingPostId = id;

    // Switch to create tab
    document.querySelector('[data-tab="content-create"]')?.click();

    // Populate form fields
    const form = $('postForm');
    if (!form) return;

    const setVal = (name, val) => {
      const el = form.querySelector(`[name="${name}"]`);
      if (el) el.value = val || '';
    };

    setVal('campaign_id', item.campaign_id);
    setVal('platform', item.platform);
    setVal('content_type', item.content_type);
    setVal('title', item.title);
    setVal('body', item.body);
    setVal('cta', item.cta);
    setVal('tags', item.tags);
    setVal('scheduled_for', item.scheduled_for ? item.scheduled_for.slice(0, 16) : '');
    setVal('ai_score', item.ai_score);
    setVal('recurrence', item.recurrence || 'none');

    const evergreen = form.querySelector('[name="is_evergreen"]');
    if (evergreen) evergreen.checked = !!item.is_evergreen;

    // Update submit button text
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.textContent = 'Update Post';

    // Show cancel edit button
    let cancelBtn = $('cancelEditPost');
    if (!cancelBtn) {
      cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.id = 'cancelEditPost';
      cancelBtn.className = 'btn btn-ghost';
      cancelBtn.textContent = 'Cancel Edit';
      submitBtn?.parentElement?.insertBefore(cancelBtn, submitBtn.nextSibling);
    }
    cancelBtn.style.display = '';
    cancelBtn.onclick = () => {
      editingPostId = null;
      form.reset();
      if (submitBtn) submitBtn.textContent = 'Save Post';
      cancelBtn.style.display = 'none';
    };

    // Trigger preview update
    $('postBodyTextarea')?.dispatchEvent(new Event('input'));

    success('Post loaded for editing');
  } catch (err) {
    error('Failed to load post: ' + err.message);
  }
}

/* ---- Post list ---- */

async function refreshPosts() {
  try {
    const params = new URLSearchParams();
    const fp = $('filterPlatform')?.value;
    const fs = $('filterStatus')?.value;
    const fc = $('filterCampaign')?.value;
    if (fp) params.set('platform', fp);
    if (fs) params.set('status', fs);
    if (fc) params.set('campaign_id', fc);

    const { items } = await api('/api/posts?' + params.toString());
    const search = ($('filterSearch')?.value || '').toLowerCase();
    const filtered = search
      ? items.filter((p) => (p.title + ' ' + p.body + ' ' + p.tags).toLowerCase().includes(search))
      : items;

    const table = $('postTable');
    if (!table) return;

    table.innerHTML = filtered.length === 0
      ? tableEmpty(8, 'No posts found. Create your first post to get started.')
      : filtered.map((p) => `<tr>
      <td><input type="checkbox" class="post-check" value="${p.id}" /></td>
      <td>${p.id}</td>
      <td>${escapeHtml(p.platform)}</td>
      <td>${escapeHtml(p.content_type || 'social_post')}</td>
      <td><strong>${escapeHtml(p.title)}</strong><div class="text-small text-muted">${escapeHtml((p.cta || '').slice(0, 70))}</div></td>
      <td>${statusBadge(p.status)}</td>
      <td>${formatDateTime(p.scheduled_for)}</td>
      <td>
        <button class="btn btn-sm btn-outline" data-action="edit" data-id="${p.id}">Edit</button>
        ${p.status !== 'published' ? `<button class="btn btn-sm btn-success" data-action="publish" data-id="${p.id}">Publish</button>` : ''}
        <button class="btn btn-sm btn-ai" data-action="repurpose" data-id="${p.id}" data-body="${escapeHtml(p.body || '')}" title="AI Repurpose"><span class="btn-ai-icon">&#9733;</span></button>
        <button class="btn btn-sm btn-danger" data-action="delete" data-id="${p.id}">Del</button>
      </td>
    </tr>`).join('');

    // Wire action buttons
    table.querySelectorAll('button[data-action]').forEach((btn) => {
      btn.addEventListener('click', () => handlePostAction(btn.dataset.action, parseInt(btn.dataset.id), btn));
    });

    // Wire checkboxes
    table.querySelectorAll('.post-check').forEach((cb) => {
      cb.addEventListener('change', updateBulkBar);
    });

    updateBulkBar();
  } catch (err) {
    error('Failed to load posts: ' + err.message);
  }
}

async function handlePostAction(action, id, btn) {
  try {
    if (action === 'edit') {
      await loadPostForEditing(id);
      return;
    } else if (action === 'publish') {
      await api(`/api/posts/${id}`, { method: 'PATCH', body: JSON.stringify({ status: 'published' }) });
      success('Post published');
    } else if (action === 'delete') {
      if (!await confirm('Delete Post', 'Are you sure you want to delete this post? This action cannot be undone.')) return;
      await api(`/api/posts/${id}`, { method: 'DELETE' });
      success('Post deleted');
    } else if (action === 'repurpose') {
      const body = btn?.dataset.body || '';
      if (!body) { error('Post has no content to repurpose'); return; }
      await runRepurposeChain(body, btn?.closest('tr')?.querySelector('td:nth-child(5)')?.textContent || '');
      return;
    }
    refreshPosts();
  } catch (err) {
    error(err.message);
  }
}

async function runRepurposeChain(content, originalTitle) {
  const panel = $('repurposeChainPanel');
  const results = $('repurposeChainResults');
  const actions = $('repurposeChainActions');
  if (!panel || !results) return;

  panel.classList.remove('hidden');
  panel.scrollIntoView({ behavior: 'smooth' });
  results.innerHTML = '<div class="flex gap-1"><div class="loading-spinner"></div> <span class="text-muted">Generating platform variants...</span></div>';
  if (actions) actions.style.display = 'none';

  try {
    const { item } = await api('/api/ai/repurpose-chain', {
      method: 'POST',
      body: JSON.stringify({ content, title: originalTitle, platforms: ['twitter', 'instagram', 'linkedin', 'facebook', 'email'] }),
    });

    const variants = item?.variants || [];
    if (variants.length === 0) {
      results.innerHTML = item?.raw
        ? `<pre class="ai-output">${escapeHtml(item.raw)}</pre>`
        : '<p class="text-muted">No variants generated.</p>';
      return;
    }

    results.innerHTML = variants.map((v, i) => `
      <div class="card mb-1 repurpose-variant" data-idx="${i}">
        <div class="flex-between">
          <span class="badge badge-${escapeHtml(v.platform)}">${escapeHtml(v.platform)}</span>
          <span class="text-small text-muted">${(v.content || '').length} chars</span>
        </div>
        <div class="mt-1 text-small">${escapeHtml(v.content || '')}</div>
        ${v.hashtags ? `<div class="text-small text-muted mt-1">${escapeHtml(v.hashtags)}</div>` : ''}
        <div class="flex gap-1 mt-1">
          <button class="btn btn-sm btn-outline save-variant-btn" data-idx="${i}">Save as Draft</button>
          <button class="btn btn-sm btn-ghost copy-variant-btn" data-idx="${i}">Copy</button>
        </div>
      </div>
    `).join('');

    // Store variants for queue/save actions
    panel.dataset.variants = JSON.stringify(variants);
    if (actions) actions.style.display = '';

    // Wire individual buttons
    results.querySelectorAll('.save-variant-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const idx = parseInt(btn.dataset.idx);
        const v = variants[idx];
        if (!v) return;
        btn.classList.add('loading'); btn.disabled = true;
        try {
          await api('/api/posts', {
            method: 'POST',
            body: JSON.stringify({ title: originalTitle || 'Repurposed Post', body: v.content, platform: v.platform, status: 'draft', tags: v.hashtags || '' }),
          });
          success(`Draft saved for ${v.platform}`);
          btn.textContent = 'Saved';
        } catch (e) { error(e.message); }
        finally { btn.classList.remove('loading'); btn.disabled = false; }
      });
    });

    results.querySelectorAll('.copy-variant-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.dataset.idx);
        const v = variants[idx];
        if (v?.content) {
          copyToClipboard(v.content);
          success('Copied to clipboard');
        }
      });
    });

    success(`${variants.length} platform variants generated`);
  } catch (err) {
    results.innerHTML = `<p class="text-danger">${escapeHtml(err.message)}</p>`;
    error('Repurpose failed: ' + err.message);
  }
}

function updateBulkBar() {
  const checks = $$('.post-check:checked', $('postTable') || document);
  selectedIds = new Set([...checks].map((c) => parseInt(c.value)));
  const bar = $('bulkBar');
  const count = $('bulkCount');
  if (bar) bar.style.display = selectedIds.size > 0 ? '' : 'none';
  if (count) count.textContent = selectedIds.size;
}

async function bulkAction(action) {
  if (selectedIds.size === 0) return;
  try {
    await api('/api/posts/bulk', {
      method: 'POST',
      body: JSON.stringify({ ids: [...selectedIds], action }),
    });
    success(`Bulk ${action} completed`);
    refreshPosts();
  } catch (err) {
    error(err.message);
  }
}

/* ---- Calendar ---- */

function renderCalendar(posts) {
  const grid = $('calendarGrid');
  const title = $('calTitle');
  if (!grid || !title) return;

  const year = calendarDate.getFullYear();
  const month = calendarDate.getMonth();
  title.textContent = calendarDate.toLocaleString(undefined, { month: 'long', year: 'numeric' });

  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();

  // Group posts by day
  const postsByDay = {};
  (posts || []).forEach((p) => {
    const d = p.scheduled_for || p.created_at;
    if (!d) return;
    const dt = new Date(d);
    if (dt.getFullYear() === year && dt.getMonth() === month) {
      const day = dt.getDate();
      if (!postsByDay[day]) postsByDay[day] = [];
      postsByDay[day].push(p);
    }
  });

  let html = '<div class="cal-header">Sun</div><div class="cal-header">Mon</div><div class="cal-header">Tue</div><div class="cal-header">Wed</div><div class="cal-header">Thu</div><div class="cal-header">Fri</div><div class="cal-header">Sat</div>';

  // Empty cells before first day
  for (let i = 0; i < firstDay; i++) {
    html += '<div class="cal-day cal-empty"></div>';
  }

  const today = new Date();
  for (let day = 1; day <= daysInMonth; day++) {
    const isToday = day === today.getDate() && month === today.getMonth() && year === today.getFullYear();
    const dayPosts = postsByDay[day] || [];
    const dots = dayPosts.map((p) =>
      `<div class="cal-dot cal-dot-${p.status}" title="${escapeHtml(p.title)}"></div>`
    ).join('');
    html += `<div class="cal-day${isToday ? ' cal-today' : ''}"><span class="cal-num">${day}</span>${dots}</div>`;
  }

  grid.innerHTML = html;
}

/* ---- Campaign filter dropdown ---- */

async function loadCampaignFilter() {
  try {
    const { items } = await api('/api/campaigns');
    const sel = $('filterCampaign');
    if (sel) {
      sel.innerHTML = '<option value="">All Campaigns</option>' +
        items.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
    }
    // Also populate the post form campaign select
    const postSel = $('campaignSelect');
    if (postSel) {
      postSel.innerHTML = '<option value="">None</option>' +
        items.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
    }
  } catch (err) { error('Failed to load campaigns: ' + err.message); }
}

export async function refresh() {
  await loadCampaignFilter();
  await refreshPosts();

  // Load calendar data
  try {
    const { items } = await api('/api/posts');
    renderCalendar(items);
  } catch (err) { error('Failed to load calendar: ' + err.message); }
}

export function init() {
  // Post form
  onSubmit('postForm', async (e) => {
    const data = formData(e);
    if (!data.status) {
      data.status = data.scheduled_for ? 'scheduled' : 'draft';
    }
    try {
      if (editingPostId) {
        await api(`/api/posts/${editingPostId}`, { method: 'PATCH', body: JSON.stringify(data) });
        success('Post updated');
        editingPostId = null;
        const submitBtn = e.target.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.textContent = 'Save Post';
        const cancelBtn = $('cancelEditPost');
        if (cancelBtn) cancelBtn.style.display = 'none';
      } else {
        await api('/api/posts', { method: 'POST', body: JSON.stringify(data) });
        success('Post created');
      }
      e.target.reset();
      refreshPosts();
    } catch (err) {
      error(err.message);
    }
  });

  // Filters
  ['filterPlatform', 'filterStatus', 'filterCampaign'].forEach((id) => {
    const el = $(id);
    if (el) el.addEventListener('change', refreshPosts);
  });
  const search = $('filterSearch');
  if (search) {
    search.addEventListener('input', debounce(refreshPosts, 300));
  }

  // Select all checkbox
  const selectAll = $('selectAll');
  if (selectAll) {
    selectAll.addEventListener('change', () => {
      $$('.post-check', $('postTable') || document).forEach((cb) => {
        cb.checked = selectAll.checked;
      });
      updateBulkBar();
    });
  }

  // Bulk actions
  onClick('bulkPublish', () => bulkAction('publish'));
  onClick('bulkDelete', async () => {
    if (await confirm('Bulk Delete', `Are you sure you want to delete ${selectedIds.size} post(s)? This action cannot be undone.`)) bulkAction('delete');
  });

  // Bulk repurpose
  onClick('bulkRepurpose', async () => {
    if (selectedIds.size === 0) return;
    if (selectedIds.size > 1) {
      error('Select one post to repurpose');
      return;
    }
    const postId = [...selectedIds][0];
    const row = document.querySelector(`.post-check[value="${postId}"]`)?.closest('tr');
    const repurposeBtn = row?.querySelector('[data-action="repurpose"]');
    if (repurposeBtn) {
      handlePostAction('repurpose', postId, repurposeBtn);
    }
  });

  // Calendar navigation
  onClick('calPrev', () => {
    calendarDate.setMonth(calendarDate.getMonth() - 1);
    refresh();
  });
  onClick('calNext', () => {
    calendarDate.setMonth(calendarDate.getMonth() + 1);
    refresh();
  });

  // Calendar Auto-Fill
  onClick('calAutoFill', async () => {
    const btn = $('calAutoFill');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const year = calendarDate.getFullYear();
      const month = calendarDate.getMonth();
      const startDate = new Date(year, month, 1).toISOString().split('T')[0];
      const { item } = await api('/api/ai/calendar-autofill', {
        method: 'POST',
        body: JSON.stringify({ period: 'month', start_date: startDate }),
      });
      const created = item?.posts_created || 0;
      if (created > 0) {
        success(`${created} draft posts created for the calendar. Review them in the List tab.`);
        await refresh();
      } else {
        success('Calendar auto-fill complete. Check the List tab for new drafts.');
        await refresh();
      }
    } catch (err) {
      error('Calendar auto-fill failed: ' + err.message);
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });

  // Repurpose chain panel
  onClick('closeRepurposeChain', () => {
    $('repurposeChainPanel')?.classList.add('hidden');
  });

  onClick('queueAllRepurposed', async () => {
    const panel = $('repurposeChainPanel');
    const variants = JSON.parse(panel?.dataset.variants || '[]');
    if (variants.length === 0) { error('No variants to queue'); return; }
    const btn = $('queueAllRepurposed');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    let queued = 0;
    for (const v of variants) {
      try {
        await api('/api/posts', {
          method: 'POST',
          body: JSON.stringify({ title: 'Repurposed Post', body: v.content, platform: v.platform, status: 'scheduled', tags: v.hashtags || '' }),
        });
        queued++;
      } catch (_) {}
    }
    success(`${queued} posts queued for publishing`);
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    panel?.classList.add('hidden');
    refreshPosts();
  });

  onClick('saveAllRepurposed', async () => {
    const panel = $('repurposeChainPanel');
    const variants = JSON.parse(panel?.dataset.variants || '[]');
    if (variants.length === 0) { error('No variants to save'); return; }
    const btn = $('saveAllRepurposed');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    let saved = 0;
    for (const v of variants) {
      try {
        await api('/api/posts', {
          method: 'POST',
          body: JSON.stringify({ title: 'Repurposed Post', body: v.content, platform: v.platform, status: 'draft', tags: v.hashtags || '' }),
        });
        saved++;
      } catch (_) {}
    }
    success(`${saved} drafts saved`);
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    panel?.classList.add('hidden');
    refreshPosts();
  });

  // AI generate body button
  onClick('aiGenerateBody', async () => {
    const form = $('postForm');
    if (!form) return;
    const btn = $('aiGenerateBody');
    const topic = form.querySelector('[name="title"]')?.value || 'marketing';
    const platform = form.querySelector('[name="platform"]')?.value || 'instagram';
    const contentType = form.querySelector('[name="content_type"]')?.value || 'social_post';
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/content', {
        method: 'POST',
        body: JSON.stringify({ content_type: contentType, platform, topic, tone: 'professional' }),
      });
      const bodyField = form.querySelector('[name="body"]');
      if (bodyField && item?.content) {
        bodyField.value = item.content;
        success('Content generated with AI');
      }
    } catch (err) {
      error(err.message);
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });

  // AI generate title
  onClick('aiGenerateTitle', async () => {
    const form = $('postForm');
    if (!form) return;
    const btn = $('aiGenerateTitle');
    const body = form.querySelector('[name="body"]')?.value || '';
    const platform = form.querySelector('[name="platform"]')?.value || 'instagram';
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/content', {
        method: 'POST',
        body: JSON.stringify({
          content_type: 'social_post',
          platform,
          topic: body ? 'Create a compelling title for this content: ' + body.slice(0, 200) : 'engaging marketing post',
          tone: 'professional',
          goal: 'Generate just a short, catchy title (under 80 chars)',
        }),
      });
      const titleField = form.querySelector('[name="title"]');
      if (titleField && item?.content) {
        // Extract first line as title
        const title = item.content.split('\n')[0].replace(/^["#*]+|["*]+$/g, '').trim().slice(0, 100);
        titleField.value = title;
        success('Title generated');
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // AI generate hashtags
  onClick('aiGenerateHashtags', async () => {
    const form = $('postForm');
    if (!form) return;
    const btn = $('aiGenerateHashtags');
    const topic = form.querySelector('[name="title"]')?.value || form.querySelector('[name="body"]')?.value?.slice(0, 100) || 'marketing';
    const platform = form.querySelector('[name="platform"]')?.value || 'instagram';
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/hashtags', {
        method: 'POST',
        body: JSON.stringify({ topic, platform }),
      });
      const tagsField = form.querySelector('[name="tags"]');
      if (tagsField && item?.hashtags) {
        // Extract hashtags from response
        const hashtags = item.hashtags.match(/#[\p{L}\p{N}_]+/gu);
        if (hashtags) {
          tagsField.value = hashtags.slice(0, 15).join(' ');
          success(`${hashtags.length} hashtags generated`);
        } else {
          tagsField.value = item.hashtags.slice(0, 200);
          success('Hashtags generated');
        }
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // AI score post
  onClick('aiScorePost', async () => {
    const form = $('postForm');
    if (!form) return;
    const btn = $('aiScorePost');
    const content = form.querySelector('[name="body"]')?.value || '';
    const platform = form.querySelector('[name="platform"]')?.value || 'instagram';
    if (!content) { error('Write some content first to score it'); return; }
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/score', {
        method: 'POST',
        body: JSON.stringify({ content, platform }),
      });
      if (item?.score) {
        // Try to extract numeric score
        const scoreMatch = item.score.match(/(\d{1,3})\s*\/?\s*100|overall[:\s]*(\d{1,3})/i);
        const scoreNum = scoreMatch ? parseInt(scoreMatch[1] || scoreMatch[2]) : null;
        if (scoreNum) {
          const scoreField = form.querySelector('[name="ai_score"]');
          if (scoreField) scoreField.value = scoreNum;
        }
        // Show full score in an alert-like toast
        success(`AI Score: ${scoreNum || 'See details'}. Check AI Studio for full breakdown.`);
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // AI Pre-Flight Check on post
  onClick('aiPreflightPost', async () => {
    const form = $('postForm');
    if (!form) return;
    const btn = $('aiPreflightPost');
    const content = form.querySelector('[name="body"]')?.value || '';
    const platform = form.querySelector('[name="platform"]')?.value || 'instagram';
    if (!content) { error('Write some content first'); return; }
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/preflight', {
        method: 'POST',
        body: JSON.stringify({ content, platform }),
      });
      const review = item?.review;
      if (review) {
        const statusEmoji = review.status === 'approved' ? '✅' : review.status === 'needs_revision' ? '⚠️' : '❌';
        success(`Pre-Flight: ${statusEmoji} ${review.status} (Score: ${review.overall_score}/100)`);
        if (review.summary) infoModal('Pre-Flight Check', `Status: ${review.status}\nScore: ${review.overall_score}/100\n\n${review.summary}`, { icon: statusEmoji });
      } else {
        success('Pre-flight check complete — see AI Studio for details');
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // AI Performance Predictor on post
  onClick('aiPredictPost', async () => {
    const form = $('postForm');
    if (!form) return;
    const btn = $('aiPredictPost');
    const content = form.querySelector('[name="body"]')?.value || '';
    const platform = form.querySelector('[name="platform"]')?.value || 'instagram';
    const scheduledTime = form.querySelector('[name="scheduled_for"]')?.value || null;
    if (!content) { error('Write some content first'); return; }
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/predict', {
        method: 'POST',
        body: JSON.stringify({ content, platform, scheduled_time: scheduledTime }),
      });
      const pred = item?.prediction;
      if (pred) {
        success(`Publish Confidence: ${pred.confidence_score}/100`);
        const details = [
          `Confidence Score: ${pred.confidence_score}/100`,
          `Timing Score: ${pred.timing_score}/100`,
          pred.timing_suggestion ? `Better time: ${pred.timing_suggestion}` : '',
          '',
          'Strengths: ' + (pred.strengths || []).join(', '),
          'Weaknesses: ' + (pred.weaknesses || []).join(', '),
          '',
          'Tips: ' + (pred.optimization_tips || []).join('; '),
        ].filter(Boolean).join('\n');
        infoModal('Performance Prediction', details, { icon: '&#128200;' });
      } else {
        success('Prediction complete');
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // ---- Live Platform Preview & Character Counter ----
  initLivePreview();

  // Check for AI-generated content from AI Studio "Use in Post"
  const stored = sessionStorage.getItem('ai_generated_content');
  if (stored) {
    sessionStorage.removeItem('ai_generated_content');
    sessionStorage.removeItem('ai_generated_tool');
    // Switch to create tab and populate the body field
    document.querySelector('[data-tab="content-create"]')?.click();
    const bodyField = document.querySelector('#postForm [name="body"]');
    if (bodyField) {
      bodyField.value = stored;
      bodyField.dispatchEvent(new Event('input'));
    }
  }
}

/* ---- Live Platform Preview ---- */
const PLATFORM_LIMITS = {
  twitter: 280, threads: 500, mastodon: 500, bluesky: 300,
  instagram: 2200, facebook: 63206, linkedin: 3000,
  tiktok: 2200, pinterest: 500, reddit: 40000,
  telegram: 4096, discord: 2000, slack: 40000,
  youtube: 5000, wordpress: 100000, medium: 100000,
};

function initLivePreview() {
  const bodyField = $('postBodyTextarea');
  const titleField = $('postTitleInput');
  const platformSelect = $('postPlatformSelect');
  if (!bodyField) return;

  function updatePreview() {
    const body = bodyField.value || '';
    const title = titleField?.value || '';
    const platform = platformSelect?.value || 'instagram';

    // Character counter
    const counterEl = $('postCharCounter');
    const limit = PLATFORM_LIMITS[platform];
    if (counterEl) {
      const len = body.length;
      counterEl.textContent = limit
        ? `${len} / ${limit} characters`
        : `${len} characters`;
      counterEl.className = 'char-counter';
      if (limit) {
        if (len > limit) counterEl.classList.add('danger');
        else if (len > limit * 0.9) counterEl.classList.add('warn');
      }
    }

    // Platform badge
    const badge = $('previewPlatformBadge');
    if (badge) badge.textContent = platform;

    // Preview body
    const previewBody = $('previewBody');
    if (previewBody) {
      if (!body && !title) {
        previewBody.innerHTML = '<span class="text-muted">Start typing to see a preview...</span>';
      } else {
        let html = '';
        if (title) html += `<strong>${escapeHtml(title)}</strong><br><br>`;
        html += escapeHtml(body).replace(/\n/g, '<br>');
        previewBody.innerHTML = html;
      }
    }

    // Character limit indicator in preview
    const limitEl = $('previewCharLimit');
    if (limitEl && limit) {
      const remaining = limit - body.length;
      if (remaining < 0) {
        limitEl.innerHTML = `<span class="text-danger">${Math.abs(remaining)} characters over limit for ${platform}</span>`;
      } else if (remaining < limit * 0.1) {
        limitEl.innerHTML = `<span class="text-warning">${remaining} characters remaining</span>`;
      } else {
        limitEl.textContent = '';
      }
    }
  }

  bodyField.addEventListener('input', updatePreview);
  if (titleField) titleField.addEventListener('input', updatePreview);
  if (platformSelect) platformSelect.addEventListener('change', updatePreview);

  // Initial render
  updatePreview();
}
