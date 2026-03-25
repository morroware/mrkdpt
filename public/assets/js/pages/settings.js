/**
 * Settings page — business info, health, token, backup, webhooks, cron log.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDateTime, onSubmit, formData, onClick } from '../core/utils.js';
import { success, error } from '../core/toast.js';

async function refreshSettingsInfo() {
  try {
    const data = await api('/api/settings');
    const el = $('settingsInfo');
    if (el) {
      el.innerHTML = `
        <p><strong>Business:</strong> ${escapeHtml(data.business_name)}</p>
        <p><strong>Industry:</strong> ${escapeHtml(data.business_industry)}</p>
        <p><strong>Timezone:</strong> ${escapeHtml(data.timezone)}</p>
        <p><strong>AI Provider:</strong> ${escapeHtml(data.ai?.active_provider || 'none')}</p>
        <p><strong>SMTP:</strong> ${data.smtp_configured ? 'Configured' : 'Not configured'}</p>
        <p><strong>App URL:</strong> ${escapeHtml(data.app_url || 'Not set')}</p>
      `;
    }
  } catch (err) {
    error('Failed to load settings: ' + err.message);
  }
}

async function refreshHealth() {
  try {
    const data = await api('/api/settings/health');
    const el = $('healthInfo');
    if (el) {
      const exts = data.extensions || {};
      el.innerHTML = `
        <p><strong>PHP:</strong> ${escapeHtml(data.php_version)}</p>
        <p><strong>SQLite:</strong> ${escapeHtml(data.sqlite_version)}</p>
        <p><strong>Disk Free:</strong> ${data.disk_free_mb ? data.disk_free_mb + ' MB' : 'Unknown'}</p>
        <p><strong>Data Dir:</strong> ${data.data_dir_writable ? 'Writable' : 'NOT writable'}</p>
        <p><strong>Extensions:</strong> ${Object.entries(exts).map(([k, v]) => `${k}: ${v ? 'OK' : 'Missing'}`).join(', ')}</p>
        ${data.last_cron ? `<p><strong>Last Cron:</strong> ${formatDateTime(data.last_cron.ran_at)}</p>` : ''}
      `;
    }
  } catch (err) {
    error('Failed to load health: ' + err.message);
  }
}

async function refreshToken() {
  try {
    const data = await api('/api/me');
    const el = $('tokenInfo');
    if (el) {
      el.innerHTML = `
        <p class="text-small">Use this token for API access:</p>
        <code class="token-display">${escapeHtml(data.api_token || 'No token')}</code>
        <button class="btn btn-sm btn-outline mt-1" id="regenToken">Regenerate</button>
      `;
      onClick('regenToken', async () => {
        try {
          const result = await api('/api/regenerate-token', { method: 'POST' });
          success('Token regenerated');
          refreshToken();
        } catch (err) { error(err.message); }
      });
    }
  } catch { /* not logged in */ }
}

async function refreshWebhooks() {
  try {
    const { items } = await api('/api/webhooks');
    const list = $('webhookList');
    if (!list) return;

    list.innerHTML = items.length
      ? items.map((w) => `<div class="list-item flex-between">
          <div>
            <span class="badge">${escapeHtml(w.event)}</span>
            <span class="text-small">${escapeHtml(w.url)}</span>
          </div>
          <div class="btn-group">
            <button class="btn btn-sm btn-outline" data-test-wh="${w.id}">Test</button>
            <button class="btn btn-sm btn-danger" data-delete-wh="${w.id}">Del</button>
          </div>
        </div>`).join('')
      : '<p class="text-muted">No webhooks configured</p>';

    list.querySelectorAll('[data-test-wh]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api(`/api/webhooks/${btn.dataset.testWh}/test`, { method: 'POST' });
          success('Webhook test sent');
        } catch (err) { error(err.message); }
      });
    });

    list.querySelectorAll('[data-delete-wh]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this webhook?')) return;
        try {
          await api(`/api/webhooks/${btn.dataset.deleteWh}`, { method: 'DELETE' });
          success('Webhook deleted');
          refreshWebhooks();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load webhooks: ' + err.message);
  }
}

async function refreshCronLog() {
  try {
    const { items } = await api('/api/cron-log');
    const el = $('cronLog');
    if (!el) return;

    el.innerHTML = items.length
      ? items.slice(0, 20).map((l) =>
          `<div class="list-item"><span class="text-small">${formatDateTime(l.ran_at)}</span> &mdash; ${escapeHtml(l.summary || 'OK')}</div>`
        ).join('')
      : '<p class="text-muted">No cron runs recorded</p>';
  } catch (err) {
    error('Failed to load cron log: ' + err.message);
  }
}

export async function refresh() {
  await Promise.all([
    refreshSettingsInfo(),
    refreshHealth(),
    refreshToken(),
    refreshWebhooks(),
    refreshCronLog(),
  ]);
}

export function init() {
  // Webhook form
  onSubmit('webhookForm', async (e) => {
    try {
      await api('/api/webhooks', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Webhook added');
      refreshWebhooks();
    } catch (err) { error(err.message); }
  });

  // Backup download
  onClick('downloadBackup', async () => {
    try {
      const response = await api('/api/settings/backup', { method: 'POST' });
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'marketing-backup.sqlite';
      a.click();
      URL.revokeObjectURL(url);
      success('Backup downloaded');
    } catch (err) {
      error('Backup failed: ' + err.message);
    }
  });
}
