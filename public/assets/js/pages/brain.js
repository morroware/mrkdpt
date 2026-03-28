/**
 * AI Brain — Self-awareness dashboard, learnings, activity log, pipelines, and feedback.
 */
import { api } from '../core/api.js';
import { success, error } from '../core/toast.js';
import { $, $$, escapeHtml, formatDateTime } from '../core/utils.js';

let currentPipelineTemplate = null;

/* ================================================================== */
/*  PAGE LIFECYCLE                                                     */
/* ================================================================== */

export function init() {
  $('#brainRefreshBtn')?.addEventListener('click', refresh);
  $('#brainCapturePerf')?.addEventListener('click', capturePerformance);
  $('#brainLearningFilter')?.addEventListener('change', loadLearnings);
  $('#brainActivityFilter')?.addEventListener('change', loadActivity);
  $('#brainPipelineRunBtn')?.addEventListener('click', runPipeline);
  $('#brainPipelineClose')?.addEventListener('click', closePipelineRunner);
}

export async function refresh() {
  await Promise.all([
    loadOverview(),
    loadLearnings(),
    loadActivity(),
    loadPipelineTemplates(),
    loadPipelineHistory(),
    loadFeedback(),
  ]);
}

/* ================================================================== */
/*  OVERVIEW TAB                                                       */
/* ================================================================== */

async function loadOverview() {
  try {
    const [statusRes, statsRes] = await Promise.all([
      api('/api/ai/brain/status'),
      api('/api/ai/brain/stats?days=7'),
    ]);
    const status = statusRes.item || {};
    const stats = statsRes.item || {};

    // Metrics row
    const metricsEl = $('#brainMetrics');
    if (metricsEl) {
      metricsEl.innerHTML = `
        <div class="metric-card"><div class="metric-value">${stats.total_calls || 0}</div><div class="metric-label">AI Calls (7d)</div></div>
        <div class="metric-card"><div class="metric-value">${status.total_learnings || 0}</div><div class="metric-label">Learned Insights</div></div>
        <div class="metric-card"><div class="metric-value">${status.total_memories || 0}</div><div class="metric-label">Shared Memories</div></div>
        <div class="metric-card"><div class="metric-value">${status.total_feedback || 0}</div><div class="metric-label">Feedback Points</div></div>
      `;
    }

    // Knowledge coverage map
    const mapEl = $('#brainKnowledgeMap');
    if (mapEl) {
      const allCategories = ['audience', 'content', 'strategy', 'performance', 'brand', 'competitor', 'channel', 'timing'];
      const byCategory = {};
      (status.learnings_by_category || []).forEach(c => { byCategory[c.category] = c; });
      const gaps = status.knowledge_gaps || [];

      mapEl.innerHTML = allCategories.map(cat => {
        const data = byCategory[cat];
        const count = data ? data.count : 0;
        const conf = data ? Math.round(data.avg_confidence * 100) : 0;
        const isGap = gaps.includes(cat);
        const barWidth = Math.min(100, count * 10);
        const statusClass = isGap ? 'text-danger' : count >= 5 ? 'text-success' : 'text-secondary';
        return `<div class="flex-between mb-0" style="padding:4px 0;border-bottom:1px solid var(--line)">
          <span class="${statusClass}" style="min-width:100px;font-weight:600">${capitalize(cat)}</span>
          <div style="flex:1;margin:0 12px;background:var(--input-bg);border-radius:4px;height:8px;overflow:hidden">
            <div style="width:${barWidth}%;height:100%;background:${isGap ? 'var(--danger,#ef4444)' : 'linear-gradient(135deg,#6366f1,#a855f7)'};border-radius:4px;transition:width 0.3s"></div>
          </div>
          <span class="text-small text-muted">${count} insights${conf ? ` (${conf}% conf)` : ''}</span>
        </div>`;
      }).join('');
    }

    // Top learnings
    const topEl = $('#brainTopLearnings');
    if (topEl) {
      const top = status.strongest_learnings || [];
      if (top.length === 0) {
        topEl.innerHTML = '<p class="text-muted">No learnings yet. Use AI tools to start building knowledge.</p>';
      } else {
        topEl.innerHTML = top.map(l => `
          <div style="padding:8px 0;border-bottom:1px solid var(--line)">
            <div class="flex-between">
              <span class="badge" style="background:var(--accent);color:white;font-size:11px;padding:2px 8px;border-radius:12px">${escapeHtml(l.category)}</span>
              <span class="text-small text-muted">Confirmed x${l.times_reinforced}</span>
            </div>
            <p class="mt-0 mb-0 text-small" style="margin-top:4px">${escapeHtml(l.insight)}</p>
          </div>
        `).join('');
      }
    }

    // Recent activity
    const actEl = $('#brainRecentActivity');
    if (actEl) {
      const byTool = stats.by_tool || [];
      if (byTool.length === 0) {
        actEl.innerHTML = '<p class="text-muted">No AI activity recorded yet.</p>';
      } else {
        actEl.innerHTML = `<div class="flex flex-wrap gap-1">${byTool.map(t =>
          `<span class="badge" style="background:var(--input-bg);padding:6px 12px;border-radius:8px;font-size:12px">${escapeHtml(t.tool_name)} <strong>${t.count}</strong></span>`
        ).join('')}</div>`;
      }
    }
  } catch (e) {
    error('Failed to load brain overview: ' + e.message);
  }
}

/* ================================================================== */
/*  LEARNINGS TAB                                                      */
/* ================================================================== */

async function loadLearnings() {
  const filter = $('#brainLearningFilter')?.value || '';
  const url = filter ? `/api/ai/brain/learnings?category=${filter}` : '/api/ai/brain/learnings';
  try {
    const data = await api(url);
    const items = data.items || [];
    const el = $('#brainLearningsList');
    if (!el) return;

    if (items.length === 0) {
      el.innerHTML = '<p class="text-muted">No learnings yet. Use AI tools and the system will automatically extract insights.</p>';
      return;
    }

    el.innerHTML = items.map(l => `
      <div class="card" style="padding:12px">
        <div class="flex-between">
          <div class="flex gap-1" style="align-items:center">
            <span class="badge" style="background:${categoryColor(l.category)};color:white;font-size:11px;padding:2px 8px;border-radius:12px">${escapeHtml(l.category)}</span>
            <span class="text-small text-muted">from ${escapeHtml(l.source_tool || 'unknown')}</span>
          </div>
          <div class="flex gap-1" style="align-items:center">
            <span class="text-small" title="Confidence">${Math.round(l.confidence * 100)}%</span>
            ${l.times_reinforced > 1 ? `<span class="text-small text-success" title="Reinforced">x${l.times_reinforced}</span>` : ''}
            <button class="btn btn-ghost btn-sm text-danger" data-delete-learning="${l.id}" title="Delete">&#10005;</button>
          </div>
        </div>
        <p class="mt-0 mb-0" style="margin-top:6px">${escapeHtml(l.insight)}</p>
        <div class="text-small text-muted" style="margin-top:4px">${formatDateTime(l.created_at)}</div>
      </div>
    `).join('');

    // Wire delete buttons
    el.querySelectorAll('[data-delete-learning]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.deleteLearning;
        try {
          await api(`/api/ai/brain/learnings/${id}`, { method: 'DELETE' });
          success('Learning deleted');
          loadLearnings();
        } catch (e) { error(e.message); }
      });
    });
  } catch (e) {
    error('Failed to load learnings: ' + e.message);
  }
}

/* ================================================================== */
/*  ACTIVITY LOG TAB                                                   */
/* ================================================================== */

async function loadActivity() {
  const filter = $('#brainActivityFilter')?.value || '';
  const url = filter ? `/api/ai/brain/activity?category=${filter}&limit=50` : '/api/ai/brain/activity?limit=50';
  try {
    const data = await api(url);
    const items = data.items || [];
    const el = $('#brainActivityList');
    if (!el) return;

    if (items.length === 0) {
      el.innerHTML = '<p class="text-muted">No activity logged yet.</p>';
      return;
    }

    el.innerHTML = items.map(a => `
      <div style="padding:8px 12px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:flex-start">
        <span class="badge" style="background:${categoryColor(a.tool_category)};color:white;font-size:10px;padding:2px 6px;border-radius:8px;min-width:60px;text-align:center">${escapeHtml(a.tool_category)}</span>
        <div style="flex:1;min-width:0">
          <strong>${escapeHtml(a.tool_name)}</strong>
          <span class="text-muted text-small"> ${escapeHtml(a.input_summary || '')}</span>
          ${a.output_summary ? `<div class="text-small text-muted" style="margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:600px">${escapeHtml(a.output_summary.substring(0, 150))}</div>` : ''}
        </div>
        <span class="text-small text-muted" style="white-space:nowrap">${a.provider ? escapeHtml(a.provider) + ' · ' : ''}${formatDateTime(a.created_at)}</span>
      </div>
    `).join('');
  } catch (e) {
    error('Failed to load activity: ' + e.message);
  }
}

/* ================================================================== */
/*  PIPELINES TAB                                                      */
/* ================================================================== */

async function loadPipelineTemplates() {
  try {
    const data = await api('/api/ai/pipelines/templates');
    const items = data.items || [];
    const el = $('#brainPipelineTemplates');
    if (!el) return;

    el.innerHTML = items.map(t => `
      <div class="card" style="cursor:pointer" data-pipeline-id="${escapeHtml(t.id)}">
        <h4 class="mt-0 mb-0" style="color:var(--accent)">${escapeHtml(t.name)}</h4>
        <p class="text-small text-muted mt-0">${escapeHtml(t.description)}</p>
        <div class="flex flex-wrap gap-1">
          ${t.steps.map((s, i) => `<span class="badge" style="background:var(--input-bg);padding:3px 8px;border-radius:6px;font-size:11px">${i + 1}. ${escapeHtml(s.label)}</span>`).join('<span style="color:var(--text-muted)">&#8594;</span>')}
        </div>
      </div>
    `).join('');

    // Wire click to open pipeline runner
    el.querySelectorAll('[data-pipeline-id]').forEach(card => {
      card.addEventListener('click', () => openPipelineRunner(card.dataset.pipelineId, items.find(t => t.id === card.dataset.pipelineId)));
    });
  } catch (e) {
    error('Failed to load pipeline templates: ' + e.message);
  }
}

function openPipelineRunner(templateId, template) {
  currentPipelineTemplate = { id: templateId, ...template };
  const runner = $('#brainPipelineRunner');
  if (!runner) return;

  runner.classList.remove('hidden');
  $('#brainPipelineName').textContent = template.name;
  $('#brainPipelineDesc').textContent = template.description;

  // Show steps
  $('#brainPipelineSteps').innerHTML = template.steps.map((s, i) => `
    <div class="flex gap-1" style="align-items:center;padding:4px 0">
      <span style="background:linear-gradient(135deg,#6366f1,#a855f7);color:white;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">${i + 1}</span>
      <span>${escapeHtml(s.label)}</span>
      <span class="text-muted text-small">(${escapeHtml(s.tool)})</span>
    </div>
  `).join('');

  // Build input fields based on common pipeline variables
  const variableSet = new Set();
  const rawSteps = template.steps || [];
  rawSteps.forEach(s => {
    const maps = Object.values(s.map || {});
    maps.forEach(v => {
      if (typeof v === 'string') {
        const matches = v.matchAll(/\{\{(\w+)\}\}/g);
        for (const m of matches) {
          if (!m[1].startsWith('prev')) variableSet.add(m[1]);
        }
      }
    });
  });

  const inputs = $('#brainPipelineInputs');
  if (inputs) {
    inputs.innerHTML = Array.from(variableSet).map(v => `
      <div>
        <label>${capitalize(v.replace(/_/g, ' '))}</label>
        <input data-pipeline-var="${v}" placeholder="Enter ${v.replace(/_/g, ' ')}" />
      </div>
    `).join('');
  }

  // Reset results
  $('#brainPipelineResults')?.classList.add('hidden');
}

function closePipelineRunner() {
  $('#brainPipelineRunner')?.classList.add('hidden');
  currentPipelineTemplate = null;
}

async function runPipeline() {
  if (!currentPipelineTemplate) return;

  const btn = $('#brainPipelineRunBtn');
  btn.classList.add('loading');
  btn.disabled = true;

  // Gather variables
  const variables = {};
  $$('#brainPipelineInputs [data-pipeline-var]').forEach(input => {
    variables[input.dataset.pipelineVar] = input.value;
  });

  try {
    const data = await api('/api/ai/pipelines/run', {
      method: 'POST',
      body: JSON.stringify({ template_id: currentPipelineTemplate.id, variables }),
    });
    const result = data.item || {};

    const resultsEl = $('#brainPipelineResults');
    if (resultsEl) {
      resultsEl.classList.remove('hidden');
      const steps = result.steps || [];
      resultsEl.innerHTML = `
        <h4>Pipeline Results <span class="badge ${result.status === 'completed' ? 'text-success' : 'text-danger'}">${result.status}</span></h4>
        ${steps.map(s => `
          <div class="card mb-1" style="padding:12px">
            <div class="flex-between">
              <strong>Step ${s.step}: ${escapeHtml(s.label)}</strong>
              <span class="${s.status === 'completed' ? 'text-success' : 'text-danger'}">${s.status}${s.duration_ms ? ` (${(s.duration_ms / 1000).toFixed(1)}s)` : ''}</span>
            </div>
            ${s.status === 'completed' ? `<div class="mt-1 text-small" style="max-height:200px;overflow:auto;background:var(--input-bg);padding:8px;border-radius:var(--radius);white-space:pre-wrap">${escapeHtml(typeof s.output === 'string' ? s.output : JSON.stringify(s.output, null, 2)).substring(0, 2000)}</div>` : ''}
            ${s.error ? `<div class="text-danger text-small mt-1">${escapeHtml(s.error)}</div>` : ''}
          </div>
        `).join('')}
        ${result.next_actions?.length ? `
          <div class="card" style="padding:12px;border:1px solid var(--accent)">
            <h4 class="mt-0">Suggested Next Actions</h4>
            <div class="flex flex-wrap gap-1">
              ${result.next_actions.map(a => `<button class="btn btn-ai btn-sm" data-next-tool="${a.tool}" title="${escapeHtml(a.reason)}">${escapeHtml(a.tool)}</button>`).join('')}
            </div>
          </div>
        ` : ''}
      `;
    }

    success('Pipeline completed!');
    loadPipelineHistory();
  } catch (e) {
    error('Pipeline failed: ' + e.message);
  } finally {
    btn.classList.remove('loading');
    btn.disabled = false;
  }
}

async function loadPipelineHistory() {
  try {
    const data = await api('/api/ai/pipelines/runs?limit=10');
    const items = data.items || [];
    const el = $('#brainPipelineHistory');
    if (!el) return;

    if (items.length === 0) {
      el.innerHTML = '<p class="text-muted">No pipeline runs yet.</p>';
      return;
    }

    el.innerHTML = items.map(r => `
      <div style="padding:8px 12px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:center">
        <span class="${r.status === 'completed' ? 'text-success' : r.status === 'partial' ? 'text-secondary' : 'text-danger'}" style="font-weight:600">${r.status}</span>
        <strong style="flex:1">${escapeHtml(r.name)}</strong>
        <span class="text-small text-muted">${r.steps_completed}/${r.steps_total} steps</span>
        <span class="text-small text-muted">${formatDateTime(r.started_at)}</span>
      </div>
    `).join('');
  } catch (e) {
    // Silently fail for non-critical section
  }
}

/* ================================================================== */
/*  FEEDBACK TAB                                                       */
/* ================================================================== */

async function loadFeedback() {
  try {
    const data = await api('/api/ai/brain/feedback?limit=30');
    const items = data.items || [];
    const el = $('#brainFeedbackList');
    if (!el) return;

    if (items.length === 0) {
      el.innerHTML = '<p class="text-muted">No performance feedback yet. Publish AI-generated content and capture performance to start the feedback loop.</p>';
      return;
    }

    el.innerHTML = items.map(f => `
      <div style="padding:8px 12px;border-bottom:1px solid var(--line)">
        <div class="flex-between">
          <strong>${escapeHtml(f.entity_type)} #${f.entity_id}: ${escapeHtml(f.metric_name)}</strong>
          <span style="font-size:18px;font-weight:700;color:var(--accent)">${f.metric_value}</span>
        </div>
        ${f.feedback_note ? `<div class="text-small text-muted">${escapeHtml(f.feedback_note)}</div>` : ''}
        ${f.tool_name ? `<div class="text-small text-muted">Generated by: ${escapeHtml(f.tool_name)}</div>` : ''}
        <div class="text-small text-muted">${formatDateTime(f.created_at)}</div>
      </div>
    `).join('');
  } catch (e) {
    // Silently fail
  }
}

async function capturePerformance() {
  const btn = $('#brainCapturePerf');
  btn.classList.add('loading');
  btn.disabled = true;
  try {
    const data = await api('/api/ai/brain/capture-performance', { method: 'POST' });
    const captured = data.item?.captured || 0;
    success(`Captured performance data for ${captured} posts`);
    if (captured > 0) loadFeedback();
  } catch (e) {
    error(e.message);
  } finally {
    btn.classList.remove('loading');
    btn.disabled = false;
  }
}

/* ================================================================== */
/*  HELPERS                                                            */
/* ================================================================== */

function capitalize(str) {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

function categoryColor(cat) {
  const colors = {
    content: '#6366f1',
    strategy: '#8b5cf6',
    analysis: '#a855f7',
    conversation: '#3b82f6',
    pipeline: '#06b6d4',
    audience: '#f59e0b',
    brand: '#ec4899',
    competitor: '#ef4444',
    channel: '#10b981',
    timing: '#f97316',
    performance: '#14b8a6',
    general: '#6b7280',
  };
  return colors[cat] || '#6b7280';
}
