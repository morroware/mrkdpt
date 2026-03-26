/**
 * Automations page module.
 */
import { api } from '../core/api.js';
import { $, escapeHtml, formatDate } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('automationForm')?.addEventListener('submit', handleCreate);
}

export async function refresh() {
  await loadAutomations();
}

async function loadAutomations() {
  try {
    const data = await api('/api/automations');
    const tb = $('automationTable');
    if (!tb) return;
    tb.innerHTML = data.map(a => `<tr>
      <td><strong>${escapeHtml(a.name)}</strong></td>
      <td><span class="badge badge-info">${escapeHtml(a.trigger_event)}</span></td>
      <td><span class="badge">${escapeHtml(a.action_type)}</span></td>
      <td>${a.run_count}</td>
      <td class="text-muted text-small">${a.last_run ? formatDate(a.last_run) : 'Never'}</td>
      <td>
        <button class="btn btn-sm ${a.is_active ? 'btn-success' : 'btn-outline'}" onclick="window._toggleAutomation(${a.id}, ${a.is_active ? 0 : 1})">
          ${a.is_active ? 'Active' : 'Paused'}
        </button>
      </td>
      <td><button class="btn btn-sm btn-danger" onclick="window._deleteAutomation(${a.id})">Del</button></td>
    </tr>`).join('') || '<tr><td colspan="7" class="text-muted">No automation rules yet</td></tr>';
  } catch (err) {
    toast('Failed to load automations: ' + err.message, 'error');
  }
}

async function handleCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());

  // Parse JSON fields
  try {
    data.conditions = data.conditions ? JSON.parse(data.conditions) : {};
  } catch { data.conditions = {}; }
  try {
    data.action_config = data.action_config ? JSON.parse(data.action_config) : {};
  } catch {
    toast('Invalid action config JSON', 'error');
    return;
  }

  try {
    await api('/api/automations', { method: 'POST', body: JSON.stringify(data) });
    toast('Automation created', 'success');
    e.target.reset();
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

window._toggleAutomation = async (id, active) => {
  try {
    await api(`/api/automations/${id}`, { method: 'PATCH', body: JSON.stringify({ is_active: active }) });
    toast(active ? 'Activated' : 'Paused', 'success');
    refresh();
  } catch (err) { toast(err.message, 'error'); }
};

window._deleteAutomation = async (id) => {
  if (!confirm('Delete this automation?')) return;
  try { await api(`/api/automations/${id}`, { method: 'DELETE' }); toast('Deleted', 'success'); refresh(); } catch (e) { toast(e.message, 'error'); }
};

