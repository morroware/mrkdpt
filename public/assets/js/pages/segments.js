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

    list.onclick = async (e) => {
      const viewBtn = e.target.closest('[data-view]');
      if (viewBtn) {
        viewSegmentContacts(parseInt(viewBtn.dataset.view));
        return;
      }
      const refreshBtn = e.target.closest('[data-refresh]');
      if (refreshBtn) {
        try {
          await api(`/api/segments/${refreshBtn.dataset.refresh}/recompute`, { method: 'POST', body: '{}' });
          success('Segment refreshed');
          loadSegments();
        } catch (e) { error(e.message); }
        return;
      }
      const delBtn = e.target.closest('[data-del]');
      if (delBtn) {
        if (!await confirm('Delete Segment', 'Are you sure you want to delete this segment? This cannot be undone.')) return;
        try {
          await api(`/api/segments/${delBtn.dataset.del}`, { method: 'DELETE' });
          success('Segment deleted');
          loadSegments();
        } catch (e) { error(e.message); }
      }
    };
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

  // AI Audience Builder - Natural Language
  onClick('aiDescribeAudience', async () => {
    const desc = $('audienceDescription')?.value?.trim();
    if (!desc) { error('Please describe your target audience.'); return; }

    const btn = $('aiDescribeAudience');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }

    try {
      const { item } = await api('/api/ai/describe-audience', {
        method: 'POST',
        body: JSON.stringify({ description: desc }),
      });

      const resultEl = $('aiAudienceResult');
      if (!resultEl) return;
      resultEl.classList.remove('hidden');

      const criteria = item?.criteria;
      if (criteria && typeof criteria === 'object') {
        // Auto-fill the segment form
        const form = $('segmentForm');
        if (form) {
          const nameField = form.querySelector('[name="name"]');
          if (nameField && !nameField.value) nameField.value = item.segment_name || 'AI-Generated Segment';
          const descField = form.querySelector('[name="description"]');
          if (descField) descField.value = desc;

          if (criteria.min_score) {
            const el = form.querySelector('[name="criteria_min_score"]');
            if (el) el.value = criteria.min_score;
          }
          if (criteria.max_score) {
            const el = form.querySelector('[name="criteria_max_score"]');
            if (el) el.value = criteria.max_score;
          }
          if (criteria.tags) {
            const el = form.querySelector('[name="criteria_tags"]');
            if (el) el.value = criteria.tags;
          }
          if (criteria.source) {
            const el = form.querySelector('[name="criteria_source"]');
            if (el) el.value = criteria.source;
          }
          if (criteria.has_activity_since) {
            const el = form.querySelector('[name="criteria_has_activity_since"]');
            if (el) el.value = criteria.has_activity_since;
          }
          if (criteria.no_activity_since) {
            const el = form.querySelector('[name="criteria_no_activity_since"]');
            if (el) el.value = criteria.no_activity_since;
          }
        }

        resultEl.innerHTML = `
          <div class="card" style="border-left:3px solid var(--accent)">
            <strong>AI-Generated Criteria</strong>
            <p class="text-small text-muted mt-1">${escapeHtml(item.explanation || 'Segment criteria populated in the form below.')}</p>
            <p class="text-small mt-1">Estimated size: <strong>${escapeHtml(item.estimated_size || 'Unknown')}</strong></p>
            <button class="btn btn-sm btn-ai mt-1" id="applyAiSegment">Create This Segment</button>
          </div>
        `;

        $('applyAiSegment')?.addEventListener('click', () => {
          form?.querySelector('button[type="submit"]')?.click();
        });

        success('Segment criteria generated from your description');
      } else {
        resultEl.innerHTML = `<pre class="ai-output text-small">${escapeHtml(item?.raw || 'No criteria generated')}</pre>`;
      }
    } catch (err) {
      error('Failed to build audience: ' + err.message);
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });
}
