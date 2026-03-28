/**
 * Social Publish Queue — manage post queue, view best times.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDateTime, onSubmit, onClick, statusBadge, tableEmpty, confirm } from '../core/utils.js';
import { success, error } from '../core/toast.js';

async function loadQueue() {
  try {
    const [{ items }, metrics] = await Promise.all([
      api('/api/social-queue'),
      api('/api/social-queue/metrics'),
    ]);

    const metricsEl = $('queueMetrics');
    if (metricsEl) {
      metricsEl.innerHTML = `
        <div class="metric-card"><span class="metric-value">${metrics.total || 0}</span><span class="metric-label">Total</span></div>
        <div class="metric-card"><span class="metric-value">${metrics.queued || 0}</span><span class="metric-label">Queued</span></div>
        <div class="metric-card"><span class="metric-value">${metrics.published || 0}</span><span class="metric-label">Published</span></div>
        <div class="metric-card"><span class="metric-value">${metrics.failed || 0}</span><span class="metric-label">Failed</span></div>`;
    }

    const table = $('queueTable');
    if (!table) return;
    table.innerHTML = items.length ? items.map((q) => `<tr>
      <td><strong>${escapeHtml(q.post_title || 'Post #' + q.post_id)}</strong><div class="text-small text-muted">${escapeHtml(q.post_platform || '')}</div></td>
      <td>${escapeHtml(q.account_name || '-')}</td>
      <td>${formatDateTime(q.optimal_time)}</td>
      <td>${q.priority}</td>
      <td>${statusBadge(q.status)}</td>
      <td>
        ${q.status === 'queued' ? `<button class="btn btn-sm btn-danger" data-remove="${q.id}">Remove</button>` : ''}
      </td>
    </tr>`).join('') : tableEmpty(6, 'Queue is empty');

    table.querySelectorAll('[data-remove]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!await confirm('Remove from Queue', 'Are you sure you want to remove this post from the queue?', { okText: 'Remove', okClass: 'btn-danger' })) return;
        btn.classList.add('loading');
        btn.disabled = true;
        try {
          await api(`/api/social-queue/${btn.dataset.remove}`, { method: 'DELETE' });
          success('Removed from queue');
          loadQueue();
        } catch (e) { error(e.message); }
        finally { btn.classList.remove('loading'); btn.disabled = false; }
      });
    });
  } catch (e) {
    error('Failed to load queue: ' + e.message);
  }
}

async function loadSelects() {
  try {
    const [posts, accounts] = await Promise.all([
      api('/api/posts'),
      api('/api/social-accounts'),
    ]);

    const postSel = $('queuePostSelect');
    if (postSel && posts.items) {
      postSel.innerHTML = posts.items.map((p) => `<option value="${p.id}">${escapeHtml(p.title)} (${p.platform})</option>`).join('');
    }
    const accSel = $('queueAccountSelect');
    if (accSel && accounts.items) {
      accSel.innerHTML = accounts.items.map((a) => `<option value="${a.id}">${escapeHtml(a.account_name)} (${a.platform})</option>`).join('');
    }
  } catch (err) { error('Failed to load form options: ' + err.message); }
}

async function loadBestTimes() {
  try {
    const platform = $('bestTimesPlatform')?.value || '';
    const { items } = await api('/api/social-queue/best-times?platform=' + encodeURIComponent(platform));
    const list = $('bestTimesList');
    if (!list) return;

    if (!items.length) {
      list.innerHTML = '<p class="text-muted">No publishing data yet. Best times will appear as you publish content.</p>';
      return;
    }

    list.innerHTML = `<div class="table-wrap"><table class="data-table"><thead><tr><th>Day</th><th>Time</th><th>Posts</th><th>Successes</th></tr></thead><tbody>${
      items.map((t) => `<tr><td>${t.day}</td><td>${t.hour}</td><td>${t.total_posts}</td><td>${t.successes}</td></tr>`).join('')
    }</tbody></table></div>`;
  } catch (e) {
    error(e.message);
  }
}

export function refresh() {
  loadQueue();
  loadSelects();
  loadBestTimes();
}

export function init() {
  onSubmit('queueForm', async (e) => {
    const fd = new FormData(e.target);
    try {
      await api('/api/social-queue', {
        method: 'POST',
        body: JSON.stringify({
          post_id: parseInt(fd.get('post_id')),
          social_account_id: parseInt(fd.get('social_account_id')),
          optimal_time: fd.get('optimal_time') || null,
          priority: parseInt(fd.get('priority') || '5'),
        }),
      });
      success('Added to queue');
      e.target.reset();
      loadQueue();
    } catch (e) {
      error(e.message);
    }
  });

  const platformFilter = $('bestTimesPlatform');
  if (platformFilter) {
    platformFilter.addEventListener('change', loadBestTimes);
  }

  // AI Smart Posting Times
  onClick('queueRunSmartTimes', async () => {
    const btn = $('queueRunSmartTimes');
    const platform = $('queueSmartPlatform')?.value || 'instagram';
    const audience = $('queueSmartAudience')?.value || '';
    const outputEl = $('queueSmartOutput');

    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    if (outputEl) outputEl.textContent = 'Analyzing optimal posting times...';

    try {
      const { item } = await api('/api/ai/smart-times', {
        method: 'POST',
        body: JSON.stringify({ platform, audience, content_type: 'social_post' }),
      });
      if (item?.schedule && outputEl) {
        outputEl.textContent = item.schedule;
        success('Smart posting times generated');
      }
    } catch (e) {
      if (outputEl) outputEl.textContent = 'Error: ' + e.message;
      error(e.message);
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });
}
