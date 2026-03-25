/**
 * Form Builder page module.
 */
import { api, getBasePath } from '../core/api.js';
import { $, formatDate } from '../core/utils.js';
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
    const el = $('formList');
    if (!el) return;
    const base = window.location.origin + getBasePath();
    el.innerHTML = data.map(f => {
      const embedUrl = `${base}/f/${f.slug}`;
      const fieldCount = (JSON.parse(f.fields || '[]')).length;
      return `<div class="card">
        <div class="flex-between"><h3>${esc(f.name)}</h3><span class="badge badge-${f.status === 'active' ? 'success' : 'muted'}">${f.status}</span></div>
        <p class="text-muted text-small mt-1">${fieldCount} fields &middot; ${f.submissions} submissions &middot; ${getBasePath()}/f/${esc(f.slug)}</p>
        <div class="btn-group mt-1">
          <a href="${embedUrl}" target="_blank" class="btn btn-sm btn-outline">Preview</a>
          <button class="btn btn-sm btn-outline" onclick="window._copyFormEmbed(${f.id})">Embed Code</button>
          <button class="btn btn-sm btn-danger" onclick="window._deleteForm(${f.id})">Delete</button>
        </div>
      </div>`;
    }).join('') || '<p class="text-muted">No forms yet</p>';
  } catch {}
}

async function loadListOptions() {
  try {
    const lists = await api('/api/email/lists');
    const sel = $('formListSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">None</option>' + lists.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
  } catch {}
}

async function loadSubmissionFormOptions() {
  try {
    const forms = await api('/api/forms');
    const sel = $('submissionFormSelect');
    if (!sel) return;
    sel.innerHTML = forms.map(f => `<option value="${f.id}">${esc(f.name)} (${f.submissions})</option>`).join('');
  } catch {}
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
    const tb = $('submissionTable');
    if (!tb) return;
    tb.innerHTML = data.map(s => {
      const d = JSON.parse(s.data_json || '{}');
      const fields = Object.entries(d).map(([k, v]) => `<strong>${esc(k)}:</strong> ${esc(String(v))}`).join(', ');
      return `<tr>
        <td class="text-small">${formatDate(s.submitted_at)}</td>
        <td>${s.contact_email ? esc(s.contact_email) : '-'}</td>
        <td class="text-small">${fields}</td>
        <td class="text-small text-muted">${esc(s.page_url)}</td>
      </tr>`;
    }).join('') || '<tr><td colspan="4" class="text-muted">No submissions</td></tr>';
  } catch (err) {
    toast(err.message, 'error');
  }
}

window._copyFormEmbed = async (id) => {
  try {
    const data = await api(`/api/forms/${id}/embed`);
    navigator.clipboard.writeText(data.embed_code);
    toast('Embed code copied to clipboard', 'info');
  } catch (err) {
    toast(err.message, 'error');
  }
};

window._deleteForm = async (id) => {
  if (!confirm('Delete this form and all submissions?')) return;
  try { await api(`/api/forms/${id}`, { method: 'DELETE' }); toast('Deleted', 'success'); refresh(); } catch (e) { toast(e.message, 'error'); }
};

function esc(s) { return (s || '').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
