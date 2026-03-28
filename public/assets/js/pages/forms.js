/**
 * Form Builder page module.
 * Visual field builder with drag-to-reorder, multiple field types,
 * honeypot spam protection, email notifications, and CSV export.
 */
import { api, getBasePath } from '../core/api.js';
import { $, escapeHtml, formatDate, emptyState, confirm, tableEmpty, copyToClipboard } from '../core/utils.js';
import { toast } from '../core/toast.js';

let formFields = [];

export function init() {
  $('formBuilderForm')?.addEventListener('submit', handleCreate);
  $('loadSubmissions')?.addEventListener('click', loadSubmissions);
  $('exportSubmissions')?.addEventListener('click', exportSubmissions);
  $('addFormField')?.addEventListener('click', addField);

  // Initialize with email + name fields
  formFields = [
    { name: 'email', label: 'Email', type: 'email', required: true, placeholder: '', help_text: '', options: [] },
    { name: 'name', label: 'Full Name', type: 'text', required: true, placeholder: '', help_text: '', options: [] },
  ];
  renderFieldBuilder();
}

export async function refresh() {
  await Promise.all([loadForms(), loadListOptions(), loadSubmissionFormOptions()]);
}

// =========================================================================
// Visual Field Builder
// =========================================================================

function addField() {
  const type = $('fieldTypeSelect')?.value || 'text';
  const typeLabels = {
    text: 'Text Field', email: 'Email', tel: 'Phone', number: 'Number',
    textarea: 'Text Area', select: 'Dropdown', radio: 'Radio Buttons',
    checkbox: 'Checkboxes', date: 'Date', url: 'Website URL',
    consent: 'Consent Checkbox', heading: 'Section Heading',
    paragraph: 'Info Text', hidden: 'Hidden Field',
  };
  const label = typeLabels[type] || 'Field';
  const name = type + '_' + Date.now().toString(36);
  const needsOptions = ['select', 'radio', 'checkbox'].includes(type);

  formFields.push({
    name,
    label,
    type,
    required: false,
    placeholder: '',
    help_text: '',
    options: needsOptions ? ['Option 1', 'Option 2'] : [],
  });
  renderFieldBuilder();
}

function renderFieldBuilder() {
  const container = $('formFieldsList');
  if (!container) return;

  if (!formFields.length) {
    container.innerHTML = '<p class="text-muted text-small">No fields added yet. Use the selector above to add fields.</p>';
    syncFieldsJson();
    return;
  }

  container.innerHTML = formFields.map((f, i) => {
    const typeIcon = fieldTypeIcon(f.type);
    const needsOptions = ['select', 'radio', 'checkbox'].includes(f.type);
    const isDisplay = ['heading', 'paragraph'].includes(f.type);

    return `<div class="form-field-item" data-idx="${i}" draggable="true" style="background:var(--panel);border:1px solid var(--line);border-radius:6px;padding:.6rem .8rem">
      <div class="flex-between" style="cursor:grab">
        <div style="display:flex;align-items:center;gap:.5rem">
          <span class="text-muted" style="cursor:grab">&#9776;</span>
          <span class="text-small">${typeIcon}</span>
          <strong class="text-small">${escapeHtml(f.label)}</strong>
          <span class="badge badge-muted text-small">${f.type}</span>
          ${f.required ? '<span class="badge badge-warning text-small">required</span>' : ''}
        </div>
        <div class="btn-group">
          <button type="button" class="btn btn-sm btn-ghost" data-toggle-field="${i}" title="Edit">&#9881;</button>
          ${i > 0 ? `<button type="button" class="btn btn-sm btn-ghost" data-move-up="${i}" title="Move up">&#9650;</button>` : ''}
          ${i < formFields.length - 1 ? `<button type="button" class="btn btn-sm btn-ghost" data-move-down="${i}" title="Move down">&#9660;</button>` : ''}
          <button type="button" class="btn btn-sm btn-ghost text-danger" data-remove-field="${i}" title="Remove">&#10005;</button>
        </div>
      </div>
      <div class="field-settings hidden" id="fieldSettings${i}" style="margin-top:.5rem;padding-top:.5rem;border-top:1px solid var(--line)">
        <div class="row2" style="gap:.5rem;margin-bottom:.4rem">
          <div><label class="text-small">Label</label><input class="w-full" value="${escapeHtml(f.label)}" data-field-prop="label" data-field-idx="${i}" /></div>
          <div><label class="text-small">Field Name</label><input class="w-full" value="${escapeHtml(f.name)}" data-field-prop="name" data-field-idx="${i}" /></div>
        </div>
        ${!isDisplay ? `<div class="row2" style="gap:.5rem;margin-bottom:.4rem">
          <div><label class="text-small">Placeholder</label><input class="w-full" value="${escapeHtml(f.placeholder || '')}" data-field-prop="placeholder" data-field-idx="${i}" /></div>
          <div><label class="text-small">Help Text</label><input class="w-full" value="${escapeHtml(f.help_text || '')}" data-field-prop="help_text" data-field-idx="${i}" /></div>
        </div>
        <label class="text-small" style="display:flex;align-items:center;gap:.4rem;cursor:pointer">
          <input type="checkbox" ${f.required ? 'checked' : ''} data-field-prop="required" data-field-idx="${i}" /> Required
        </label>` : ''}
        ${needsOptions ? `<div style="margin-top:.4rem"><label class="text-small">Options (one per line)</label>
          <textarea rows="3" class="w-full" data-field-prop="options" data-field-idx="${i}" style="font-size:.85rem">${(f.options || []).join('\n')}</textarea>
        </div>` : ''}
      </div>
    </div>`;
  }).join('');

  // Wire events
  container.querySelectorAll('[data-toggle-field]').forEach(btn => {
    btn.addEventListener('click', () => {
      const panel = $('fieldSettings' + btn.dataset.toggleField);
      panel?.classList.toggle('hidden');
    });
  });

  container.querySelectorAll('[data-remove-field]').forEach(btn => {
    btn.addEventListener('click', () => {
      formFields.splice(parseInt(btn.dataset.removeField), 1);
      renderFieldBuilder();
    });
  });

  container.querySelectorAll('[data-move-up]').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = parseInt(btn.dataset.moveUp);
      [formFields[i - 1], formFields[i]] = [formFields[i], formFields[i - 1]];
      renderFieldBuilder();
    });
  });

  container.querySelectorAll('[data-move-down]').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = parseInt(btn.dataset.moveDown);
      [formFields[i], formFields[i + 1]] = [formFields[i + 1], formFields[i]];
      renderFieldBuilder();
    });
  });

  container.querySelectorAll('[data-field-prop]').forEach(input => {
    input.addEventListener('change', () => {
      const idx = parseInt(input.dataset.fieldIdx);
      const prop = input.dataset.fieldProp;
      if (prop === 'required') {
        formFields[idx].required = input.checked;
      } else if (prop === 'options') {
        formFields[idx].options = input.value.split('\n').map(s => s.trim()).filter(Boolean);
      } else {
        formFields[idx][prop] = input.value;
      }
      syncFieldsJson();
    });
  });

  // Drag-and-drop reorder
  let dragIdx = null;
  container.querySelectorAll('.form-field-item').forEach(item => {
    item.addEventListener('dragstart', (e) => {
      dragIdx = parseInt(item.dataset.idx);
      item.style.opacity = '0.5';
    });
    item.addEventListener('dragend', () => {
      item.style.opacity = '1';
      dragIdx = null;
    });
    item.addEventListener('dragover', (e) => { e.preventDefault(); });
    item.addEventListener('drop', (e) => {
      e.preventDefault();
      const dropIdx = parseInt(item.dataset.idx);
      if (dragIdx !== null && dragIdx !== dropIdx) {
        const moved = formFields.splice(dragIdx, 1)[0];
        formFields.splice(dropIdx, 0, moved);
        renderFieldBuilder();
      }
    });
  });

  syncFieldsJson();
}

function syncFieldsJson() {
  const input = $('formFieldsJson');
  if (input) input.value = JSON.stringify(formFields);
}

function fieldTypeIcon(type) {
  const icons = {
    text: '&#9998;', email: '&#9993;', tel: '&#9742;', number: '#',
    textarea: '&#9776;', select: '&#9660;', radio: '&#9673;',
    checkbox: '&#9745;', date: '&#128197;', url: '&#128279;',
    consent: '&#9989;', heading: 'H', paragraph: '&#182;', hidden: '&#128065;',
  };
  return icons[type] || '&#9998;';
}

// =========================================================================
// Form List
// =========================================================================

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
        <p class="text-muted text-small mt-1">${fieldCount} fields &middot; ${f.submissions} submissions${f.notification_email ? ' &middot; &#9993; ' + escapeHtml(f.notification_email) : ''}</p>
        <p class="text-muted text-small">${getBasePath()}/f/${escapeHtml(f.slug)}</p>
        <div class="btn-group mt-1">
          <a href="${embedUrl}" target="_blank" class="btn btn-sm btn-outline">Preview</a>
          <button class="btn btn-sm btn-outline" data-copy-embed="${f.id}">Embed Code</button>
          <button class="btn btn-sm btn-danger" data-delete-form="${f.id}">Delete</button>
        </div>
      </div>`;
    }).join('') || emptyState('&#128221;', 'No forms yet', 'Build your first form to collect leads and feedback.');

    el.addEventListener('click', handleFormListClick);
  } catch (err) {
    toast('Failed to load forms: ' + err.message, 'error');
  }
}

async function handleFormListClick(e) {
  const embedBtn = e.target.closest('[data-copy-embed]');
  if (embedBtn) {
    const id = embedBtn.dataset.copyEmbed;
    try {
      const resp = await api(`/api/forms/${id}/embed`);
      const data = resp.item || resp;
      await copyToClipboard(data.embed_code, embedBtn);
      toast('Embed code copied to clipboard', 'info');
    } catch (err) { toast(err.message, 'error'); }
    return;
  }

  const deleteBtn = e.target.closest('[data-delete-form]');
  if (deleteBtn) {
    if (!await confirm('Delete Form', 'Are you sure you want to delete this form and all its submissions? This cannot be undone.')) return;
    try {
      await api(`/api/forms/${deleteBtn.dataset.deleteForm}`, { method: 'DELETE' });
      toast('Deleted', 'success');
      refresh();
    } catch (err) { toast(err.message, 'error'); }
  }
}

// =========================================================================
// Supporting functions
// =========================================================================

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
  try {
    data.fields = JSON.parse(data.fields || '[]');
  } catch {
    toast('Invalid fields data', 'error');
    return;
  }
  try {
    await api('/api/forms', { method: 'POST', body: JSON.stringify(data) });
    toast('Form created', 'success');
    e.target.reset();
    formFields = [
      { name: 'email', label: 'Email', type: 'email', required: true, placeholder: '', help_text: '', options: [] },
      { name: 'name', label: 'Full Name', type: 'text', required: true, placeholder: '', help_text: '', options: [] },
    ];
    renderFieldBuilder();
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
      const fields = Object.entries(d).filter(([k]) => k !== '_hp').map(([k, v]) => `<strong>${escapeHtml(k)}:</strong> ${escapeHtml(String(v))}`).join(', ');
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

async function exportSubmissions() {
  const formId = $('submissionFormSelect')?.value;
  if (!formId) { toast('Select a form first', 'error'); return; }
  try {
    const response = await api(`/api/forms/${formId}/submissions/export`);
    const text = await response.text();
    const blob = new Blob([text], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `form-${formId}-submissions.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast('Submissions exported', 'success');
  } catch (err) {
    toast(err.message, 'error');
  }
}
