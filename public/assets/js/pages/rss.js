/**
 * RSS Feeds page — manage feeds and view items.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, onSubmit, formData } from '../core/utils.js';
import { success, error } from '../core/toast.js';

async function refreshFeeds() {
  try {
    const { items } = await api('/api/rss-feeds');
    const list = $('rssFeedList');
    if (!list) return;

    list.innerHTML = items.length
      ? items.map((f) => `<div class="card">
          <div class="flex-between">
            <h4>${escapeHtml(f.name || f.url)}</h4>
            <div class="btn-group">
              <button class="btn btn-sm btn-outline" data-fetch="${f.id}">Fetch Now</button>
              <button class="btn btn-sm btn-danger" data-delete-feed="${f.id}">Delete</button>
            </div>
          </div>
          <p class="text-small text-muted">${escapeHtml(f.url)}</p>
          <p class="text-small text-muted">Last fetched: ${formatDate(f.last_fetched_at) || 'Never'}</p>
        </div>`).join('')
      : '<p class="text-muted">No RSS feeds configured</p>';

    list.querySelectorAll('[data-fetch]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          const result = await api(`/api/rss-feeds/${btn.dataset.fetch}/fetch`, { method: 'POST' });
          success(`Fetched ${result.new_items ?? 0} new items`);
          refreshItems();
        } catch (err) { error(err.message); }
      });
    });

    list.querySelectorAll('[data-delete-feed]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this feed?')) return;
        try {
          await api(`/api/rss-feeds/${btn.dataset.deleteFeed}`, { method: 'DELETE' });
          success('Feed deleted');
          refresh();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load feeds: ' + err.message);
  }
}

async function refreshItems() {
  try {
    const { items } = await api('/api/rss-items');
    const table = $('rssItemTable');
    if (!table) return;

    table.innerHTML = items.length
      ? items.map((i) => `<tr>
          <td><a href="${escapeHtml(i.url)}" target="_blank" rel="noopener">${escapeHtml(i.title)}</a></td>
          <td>${escapeHtml(i.feed_name || '')}</td>
          <td>${formatDate(i.published_at)}</td>
          <td></td>
        </tr>`).join('')
      : '<tr><td colspan="4" class="text-muted">No items</td></tr>';
  } catch (err) {
    error('Failed to load RSS items: ' + err.message);
  }
}

export async function refresh() {
  await Promise.all([refreshFeeds(), refreshItems()]);
}

export function init() {
  onSubmit('rssForm', async (e) => {
    try {
      await api('/api/rss-feeds', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Feed added');
      refresh();
    } catch (err) { error(err.message); }
  });
}
