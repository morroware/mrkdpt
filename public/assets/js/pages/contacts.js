/**
 * Contacts / Mini CRM page module.
 */
import { api } from '../core/api.js';
import { $, formatDate } from '../core/utils.js';
import { toast } from '../core/toast.js';

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
  });

  $('contactStageFilter')?.addEventListener('change', refresh);
  $('contactSearch')?.addEventListener('input', debounce(refresh, 300));
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
      metric('SQLs', m.sqls || 0),
      metric('Opportunities', m.opportunities || 0),
      metric('Customers', m.customers || 0),
      metric('Avg Score', Math.round(m.avg_score || 0)),
    ].join('');
  } catch {}
}

async function loadContacts() {
  try {
    const stage = $('contactStageFilter')?.value || '';
    const search = $('contactSearch')?.value || '';
    let url = '/api/contacts?';
    if (stage) url += `stage=${encodeURIComponent(stage)}&`;
    if (search) url += `search=${encodeURIComponent(search)}&`;
    const data = await api(url);
    const tb = $('contactTable');
    if (!tb) return;
    tb.innerHTML = data.map(c => `<tr>
      <td>${esc(c.email)}</td>
      <td>${esc(c.first_name)} ${esc(c.last_name)}</td>
      <td>${esc(c.company)}</td>
      <td><span class="badge badge-${stageBadge(c.stage)}">${esc(c.stage)}</span></td>
      <td>${c.score}</td>
      <td>${esc(c.source)}</td>
      <td class="text-muted text-small">${c.last_activity ? formatDate(c.last_activity) : '-'}</td>
      <td><button class="btn btn-sm btn-outline" onclick="window._viewContact(${c.id})">View</button> <button class="btn btn-sm btn-danger" onclick="window._deleteContact(${c.id})">Del</button></td>
    </tr>`).join('');
  } catch {}
}

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

window._viewContact = async (id) => {
  try {
    const c = await api(`/api/contacts/${id}`);
    $('contactModalTitle').textContent = `${c.first_name || ''} ${c.last_name || ''} - ${c.email}`;
    const body = $('contactModalBody');
    body.innerHTML = `
      <div class="row2 mb-2"><div><strong>Company:</strong> ${esc(c.company)}</div><div><strong>Phone:</strong> ${esc(c.phone)}</div></div>
      <div class="row3 mb-2"><div><strong>Stage:</strong> <span class="badge badge-${stageBadge(c.stage)}">${esc(c.stage)}</span></div><div><strong>Score:</strong> ${c.score}</div><div><strong>Source:</strong> ${esc(c.source)}</div></div>
      <div class="mb-2"><strong>Tags:</strong> ${esc(c.tags)}</div>
      <div class="mb-2"><strong>Notes:</strong> ${esc(c.notes)}</div>
      <h4>Activity Log</h4>
      <div class="mt-1">${(c.activities || []).map(a => `<div class="list-item"><span class="badge badge-info">${esc(a.activity_type)}</span> ${esc(a.description)} <span class="text-muted text-small">${formatDate(a.created_at)}</span></div>`).join('') || '<p class="text-muted">No activity yet</p>'}</div>
    `;
    $('contactModal')?.classList.add('open');
  } catch (err) {
    toast(err.message, 'error');
  }
};

window._deleteContact = async (id) => {
  if (!confirm('Delete this contact?')) return;
  try {
    await api(`/api/contacts/${id}`, { method: 'DELETE' });
    toast('Contact deleted', 'success');
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
};

function metric(label, value) {
  return `<div class="metric-card"><div class="metric-value">${value}</div><div class="metric-label">${label}</div></div>`;
}

function stageBadge(stage) {
  const map = { lead: 'muted', mql: 'info', sql: 'warning', opportunity: 'success', customer: 'success' };
  return map[stage] || 'muted';
}

function esc(s) { return (s || '').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

// CSV Import handler
window._importContactsCsv = async () => {
  const csv = prompt('Paste CSV data (email,first_name,last_name,company,phone,stage,tags):');
  if (!csv) return;
  try {
    const result = await api('/api/contacts/import', {
      method: 'POST',
      body: JSON.stringify({ csv, source: 'csv_import' }),
    });
    toast(`Imported: ${result.imported}, Skipped: ${result.skipped}`, 'success');
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
};

// CSV Export handler
window._exportContactsCsv = async () => {
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
};
