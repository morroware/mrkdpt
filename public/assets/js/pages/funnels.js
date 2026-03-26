/**
 * Funnels / Pipeline page module.
 */
import { api } from '../core/api.js';
import { $, escapeHtml, formatDate } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('funnelForm')?.addEventListener('submit', handleCreate);
}

export async function refresh() {
  await Promise.all([loadFunnels(), loadCampaignOptions()]);
}

async function loadCampaignOptions() {
  try {
    const resp = await api('/api/campaigns');
    const camps = resp.items || resp;
    const sel = $('funnelCampaignSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">None</option>' + camps.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
  } catch (err) {
    toast('Failed to load campaigns: ' + err.message, 'error');
  }
}

async function loadFunnels() {
  try {
    const data = await api('/api/funnels');
    const el = $('funnelList');
    if (!el) return;
    el.innerHTML = data.map(f => {
      const stages = f.stages || [];
      const maxVal = Math.max(...stages.map(s => Math.max(s.target_count, s.actual_count)), 1);
      return `<div class="card">
        <div class="flex-between"><h3>${escapeHtml(f.name)}</h3>${f.campaign_name ? `<span class="badge">${escapeHtml(f.campaign_name)}</span>` : ''}</div>
        ${f.description ? `<p class="text-muted text-small mt-1">${escapeHtml(f.description)}</p>` : ''}
        <div class="funnel-viz mt-1">
          ${stages.map((s, i) => {
            const widthPct = maxVal > 0 ? Math.max(20, Math.round((s.actual_count / maxVal) * 100)) : 100 - i * 15;
            const rate = s.target_count > 0 ? ((s.actual_count / s.target_count) * 100).toFixed(1) : '0.0';
            return `<div class="funnel-stage" style="margin-bottom:4px">
              <div class="flex-between text-small"><span><strong>${escapeHtml(s.name)}</strong></span><span>${s.actual_count} / ${s.target_count} (${rate}%)</span></div>
              <div style="background:var(--bg-tertiary);border-radius:6px;height:28px;margin-top:3px;overflow:hidden;position:relative">
                <div style="background:${s.color || 'var(--accent)'};height:100%;border-radius:6px;width:${widthPct}%;transition:width .4s;display:flex;align-items:center;justify-content:center;min-width:40px">
                  <span style="color:#fff;font-size:11px;font-weight:600">${s.actual_count}</span>
                </div>
              </div>
            </div>`;
          }).join('')}
        </div>
        <div class="btn-group mt-1">
          <button class="btn btn-sm btn-outline" onclick="window._editFunnelStages(${f.id})">Edit Stages</button>
          <button class="btn btn-sm btn-ai" onclick="window._aiFunnelAdvisor(${f.id})"><span class="btn-ai-icon">&#9733;</span> AI Advisor</button>
          <button class="btn btn-sm btn-danger" onclick="window._deleteFunnel(${f.id})">Delete</button>
        </div>
      </div>`;
    }).join('') || '<p class="text-muted">No funnels yet. Create one to visualize your marketing pipeline.</p>';
  } catch (err) {
    toast('Failed to load funnels: ' + err.message, 'error');
  }
}

async function handleCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = {
    name: fd.get('name'),
    campaign_id: fd.get('campaign_id') || null,
    description: fd.get('description') || '',
    stages: [],
  };

  for (let i = 1; i <= 4; i++) {
    const name = fd.get(`stage_${i}_name`);
    if (name) {
      data.stages.push({
        name,
        target_count: parseInt(fd.get(`stage_${i}_target`) || '0', 10),
        actual_count: parseInt(fd.get(`stage_${i}_actual`) || '0', 10),
        color: fd.get(`stage_${i}_color`) || '#4c8dff',
      });
    }
  }

  try {
    await api('/api/funnels', { method: 'POST', body: JSON.stringify(data) });
    toast('Funnel created', 'success');
    e.target.reset();
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

window._editFunnelStages = async (id) => {
  try {
    const funnel = await api(`/api/funnels/${id}`);
    const stages = funnel.stages || [];
    for (const stage of stages) {
      const newActual = prompt(`${stage.name} - current: ${stage.actual_count}, new actual count:`, String(stage.actual_count));
      if (newActual !== null) {
        const actual = parseInt(newActual, 10);
        const rate = stage.target_count > 0 ? (actual / stage.target_count) * 100 : 0;
        await api(`/api/funnels/stages/${stage.id}`, { method: 'PATCH', body: JSON.stringify({ actual_count: actual, conversion_rate: rate }) });
      }
    }
    toast('Stages updated', 'success');
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
};

window._deleteFunnel = async (id) => {
  if (!confirm('Delete this funnel?')) return;
  try { await api(`/api/funnels/${id}`, { method: 'DELETE' }); toast('Deleted', 'success'); refresh(); } catch (e) { toast(e.message, 'error'); }
};

window._aiFunnelAdvisor = async (id) => {
  const card = document.getElementById('funnelAdvisorCard');
  const output = document.getElementById('funnelAdvisorOutput');
  if (card) card.classList.remove('hidden');
  if (output) output.textContent = 'Analyzing funnel... please wait.';
  try {
    const { item } = await api('/api/ai/funnel-advisor', { method: 'POST', body: JSON.stringify({ funnel_id: id }) });
    if (output) output.textContent = item?.advice || 'No advice available';
    if (card) card.scrollIntoView({ behavior: 'smooth' });
  } catch (e) {
    if (output) output.textContent = 'Error: ' + e.message;
  }
};

