/**
 * Contacts / CRM page module.
 * Enhanced with deals/pipeline, tasks/follow-ups, notes, and tabbed contact detail.
 */
import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, tableEmpty, emptyState, debounce, confirm } from '../core/utils.js';
import { toast } from '../core/toast.js';

let currentContactId = null;

export function init() {
  const form = $('contactForm');
  if (form) form.addEventListener('submit', handleCreate);

  const showBtn = $('showContactForm');
  if (showBtn) showBtn.addEventListener('click', () => {
    $('contactFormWrap')?.classList.toggle('hidden');
  });

  const closeModal = $('closeContactModal');
  if (closeModal) closeModal.addEventListener('click', () => {
    $('contactModal')?.classList.remove('open');
    currentContactId = null;
  });

  $('contactStageFilter')?.addEventListener('change', refresh);
  $('contactSearch')?.addEventListener('input', debounce(refresh, 300));
  $('contactToolbar')?.addEventListener('click', handleToolbarAction);

  $('contactTable')?.addEventListener('click', async (event) => {
    const actionBtn = event.target.closest('[data-contact-action]');
    if (!actionBtn) return;

    const id = Number.parseInt(actionBtn.dataset.contactId || '', 10);
    if (!id) return;

    if (actionBtn.dataset.contactAction === 'view') {
      await viewContact(id);
      return;
    }

    if (actionBtn.dataset.contactAction === 'delete') {
      await deleteContact(id);
    }
  });

  // Tab switching within modal
  $('contactModalBody')?.addEventListener('click', (e) => {
    const tabBtn = e.target.closest('.tab-btn[data-tab]');
    if (!tabBtn) return;
    const modal = $('contactModalBody');
    modal.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    modal.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    tabBtn.classList.add('active');
    $(tabBtn.dataset.tab)?.classList.add('active');
  });
}

export async function refresh() {
  await Promise.all([loadMetrics(), loadContacts()]);
}

async function loadMetrics() {
  try {
    const m = await api('/api/contacts/metrics');
    const el = $('contactMetrics');
    if (!el) return;
    el.innerHTML = [
      metric('Total', m.total || 0),
      metric('Leads', m.leads || 0),
      metric('MQLs', m.mqls || 0),
      metric('Opportunities', m.opportunities || 0),
      metric('Customers', m.customers || 0),
      metric('Pipeline', '$' + formatCurrency(m.pipeline_value || 0)),
      metric('Won', '$' + formatCurrency(m.won_value || 0)),
      metric('Overdue Tasks', m.overdue_tasks || 0),
    ].join('');
  } catch (err) {
    toast('Failed to load contact metrics: ' + err.message, 'error');
  }
}

async function loadContacts() {
  try {
    const stage = $('contactStageFilter')?.value || '';
    const search = $('contactSearch')?.value || '';
    let url = '/api/contacts?';
    if (stage) url += `stage=${encodeURIComponent(stage)}&`;
    if (search) url += `search=${encodeURIComponent(search)}&`;
    const data = await api(url);
    const items = data.items || data;
    const tb = $('contactTable');
    if (!tb) return;
    if (!items.length) {
      tb.innerHTML = tableEmpty(8, 'No contacts yet. Add your first contact or import from CSV.');
      return;
    }
    tb.innerHTML = items.map(c => `<tr>
      <td>${escapeHtml(c.email)}</td>
      <td>${escapeHtml(c.first_name)} ${escapeHtml(c.last_name)}</td>
      <td>${escapeHtml(c.company)}</td>
      <td><span class="badge badge-${stageBadge(c.stage)}">${escapeHtml(c.stage)}</span></td>
      <td>${c.score}</td>
      <td>${escapeHtml(c.source)}</td>
      <td class="text-muted text-small">${c.last_activity ? formatDate(c.last_activity) : '-'}</td>
      <td>
        <button class="btn btn-sm btn-outline" data-contact-action="view" data-contact-id="${c.id}">View</button>
        <button class="btn btn-sm btn-danger" data-contact-action="delete" data-contact-id="${c.id}">Del</button>
      </td>
    </tr>`).join('');
  } catch (err) {
    toast('Failed to load contacts: ' + err.message, 'error');
  }
}

// =========================================================================
// Contact Detail (Tabbed Modal)
// =========================================================================

async function viewContact(id) {
  try {
    const resp = await api(`/api/contacts/${id}`);
    const c = resp.item || resp;
    currentContactId = c.id;

    const modalTitle = $('contactModalTitle');
    if (modalTitle) modalTitle.textContent = `${c.first_name || ''} ${c.last_name || ''} - ${c.email}`;

    // Header
    const header = $('contactDetailHeader');
    if (header) {
      header.innerHTML = `
        <div class="row3 mb-1">
          <div><strong>Stage:</strong> <span class="badge badge-${stageBadge(c.stage)}">${escapeHtml(c.stage)}</span></div>
          <div><strong>Score:</strong> ${c.score}</div>
          <div><strong>Source:</strong> ${escapeHtml(c.source)}${c.source_detail ? ' (' + escapeHtml(c.source_detail) + ')' : ''}</div>
        </div>
        ${c.tags ? `<div class="mb-1">${c.tags.split(',').map(t => `<span class="badge badge-muted">${escapeHtml(t.trim())}</span>`).join(' ')}</div>` : ''}
      `;
    }

    // Info tab
    const customFields = (() => { try { return JSON.parse(c.custom_fields || '{}'); } catch { return {}; } })();
    const customHtml = Object.entries(customFields).filter(([, v]) => v).map(([k, v]) =>
      `<div><strong>${escapeHtml(k)}:</strong> ${escapeHtml(String(v))}</div>`
    ).join('');

    $('cm-info').innerHTML = `
      <div class="row2 mb-1"><div><strong>Company:</strong> ${escapeHtml(c.company)}</div><div><strong>Phone:</strong> ${escapeHtml(c.phone)}</div></div>
      <div class="row2 mb-1"><div><strong>Email:</strong> ${escapeHtml(c.email)}</div><div><strong>Created:</strong> ${formatDate(c.created_at)}</div></div>
      ${c.notes ? `<div class="mb-1"><strong>Notes:</strong> ${escapeHtml(c.notes)}</div>` : ''}
      ${customHtml ? `<div class="mb-1"><h4 class="text-small text-muted">Custom Fields</h4>${customHtml}</div>` : ''}
      <h4 class="mt-1 mb-1">Edit Custom Fields</h4>
      <div class="row2" style="gap:.5rem">
        <input id="cfKey" placeholder="Field name" class="w-full" />
        <input id="cfValue" placeholder="Value" class="w-full" />
        <button class="btn btn-sm" id="saveCustomField">Save</button>
      </div>
    `;
    $('saveCustomField')?.addEventListener('click', async () => {
      const key = $('cfKey')?.value?.trim();
      const val = $('cfValue')?.value?.trim();
      if (!key) return;
      customFields[key] = val;
      try {
        await api(`/api/contacts/${c.id}`, { method: 'PATCH', body: JSON.stringify({ custom_fields: customFields }) });
        toast('Custom field saved', 'success');
        viewContact(c.id);
      } catch (err) { toast(err.message, 'error'); }
    });

    // Activity tab
    const cmActivity = $('cm-activity');
    if (cmActivity) cmActivity.innerHTML = (c.activities || []).length
      ? (c.activities || []).map(a => `<div class="list-item" style="padding:.4rem 0;border-bottom:1px solid var(--line)">
          <span class="badge badge-info text-small">${escapeHtml(a.activity_type)}</span>
          ${escapeHtml(a.description)}
          <span class="text-muted text-small">${formatDate(a.created_at)}</span>
        </div>`).join('')
      : '<p class="text-muted">No activity yet</p>';

    // Deals tab
    renderDealsTab(c);

    // Tasks tab
    renderTasksTab(c);

    // Notes tab
    renderNotesTab(c);

    // Reset to Info tab
    const body = $('contactModalBody');
    body.querySelectorAll('.tab-btn').forEach((b, i) => b.classList.toggle('active', i === 0));
    body.querySelectorAll('.tab-panel').forEach((p, i) => p.classList.toggle('active', i === 0));

    $('contactModal')?.classList.add('open');
  } catch (err) {
    toast(err.message, 'error');
  }
}

function renderDealsTab(contact) {
  const deals = contact.deals || [];
  const el = $('cm-deals');
  if (!el) return;

  const dealStages = ['lead', 'qualified', 'proposal', 'negotiation', 'closed'];

  el.innerHTML = `
    <div class="card mb-1" style="padding:.8rem;background:var(--input-bg)">
      <h4 class="text-small mb-1">Add Deal</h4>
      <div class="row2" style="gap:.4rem;margin-bottom:.4rem">
        <input id="dealTitle" placeholder="Deal title" class="w-full" />
        <input id="dealValue" type="number" placeholder="Value" min="0" step="0.01" class="w-full" />
      </div>
      <div class="row3" style="gap:.4rem">
        <select id="dealStage" class="w-full">${dealStages.map(s => `<option value="${s}">${s}</option>`).join('')}</select>
        <input id="dealClose" type="date" class="w-full" />
        <button class="btn btn-sm" id="createDeal">Add</button>
      </div>
    </div>
    ${deals.length ? deals.map(d => `<div class="list-item" style="padding:.6rem 0;border-bottom:1px solid var(--line)">
      <div class="flex-between">
        <div>
          <strong>${escapeHtml(d.title)}</strong>
          <span class="badge badge-${d.status === 'won' ? 'success' : d.status === 'lost' ? 'danger' : 'info'}">${escapeHtml(d.status)}</span>
          <span class="badge badge-muted">${escapeHtml(d.stage)}</span>
        </div>
        <div><strong>$${formatCurrency(d.value)}</strong></div>
      </div>
      <div class="text-small text-muted mt-1">
        ${d.expected_close ? 'Close: ' + d.expected_close : ''}
        ${d.probability ? ' &middot; ' + d.probability + '% prob.' : ''}
      </div>
      ${d.status === 'open' ? `<div class="btn-group mt-1">
        <button class="btn btn-sm btn-success" data-deal-win="${d.id}">Won</button>
        <button class="btn btn-sm btn-danger" data-deal-lose="${d.id}">Lost</button>
      </div>` : ''}
    </div>`).join('') : '<p class="text-muted">No deals yet</p>'}
  `;

  el.querySelector('#createDeal')?.addEventListener('click', async () => {
    const title = $('dealTitle')?.value?.trim();
    if (!title) { toast('Deal title required', 'error'); return; }
    try {
      await api('/api/deals', {
        method: 'POST',
        body: JSON.stringify({
          contact_id: contact.id,
          title,
          value: parseFloat($('dealValue')?.value || '0'),
          stage: $('dealStage')?.value || 'lead',
          expected_close: $('dealClose')?.value || '',
        }),
      });
      toast('Deal created', 'success');
      viewContact(contact.id);
      refresh();
    } catch (err) { toast(err.message, 'error'); }
  });

  el.querySelectorAll('[data-deal-win]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await api(`/api/deals/${btn.dataset.dealWin}`, { method: 'PATCH', body: JSON.stringify({ status: 'won' }) });
        toast('Deal marked as won!', 'success');
        viewContact(contact.id);
        refresh();
      } catch (err) { toast(err.message, 'error'); }
    });
  });

  el.querySelectorAll('[data-deal-lose]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await api(`/api/deals/${btn.dataset.dealLose}`, { method: 'PATCH', body: JSON.stringify({ status: 'lost' }) });
        toast('Deal marked as lost', 'info');
        viewContact(contact.id);
        refresh();
      } catch (err) { toast(err.message, 'error'); }
    });
  });
}

function renderTasksTab(contact) {
  const tasks = contact.tasks || [];
  const el = $('cm-tasks');
  if (!el) return;

  el.innerHTML = `
    <div class="card mb-1" style="padding:.8rem;background:var(--input-bg)">
      <h4 class="text-small mb-1">Add Task</h4>
      <div class="row2" style="gap:.4rem;margin-bottom:.4rem">
        <input id="taskTitle" placeholder="Task / Follow-up" class="w-full" />
        <input id="taskDue" type="date" class="w-full" />
      </div>
      <div class="row2" style="gap:.4rem">
        <select id="taskPriority" class="w-full"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select>
        <button class="btn btn-sm" id="createTask">Add</button>
      </div>
    </div>
    ${tasks.length ? tasks.map(t => {
      const overdue = t.status === 'pending' && t.due_date && t.due_date < new Date().toISOString().slice(0, 10);
      const priBadge = t.priority === 'high' ? 'danger' : t.priority === 'low' ? 'muted' : 'warning';
      return `<div class="list-item" style="padding:.5rem 0;border-bottom:1px solid var(--line);${t.status === 'completed' ? 'opacity:.6' : ''}">
        <div class="flex-between">
          <div style="display:flex;align-items:center;gap:.5rem">
            ${t.status === 'pending' ? `<button class="btn btn-sm btn-ghost" data-complete-task="${t.id}" title="Mark complete">&#9744;</button>` : '<span>&#9745;</span>'}
            <span ${t.status === 'completed' ? 'style="text-decoration:line-through"' : ''}>${escapeHtml(t.title)}</span>
            <span class="badge badge-${priBadge} text-small">${t.priority}</span>
            ${overdue ? '<span class="badge badge-danger text-small">overdue</span>' : ''}
          </div>
          <div class="text-small text-muted">${t.due_date || 'No date'}</div>
        </div>
      </div>`;
    }).join('') : '<p class="text-muted">No tasks yet</p>'}
  `;

  el.querySelector('#createTask')?.addEventListener('click', async () => {
    const title = $('taskTitle')?.value?.trim();
    if (!title) { toast('Task title required', 'error'); return; }
    try {
      await api('/api/tasks', {
        method: 'POST',
        body: JSON.stringify({
          contact_id: contact.id,
          title,
          due_date: $('taskDue')?.value || '',
          priority: $('taskPriority')?.value || 'medium',
        }),
      });
      toast('Task added', 'success');
      viewContact(contact.id);
    } catch (err) { toast(err.message, 'error'); }
  });

  el.querySelectorAll('[data-complete-task]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await api(`/api/tasks/${btn.dataset.completeTask}`, { method: 'PATCH', body: JSON.stringify({ status: 'completed' }) });
        toast('Task completed', 'success');
        viewContact(contact.id);
      } catch (err) { toast(err.message, 'error'); }
    });
  });
}

function renderNotesTab(contact) {
  const notes = contact.contact_notes || [];
  const el = $('cm-notes');
  if (!el) return;

  el.innerHTML = `
    <div class="card mb-1" style="padding:.8rem;background:var(--input-bg)">
      <textarea id="newNote" rows="3" placeholder="Add a note..." class="w-full" style="margin-bottom:.4rem"></textarea>
      <button class="btn btn-sm" id="saveNote">Save Note</button>
    </div>
    ${notes.length ? notes.map(n => `<div class="list-item" style="padding:.6rem 0;border-bottom:1px solid var(--line)">
      <div class="flex-between">
        <div style="white-space:pre-wrap">${escapeHtml(n.content)}</div>
        <button class="btn btn-sm btn-ghost text-danger" data-delete-note="${n.id}" title="Delete">&#10005;</button>
      </div>
      <div class="text-small text-muted mt-1">${formatDate(n.created_at)}</div>
    </div>`).join('') : '<p class="text-muted">No notes yet</p>'}
  `;

  el.querySelector('#saveNote')?.addEventListener('click', async () => {
    const content = $('newNote')?.value?.trim();
    if (!content) return;
    try {
      await api(`/api/contacts/${contact.id}/notes`, {
        method: 'POST',
        body: JSON.stringify({ content }),
      });
      toast('Note saved', 'success');
      viewContact(contact.id);
    } catch (err) { toast(err.message, 'error'); }
  });

  el.querySelectorAll('[data-delete-note]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await api(`/api/notes/${btn.dataset.deleteNote}`, { method: 'DELETE' });
        toast('Note deleted', 'success');
        viewContact(contact.id);
      } catch (err) { toast(err.message, 'error'); }
    });
  });
}

// =========================================================================
// CRUD & Helpers
// =========================================================================

async function handleCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  try {
    await api('/api/contacts', { method: 'POST', body: JSON.stringify(data) });
    toast('Contact saved', 'success');
    e.target.reset();
    $('contactFormWrap')?.classList.add('hidden');
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

async function deleteContact(id) {
  if (!await confirm('Delete Contact', 'Are you sure you want to delete this contact and all associated deals, tasks, and notes?')) return;
  try {
    await api(`/api/contacts/${id}`, { method: 'DELETE' });
    toast('Contact deleted', 'success');
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

function metric(label, value) {
  return `<div class="metric-card"><div class="metric-value">${value}</div><div class="metric-label">${label}</div></div>`;
}

function stageBadge(stage) {
  const map = { lead: 'muted', mql: 'info', sql: 'warning', opportunity: 'success', customer: 'success' };
  return map[stage] || 'muted';
}

function formatCurrency(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

async function handleToolbarAction(event) {
  const button = event.target.closest('[data-contact-action]');
  if (!button) return;

  if (button.dataset.contactAction === 'import-csv') {
    await importContactsCsv();
  }

  if (button.dataset.contactAction === 'export-csv') {
    await exportContactsCsv();
  }
}

// CSV Import handler
async function importContactsCsv() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.csv';
  input.style.display = 'none';
  document.body.appendChild(input);

  const file = await new Promise((resolve) => {
    input.addEventListener('change', () => resolve(input.files[0] || null));
    input.click();
  });
  input.remove();

  if (!file) return;
  try {
    const csv = await file.text();
    const result = await api('/api/contacts/import', {
      method: 'POST',
      body: JSON.stringify({ csv, source: 'csv_import' }),
    });
    toast(`Imported: ${result.imported}, Skipped: ${result.skipped}`, 'success');
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

// CSV Export handler
async function exportContactsCsv() {
  try {
    const response = await api('/api/contacts/export');
    const text = await response.text();
    const blob = new Blob([text], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'contacts.csv';
    a.click();
    URL.revokeObjectURL(url);
    toast('Contacts exported', 'success');
  } catch (err) {
    toast(err.message, 'error');
  }
}
