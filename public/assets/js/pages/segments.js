/**
 * Audience Segments — create, manage, and view dynamic contact segments.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, onSubmit, onClick, emptyState, confirm } from '../core/utils.js';
import { success, error } from '../core/toast.js';

async function loadSegments() {
  try {
    const { items } = await api('/api/segments');
    const list = $('segmentList');
    if (!list) return;

    list.innerHTML = items.map((s) => `<div class="card">
      <div class="flex-between">
        <h3>${escapeHtml(s.name)}</h3>
        <span class="badge">${s.contact_count} contacts</span>
      </div>
      <p class="text-small text-muted">${escapeHtml(s.description || '')}</p>
      <p class="text-small text-muted mt-1">Last computed: ${formatDate(s.last_computed)}</p>
      <div class="btn-group mt-1">
        <button class="btn btn-sm" data-view="${s.id}">View Contacts</button>
        <button class="btn btn-sm btn-outline" data-refresh="${s.id}">Refresh</button>
        <button class="btn btn-sm btn-danger" data-del="${s.id}">Delete</button>
      </div>
    </div>`).join('') || emptyState('&#128101;', 'No segments yet', 'Create your first segment above to group contacts by criteria.');

    list.querySelectorAll('[data-view]').forEach((btn) => {
      btn.addEventListener('click', () => viewSegmentContacts(parseInt(btn.dataset.view)));
    });
    list.querySelectorAll('[data-refresh]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api(`/api/segments/${btn.dataset.refresh}/recompute`, { method: 'POST', body: '{}' });
          success('Segment refreshed');
          loadSegments();
        } catch (e) { error(e.message); }
      });
    });
    list.querySelectorAll('[data-del]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!await confirm('Delete Segment', 'Are you sure you want to delete this segment? This cannot be undone.')) return;
        try {
          await api(`/api/segments/${btn.dataset.del}`, { method: 'DELETE' });
          success('Segment deleted');
          loadSegments();
        } catch (e) { error(e.message); }
      });
    });
  } catch (e) {
    error('Failed to load segments: ' + e.message);
  }
}

async function viewSegmentContacts(id) {
  try {
    const { items } = await api(`/api/segments/${id}/contacts`);
    const modal = $('segmentModal');
    const body = $('segmentModalBody');
    if (!modal || !body) return;

    body.innerHTML = `<p class="mb-2">${items.length} contacts found</p>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Email</th><th>Name</th><th>Company</th><th>Stage</th><th>Score</th></tr></thead><tbody>${
        items.map((c) => `<tr><td>${escapeHtml(c.email)}</td><td>${escapeHtml((c.first_name || '') + ' ' + (c.last_name || ''))}</td><td>${escapeHtml(c.company || '')}</td><td><span class="badge">${c.stage}</span></td><td>${c.score}</td></tr>`).join('')
      }</tbody></table></div>`;
    modal.classList.add('visible');
  } catch (e) {
    error(e.message);
  }
}

export function refresh() {
  loadSegments();
}

export function init() {
  onSubmit('segmentForm', async (e) => {
    const form = e.target;
    const fd = new FormData(form);
    const criteria = {};

    const stageSelect = $('segStage');
    if (stageSelect) {
      const selected = [...stageSelect.selectedOptions].map((o) => o.value);
      if (selected.length) criteria.stage = selected;
    }
    if (fd.get('criteria_min_score')) criteria.min_score = parseInt(fd.get('criteria_min_score'));
    if (fd.get('criteria_max_score')) criteria.max_score = parseInt(fd.get('criteria_max_score'));
    if (fd.get('criteria_tags')) criteria.tags = fd.get('criteria_tags');
    if (fd.get('criteria_source')) criteria.source = fd.get('criteria_source');
    if (fd.get('criteria_company')) criteria.company = fd.get('criteria_company');
    if (fd.get('criteria_has_activity_since')) criteria.has_activity_since = fd.get('criteria_has_activity_since');
    if (fd.get('criteria_no_activity_since')) criteria.no_activity_since = fd.get('criteria_no_activity_since');

    try {
      await api('/api/segments', {
        method: 'POST',
        body: JSON.stringify({
          name: fd.get('name'),
          description: fd.get('description') || '',
          criteria,
        }),
      });
      form.reset();
      success('Segment created');
      loadSegments();
    } catch (e) {
      error(e.message);
    }
  });

  onClick('closeSegmentModal', () => {
    $('segmentModal')?.classList.remove('visible');
  });

  // AI Smart Segmentation
  onClick('aiSmartSegments', async () => {
    const btn = $('aiSmartSegments');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/smart-segments', { method: 'POST', body: '{}' });
      const card = $('smartSegmentsCard');
      const list = $('smartSegmentsList');
      if (!card || !list) return;

      if (item?.segments && Array.isArray(item.segments)) {
        list.innerHTML = item.segments.map((s) => `<div class="card mb-1">
          <div class="flex-between">
            <strong>${escapeHtml(s.name || '')}</strong>
            <span class="badge badge-${s.priority === 'high' ? 'danger' : s.priority === 'medium' ? 'warning' : 'info'}">${escapeHtml(s.priority || '')}</span>
          </div>
          <p class="text-small text-muted">${escapeHtml(s.description || '')}</p>
          <p class="text-small"><strong>Estimated size:</strong> ${escapeHtml(s.estimated_size || '')}</p>
          <p class="text-small"><strong>Action:</strong> ${escapeHtml(s.recommended_action || '')}</p>
        </div>`).join('');
        card.classList.remove('hidden');
        card.scrollIntoView({ behavior: 'smooth' });
        success(`${item.segments.length} segment suggestions generated`);
      } else {
        list.innerHTML = `<pre class="ai-output">${escapeHtml(item?.raw || 'No suggestions')}</pre>`;
        card.classList.remove('hidden');
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });
}
