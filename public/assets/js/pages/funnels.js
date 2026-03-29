/**
 * Funnels / Pipeline page module.
 */
import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, emptyState, confirm } from '../core/utils.js';
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
    const items = data.items || data;
    const el = $('funnelList');
    if (!el) return;
    el.innerHTML = items.map(f => {
      const stages = f.stages || [];
      const maxVal = Math.max(...stages.map(s => Math.max(s.target_count || 0, s.actual_count || 0)), 1);
      return `<div class="card" data-funnel-id="${f.id}">
        <div class="flex-between"><h3>${escapeHtml(f.name)}</h3>${f.campaign_name ? `<span class="badge">${escapeHtml(f.campaign_name)}</span>` : ''}</div>
        ${f.description ? `<p class="text-muted text-small mt-1">${escapeHtml(f.description)}</p>` : ''}
        <div class="funnel-viz mt-1">
          ${stages.map((s) => {
            const widthPct = maxVal > 0 ? Math.max(20, Math.round((s.actual_count / maxVal) * 100)) : 20;
            const rate = s.target_count > 0 ? ((s.actual_count / s.target_count) * 100).toFixed(1) : '0.0';
            return `<div class="funnel-stage mb-1">
              <div class="flex-between text-small"><span><strong>${escapeHtml(s.name)}</strong></span><span><input type="number" class="stage-actual-input" data-stage-id="${s.id}" data-target="${s.target_count}" value="${s.actual_count}" min="0" style="width:60px;padding:1px 4px;text-align:right;border:1px solid var(--line);border-radius:4px;background:var(--input-bg);color:var(--text);font-size:inherit" title="Edit actual count" aria-label="Actual count for ${escapeHtml(s.name)}"> / ${s.target_count} (${rate}%)</span></div>
              <div class="progress" style="height:28px;margin-top:3px">
                <div class="progress-bar" style="width:${widthPct}%;background:${s.color || 'var(--accent)'};display:flex;align-items:center;justify-content:center;min-width:40px">
                  <span style="color:#fff;font-size:11px;font-weight:600">${s.actual_count}</span>
                </div>
              </div>
            </div>`;
          }).join('')}
        </div>
        <div class="btn-group mt-1">
          <button class="btn btn-sm btn-outline" data-save-stages="${f.id}">Save Stages</button>
          <button class="btn btn-sm btn-ai" data-funnel-advisor="${f.id}"><span class="btn-ai-icon">&#9733;</span> AI Advisor</button>
          <button class="btn btn-sm btn-danger" data-delete-funnel="${f.id}">Delete</button>
        </div>
      </div>`;
    }).join('') || emptyState('&#127987;', 'No funnels yet', 'Create one to visualize your marketing pipeline and track conversions.');

    // Event delegation (use onclick to prevent accumulation on refresh)
    el.onclick = handleFunnelListClick;
  } catch (err) {
    toast('Failed to load funnels: ' + err.message, 'error');
  }
}

async function handleFunnelListClick(e) {
  const saveBtn = e.target.closest('[data-save-stages]');
  if (saveBtn) {
    const funnelId = saveBtn.dataset.saveStages;
    const card = saveBtn.closest('[data-funnel-id]');
    if (!card) return;
    const inputs = card.querySelectorAll('.stage-actual-input');
    let updated = 0;
    saveBtn.classList.add('loading');
    saveBtn.disabled = true;
    for (const input of inputs) {
      const stageId = input.dataset.stageId;
      const target = parseInt(input.dataset.target, 10) || 0;
      const actual = parseInt(input.value, 10);
      if (isNaN(actual)) continue;
      const rate = target > 0 ? (actual / target) * 100 : 0;
      try {
        await api(`/api/funnels/stages/${stageId}`, { method: 'PATCH', body: JSON.stringify({ actual_count: actual, conversion_rate: rate }) });
        updated++;
      } catch (err) {
        toast(`Failed to update stage: ${err.message}`, 'error');
      }
    }
    saveBtn.classList.remove('loading');
    saveBtn.disabled = false;
    if (updated > 0) {
      toast(`${updated} stage${updated > 1 ? 's' : ''} updated`, 'success');
      refresh();
    }
    return;
  }

  const advisorBtn = e.target.closest('[data-funnel-advisor]');
  if (advisorBtn) {
    const id = advisorBtn.dataset.funnelAdvisor;
    const card = document.getElementById('funnelAdvisorCard');
    const output = document.getElementById('funnelAdvisorOutput');
    if (card) card.classList.remove('hidden');
    if (output) output.textContent = 'Analyzing funnel... please wait.';
    advisorBtn.classList.add('loading');
    advisorBtn.disabled = true;
    try {
      const { item } = await api('/api/ai/funnel-advisor', { method: 'POST', body: JSON.stringify({ funnel_id: id }) });
      if (output) output.textContent = item?.advice || 'No advice available';
      if (card) card.scrollIntoView({ behavior: 'smooth' });
    } catch (err) {
      if (output) output.textContent = 'Error: ' + err.message;
    } finally {
      advisorBtn.classList.remove('loading');
      advisorBtn.disabled = false;
    }
    return;
  }

  const deleteBtn = e.target.closest('[data-delete-funnel]');
  if (deleteBtn) {
    if (!await confirm('Delete Funnel', 'Are you sure you want to delete this funnel and all its stages? This cannot be undone.')) return;
    try {
      await api(`/api/funnels/${deleteBtn.dataset.deleteFunnel}`, { method: 'DELETE' });
      toast('Deleted', 'success');
      refresh();
    } catch (err) { toast(err.message, 'error'); }
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
