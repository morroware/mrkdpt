/**
 * Social Accounts page — connect, list, test, delete.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, onSubmit, formData } from '../core/utils.js';
import { success, error } from '../core/toast.js';

export async function refresh() {
  try {
    const { items } = await api('/api/social-accounts');
    const list = $('socialList');
    if (!list) return;

    list.innerHTML = items.length
      ? items.map((a) => `<div class="card">
          <div class="flex-between">
            <h4><span class="badge">${escapeHtml(a.platform)}</span> ${escapeHtml(a.account_name)}</h4>
            <div class="btn-group">
              <button class="btn btn-sm btn-outline" data-test="${a.id}">Test</button>
              <button class="btn btn-sm btn-danger" data-delete="${a.id}">Delete</button>
            </div>
          </div>
          <p class="text-small text-muted">Token: ${a.access_token ? '\u2022\u2022\u2022\u2022\u2022' : 'Not set'}</p>
        </div>`).join('')
      : '<p class="text-muted">No social accounts connected</p>';

    list.querySelectorAll('[data-test]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api(`/api/social-accounts/${btn.dataset.test}/test`, { method: 'POST' });
          success('Connection test passed');
        } catch (err) { error(err.message); }
      });
    });

    list.querySelectorAll('[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Remove this account?')) return;
        try {
          await api(`/api/social-accounts/${btn.dataset.delete}`, { method: 'DELETE' });
          success('Account removed');
          refresh();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load social accounts: ' + err.message);
  }
}

const META_HINTS = {
  twitter: 'OAuth 2.0 Bearer token in Access Token field',
  bluesky: '{"identifier":"handle.bsky.social","password":"app-password"}',
  mastodon: '{"instance_url":"https://mastodon.social"}',
  facebook: '{"page_id":"123456"} — Page Access Token in Access Token field',
  instagram: '{"ig_account_id":"123456"} — uses Facebook Graph API token',
  linkedin: '{"urn":"urn:li:person:xxx"} or {"urn":"urn:li:organization:xxx"}',
  threads: '{"threads_user_id":"123456"} — uses Meta/Threads API token',
  pinterest: '{"board_id":"123456"} — optional: {"board_id":"...","link":"https://..."}',
  tiktok: 'OAuth 2.0 token with video.publish scope',
  reddit: '{"subreddit":"marketing"} — OAuth 2.0 Bearer token',
  telegram: '{"chat_id":"@channelname"} — Bot token in Access Token field',
  discord: 'Paste full webhook URL in Access Token field (no meta needed)',
  slack: 'Paste incoming webhook URL in Access Token field (no meta needed)',
  wordpress: '{"site_url":"https://myblog.com","status":"draft"} — "user:app_password" in Access Token',
  medium: 'Integration token in Access Token field — optional: {"publish_status":"draft","tags":["marketing"]}',
};

function updateMetaHint() {
  const sel = document.getElementById('socialPlatformSelect');
  const hint = document.getElementById('socialMetaHint');
  if (sel && hint) {
    hint.textContent = META_HINTS[sel.value] || '';
  }
}

export function init() {
  const sel = document.getElementById('socialPlatformSelect');
  if (sel) {
    sel.addEventListener('change', updateMetaHint);
    updateMetaHint();
  }

  onSubmit('socialForm', async (e) => {
    try {
      await api('/api/social-accounts', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Account connected');
      updateMetaHint();
      refresh();
    } catch (err) {
      error(err.message);
    }
  });
}
