/**
 * Form Builder page module.
 */
import { api, getBasePath } from '../core/api.js';
import { $, escapeHtml, formatDate, emptyState, confirm, tableEmpty } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('formBuilderForm')?.addEventListener('submit', handleCreate);
  $('loadSubmissions')?.addEventListener('click', loadSubmissions);
}

export async function refresh() {
  await Promise.all([loadForms(), loadListOptions(), loadSubmissionFormOptions()]);
}

async function loadForms() {
  try {
    const data = await api('/api/forms');
    const items = data.items || data;
    const el = $('formList');
    if (!el) return;
    const base = window.location.origin + getBasePath();
    el.innerHTML = items.map(f => {
      const embedUrl = `${base}/f/${f.slug}`;
      let fieldCount = 0;
      try { fieldCount = (JSON.parse(f.fields || '[]')).length; } catch { fieldCount = 0; }
      return `<div class="card">
        <div class="flex-between"><h3>${escapeHtml(f.name)}</h3><span class="badge badge-${f.status === 'active' ? 'success' : 'muted'}">${f.status}</span></div>
        <p class="text-muted text-small mt-1">${fieldCount} fields &middot; ${f.submissions} submissions &middot; ${getBasePath()}/f/${escapeHtml(f.slug)}</p>
        <div class="btn-group mt-1">
          <a href="${embedUrl}" target="_blank" class="btn btn-sm btn-outline">Preview</a>
          <button class="btn btn-sm btn-outline" onclick="window._copyFormEmbed(${f.id})">Embed Code</button>
          <button class="btn btn-sm btn-danger" onclick="window._deleteForm(${f.id})">Delete</button>
        </div>
      </div>`;
    }).join('') || emptyState('&#128221;', 'No forms yet', 'Build your first form to collect leads and feedback.');
  } catch (err) {
    toast('Failed to load forms: ' + err.message, 'error');
  }
}

async function loadListOptions() {
  try {
    const resp = await api('/api/email-lists');
    const lists = resp.items || resp;
    const sel = $('formListSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">None</option>' + lists.map(l => `<option value="${l.id}">${escapeHtml(l.name)}</option>`).join('');
  } catch (err) {
    toast('Failed to load email lists: ' + err.message, 'error');
  }
}

async function loadSubmissionFormOptions() {
  try {
    const resp = await api('/api/forms');
    const forms = resp.items || resp;
    const sel = $('submissionFormSelect');
    if (!sel) return;
    sel.innerHTML = forms.map(f => `<option value="${f.id}">${escapeHtml(f.name)} (${f.submissions})</option>`).join('');
  } catch (err) {
    toast('Failed to load form options: ' + err.message, 'error');
  }
}

async function handleCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  // Parse fields JSON
  try {
    data.fields = JSON.parse(data.fields || '[]');
  } catch {
    toast('Invalid fields JSON', 'error');
    return;
  }
  try {
    await api('/api/forms', { method: 'POST', body: JSON.stringify(data) });
    toast('Form created', 'success');
    e.target.reset();
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

async function loadSubmissions() {
  const formId = $('submissionFormSelect')?.value;
  if (!formId) return;
  try {
    const data = await api(`/api/forms/${formId}/submissions`);
    const items = data.items || data;
    const tb = $('submissionTable');
    if (!tb) return;
    tb.innerHTML = items.map(s => {
      let d = {};
      try { d = JSON.parse(s.data_json || '{}'); } catch { d = {}; }
      const fields = Object.entries(d).map(([k, v]) => `<strong>${escapeHtml(k)}:</strong> ${escapeHtml(String(v))}`).join(', ');
      return `<tr>
        <td class="text-small">${formatDate(s.submitted_at)}</td>
        <td>${s.contact_email ? escapeHtml(s.contact_email) : '-'}</td>
        <td class="text-small">${fields}</td>
        <td class="text-small text-muted">${escapeHtml(s.page_url)}</td>
      </tr>`;
    }).join('') || tableEmpty(4, 'No submissions yet');
  } catch (err) {
    toast(err.message, 'error');
  }
}

window._copyFormEmbed = async (id) => {
  try {
    const resp = await api(`/api/forms/${id}/embed`);
    const data = resp.item || resp;
    navigator.clipboard.writeText(data.embed_code);
    toast('Embed code copied to clipboard', 'info');
  } catch (err) {
    toast(err.message, 'error');
  }
};

window._deleteForm = async (id) => {
  if (!await confirm('Delete Form', 'Are you sure you want to delete this form and all its submissions? This cannot be undone.')) return;
  try { await api(`/api/forms/${id}`, { method: 'DELETE' }); toast('Deleted', 'success'); refresh(); } catch (e) { toast(e.message, 'error'); }
};

