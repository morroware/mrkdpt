/**
 * Social Accounts page — connect, list, test, delete.
 *
 * Dynamically shows platform-specific form fields instead of raw JSON input.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, onSubmit, formData, emptyState, confirm } from '../core/utils.js';
import { success, error } from '../core/toast.js';

/* ── Token field labels & placeholders per platform ── */
const TOKEN_CONFIG = {
  twitter:   { label: 'OAuth 2.0 Bearer Token', placeholder: 'User-context Bearer token from X Developer Portal', hint: 'Requires tweet.read + tweet.write + users.read scopes' },
  bluesky:   { label: 'Access Token', placeholder: 'Leave empty — uses App Password from fields below', hint: 'Bluesky uses handle + app password instead of OAuth tokens', hide: true },
  mastodon:  { label: 'OAuth Access Token', placeholder: 'Token from your Mastodon app settings', hint: 'Create an app at Preferences → Development on your instance' },
  facebook:  { label: 'Page Access Token', placeholder: 'Long-lived Page Access Token', hint: 'Generate via Graph API Explorer or your app settings' },
  instagram: { label: 'Facebook Graph API Token', placeholder: 'Token with instagram_basic + instagram_content_publish', hint: 'Same token type as Facebook — requires Instagram Business account' },
  linkedin:  { label: 'OAuth 2.0 Access Token', placeholder: 'Token with w_member_social scope', hint: 'Uses the LinkedIn Community Management API (Posts API)' },
  threads:   { label: 'Threads API Token', placeholder: 'Token from Meta Threads API', hint: 'Requires threads_basic + threads_content_publish scopes' },
  pinterest: { label: 'OAuth 2.0 Access Token', placeholder: 'Token with pins:read + pins:write scopes', hint: '' },
  tiktok:    { label: 'OAuth 2.0 Access Token', placeholder: 'Token with video.publish scope', hint: '' },
  reddit:    { label: 'OAuth 2.0 Access Token', placeholder: 'Bearer token from Reddit app', hint: 'Create a Reddit app at reddit.com/prefs/apps' },
  telegram:  { label: 'Bot Token', placeholder: '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11', hint: 'Get from @BotFather on Telegram' },
  discord:   { label: 'Webhook URL', placeholder: 'https://discord.com/api/webhooks/...', hint: 'Server Settings → Integrations → Webhooks → Copy URL' },
  slack:     { label: 'Webhook URL', placeholder: 'https://hooks.slack.com/services/T.../B.../...', hint: 'Create at api.slack.com → Incoming Webhooks' },
  wordpress: { label: 'Credentials', placeholder: 'username:application_password', hint: 'Generate an Application Password in WordPress under Users → Profile' },
  medium:    { label: 'Integration Token', placeholder: 'Integration token from Medium settings', hint: 'Medium stopped issuing new tokens in 2023 — existing tokens may still work' },
};

/* ── Platform display names ── */
const PLATFORM_NAMES = {
  twitter: 'Twitter / X', bluesky: 'Bluesky', mastodon: 'Mastodon',
  facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn',
  threads: 'Threads', pinterest: 'Pinterest', tiktok: 'TikTok',
  reddit: 'Reddit', telegram: 'Telegram', discord: 'Discord',
  slack: 'Slack', wordpress: 'WordPress', medium: 'Medium',
};

/* ── Show/hide platform fields and update token label ── */
function updatePlatformFields() {
  const sel = document.getElementById('socialPlatformSelect');
  if (!sel) return;
  const platform = sel.value;

  // Update token field label, placeholder, hint.
  const cfg = TOKEN_CONFIG[platform] || {};
  const tokenLabel = document.getElementById('socialTokenLabel');
  const tokenInput = document.getElementById('socialTokenInput');
  const tokenHint  = document.getElementById('socialTokenHint');
  const tokenWrap  = document.getElementById('socialTokenWrap');

  if (tokenLabel) tokenLabel.textContent = cfg.label || 'Access Token';
  if (tokenInput) tokenInput.placeholder = cfg.placeholder || 'OAuth token';
  if (tokenHint)  tokenHint.textContent  = cfg.hint || '';
  if (tokenWrap)  tokenWrap.style.display = cfg.hide ? 'none' : '';

  // Show only the matching platform fields section.
  document.querySelectorAll('#socialPlatformFields .social-fields').forEach((el) => {
    el.style.display = el.dataset.platform === platform ? '' : 'none';
  });
}

/* ── Collect meta_json from platform-specific fields ── */
function collectMeta() {
  const sel = document.getElementById('socialPlatformSelect');
  if (!sel) return '{}';
  const platform = sel.value;
  const section = document.querySelector(`#socialPlatformFields [data-platform="${platform}"]`);
  if (!section) return '{}';

  const meta = {};
  section.querySelectorAll('[data-meta]').forEach((input) => {
    const key = input.dataset.meta;
    let val = input.value.trim();
    if (val === '') return;

    // Handle special types.
    if (key === 'tags') {
      meta[key] = val.split(',').map((t) => t.trim()).filter(Boolean).slice(0, 5);
    } else {
      meta[key] = val;
    }
  });

  return JSON.stringify(meta);
}

/* ── Render accounts list ── */
export async function refresh() {
  try {
    const { items } = await api('/api/social-accounts');
    const list = $('socialList');
    if (!list) return;

    list.innerHTML = items.length
      ? items.map((a) => {
          const name = PLATFORM_NAMES[a.platform] || a.platform;
          const meta = typeof a.meta === 'object' && a.meta ? a.meta : {};
          const details = buildAccountDetails(a.platform, meta);
          return `<div class="card">
          <div class="flex-between">
            <h4><span class="badge">${escapeHtml(name)}</span> ${escapeHtml(a.account_name)}</h4>
            <div class="btn-group">
              <button class="btn btn-sm btn-outline" data-test="${a.id}">Test</button>
              <button class="btn btn-sm btn-danger" data-delete="${a.id}">Delete</button>
            </div>
          </div>
          <p class="text-small text-muted">Token: ${a.access_token ? '\u2022\u2022\u2022\u2022\u2022' : 'Not set'}${details}</p>
        </div>`;
        }).join('')
      : emptyState('&#128279;', 'No Social Accounts', 'Connect your social media accounts to start publishing.');

    list.querySelectorAll('[data-test]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = 'Testing...';
        try {
          const res = await api(`/api/social-accounts/${btn.dataset.test}/test`, { method: 'POST' });
          success(`Connection OK${res.info ? ': ' + res.info : ''}`);
        } catch (err) { error('Test failed: ' + err.message); }
        finally { btn.disabled = false; btn.textContent = 'Test'; }
      });
    });

    list.querySelectorAll('[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!await confirm('Remove Account', 'Are you sure you want to remove this social account?')) return;
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

/* ── Build detail string for account card ── */
function buildAccountDetails(platform, meta) {
  const parts = [];
  if (meta.instance_url) parts.push(meta.instance_url);
  if (meta.page_id) parts.push('Page: ' + meta.page_id);
  if (meta.ig_account_id) parts.push('IG: ' + meta.ig_account_id);
  if (meta.urn) parts.push(meta.urn);
  if (meta.threads_user_id) parts.push('User: ' + meta.threads_user_id);
  if (meta.board_id) parts.push('Board: ' + meta.board_id);
  if (meta.subreddit) parts.push('r/' + meta.subreddit);
  if (meta.chat_id) parts.push('Chat: ' + meta.chat_id);
  if (meta.site_url) parts.push(meta.site_url);
  if (meta.identifier) parts.push(meta.identifier);
  if (meta.privacy_level) parts.push('Privacy: ' + meta.privacy_level);
  return parts.length ? ' · ' + parts.map(escapeHtml).join(' · ') : '';
}

/* ── Init ── */
export function init() {
  const sel = document.getElementById('socialPlatformSelect');
  if (sel) {
    sel.addEventListener('change', updatePlatformFields);
    updatePlatformFields();
  }

  onSubmit('socialForm', async (e) => {
    try {
      const data = formData(e);
      data.meta_json = collectMeta();

      await api('/api/social-accounts', { method: 'POST', body: JSON.stringify(data) });
      e.target.reset();

      // Clear platform-specific fields.
      document.querySelectorAll('#socialPlatformFields [data-meta]').forEach((el) => {
        if (el.tagName === 'SELECT') el.selectedIndex = 0;
        else el.value = '';
      });

      success('Account connected');
      updatePlatformFields();
      refresh();
    } catch (err) {
      error(err.message);
    }
  });
}
