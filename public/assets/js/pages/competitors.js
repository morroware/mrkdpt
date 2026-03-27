/**
 * Competitors page — CRUD.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, onSubmit, formData, onClick, emptyState, confirm } from '../core/utils.js';
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
      : emptyState('&#128065;', 'No Competitors', 'Track your competitors to stay ahead of the market.');

    list.querySelectorAll('[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!await confirm('Delete Competitor', 'Are you sure you want to delete this competitor?')) return;
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

  // AI Deep Dive button for competitors
  const aiBtn = document.getElementById('aiAnalyzeCompetitor');
  if (aiBtn) {
    aiBtn.addEventListener('click', async () => {
      const form = document.getElementById('competitorForm');
      if (!form) return;
      const name = form.querySelector('[name="name"]')?.value || '';
      const channel = form.querySelector('[name="channel"]')?.value || '';
      const positioning = form.querySelector('[name="positioning"]')?.value || '';
      if (!name) { error('Enter a competitor name first'); return; }
      aiBtn.classList.add('loading');
      aiBtn.disabled = true;
      try {
        const { item } = await api('/api/ai/competitor-analysis', {
          method: 'POST',
          body: JSON.stringify({
            name,
            notes: `Channel: ${channel}. Positioning: ${positioning}.`,
          }),
        });
        if (item?.analysis) {
          // Fill in the form fields with AI insights
          const actField = form.querySelector('[name="recent_activity"]');
          const oppField = form.querySelector('[name="opportunity"]');
          const posField = form.querySelector('[name="positioning"]');
          // Extract relevant sections from the analysis
          const analysis = item.analysis;
          if (posField && !posField.value) {
            const posMatch = analysis.match(/positioning[:\s]*(.*?)(?:\n|$)/i);
            if (posMatch) posField.value = posMatch[1].trim().slice(0, 200);
          }
          if (actField) actField.value = analysis.slice(0, 500);
          if (oppField) {
            const oppMatch = analysis.match(/opportunit(?:y|ies)[:\s]*([\s\S]*?)(?:\n\n|\d\.\s|$)/i);
            if (oppMatch) oppField.value = oppMatch[1].trim().slice(0, 500);
          }
          success('AI competitor analysis complete');
        }
      } catch (err) { error(err.message); }
      finally { aiBtn.classList.remove('loading'); aiBtn.disabled = false; }
    });
  }

  // AI Competitor Radar — analyze all competitors at once
  onClick('runCompetitorRadar', async () => {
    const btn = $('runCompetitorRadar');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/competitor-radar', { method: 'POST', body: '{}' });
      const card = $('competitorRadarCard');
      const output = $('competitorRadarOutput');
      if (card && output) {
        output.textContent = item?.radar || 'No data';
        card.classList.remove('hidden');
        card.scrollIntoView({ behavior: 'smooth' });
      }
      success('Competitor radar complete');
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });
}
