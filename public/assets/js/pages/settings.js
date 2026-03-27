/**
 * Settings page — editable business info, health, token, backup, webhooks, cron log.
 */

import { api, setApiToken } from '../core/api.js';
import { $, escapeHtml, formatDateTime, onSubmit, formData, onClick, copyToClipboard, confirm } from '../core/utils.js';
import { success, error } from '../core/toast.js';

let currentSettings = {};

async function refreshSettingsInfo() {
  try {
    const data = await api('/api/settings');
    currentSettings = data;
    const el = $('settingsInfo');
    if (el) {
      el.innerHTML = `
        <form id="settingsForm" class="form-grid">
          <div class="form-group">
            <label for="set_business_name">Business Name</label>
            <input type="text" id="set_business_name" name="BUSINESS_NAME" class="input" value="${escapeHtml(data.business_name || '')}" />
          </div>
          <div class="form-group">
            <label for="set_business_industry">Industry</label>
            <input type="text" id="set_business_industry" name="BUSINESS_INDUSTRY" class="input" value="${escapeHtml(data.business_industry || '')}" />
          </div>
          <div class="form-group">
            <label for="set_timezone">Timezone</label>
            <input type="text" id="set_timezone" name="TIMEZONE" class="input" value="${escapeHtml(data.timezone || '')}" placeholder="America/New_York" />
          </div>
          <div class="form-group">
            <label for="set_ai_provider">AI Provider</label>
            <select id="set_ai_provider" name="AI_PROVIDER" class="input">
              ${['openai','anthropic','gemini','deepseek','groq','mistral','openrouter','xai','together'].map(p => {
                const hasKey = data.ai?.providers?.[p]?.configured;
                const label = p.charAt(0).toUpperCase() + p.slice(1) + (hasKey ? '' : ' (no key)');
                return `<option value="${p}" ${(data.ai_provider || 'openai') === p ? 'selected' : ''}>${label}</option>`;
              }).join('')}
            </select>
          </div>
          <div class="form-group" id="modelSelectGroup">
            <label for="set_ai_model">Model</label>
            <select id="set_ai_model" class="input"></select>
          </div>
          <div class="form-group" style="grid-column: 1 / -1;">
            <label for="set_ai_system_prompt">Custom AI System Prompt <span class="text-muted text-small">(leave blank for default)</span></label>
            <textarea id="set_ai_system_prompt" name="AI_SYSTEM_PROMPT" class="input" rows="3" placeholder="You are a practical SMB marketing strategist. Be concise but specific.">${escapeHtml(data.ai_system_prompt || '')}</textarea>
          </div>
          <div class="form-group">
            <label for="set_app_url">App URL</label>
            <input type="text" id="set_app_url" name="APP_URL" class="input" value="${escapeHtml(data.app_url || '')}" placeholder="https://yourdomain.com" />
          </div>
          <div class="form-group" style="grid-column: 1 / -1;">
            <h4 style="margin:0.5rem 0 0.25rem;">AI Provider API Keys & Endpoints</h4>
            <p class="text-muted text-small" style="margin:0;">Leave key fields blank to keep existing values. Enter a new key to replace.</p>
          </div>
          <div class="form-group"><label for="set_openai_key">OpenAI API Key</label><input type="password" id="set_openai_key" name="OPENAI_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.openai_api_key ? 'Saved (enter to replace)' : 'sk-...'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_openai_base">OpenAI Base URL</label><input type="text" id="set_openai_base" name="OPENAI_BASE_URL" class="input" value="${escapeHtml(data.ai_config?.openai_base_url || '')}" /></div>
          <div class="form-group"><label for="set_anthropic_key">Anthropic API Key</label><input type="password" id="set_anthropic_key" name="ANTHROPIC_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.anthropic_api_key ? 'Saved (enter to replace)' : 'sk-ant-...'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_gemini_key">Gemini API Key</label><input type="password" id="set_gemini_key" name="GEMINI_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.gemini_api_key ? 'Saved (enter to replace)' : 'AIza...'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_deepseek_key">DeepSeek API Key</label><input type="password" id="set_deepseek_key" name="DEEPSEEK_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.deepseek_api_key ? 'Saved (enter to replace)' : 'DeepSeek key'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_deepseek_base">DeepSeek Base URL</label><input type="text" id="set_deepseek_base" name="DEEPSEEK_BASE_URL" class="input" value="${escapeHtml(data.ai_config?.deepseek_base_url || '')}" /></div>
          <div class="form-group"><label for="set_groq_key">Groq API Key</label><input type="password" id="set_groq_key" name="GROQ_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.groq_api_key ? 'Saved (enter to replace)' : 'gsk_...'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_groq_base">Groq Base URL</label><input type="text" id="set_groq_base" name="GROQ_BASE_URL" class="input" value="${escapeHtml(data.ai_config?.groq_base_url || '')}" /></div>
          <div class="form-group"><label for="set_mistral_key">Mistral API Key</label><input type="password" id="set_mistral_key" name="MISTRAL_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.mistral_api_key ? 'Saved (enter to replace)' : 'Mistral key'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_mistral_base">Mistral Base URL</label><input type="text" id="set_mistral_base" name="MISTRAL_BASE_URL" class="input" value="${escapeHtml(data.ai_config?.mistral_base_url || '')}" /></div>
          <div class="form-group"><label for="set_openrouter_key">OpenRouter API Key</label><input type="password" id="set_openrouter_key" name="OPENROUTER_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.openrouter_api_key ? 'Saved (enter to replace)' : 'sk-or-...'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_openrouter_base">OpenRouter Base URL</label><input type="text" id="set_openrouter_base" name="OPENROUTER_BASE_URL" class="input" value="${escapeHtml(data.ai_config?.openrouter_base_url || '')}" /></div>
          <div class="form-group"><label for="set_xai_key">xAI API Key</label><input type="password" id="set_xai_key" name="XAI_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.xai_api_key ? 'Saved (enter to replace)' : 'xAI key'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_xai_base">xAI Base URL</label><input type="text" id="set_xai_base" name="XAI_BASE_URL" class="input" value="${escapeHtml(data.ai_config?.xai_base_url || '')}" /></div>
          <div class="form-group"><label for="set_together_key">Together API Key</label><input type="password" id="set_together_key" name="TOGETHER_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.together_api_key ? 'Saved (enter to replace)' : 'Together key'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_together_base">Together Base URL</label><input type="text" id="set_together_base" name="TOGETHER_BASE_URL" class="input" value="${escapeHtml(data.ai_config?.together_base_url || '')}" /></div>
          <div class="form-group"><label for="set_banana_key">NanoBanana API Key</label><input type="password" id="set_banana_key" name="BANANA_API_KEY" class="input" placeholder="${data.ai_config?.key_flags?.banana_api_key ? 'Saved (enter to replace)' : 'Banana key'}" autocomplete="off" /></div>
          <div class="form-group"><label for="set_banana_base">NanoBanana Base URL</label><input type="text" id="set_banana_base" name="BANANA_BASE_URL" class="input" value="${escapeHtml(data.ai_config?.banana_base_url || '')}" /></div>
          <div class="form-group"><label for="set_banana_model">NanoBanana Model ID</label><input type="text" id="set_banana_model" name="BANANA_MODEL_ID" class="input" value="${escapeHtml(data.ai_config?.banana_model_id || '')}" placeholder="Required for Banana image generation" /></div>
          <div class="form-group flex" style="align-items: flex-end;">
            <button type="submit" class="btn btn-ai">Save Settings</button>
          </div>
        </form>
        <div class="mt-1">
          <p class="text-muted text-small"><strong>AI Provider Status:</strong>
            ${['openai','anthropic','gemini','deepseek','groq','mistral','openrouter','xai','together'].map(p => {
              const ok = data.ai?.providers?.[p]?.configured;
              return `<span class="${ok ? 'text-success' : 'text-muted'}" style="margin-right:0.5em;">${p.charAt(0).toUpperCase() + p.slice(1)}: ${ok ? 'Ready' : 'No key'}</span>`;
            }).join(' ')}
          </p>
          <p class="text-muted text-small"><strong>SMTP:</strong> ${data.smtp_configured ? 'Configured' : 'Not configured (set in .env)'}</p>
        </div>
      `;

      // Wire up model dropdown based on provider selection
      const providerSelect = document.getElementById('set_ai_provider');
      const modelSelect = document.getElementById('set_ai_model');
      const aiModels = data.ai?.models || {};
      const currentModels = data.ai_models || data.ai?.current_models || {};

      function updateModelDropdown() {
        if (!modelSelect || !providerSelect) return;
        const provider = providerSelect.value;
        const models = aiModels[provider] || {};
        const currentModel = currentModels[provider] || '';
        const modelKey = provider.toUpperCase() + '_MODEL';
        modelSelect.name = modelKey;
        modelSelect.innerHTML = Object.entries(models).map(([id, label]) =>
          `<option value="${id}" ${id === currentModel ? 'selected' : ''}>${escapeHtml(label)}</option>`
        ).join('');
      }
      updateModelDropdown();
      if (providerSelect) {
        providerSelect.addEventListener('change', updateModelDropdown);
      }

      const form = document.getElementById('settingsForm');
      if (form) {
        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          const btn = form.querySelector('button[type="submit"]');
          btn.disabled = true;
          btn.classList.add('loading');
          try {
            const fd = new FormData(form);
            const payload = {};
            for (const [k, v] of fd.entries()) {
              payload[k] = v;
            }
            const sensitive = ['OPENAI_API_KEY','ANTHROPIC_API_KEY','GEMINI_API_KEY','DEEPSEEK_API_KEY','GROQ_API_KEY','MISTRAL_API_KEY','OPENROUTER_API_KEY','XAI_API_KEY','TOGETHER_API_KEY','BANANA_API_KEY'];
            sensitive.forEach((key) => {
              if ((payload[key] || '').trim() === '') {
                delete payload[key];
              }
            });
            await api('/api/settings', { method: 'PUT', body: JSON.stringify(payload) });
            success('Settings saved');
          } catch (err) {
            error('Failed to save: ' + err.message);
          } finally {
            btn.disabled = false;
            btn.classList.remove('loading');
          }
        });
      }
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
        <p><strong>Extensions:</strong> ${Object.entries(exts).map(([k, v]) => `${k}: ${v ? '<span class="text-success">OK</span>' : '<span class="text-danger">Missing</span>'}`).join(', ')}</p>
        ${data.last_cron ? `<p><strong>Last Cron:</strong> ${formatDateTime(data.last_cron.ran_at)}</p>` : '<p class="text-muted">No cron runs recorded yet</p>'}
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
      const token = data.api_token || '';
      const masked = token.length > 8 ? '••••••••' + token.slice(-8) : token;
      el.innerHTML = `
        <p class="text-small">Use this token for API access:</p>
        <div class="flex gap-1" style="align-items: center;">
          <code class="token-display" id="tokenDisplay">${escapeHtml(masked)}</code>
          <button class="btn btn-sm btn-ghost" id="copyToken" title="Copy full token" aria-label="Copy token">Copy</button>
          <button class="btn btn-sm btn-outline" id="regenToken">Regenerate</button>
        </div>
      `;
      const fullToken = token;
      onClick('copyToken', () => {
        const btn = $('copyToken');
        copyToClipboard(fullToken, btn);
      });
      onClick('regenToken', async () => {
        const ok = await confirm('Regenerate Token', 'The current API token will stop working. Any integrations using it will need to be updated.', { icon: '&#128274;', okText: 'Regenerate', okClass: 'btn-warning' });
        if (!ok) return;
        try {
          const result = await api('/api/regenerate-token', { method: 'POST' });
          if (result.api_token) setApiToken(result.api_token);
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
        const ok = await confirm('Delete Webhook', 'Are you sure you want to remove this webhook?');
        if (!ok) return;
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
