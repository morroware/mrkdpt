/**
 * Competitors page — CRUD.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, onSubmit, formData } from '../core/utils.js';
import { success, error } from '../core/toast.js';

export async function refresh() {
  try {
    const { items } = await api('/api/competitors');
    const list = $('competitorList');
    if (!list) return;

    list.innerHTML = items.length
      ? items.map((c) => `<div class="card">
          <div class="flex-between">
            <h4>${escapeHtml(c.name)} <span class="badge">${escapeHtml(c.channel)}</span></h4>
            <button class="btn btn-sm btn-danger" data-delete="${c.id}">Delete</button>
          </div>
          ${c.positioning ? `<p class="text-small"><strong>Positioning:</strong> ${escapeHtml(c.positioning)}</p>` : ''}
          ${c.recent_activity ? `<p class="text-small"><strong>Activity:</strong> ${escapeHtml(c.recent_activity)}</p>` : ''}
          ${c.opportunity ? `<p class="text-small"><strong>Opportunity:</strong> ${escapeHtml(c.opportunity)}</p>` : ''}
        </div>`).join('')
      : '<p class="text-muted">No competitors tracked yet</p>';

    list.querySelectorAll('[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this competitor?')) return;
        try {
          await api(`/api/competitors/${btn.dataset.delete}`, { method: 'DELETE' });
          success('Competitor removed');
          refresh();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load competitors: ' + err.message);
  }
}

export function init() {
  onSubmit('competitorForm', async (e) => {
    try {
      await api('/api/competitors', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Competitor added');
      refresh();
    } catch (err) {
      error(err.message);
    }
  });
}
