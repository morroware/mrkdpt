/**
 * Campaigns page — CRUD.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, onSubmit, formData } from '../core/utils.js';
import { success, error } from '../core/toast.js';

export async function refresh() {
  try {
    const { items } = await api('/api/campaigns');
    const list = $('campaignList');
    if (!list) return;

    list.innerHTML = items.length
      ? items.map((c) => `<div class="card">
          <div class="flex-between">
            <h4>${escapeHtml(c.name)}</h4>
            <button class="btn btn-sm btn-danger" data-delete="${c.id}">Delete</button>
          </div>
          <p><strong>${escapeHtml(c.channel)}</strong> &mdash; ${escapeHtml(c.objective)}</p>
          <p class="text-small text-muted">Budget: $${escapeHtml(c.budget || '0')} | ${formatDate(c.start_date)}${c.end_date ? ' \u2192 ' + formatDate(c.end_date) : ''}</p>
          ${c.notes ? `<p class="text-small">${escapeHtml(c.notes)}</p>` : ''}
        </div>`).join('')
      : '<p class="text-muted">No campaigns yet</p>';

    list.querySelectorAll('[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this campaign?')) return;
        try {
          await api(`/api/campaigns/${btn.dataset.delete}`, { method: 'DELETE' });
          success('Campaign deleted');
          refresh();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load campaigns: ' + err.message);
  }
}

export function init() {
  onSubmit('campaignForm', async (e) => {
    try {
      await api('/api/campaigns', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Campaign created');
      refresh();
    } catch (err) {
      error(err.message);
    }
  });
}
