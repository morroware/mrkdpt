/**
 * Audience Segments — create, manage, and view dynamic contact segments.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, onSubmit, onClick } from '../core/utils.js';
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
    </div>`).join('') || '<p class="text-muted">No segments yet. Create one above.</p>';

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
        if (!confirm('Delete this segment?')) return;
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
}
