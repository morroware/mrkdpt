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

export function init() {
  onSubmit('socialForm', async (e) => {
    try {
      await api('/api/social-accounts', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Account connected');
      refresh();
    } catch (err) {
      error(err.message);
    }
  });
}
