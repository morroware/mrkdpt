/**
 * Campaigns page — CRUD with ROI metrics and performance tracking.
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
      ? items.map((c) => {
          const spend = parseFloat(c.spend_to_date || 0);
          const revenue = parseFloat(c.revenue || 0);
          const budget = parseFloat(c.budget || 0);
          const roi = spend > 0 ? ((revenue - spend) / spend * 100).toFixed(1) : '-';
          const budgetUsed = budget > 0 ? Math.min(100, (spend / budget * 100)).toFixed(0) : 0;

          return `<div class="card">
          <div class="flex-between">
            <h4>${escapeHtml(c.name)}</h4>
            <div>
              <button class="btn btn-sm btn-outline" data-metrics="${c.id}">Metrics</button>
              <button class="btn btn-sm btn-danger" data-delete="${c.id}">Delete</button>
            </div>
          </div>
          <p><strong>${escapeHtml(c.channel)}</strong> &mdash; ${escapeHtml(c.objective)}</p>
          <p class="text-small text-muted">Budget: $${budget.toFixed(2)} | Spent: $${spend.toFixed(2)} | Revenue: $${revenue.toFixed(2)} | ROI: ${roi}%</p>
          ${budget > 0 ? `<div style="background:var(--input-bg);border-radius:4px;height:6px;margin:8px 0"><div style="background:${budgetUsed > 90 ? 'var(--danger)' : 'var(--accent)'};height:100%;border-radius:4px;width:${budgetUsed}%"></div></div>` : ''}
          <p class="text-small text-muted">${formatDate(c.start_date)}${c.end_date ? ' &rarr; ' + formatDate(c.end_date) : ''}</p>
          ${c.notes ? `<p class="text-small">${escapeHtml(c.notes)}</p>` : ''}
          <div class="campaign-metrics-inline" id="cm-${c.id}"></div>
        </div>`;
        }).join('')
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

    list.querySelectorAll('[data-metrics]').forEach((btn) => {
      btn.addEventListener('click', () => toggleMetrics(parseInt(btn.dataset.metrics)));
    });
  } catch (err) {
    error('Failed to load campaigns: ' + err.message);
  }
}

async function toggleMetrics(campaignId) {
  const container = $(`cm-${campaignId}`);
  if (!container) return;

  // Toggle visibility
  if (container.innerHTML) {
    container.innerHTML = '';
    return;
  }

  try {
    const [summary, { items: daily }] = await Promise.all([
      api(`/api/campaigns/${campaignId}/summary`),
      api(`/api/campaigns/${campaignId}/metrics`),
    ]);

    container.innerHTML = `
      <div class="metrics-row mt-1" style="font-size:0.8rem">
        <div class="metric-card"><span class="metric-value">${summary.total_impressions || 0}</span><span class="metric-label">Impressions</span></div>
        <div class="metric-card"><span class="metric-value">${summary.total_clicks || 0}</span><span class="metric-label">Clicks</span></div>
        <div class="metric-card"><span class="metric-value">${summary.total_conversions || 0}</span><span class="metric-label">Conversions</span></div>
        <div class="metric-card"><span class="metric-value">${summary.ctr_percent || 0}%</span><span class="metric-label">CTR</span></div>
        <div class="metric-card"><span class="metric-value">${summary.roas || 0}x</span><span class="metric-label">ROAS</span></div>
        <div class="metric-card"><span class="metric-value">$${summary.cost_per_acquisition || 0}</span><span class="metric-label">CPA</span></div>
      </div>
      <form class="stack mt-1" data-add-metric="${campaignId}">
        <div class="row3" style="font-size:0.85rem">
          <div><label>Date</label><input name="metric_date" type="date" value="${new Date().toISOString().slice(0,10)}" /></div>
          <div><label>Spend</label><input name="spend" type="number" step="0.01" placeholder="0.00" /></div>
          <div><label>Revenue</label><input name="revenue" type="number" step="0.01" placeholder="0.00" /></div>
        </div>
        <div class="row3" style="font-size:0.85rem">
          <div><label>Impressions</label><input name="impressions" type="number" placeholder="0" /></div>
          <div><label>Clicks</label><input name="clicks" type="number" placeholder="0" /></div>
          <div><label>Conversions</label><input name="conversions" type="number" placeholder="0" /></div>
        </div>
        <button type="submit" class="btn btn-sm">Add Metric</button>
      </form>
      ${daily.length ? `<div class="table-wrap mt-1"><table class="data-table" style="font-size:0.8rem"><thead><tr><th>Date</th><th>Spend</th><th>Revenue</th><th>Impr.</th><th>Clicks</th><th>Conv.</th></tr></thead><tbody>${
        daily.map((d) => `<tr><td>${d.metric_date}</td><td>$${parseFloat(d.spend).toFixed(2)}</td><td>$${parseFloat(d.revenue).toFixed(2)}</td><td>${d.impressions}</td><td>${d.clicks}</td><td>${d.conversions}</td></tr>`).join('')
      }</tbody></table></div>` : ''}`;

    container.querySelector(`[data-add-metric="${campaignId}"]`)?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = Object.fromEntries(new FormData(e.target).entries());
      try {
        await api(`/api/campaigns/${campaignId}/metrics`, {
          method: 'POST',
          body: JSON.stringify(fd),
        });
        success('Metric added');
        container.innerHTML = '';
        toggleMetrics(campaignId);
        refresh();
      } catch (err) { error(err.message); }
    });
  } catch (err) {
    error(err.message);
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

  // AI campaign strategy
  const stratBtn = document.getElementById('aiCampaignStrategy');
  if (stratBtn) {
    stratBtn.addEventListener('click', async () => {
      const form = document.getElementById('campaignForm');
      if (!form) return;
      const objective = form.querySelector('[name="objective"]')?.value || '';
      const channel = form.querySelector('[name="channel"]')?.value || '';
      const name = form.querySelector('[name="name"]')?.value || '';
      stratBtn.classList.add('loading');
      stratBtn.disabled = true;
      try {
        const { item } = await api('/api/ai/social-strategy', {
          method: 'POST',
          body: JSON.stringify({
            goals: objective || 'increase brand awareness and drive leads',
            current_state: `Campaign: ${name}. Channel: ${channel}. Planning phase.`,
          }),
        });
        if (item?.strategy) {
          const notes = form.querySelector('[name="notes"]');
          if (notes) {
            notes.value = item.strategy.slice(0, 2000);
            success('AI strategy generated and added to notes');
          }
        }
      } catch (err) { error(err.message); }
      finally { stratBtn.classList.remove('loading'); stratBtn.disabled = false; }
    });
  }

  // AI campaign optimizer
  const optBtn = document.getElementById('aiCampaignOptimize');
  if (optBtn) {
    optBtn.addEventListener('click', async () => {
      const form = document.getElementById('campaignForm');
      if (!form) return;
      const name = form.querySelector('[name="name"]')?.value || '';
      const channel = form.querySelector('[name="channel"]')?.value || '';
      const objective = form.querySelector('[name="objective"]')?.value || '';
      const budget = form.querySelector('[name="budget"]')?.value || '';
      const notes = form.querySelector('[name="notes"]')?.value || '';

      if (!name && !notes) {
        error('Fill in campaign details or notes first');
        return;
      }

      optBtn.classList.add('loading');
      optBtn.disabled = true;
      try {
        const { item } = await api('/api/ai/campaign-optimizer', {
          method: 'POST',
          body: JSON.stringify({
            campaign_data: `Name: ${name}\nChannel: ${channel}\nObjective: ${objective}\nBudget: $${budget}\nNotes: ${notes}`,
            goals: objective || 'maximize ROI',
          }),
        });
        if (item?.optimization) {
          const notesField = form.querySelector('[name="notes"]');
          if (notesField) {
            notesField.value = item.optimization.slice(0, 3000);
            success('AI optimization recommendations added to notes');
          }
        }
      } catch (err) { error(err.message); }
      finally { optBtn.classList.remove('loading'); optBtn.disabled = false; }
    });
  }
}
