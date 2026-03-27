/**
 * RSS Feeds page — manage feeds and view items.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, onSubmit, formData, onClick, emptyState, tableEmpty, confirm } from '../core/utils.js';
import { success, error } from '../core/toast.js';
import { navigate } from '../core/router.js';

let lastRssPost = '';

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
      : emptyState('&#128225;', 'No RSS Feeds', 'Add RSS feeds to monitor industry news and generate content.');

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
        if (!await confirm('Delete Feed', 'Are you sure you want to delete this RSS feed?')) return;
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
          <td><button class="btn btn-sm btn-ai" data-rss-post="${i.id}" data-rss-title="${escapeHtml(i.title)}" data-rss-summary="${escapeHtml(i.summary || '')}" data-rss-url="${escapeHtml(i.url || '')}"><span class="btn-ai-icon">&#9733;</span> AI Post</button></td>
        </tr>`).join('')
      : tableEmpty(4, 'No RSS items yet');

    // Wire AI Post buttons
    table.querySelectorAll('[data-rss-post]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const title = btn.dataset.rssTitle || '';
        const summary = btn.dataset.rssSummary || '';
        const url = btn.dataset.rssUrl || '';
        const platform = $('rssPostPlatform')?.value || 'twitter';

        btn.classList.add('loading'); btn.disabled = true;
        try {
          const { item } = await api('/api/ai/rss-to-post', {
            method: 'POST',
            body: JSON.stringify({ title, summary, url, platform }),
          });
          lastRssPost = item?.post || '';
          const modal = $('rssPostModal');
          const output = $('rssPostOutput');
          if (modal && output) {
            output.textContent = lastRssPost;
            modal.classList.add('visible');
          }
          success('Post generated from RSS item');
        } catch (err) { error(err.message); }
        finally { btn.classList.remove('loading'); btn.disabled = false; }
      });
    });
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

  onClick('closeRssPostModal', () => $('rssPostModal')?.classList.remove('visible'));

  onClick('rssPostCopy', () => {
    if (!lastRssPost) return;
    navigator.clipboard.writeText(lastRssPost).then(() => success('Copied')).catch(() => error('Copy failed'));
  });

  onClick('rssPostCreate', () => {
    if (!lastRssPost) return;
    try { sessionStorage.setItem('ai_generated_content', lastRssPost); } catch (err) { error('Failed to store content: ' + err.message); }
    $('rssPostModal')?.classList.remove('visible');
    navigate('content');
    setTimeout(() => {
      document.querySelector('[data-tab="content-create"]')?.click();
      const bodyField = document.querySelector('#postForm [name="body"]');
      if (bodyField) { bodyField.value = lastRssPost; success('RSS post loaded into content form'); }
    }, 200);
  });

  // Re-generate when platform changes
  const platformSelect = $('rssPostPlatform');
  if (platformSelect) {
    platformSelect.addEventListener('change', () => {
      // User will need to click AI Post again from the table
    });
  }
}
