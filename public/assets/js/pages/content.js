/**
 * Content Studio — post list, create form, calendar view, bulk actions, filters.
 */

import { api } from '../core/api.js';
import { $, $$, escapeHtml, formatDateTime, onSubmit, formData, statusBadge, onClick } from '../core/utils.js';
import { success, error } from '../core/toast.js';

let selectedIds = new Set();
let calendarDate = new Date();

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

    table.innerHTML = filtered.map((p) => `<tr>
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
    if (action === 'publish') {
      await api(`/api/posts/${id}`, { method: 'PATCH', body: JSON.stringify({ status: 'published' }) });
      success('Post published');
    } else if (action === 'delete') {
      if (!confirm('Delete this post?')) return;
      await api(`/api/posts/${id}`, { method: 'DELETE' });
      success('Post deleted');
    } else if (action === 'repurpose') {
      const body = btn?.dataset.body || '';
      if (!body) { error('Post has no content to repurpose'); return; }
      // Navigate to AI Studio repurpose tool with content pre-filled
      sessionStorage.setItem('repurpose_content', body);
      window.location.hash = '#ai';
      setTimeout(() => {
        document.querySelector('.ai-cat-btn[data-ai-cat="creation"]')?.click();
        const repurposeField = document.getElementById('aiRepurposeContent');
        if (repurposeField) {
          repurposeField.value = body;
          repurposeField.scrollIntoView({ behavior: 'smooth' });
          success('Content loaded — select formats and click Repurpose');
        }
      }, 200);
      return;
    }
    refreshPosts();
  } catch (err) {
    error(err.message);
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
  } catch { /* ignore */ }
}

export async function refresh() {
  await loadCampaignFilter();
  await refreshPosts();

  // Load calendar data
  try {
    const { items } = await api('/api/posts');
    renderCalendar(items);
  } catch { /* ignore */ }
}

export function init() {
  // Post form
  onSubmit('postForm', async (e) => {
    const data = formData(e);
    if (!data.status) {
      data.status = data.scheduled_for ? 'scheduled' : 'draft';
    }
    try {
      await api('/api/posts', { method: 'POST', body: JSON.stringify(data) });
      e.target.reset();
      success('Post created');
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
    let timeout;
    search.addEventListener('input', () => {
      clearTimeout(timeout);
      timeout = setTimeout(refreshPosts, 300);
    });
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
  onClick('bulkDelete', () => {
    if (confirm(`Delete ${selectedIds.size} posts?`)) bulkAction('delete');
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
        if (review.summary) alert(`Pre-Flight Check: ${review.status}\nScore: ${review.overall_score}/100\n\n${review.summary}`);
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
        alert(details);
      } else {
        success('Prediction complete');
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

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
