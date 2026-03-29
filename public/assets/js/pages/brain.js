/**
 * AI Brain — Streamlined self-awareness dashboard with actionable insights,
 * knowledge map, activity timeline, pipelines, and performance feedback.
 */
import { api } from '../core/api.js';
import { success, error } from '../core/toast.js';
import { $, $$, escapeHtml, formatDateTime } from '../core/utils.js';
import { navigate } from '../core/router.js';

let currentPipelineTemplate = null;
let currentAgentTaskId = null;

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

  // Agents tab
  $('#agentCreateBtn')?.addEventListener('click', () => createAgentTask(true));
  $('#agentPlanOnlyBtn')?.addEventListener('click', () => createAgentTask(false));
  $('#agentTaskClose')?.addEventListener('click', closeAgentWorkspace);
  $('#agentExecuteNextBtn')?.addEventListener('click', executeNextAgentStep);
  $('#agentExecuteAllBtn')?.addEventListener('click', executeAllAgentSteps);
  $('#agentApproveBtn')?.addEventListener('click', approveAgentStep);
  $('#agentRejectBtn')?.addEventListener('click', rejectAgentStep);
  $('#agentCancelBtn')?.addEventListener('click', cancelAgentTask);

  // Search tab
  $('#searchExecuteBtn')?.addEventListener('click', executeSearch);
  $('#searchSourceWebsite')?.addEventListener('change', (e) => {
    $('#searchUrlField')?.classList.toggle('hidden', !e.target.checked);
  });

  // Models tab
  $('#modelRoutingSaveBtn')?.addEventListener('click', saveModelRoute);
  $('#modelRoutingDeleteBtn')?.addEventListener('click', deleteModelRoute);
  $('#modelRoutingProvider')?.addEventListener('change', onProviderChange);
  $('#modelRoutingTaskType')?.addEventListener('change', onTaskTypeChange);

  // Daily briefing
  $('#brainRefreshBriefing')?.addEventListener('click', loadDailyBriefing);
  // Recommendations
  $('#brainRefreshRecs')?.addEventListener('click', loadRecommendations);
  // Knowledge base - add manual learning
  $('#brainAddLearningBtn')?.addEventListener('click', addManualLearning);
  // Brain initialization
  $('#brainInitializeBtn')?.addEventListener('click', initializeBrain);

  // Quick-start action buttons on overview
  document.querySelectorAll('[data-brain-action]').forEach(btn => {
    btn.addEventListener('click', () => {
      const action = btn.dataset.brainAction;
      if (action === 'run-pipeline') {
        document.querySelector('[data-tab="brain-pipelines"]')?.click();
      } else if (action === 'view-learnings') {
        document.querySelector('[data-tab="brain-learnings"]')?.click();
      } else if (action === 'ai-studio') {
        navigate('ai');
      } else if (action === 'ai-chat') {
        navigate('chat');
      }
    });
  });
}

export async function refresh() {
  // Show skeleton loading state
  showLoadingState();
  await Promise.all([
    loadOverview(),
    loadDailyBriefing(),
    loadRecommendations(),
    loadLearnings(),
    loadActivity(),
    loadPipelineTemplates(),
    loadPipelineHistory(),
    loadFeedback(),
    loadAgentTypes(),
    loadAgentHistory(),
    loadSearchHistory(),
    loadModelRouting(),
    loadKnowledgeBase(),
  ]);
}

function showLoadingState() {
  const metrics = $('#brainMetrics');
  if (metrics) {
    metrics.innerHTML = Array(4).fill(0).map(() =>
      '<div class="metric-card skeleton"><div class="metric-value">--</div><div class="metric-label">Loading...</div></div>'
    ).join('');
  }
}

/* ================================================================== */
/*  OVERVIEW TAB — Redesigned with actionable insights                 */
/* ================================================================== */

async function loadOverview() {
  try {
    const [statusRes, statsRes] = await Promise.all([
      api('/api/ai/brain/status'),
      api('/api/ai/brain/stats?days=7'),
    ]);
    const status = statusRes.item || {};
    const stats = statsRes.item || {};

    renderMetrics(status, stats);
    renderHealthScore(status, stats);
    renderKnowledgeMap(status);
    renderTopLearnings(status);
    renderRecentActivity(stats);
    renderQuickActions(status);
  } catch (e) {
    error('Failed to load brain overview: ' + e.message);
  }
}

function renderMetrics(status, stats) {
  const metricsEl = $('#brainMetrics');
  if (!metricsEl) return;

  const items = [
    { value: stats.total_calls || 0, label: 'AI Calls (7d)', icon: '&#9889;', trend: getTrend(stats.total_calls, 'calls') },
    { value: status.total_learnings || 0, label: 'Learned Insights', icon: '&#128161;', trend: null },
    { value: status.total_feedback || 0, label: 'Feedback Points', icon: '&#127919;', trend: null },
    { value: calculateBrainScore(status, stats), label: 'Brain Score', icon: '&#129504;', trend: null, suffix: '/100' },
  ];

  metricsEl.innerHTML = items.map(m => `
    <div class="metric-card">
      <div class="metric-icon">${m.icon}</div>
      <div class="metric-value">${m.value}${m.suffix || ''}</div>
      <div class="metric-label">${m.label}</div>
      ${m.trend ? `<div class="metric-trend ${m.trend > 0 ? 'up' : 'down'}">${m.trend > 0 ? '&#9650;' : '&#9660;'} ${Math.abs(m.trend)}%</div>` : ''}
    </div>
  `).join('');
}

function calculateBrainScore(status, stats) {
  const learnings = Math.min(40, (status.total_learnings || 0) * 4);
  const activity = Math.min(30, (stats.total_calls || 0) * 1.5);
  const feedback = Math.min(20, (status.total_feedback || 0) * 5);
  const gaps = status.knowledge_gaps || [];
  const coverage = Math.max(0, 10 - gaps.length * 2);
  return Math.min(100, Math.round(learnings + activity + feedback + coverage));
}

function getTrend(current, type) {
  const prev = parseInt(localStorage.getItem('brain_prev_' + type) || '0');
  localStorage.setItem('brain_prev_' + type, String(current || 0));
  if (!prev || !current) return null;
  return Math.round(((current - prev) / prev) * 100);
}

function renderHealthScore(status, stats) {
  const el = $('#brainHealthScore');
  if (!el) return;

  const score = calculateBrainScore(status, stats);
  const gaps = status.knowledge_gaps || [];

  let statusText, statusClass;
  if (score >= 70) { statusText = 'Thriving'; statusClass = 'success'; }
  else if (score >= 40) { statusText = 'Growing'; statusClass = 'warning'; }
  else { statusText = 'Just Getting Started'; statusClass = 'info'; }

  const suggestions = [];
  if (gaps.length > 3) suggestions.push('Use more AI tools across different categories to fill knowledge gaps.');
  if ((stats.total_calls || 0) < 5) suggestions.push('Try running some AI tools — each one teaches the Brain something new.');
  if ((status.total_feedback || 0) === 0) suggestions.push('Capture performance data to help the AI learn what content works best.');
  if ((status.total_learnings || 0) > 5 && gaps.length > 0) suggestions.push(`Focus on ${gaps.slice(0, 2).map(capitalize).join(' and ')} — your AI has gaps there.`);
  if (suggestions.length === 0) suggestions.push('Your AI Brain is well-rounded! Keep using tools to reinforce and expand its knowledge.');

  el.innerHTML = `
    <div class="brain-health">
      <div class="brain-health-ring">
        <svg viewBox="0 0 100 100" class="brain-ring-svg">
          <circle cx="50" cy="50" r="42" class="brain-ring-bg"/>
          <circle cx="50" cy="50" r="42" class="brain-ring-fill" style="stroke-dasharray: ${score * 2.64} 264; stroke-dashoffset: 0"/>
        </svg>
        <div class="brain-ring-label">
          <span class="brain-ring-score">${score}</span>
          <span class="brain-ring-max">/100</span>
        </div>
      </div>
      <div class="brain-health-info">
        <div class="brain-health-status text-${statusClass}">${statusText}</div>
        <div class="brain-health-suggestions">
          ${suggestions.map(s => `<div class="brain-suggestion"><span class="brain-suggestion-icon">&#10148;</span> ${s}</div>`).join('')}
        </div>
      </div>
    </div>
  `;
}

function renderKnowledgeMap(status) {
  const mapEl = $('#brainKnowledgeMap');
  if (!mapEl) return;

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

    return `<div class="knowledge-bar ${isGap ? 'knowledge-gap' : count >= 5 ? 'knowledge-strong' : ''}">
      <div class="knowledge-bar-header">
        <span class="knowledge-cat-icon">${categoryEmoji(cat)}</span>
        <span class="knowledge-cat-name">${capitalize(cat)}</span>
        <span class="knowledge-cat-stat">${count} insight${count !== 1 ? 's' : ''}${conf ? ` &middot; ${conf}%` : ''}</span>
      </div>
      <div class="knowledge-bar-track">
        <div class="knowledge-bar-fill${isGap ? ' gap' : ''}" style="width:${barWidth}%"></div>
      </div>
    </div>`;
  }).join('');
}

function renderTopLearnings(status) {
  const topEl = $('#brainTopLearnings');
  if (!topEl) return;

  const top = status.strongest_learnings || [];
  if (top.length === 0) {
    topEl.innerHTML = `
      <div class="brain-empty-state">
        <div class="brain-empty-icon">&#128218;</div>
        <p>No learnings yet. Use AI tools to start building knowledge.</p>
        <button class="btn btn-ai btn-sm" data-brain-action="ai-studio"><span class="btn-ai-icon">&#9733;</span> Open AI Studio</button>
      </div>
    `;
    topEl.querySelector('[data-brain-action]')?.addEventListener('click', () => navigate('ai'));
    return;
  }

  topEl.innerHTML = top.map(l => `
    <div class="brain-learning-card">
      <div class="brain-learning-header">
        <span class="brain-cat-badge" style="--cat-color:${categoryColor(l.category)}">${categoryEmoji(l.category)} ${capitalize(l.category)}</span>
        <span class="brain-reinforced" title="Confirmed ${l.times_reinforced} time${l.times_reinforced !== 1 ? 's' : ''}">&#10003; x${l.times_reinforced}</span>
      </div>
      <p class="brain-learning-text">${escapeHtml(l.insight)}</p>
    </div>
  `).join('');
}

function renderRecentActivity(stats) {
  const actEl = $('#brainRecentActivity');
  if (!actEl) return;

  const byTool = stats.by_tool || [];
  if (byTool.length === 0) {
    actEl.innerHTML = '<p class="text-muted text-small">No AI activity recorded yet. Start using AI tools to see activity here.</p>';
    return;
  }

  // Sort by count descending, take top 10
  const sorted = [...byTool].sort((a, b) => b.count - a.count).slice(0, 10);
  const maxCount = sorted[0]?.count || 1;

  actEl.innerHTML = `<div class="activity-bar-chart">${sorted.map(t => `
    <div class="activity-bar-row">
      <span class="activity-bar-label">${escapeHtml(t.tool_name)}</span>
      <div class="activity-bar-track">
        <div class="activity-bar-fill" style="width:${(t.count / maxCount) * 100}%"></div>
      </div>
      <span class="activity-bar-count">${t.count}</span>
    </div>
  `).join('')}</div>`;
}

function renderQuickActions(status) {
  const el = $('#brainQuickActions');
  if (!el) return;

  const gaps = status.knowledge_gaps || [];
  const actions = [];

  if (gaps.includes('content')) {
    actions.push({ icon: '&#9998;', label: 'Generate Content', desc: 'Fill your content knowledge gap', page: 'ai' });
  }
  if (gaps.includes('strategy')) {
    actions.push({ icon: '&#128202;', label: 'Build Strategy', desc: 'Develop marketing strategy insights', page: 'ai' });
  }
  if (gaps.includes('competitor')) {
    actions.push({ icon: '&#9878;', label: 'Analyze Competitors', desc: 'Research your competition', page: 'competitors' });
  }
  if (gaps.includes('audience')) {
    actions.push({ icon: '&#9823;', label: 'Define Audience', desc: 'Build audience personas', page: 'ai' });
  }

  // Always show these
  actions.push({ icon: '&#9889;', label: 'Run Pipeline', desc: 'Multi-step AI workflow', action: 'run-pipeline' });
  actions.push({ icon: '&#128172;', label: 'Ask AI Chat', desc: 'Get marketing advice', page: 'chat' });

  el.innerHTML = actions.slice(0, 4).map(a => `
    <button class="brain-quick-action" ${a.page ? `data-brain-nav="${a.page}"` : `data-brain-action="${a.action}"`}>
      <span class="brain-quick-icon">${a.icon}</span>
      <span class="brain-quick-label">${a.label}</span>
      <span class="brain-quick-desc">${a.desc}</span>
    </button>
  `).join('');

  el.querySelectorAll('[data-brain-nav]').forEach(btn => {
    btn.addEventListener('click', () => navigate(btn.dataset.brainNav));
  });
  el.querySelectorAll('[data-brain-action]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.dataset.brainAction === 'run-pipeline') {
        document.querySelector('[data-tab="brain-pipelines"]')?.click();
      }
    });
  });
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
      el.innerHTML = `
        <div class="brain-empty-state">
          <div class="brain-empty-icon">&#129504;</div>
          <p>No learnings yet. Use AI tools and the system will automatically extract insights.</p>
          <button class="btn btn-ai btn-sm" onclick="location.hash='#ai'"><span class="btn-ai-icon">&#9733;</span> Start Using AI Tools</button>
        </div>
      `;
      return;
    }

    el.innerHTML = items.map(l => `
      <div class="brain-learning-item">
        <div class="brain-learning-item-header">
          <div class="flex gap-1" style="align-items:center">
            <span class="brain-cat-badge" style="--cat-color:${categoryColor(l.category)}">${categoryEmoji(l.category)} ${capitalize(l.category)}</span>
            <span class="text-small text-muted">from ${escapeHtml(l.source_tool || 'unknown')}</span>
          </div>
          <div class="flex gap-1" style="align-items:center">
            <span class="brain-confidence" title="Confidence: ${Math.round(l.confidence * 100)}%">
              <span class="brain-conf-bar"><span class="brain-conf-fill" style="width:${l.confidence * 100}%"></span></span>
              ${Math.round(l.confidence * 100)}%
            </span>
            ${l.times_reinforced > 1 ? `<span class="brain-reinforced-badge">&#10003; x${l.times_reinforced}</span>` : ''}
            <button class="btn btn-ghost btn-sm text-danger" data-delete-learning="${l.id}" title="Delete" aria-label="Delete learning">&#10005;</button>
          </div>
        </div>
        <p class="brain-learning-item-text">${escapeHtml(l.insight)}</p>
        <div class="text-small text-muted">${formatDateTime(l.created_at)}</div>
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
      el.innerHTML = `
        <div class="brain-empty-state">
          <div class="brain-empty-icon">&#128196;</div>
          <p>No activity logged yet. Every AI tool you use will appear here.</p>
        </div>
      `;
      return;
    }

    el.innerHTML = items.map(a => `
      <div class="brain-activity-item">
        <div class="brain-activity-icon" style="--cat-color:${categoryColor(a.tool_category)}">${categoryEmoji(a.tool_category)}</div>
        <div class="brain-activity-content">
          <div class="brain-activity-header">
            <strong>${escapeHtml(a.tool_name)}</strong>
            <span class="text-small text-muted">${a.provider ? escapeHtml(a.provider) + ' &middot; ' : ''}${formatDateTime(a.created_at)}</span>
          </div>
          ${a.input_summary ? `<div class="text-small text-muted brain-activity-input">${escapeHtml(a.input_summary)}</div>` : ''}
          ${a.output_summary ? `<div class="text-small brain-activity-output">${escapeHtml(a.output_summary.substring(0, 200))}</div>` : ''}
        </div>
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
      <div class="brain-pipeline-card" data-pipeline-id="${escapeHtml(t.id)}">
        <div class="brain-pipeline-card-header">
          <h4>${escapeHtml(t.name)}</h4>
          <span class="brain-pipeline-steps-count">${t.steps.length} steps</span>
        </div>
        <p class="text-small text-muted">${escapeHtml(t.description)}</p>
        <div class="brain-pipeline-steps-preview">
          ${t.steps.map((s, i) => `<span class="brain-pipeline-step-dot" title="${escapeHtml(s.label)}">${i + 1}</span>`).join('<span class="brain-pipeline-arrow">&#8594;</span>')}
        </div>
        <button class="btn btn-ai btn-sm brain-pipeline-run-btn">Run Pipeline</button>
      </div>
    `).join('');

    el.querySelectorAll('[data-pipeline-id]').forEach(card => {
      card.querySelector('.brain-pipeline-run-btn')?.addEventListener('click', (e) => {
        e.stopPropagation();
        openPipelineRunner(card.dataset.pipelineId, items.find(t => t.id === card.dataset.pipelineId));
      });
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
  runner.scrollIntoView({ behavior: 'smooth', block: 'start' });

  $('#brainPipelineName').textContent = template.name;
  $('#brainPipelineDesc').textContent = template.description;

  // Show steps with visual flow
  $('#brainPipelineSteps').innerHTML = `<div class="brain-pipeline-flow">${template.steps.map((s, i) => `
    <div class="brain-pipeline-flow-step" id="pipelineStep${i}">
      <span class="brain-pipeline-flow-num">${i + 1}</span>
      <span class="brain-pipeline-flow-label">${escapeHtml(s.label)}</span>
      <span class="brain-pipeline-flow-tool">${escapeHtml(s.tool)}</span>
    </div>
    ${i < template.steps.length - 1 ? '<div class="brain-pipeline-flow-connector"></div>' : ''}
  `).join('')}</div>`;

  // Build input fields
  const variableSet = new Set();
  (template.steps || []).forEach(s => {
    Object.values(s.map || {}).forEach(v => {
      if (typeof v === 'string') {
        for (const m of v.matchAll(/\{\{(\w+)\}\}/g)) {
          if (!m[1].startsWith('prev')) variableSet.add(m[1]);
        }
      }
    });
  });

  const inputs = $('#brainPipelineInputs');
  if (inputs) {
    if (variableSet.size === 0) {
      inputs.innerHTML = '<p class="text-small text-muted">No additional inputs needed. Click Run to start.</p>';
    } else {
      inputs.innerHTML = Array.from(variableSet).map(v => `
        <div class="brain-pipeline-input-group">
          <label>${capitalize(v.replace(/_/g, ' '))}</label>
          <input data-pipeline-var="${v}" placeholder="Enter ${v.replace(/_/g, ' ')}" class="input" />
        </div>
      `).join('');
    }
  }

  $('#brainPipelineResults')?.classList.add('hidden');
}

function closePipelineRunner() {
  $('#brainPipelineRunner')?.classList.add('hidden');
  currentPipelineTemplate = null;
}

async function runPipeline() {
  if (!currentPipelineTemplate) return;

  const btn = $('#brainPipelineRunBtn');
  if (btn) { btn.classList.add('loading'); btn.disabled = true; }

  // Gather variables
  const variables = {};
  $$('#brainPipelineInputs [data-pipeline-var]').forEach(input => {
    variables[input.dataset.pipelineVar] = input.value;
  });

  // Animate steps
  const stepEls = document.querySelectorAll('.brain-pipeline-flow-step');

  try {
    const data = await api('/api/ai/pipelines/run', {
      method: 'POST',
      body: JSON.stringify({ template_id: currentPipelineTemplate.id, variables }),
    });
    const result = data.item || {};

    // Mark steps as complete/error
    (result.steps || []).forEach((s, i) => {
      if (stepEls[i]) {
        stepEls[i].classList.add(s.status === 'completed' ? 'step-complete' : 'step-error');
      }
    });

    const resultsEl = $('#brainPipelineResults');
    if (resultsEl) {
      resultsEl.classList.remove('hidden');
      const steps = result.steps || [];
      resultsEl.innerHTML = `
        <div class="brain-pipeline-results-header">
          <h4>Results <span class="badge ${result.status === 'completed' ? 'text-success' : 'text-danger'}">${result.status}</span></h4>
        </div>
        ${steps.map(s => `
          <div class="brain-pipeline-result-step ${s.status === 'completed' ? 'result-success' : 'result-error'}">
            <div class="flex-between">
              <strong>${s.status === 'completed' ? '&#10003;' : '&#10007;'} Step ${s.step}: ${escapeHtml(s.label)}</strong>
              <span class="text-small text-muted">${s.duration_ms ? `${(s.duration_ms / 1000).toFixed(1)}s` : ''}</span>
            </div>
            ${s.status === 'completed' ? `<div class="brain-pipeline-output">${escapeHtml(typeof s.output === 'string' ? s.output : JSON.stringify(s.output, null, 2)).substring(0, 2000)}</div>` : ''}
            ${s.error ? `<div class="text-danger text-small mt-1">${escapeHtml(s.error)}</div>` : ''}
          </div>
        `).join('')}
        ${result.next_actions?.length ? `
          <div class="brain-pipeline-next-actions">
            <h4>Suggested Next Steps</h4>
            <div class="flex flex-wrap gap-1">
              ${result.next_actions.map(a => `<button class="btn btn-ai btn-sm" title="${escapeHtml(a.reason)}">${escapeHtml(a.tool)}</button>`).join('')}
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
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
  }
}

async function loadPipelineHistory() {
  try {
    const data = await api('/api/ai/pipelines/runs?limit=10');
    const items = data.items || [];
    const el = $('#brainPipelineHistory');
    if (!el) return;

    if (items.length === 0) {
      el.innerHTML = '<p class="text-muted text-small">No pipeline runs yet. Pick a template above to get started.</p>';
      return;
    }

    el.innerHTML = items.map(r => `
      <div class="brain-history-item">
        <span class="brain-history-status ${r.status === 'completed' ? 'status-success' : r.status === 'partial' ? 'status-warning' : 'status-error'}">
          ${r.status === 'completed' ? '&#10003;' : r.status === 'partial' ? '&#9888;' : '&#10007;'}
        </span>
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
      el.innerHTML = `
        <div class="brain-empty-state">
          <div class="brain-empty-icon">&#127919;</div>
          <p>No performance feedback yet. Publish AI-generated content, then click "Capture Performance" to start the feedback loop.</p>
          <button class="btn btn-ai btn-sm" id="brainCapturePerf2">&#127919; Capture Performance Now</button>
        </div>
      `;
      el.querySelector('#brainCapturePerf2')?.addEventListener('click', capturePerformance);
      return;
    }

    el.innerHTML = items.map(f => `
      <div class="brain-feedback-item">
        <div class="brain-feedback-metric">
          <span class="brain-feedback-value">${f.metric_value}</span>
          <span class="brain-feedback-name">${escapeHtml(f.metric_name)}</span>
        </div>
        <div class="brain-feedback-details">
          <strong>${escapeHtml(f.entity_type)} #${f.entity_id}</strong>
          ${f.feedback_note ? `<div class="text-small text-muted">${escapeHtml(f.feedback_note)}</div>` : ''}
          ${f.tool_name ? `<div class="text-small text-muted">Generated by: ${escapeHtml(f.tool_name)}</div>` : ''}
          <div class="text-small text-muted">${formatDateTime(f.created_at)}</div>
        </div>
      </div>
    `).join('');
  } catch (e) {
    // Silently fail
  }
}

async function capturePerformance() {
  const btn = $('#brainCapturePerf') || $('#brainCapturePerf2');
  if (btn) {
    btn.classList.add('loading');
    btn.disabled = true;
  }
  try {
    const data = await api('/api/ai/brain/capture-performance', { method: 'POST' });
    const captured = data.item?.captured || 0;
    success(`Captured performance data for ${captured} posts`);
    if (captured > 0) loadFeedback();
  } catch (e) {
    error(e.message);
  } finally {
    if (btn) {
      btn.classList.remove('loading');
      btn.disabled = false;
    }
  }
}

/* ================================================================== */
/*  AGENTS TAB                                                         */
/* ================================================================== */

async function loadAgentTypes() {
  try {
    const data = await api('/api/ai/agents/types');
    const items = data.items || [];
    const el = $('#agentTypesGrid');
    if (!el) return;

    if (items.length === 0) {
      el.innerHTML = '<p class="text-muted text-small">Agent system not available.</p>';
      return;
    }

    const iconMap = { search: '&#128269;', edit: '&#9998;', chart: '&#128202;', compass: '&#129517;', palette: '&#127912;' };

    el.innerHTML = items.map(t => `
      <div class="agent-type-card">
        <div class="agent-type-icon">${iconMap[t.icon] || '&#129302;'}</div>
        <div class="agent-type-info">
          <strong>${escapeHtml(t.label)}</strong>
          <p class="text-small text-muted mt-0">${escapeHtml(t.description)}</p>
          <div class="flex flex-wrap gap-1 mt-1">
            ${(t.capabilities || []).slice(0, 4).map(c => `<span class="badge text-small">${escapeHtml(c.replace(/_/g, ' '))}</span>`).join('')}
          </div>
        </div>
      </div>
    `).join('');
  } catch (e) {
    // Agent system may not be available
  }
}

async function createAgentTask(execute) {
  const goal = $('#agentGoalInput')?.value?.trim();
  if (!goal) { error('Please enter a goal for the agent task.'); return; }

  const context = $('#agentContextInput')?.value?.trim() || '';
  const autoApprove = $('#agentAutoApprove')?.checked || false;
  const btn = execute ? $('#agentCreateBtn') : $('#agentPlanOnlyBtn');

  btn.classList.add('loading');
  btn.disabled = true;

  try {
    const data = await api('/api/ai/agents/tasks', {
      method: 'POST',
      body: JSON.stringify({ goal, context, auto_approve: autoApprove }),
    });
    const task = data.item || {};
    currentAgentTaskId = task.id;
    success(`Task planned with ${task.steps_total || 0} steps`);

    // Clear inputs
    if ($('#agentGoalInput')) $('#agentGoalInput').value = '';
    if ($('#agentContextInput')) $('#agentContextInput').value = '';

    // Show workspace
    await openAgentWorkspace(task.id);

    // Auto-execute if requested
    if (execute) {
      await executeAllAgentSteps();
    }

    loadAgentHistory();
  } catch (e) {
    error('Failed to create task: ' + e.message);
  } finally {
    btn.classList.remove('loading');
    btn.disabled = false;
  }
}

async function openAgentWorkspace(taskId) {
  currentAgentTaskId = taskId;
  const workspace = $('#agentTaskWorkspace');
  if (!workspace) return;

  try {
    const data = await api(`/api/ai/agents/tasks/${taskId}`);
    const task = data.item || {};

    workspace.classList.remove('hidden');
    $('#agentTaskTitle').textContent = `Task #${task.id}`;
    $('#agentTaskGoal').textContent = task.goal || '';
    $('#agentTaskStatus').textContent = task.status || 'planned';
    $('#agentTaskStatus').className = 'badge ' + agentStatusClass(task.status);

    renderAgentStepTimeline(task);
    renderAgentStepOutput(task);
    updateAgentActions(task);
  } catch (e) {
    error('Failed to load task: ' + e.message);
  }
}

function renderAgentStepTimeline(task) {
  const el = $('#agentStepTimeline');
  if (!el) return;

  const steps = task.plan || [];
  const results = task.results || [];

  el.innerHTML = steps.map((step, i) => {
    const result = results[i];
    let statusClass = 'step-pending';
    let statusIcon = '&#9675;';
    if (result) {
      if (result.status === 'completed') { statusClass = 'step-complete'; statusIcon = '&#10003;'; }
      else if (result.status === 'rejected') { statusClass = 'step-rejected'; statusIcon = '&#8634;'; }
      else { statusClass = 'step-error'; statusIcon = '&#10007;'; }
    } else if (i === (task.steps_completed || 0) && task.status === 'running') {
      statusClass = 'step-active'; statusIcon = '&#9654;';
    }

    return `
      <div class="agent-step ${statusClass}" data-step="${i}">
        <div class="agent-step-marker">${statusIcon}</div>
        <div class="agent-step-content">
          <div class="agent-step-header">
            <strong>Step ${step.step || i + 1}: ${escapeHtml(step.title || '')}</strong>
            <span class="badge text-small">${escapeHtml(step.agent || '')}</span>
          </div>
          <p class="text-small text-muted mt-0">${escapeHtml((step.instruction || '').substring(0, 150))}</p>
          ${result?.duration_ms ? `<span class="text-small text-muted">${(result.duration_ms / 1000).toFixed(1)}s</span>` : ''}
        </div>
      </div>
    `;
  }).join('');

  // Click on completed steps to view output
  el.querySelectorAll('.agent-step.step-complete, .agent-step.step-rejected').forEach(stepEl => {
    stepEl.style.cursor = 'pointer';
    stepEl.addEventListener('click', () => {
      const idx = parseInt(stepEl.dataset.step);
      const result = results[idx];
      if (result) showStepOutput(result);
    });
  });
}

function showStepOutput(result) {
  const outputEl = $('#agentStepOutput');
  const contentEl = $('#agentStepOutputContent');
  if (!outputEl || !contentEl) return;

  outputEl.classList.remove('hidden');
  const output = typeof result.output === 'string' ? result.output : JSON.stringify(result.output, null, 2);
  contentEl.innerHTML = `
    <div class="flex-between mb-1">
      <strong>${escapeHtml(result.title || 'Step ' + result.step)}</strong>
      <span class="badge text-small">${escapeHtml(result.agent || '')}</span>
    </div>
    <div class="agent-output-text">${escapeHtml(output)}</div>
    ${result.human_feedback ? `<div class="text-small text-muted mt-1">Feedback: ${escapeHtml(result.human_feedback)}</div>` : ''}
    ${result.rejection_reason ? `<div class="text-small text-danger mt-1">Rejected: ${escapeHtml(result.rejection_reason)}</div>` : ''}
  `;
}

function renderAgentStepOutput(task) {
  const outputEl = $('#agentStepOutput');
  const approvalEl = $('#agentApprovalActions');
  if (!outputEl) return;

  if (task.status === 'awaiting_approval' && task.current_step_output) {
    outputEl.classList.remove('hidden');
    $('#agentStepOutputContent').innerHTML = `<div class="agent-output-text">${escapeHtml(task.current_step_output)}</div>`;
    approvalEl?.classList.remove('hidden');
  } else if (task.status === 'completed' && task.results?.length) {
    const last = task.results[task.results.length - 1];
    showStepOutput(last);
    approvalEl?.classList.add('hidden');
  } else {
    outputEl.classList.add('hidden');
    approvalEl?.classList.add('hidden');
  }
}

function updateAgentActions(task) {
  const nextBtn = $('#agentExecuteNextBtn');
  const allBtn = $('#agentExecuteAllBtn');
  const cancelBtn = $('#agentCancelBtn');

  const isFinished = task.status === 'completed' || task.status === 'cancelled';
  const isAwaiting = task.status === 'awaiting_approval';

  if (nextBtn) { nextBtn.disabled = isFinished; nextBtn.classList.toggle('hidden', isFinished || isAwaiting); }
  if (allBtn) { allBtn.disabled = isFinished; allBtn.classList.toggle('hidden', isFinished || isAwaiting); }
  if (cancelBtn) cancelBtn.classList.toggle('hidden', isFinished);
}

async function executeNextAgentStep() {
  if (!currentAgentTaskId) return;
  const btn = $('#agentExecuteNextBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    await api(`/api/ai/agents/tasks/${currentAgentTaskId}/execute`, { method: 'POST' });
    await openAgentWorkspace(currentAgentTaskId);
  } catch (e) {
    error('Execution failed: ' + e.message);
  } finally {
    btn.classList.remove('loading'); btn.disabled = false;
  }
}

async function executeAllAgentSteps() {
  if (!currentAgentTaskId) return;
  const btn = $('#agentExecuteAllBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    await api(`/api/ai/agents/tasks/${currentAgentTaskId}/execute-all`, { method: 'POST' });
    success('All steps executed');
    await openAgentWorkspace(currentAgentTaskId);
    loadAgentHistory();
  } catch (e) {
    error('Execution failed: ' + e.message);
  } finally {
    btn.classList.remove('loading'); btn.disabled = false;
  }
}

async function approveAgentStep() {
  if (!currentAgentTaskId) return;
  const feedback = $('#agentFeedbackInput')?.value?.trim() || '';
  const btn = $('#agentApproveBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    await api(`/api/ai/agents/tasks/${currentAgentTaskId}/approve`, {
      method: 'POST',
      body: JSON.stringify({ feedback }),
    });
    if ($('#agentFeedbackInput')) $('#agentFeedbackInput').value = '';
    success('Step approved');
    await openAgentWorkspace(currentAgentTaskId);
  } catch (e) {
    error('Approval failed: ' + e.message);
  } finally {
    btn.classList.remove('loading'); btn.disabled = false;
  }
}

async function rejectAgentStep() {
  if (!currentAgentTaskId) return;
  const reason = $('#agentFeedbackInput')?.value?.trim() || '';
  const btn = $('#agentRejectBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    await api(`/api/ai/agents/tasks/${currentAgentTaskId}/reject`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    });
    if ($('#agentFeedbackInput')) $('#agentFeedbackInput').value = '';
    success('Step rejected — revising');
    await openAgentWorkspace(currentAgentTaskId);
  } catch (e) {
    error('Rejection failed: ' + e.message);
  } finally {
    btn.classList.remove('loading'); btn.disabled = false;
  }
}

async function cancelAgentTask() {
  if (!currentAgentTaskId) return;
  try {
    await api(`/api/ai/agents/tasks/${currentAgentTaskId}/cancel`, { method: 'POST' });
    success('Task cancelled');
    await openAgentWorkspace(currentAgentTaskId);
    loadAgentHistory();
  } catch (e) {
    error(e.message);
  }
}

function closeAgentWorkspace() {
  $('#agentTaskWorkspace')?.classList.add('hidden');
  $('#agentStepOutput')?.classList.add('hidden');
  currentAgentTaskId = null;
}

async function loadAgentHistory() {
  try {
    const data = await api('/api/ai/agents/tasks?limit=15');
    const items = data.items || [];
    const el = $('#agentTaskHistory');
    if (!el) return;

    if (items.length === 0) {
      el.innerHTML = '<p class="text-muted text-small">No agent tasks yet. Describe a goal above to get started.</p>';
      return;
    }

    el.innerHTML = items.map(t => `
      <div class="brain-history-item" data-agent-task-id="${t.id}" style="cursor:pointer">
        <span class="brain-history-status ${agentStatusClass(t.status)}">${agentStatusIcon(t.status)}</span>
        <div style="flex:1;min-width:0">
          <strong class="text-small" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(t.goal)}</strong>
        </div>
        <span class="text-small text-muted">${t.steps_completed || 0}/${t.steps_total || 0}</span>
        <span class="badge text-small ${agentStatusClass(t.status)}">${t.status}</span>
        <span class="text-small text-muted">${formatDateTime(t.created_at)}</span>
      </div>
    `).join('');

    el.querySelectorAll('[data-agent-task-id]').forEach(row => {
      row.addEventListener('click', () => openAgentWorkspace(parseInt(row.dataset.agentTaskId)));
    });
  } catch (e) {
    // Silently fail
  }
}

function agentStatusClass(status) {
  const map = { completed: 'status-success', running: 'status-warning', planned: 'status-info', awaiting_approval: 'status-warning', cancelled: 'status-error', failed: 'status-error' };
  return map[status] || '';
}

function agentStatusIcon(status) {
  const map = { completed: '&#10003;', running: '&#9654;', planned: '&#9679;', awaiting_approval: '&#9888;', cancelled: '&#10007;', failed: '&#10007;' };
  return map[status] || '&#9679;';
}

/* ================================================================== */
/*  SEARCH TAB                                                         */
/* ================================================================== */

async function executeSearch() {
  const query = $('#searchQueryInput')?.value?.trim();
  if (!query) { error('Please enter a search query.'); return; }

  const sources = [];
  if ($('#searchSourceInternal')?.checked) sources.push('internal');
  if ($('#searchSourceWeb')?.checked) sources.push('web');
  if ($('#searchSourceWebsite')?.checked) sources.push('website');

  if (sources.length === 0) { error('Select at least one search source.'); return; }

  const url = $('#searchUrlInput')?.value?.trim() || '';
  if (sources.includes('website') && !url) { error('Enter a URL for website analysis.'); return; }

  const btn = $('#searchExecuteBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    const data = await api('/api/ai/search', {
      method: 'POST',
      body: JSON.stringify({ query, sources, url }),
    });
    const result = data.item || {};

    const resultsEl = $('#searchResults');
    if (resultsEl) resultsEl.classList.remove('hidden');

    // Count
    const countEl = $('#searchResultsCount');
    if (countEl) countEl.textContent = `${result.total_results || 0} result${(result.total_results || 0) !== 1 ? 's' : ''} from ${sources.join(', ')}`;

    // Synthesis
    const synthEl = $('#searchSynthesis');
    if (synthEl) {
      synthEl.innerHTML = result.summary
        ? `<div class="search-synthesis-text">${escapeHtml(result.summary)}</div>`
        : '<p class="text-muted">No synthesis available.</p>';
    }

    // Internal results
    const internalEl = $('#searchInternalResults');
    const internalList = $('#searchInternalList');
    if (internalEl && internalList) {
      const internalData = result.sources?.internal?.results || [];
      if (internalData.length > 0) {
        internalEl.classList.remove('hidden');
        internalList.innerHTML = internalData.map(r => {
          const d = r.data || {};
          const title = d.title || d.name || d.subject || d.memory_key || d.insight || d.email || 'Untitled';
          return `
            <div class="brain-history-item">
              <span class="badge text-small">${escapeHtml(r.type)}</span>
              <strong style="flex:1">${escapeHtml(typeof title === 'string' ? title.substring(0, 120) : '')}</strong>
              ${d.status ? `<span class="text-small text-muted">${escapeHtml(d.status)}</span>` : ''}
            </div>
          `;
        }).join('');
      } else {
        internalEl.classList.add('hidden');
      }
    }

    // Web results
    const webEl = $('#searchWebResults');
    const webContent = $('#searchWebContent');
    if (webEl && webContent) {
      const webData = result.sources?.web;
      if (webData?.research) {
        webEl.classList.remove('hidden');
        webContent.innerHTML = `<div class="search-web-text">${escapeHtml(webData.research)}</div>`;
      } else {
        webEl.classList.add('hidden');
      }
    }

    // Website results
    const siteEl = $('#searchWebsiteResults');
    const siteContent = $('#searchWebsiteContent');
    if (siteEl && siteContent) {
      const siteData = result.sources?.website;
      if (siteData?.analysis) {
        siteEl.classList.remove('hidden');
        siteContent.innerHTML = `
          <div class="flex-between mb-1">
            <strong>${escapeHtml(siteData.title || siteData.url || '')}</strong>
            <span class="text-small text-muted">${siteData.word_count || 0} words</span>
          </div>
          ${siteData.description ? `<p class="text-small text-muted">${escapeHtml(siteData.description)}</p>` : ''}
          <div class="search-website-text">${escapeHtml(siteData.analysis)}</div>
        `;
      } else if (siteData?.error) {
        siteEl.classList.remove('hidden');
        siteContent.innerHTML = `<p class="text-danger">${escapeHtml(siteData.error)}</p>`;
      } else {
        siteEl.classList.add('hidden');
      }
    }

    success('Search complete');
    loadSearchHistory();
  } catch (e) {
    error('Search failed: ' + e.message);
  } finally {
    btn.classList.remove('loading'); btn.disabled = false;
  }
}

async function loadSearchHistory() {
  try {
    const data = await api('/api/ai/search/history?limit=15');
    const items = data.items || [];
    const el = $('#searchHistory');
    if (!el) return;

    if (items.length === 0) {
      el.innerHTML = '<p class="text-muted text-small">No searches yet. Try a search above.</p>';
      return;
    }

    el.innerHTML = items.map(s => `
      <div class="brain-history-item" style="cursor:pointer" data-search-query="${escapeHtml(s.query)}">
        <span class="brain-history-status status-info">&#128269;</span>
        <div style="flex:1;min-width:0">
          <strong class="text-small" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(s.query)}</strong>
          ${s.summary ? `<span class="text-small text-muted">${escapeHtml(s.summary.substring(0, 100))}</span>` : ''}
        </div>
        <span class="badge text-small">${escapeHtml(s.sources || 'internal')}</span>
        <span class="text-small text-muted">${s.results_count || 0} results</span>
        <span class="text-small text-muted">${formatDateTime(s.created_at)}</span>
      </div>
    `).join('');

    // Click to re-run search
    el.querySelectorAll('[data-search-query]').forEach(row => {
      row.addEventListener('click', () => {
        const input = $('#searchQueryInput');
        if (input) { input.value = row.dataset.searchQuery; }
      });
    });
  } catch (e) {
    // Silently fail
  }
}

/* ================================================================== */
/*  MODELS TAB                                                         */
/* ================================================================== */

let modelRoutingData = null;

async function loadModelRouting() {
  try {
    const data = await api('/api/ai/model-routing');
    modelRoutingData = data.item || {};

    renderModelRoutingGrid();
    populateTaskTypeSelect();
    populateProviderSelect();
  } catch (e) {
    // Silently fail
  }
}

function renderModelRoutingGrid() {
  const el = $('#modelRoutingGrid');
  if (!el || !modelRoutingData) return;

  const routing = modelRoutingData.routing || [];
  const taskTypes = modelRoutingData.task_types || {};

  if (routing.length === 0) {
    el.innerHTML = `
      <div class="brain-empty-state">
        <div class="brain-empty-icon">&#9881;</div>
        <p>No custom model routes configured. All tasks use your default provider.</p>
        <p class="text-small text-muted">Use the form below to assign specific providers to different task types.</p>
      </div>
    `;
    return;
  }

  el.innerHTML = routing.map(r => `
    <div class="model-route-card" data-route-type="${escapeHtml(r.task_type)}">
      <div class="flex-between">
        <div>
          <strong>${capitalize(r.task_type)}</strong>
          <p class="text-small text-muted mt-0">${escapeHtml(taskTypes[r.task_type] || '')}</p>
        </div>
        <button class="btn btn-ghost btn-sm text-danger" data-delete-route="${escapeHtml(r.task_type)}" title="Reset to default" aria-label="Reset route">&#10005;</button>
      </div>
      <div class="flex gap-1 mt-1">
        <span class="badge">${escapeHtml(r.provider)}</span>
        ${r.model ? `<span class="badge text-small">${escapeHtml(r.model)}</span>` : ''}
      </div>
      ${r.updated_at ? `<div class="text-small text-muted mt-1">${formatDateTime(r.updated_at)}</div>` : ''}
    </div>
  `).join('');

  // Wire delete buttons
  el.querySelectorAll('[data-delete-route]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      const taskType = btn.dataset.deleteRoute;
      try {
        await api(`/api/ai/model-routing/${taskType}`, { method: 'DELETE' });
        success(`Route for "${taskType}" reset to default`);
        loadModelRouting();
      } catch (err) { error(err.message); }
    });
  });

  // Click card to edit
  el.querySelectorAll('[data-route-type]').forEach(card => {
    card.style.cursor = 'pointer';
    card.addEventListener('click', () => {
      const type = card.dataset.routeType;
      const route = routing.find(r => r.task_type === type);
      if (route) {
        const typeSelect = $('#modelRoutingTaskType');
        const providerSelect = $('#modelRoutingProvider');
        if (typeSelect) typeSelect.value = type;
        if (providerSelect) {
          providerSelect.value = route.provider;
          onProviderChange();
          // Set model after provider options populate
          setTimeout(() => {
            const modelSelect = $('#modelRoutingModel');
            if (modelSelect) modelSelect.value = route.model || '';
          }, 50);
        }
      }
    });
  });
}

function populateTaskTypeSelect() {
  const select = $('#modelRoutingTaskType');
  if (!select || !modelRoutingData) return;

  const taskTypes = modelRoutingData.task_types || {};
  const current = select.value;

  // Keep the first placeholder option, replace the rest
  select.innerHTML = '<option value="">Select task type...</option>' +
    Object.entries(taskTypes).map(([key, desc]) =>
      `<option value="${escapeHtml(key)}">${capitalize(key)} — ${escapeHtml(desc)}</option>`
    ).join('');

  if (current) select.value = current;
}

function populateProviderSelect() {
  const select = $('#modelRoutingProvider');
  if (!select || !modelRoutingData) return;

  const providers = modelRoutingData.providers || {};
  const current = select.value;

  select.innerHTML = '<option value="">Default provider</option>' +
    Object.entries(providers)
      .filter(([, info]) => info.configured)
      .map(([key, info]) =>
        `<option value="${escapeHtml(key)}">${capitalize(key)}${info.model ? ` (${escapeHtml(info.model)})` : ''}</option>`
      ).join('');

  if (current) select.value = current;
}

function onProviderChange() {
  const providerSelect = $('#modelRoutingProvider');
  const modelSelect = $('#modelRoutingModel');
  if (!providerSelect || !modelSelect || !modelRoutingData) return;

  const provider = providerSelect.value;
  const providers = modelRoutingData.providers || {};
  const info = providers[provider];

  modelSelect.innerHTML = '<option value="">Default model</option>';

  if (info?.models?.length) {
    const labels = info.labels || {};
    info.models.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m;
      opt.textContent = labels[m] || m;
      modelSelect.appendChild(opt);
    });
  }
}

function onTaskTypeChange() {
  // Pre-select existing route values if editing
  const taskType = $('#modelRoutingTaskType')?.value;
  if (!taskType || !modelRoutingData) return;

  const existing = (modelRoutingData.routing || []).find(r => r.task_type === taskType);
  if (existing) {
    const providerSelect = $('#modelRoutingProvider');
    if (providerSelect) {
      providerSelect.value = existing.provider;
      onProviderChange();
      setTimeout(() => {
        const modelSelect = $('#modelRoutingModel');
        if (modelSelect) modelSelect.value = existing.model || '';
      }, 50);
    }
  }
}

async function saveModelRoute() {
  const taskType = $('#modelRoutingTaskType')?.value;
  const provider = $('#modelRoutingProvider')?.value;
  const model = $('#modelRoutingModel')?.value || '';

  if (!taskType) { error('Select a task type.'); return; }
  if (!provider) { error('Select a provider.'); return; }

  const btn = $('#modelRoutingSaveBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    await api('/api/ai/model-routing', {
      method: 'POST',
      body: JSON.stringify({ task_type: taskType, provider, model }),
    });
    success(`Model route saved for "${taskType}"`);
    await loadModelRouting();
  } catch (e) {
    error('Failed to save route: ' + e.message);
  } finally {
    btn.classList.remove('loading'); btn.disabled = false;
  }
}

async function deleteModelRoute() {
  const taskType = $('#modelRoutingTaskType')?.value;
  if (!taskType) { error('Select a task type to reset.'); return; }

  try {
    await api(`/api/ai/model-routing/${taskType}`, { method: 'DELETE' });
    success(`Route for "${taskType}" reset to default`);
    await loadModelRouting();
  } catch (e) {
    error(e.message);
  }
}

/* ================================================================== */
/*  DAILY BRIEFING                                                     */
/* ================================================================== */

async function loadDailyBriefing() {
  const el = $('#brainBriefingContent');
  if (!el) return;

  // Check cache — only regenerate once per 2 hours
  const cacheKey = 'brain_briefing_' + new Date().toISOString().slice(0, 13);
  const cached = sessionStorage.getItem(cacheKey);
  if (cached) {
    renderBriefing(JSON.parse(cached));
    return;
  }

  el.innerHTML = '<div class="brain-loading-pulse">Generating your daily briefing...</div>';

  try {
    const data = await api('/api/ai/brain/briefing');
    const briefing = data.item || {};
    if (briefing.error) {
      el.innerHTML = `<p class="text-muted text-small">${escapeHtml(briefing.error)}</p>`;
      return;
    }
    sessionStorage.setItem(cacheKey, JSON.stringify(briefing));
    renderBriefing(briefing);
  } catch (e) {
    el.innerHTML = '<p class="text-muted text-small">Briefing unavailable. Use AI tools to build context.</p>';
  }
}

function renderBriefing(briefing) {
  const el = $('#brainBriefingContent');
  if (!el) return;

  const priorityColors = { high: '#ef4444', medium: '#f59e0b', low: '#10b981' };
  const insightIcons = { opportunity: '&#128161;', warning: '&#9888;', celebration: '&#127881;', tip: '&#128218;' };

  el.innerHTML = `
    ${briefing.greeting ? `<div class="briefing-greeting">${escapeHtml(briefing.greeting)}</div>` : ''}

    ${briefing.priority_actions?.length ? `
      <div class="briefing-section">
        <h4 class="briefing-section-title">Priority Actions</h4>
        <div class="briefing-actions">
          ${briefing.priority_actions.map((a, i) => `
            <div class="briefing-action" data-action-idx="${i}">
              <div class="briefing-action-priority" style="--priority-color:${priorityColors[a.priority] || '#6b7280'}">${a.priority?.toUpperCase()}</div>
              <div class="briefing-action-content">
                <strong>${escapeHtml(a.title || '')}</strong>
                <p class="text-small text-muted mt-0">${escapeHtml(a.description || '')}</p>
              </div>
              <button class="btn btn-sm btn-ai briefing-do-btn" data-action-type="${escapeHtml(a.action_type || '')}" data-entity-type="${escapeHtml(a.entity_type || '')}" data-entity-id="${a.entity_id || ''}">Do It</button>
            </div>
          `).join('')}
        </div>
      </div>
    ` : ''}

    ${briefing.insights?.length ? `
      <div class="briefing-section">
        <h4 class="briefing-section-title">Insights</h4>
        ${briefing.insights.map(i => `
          <div class="briefing-insight">
            <span class="briefing-insight-icon">${insightIcons[i.type] || '&#128161;'}</span>
            <span>${escapeHtml(i.message || '')}</span>
          </div>
        `).join('')}
      </div>
    ` : ''}

    ${briefing.focus_areas?.length ? `
      <div class="briefing-section">
        <div class="briefing-focus">
          <span class="text-small text-muted">Focus today:</span>
          ${briefing.focus_areas.map(f => `<span class="badge">${escapeHtml(f)}</span>`).join('')}
        </div>
      </div>
    ` : ''}

    ${briefing.brain_growth_tip ? `
      <div class="briefing-tip">
        <span>&#129504;</span> <strong>Brain Tip:</strong> ${escapeHtml(briefing.brain_growth_tip)}
      </div>
    ` : ''}
  `;

  // Wire "Do It" buttons
  el.querySelectorAll('.briefing-do-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const actionType = btn.dataset.actionType;
      const actionMap = {
        'publish_draft': 'content', 'review_content': 'content', 'create_content': 'ai',
        'send_email': 'email', 'check_analytics': 'analytics', 'engage_audience': 'social',
        'run_campaign': 'campaigns', 'optimize_strategy': 'ai', 'review_scheduled': 'content',
        'analyze_performance': 'analytics',
      };
      navigate(actionMap[actionType] || 'dashboard');
    });
  });
}

/* ================================================================== */
/*  PROACTIVE RECOMMENDATIONS                                          */
/* ================================================================== */

async function loadRecommendations() {
  const el = $('#brainRecommendations');
  if (!el) return;

  // Cache for 4 hours
  const cacheKey = 'brain_recs_' + new Date().toISOString().slice(0, 13);
  const cached = sessionStorage.getItem(cacheKey);
  if (cached) {
    renderRecommendations(JSON.parse(cached));
    return;
  }

  el.innerHTML = '<div class="brain-loading-pulse">Analyzing your marketing data...</div>';

  try {
    const data = await api('/api/ai/brain/recommendations');
    const recs = data.items || [];
    if (recs.length > 0) {
      sessionStorage.setItem(cacheKey, JSON.stringify(recs));
    }
    renderRecommendations(recs);
  } catch (e) {
    el.innerHTML = '<p class="text-muted text-small">Recommendations unavailable.</p>';
  }
}

function renderRecommendations(recs) {
  const el = $('#brainRecommendations');
  if (!el) return;

  if (!recs.length) {
    el.innerHTML = '<p class="text-muted text-small">Use more AI tools to get personalized recommendations.</p>';
    return;
  }

  const typeIcons = { quick_win: '&#9889;', strategic: '&#128202;', experiment: '&#128300;', optimization: '&#9881;', growth: '&#128640;' };
  const impactColors = { high: '#ef4444', medium: '#f59e0b', low: '#10b981' };

  el.innerHTML = recs.map(r => `
    <div class="rec-card">
      <div class="rec-header">
        <span class="rec-type-icon">${typeIcons[r.type] || '&#128161;'}</span>
        <strong>${escapeHtml(r.title || '')}</strong>
        <div class="rec-badges">
          <span class="badge text-small" style="color:${impactColors[r.impact] || '#6b7280'}">${escapeHtml(r.impact || '')} impact</span>
          <span class="badge text-small">${escapeHtml(r.effort || '')} effort</span>
        </div>
      </div>
      <p class="text-small mt-0">${escapeHtml(r.description || '')}</p>
      <div class="rec-footer">
        <span class="badge text-small">${escapeHtml(r.category || '')}</span>
        ${r.suggested_tool ? `<button class="btn btn-ai btn-sm rec-execute-btn" data-rec-tool="${escapeHtml(r.suggested_tool)}">Run ${escapeHtml(r.suggested_tool)}</button>` : ''}
        ${r.auto_executable ? '<span class="text-small text-success">&#10003; AI can handle this</span>' : ''}
      </div>
    </div>
  `).join('');

  // Wire execute buttons
  el.querySelectorAll('.rec-execute-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      navigate('ai');
    });
  });
}

/* ================================================================== */
/*  KNOWLEDGE BASE                                                     */
/* ================================================================== */

async function loadKnowledgeBase() {
  const el = $('#brainKnowledgeContent');
  if (!el) return;

  try {
    const data = await api('/api/ai/brain/knowledge');
    const kb = data.item || {};
    renderKnowledgeBase(kb);
  } catch (e) {
    el.innerHTML = '<p class="text-muted text-small">Knowledge base unavailable.</p>';
  }
}

function renderKnowledgeBase(kb) {
  const el = $('#brainKnowledgeContent');
  if (!el) return;

  const categories = kb.categories || {};
  const completeness = kb.knowledge_completeness || {};
  const allCats = ['audience', 'content', 'strategy', 'performance', 'brand', 'competitor', 'channel', 'timing'];

  el.innerHTML = `
    <div class="kb-overview">
      <div class="kb-stat">
        <span class="kb-stat-value">${kb.total_learnings || 0}</span>
        <span class="kb-stat-label">Learnings</span>
      </div>
      <div class="kb-stat">
        <span class="kb-stat-value">${kb.total_memories || 0}</span>
        <span class="kb-stat-label">Memories</span>
      </div>
      <div class="kb-stat">
        <span class="kb-stat-value">${completeness.overall || 0}%</span>
        <span class="kb-stat-label">Complete</span>
      </div>
    </div>

    <div class="kb-categories">
      ${allCats.map(cat => {
        const data = categories[cat] || {};
        const score = completeness[cat] || 0;
        const count = data.count || 0;
        const strongest = data.strongest;

        return `
          <div class="kb-category ${count === 0 ? 'kb-gap' : score >= 60 ? 'kb-strong' : ''}">
            <div class="kb-cat-header">
              <span>${categoryEmoji(cat)} <strong>${capitalize(cat)}</strong></span>
              <span class="kb-cat-score">${score}%</span>
            </div>
            <div class="kb-cat-bar"><div class="kb-cat-bar-fill" style="width:${score}%"></div></div>
            ${count > 0 ? `
              <div class="text-small text-muted">${count} insight${count !== 1 ? 's' : ''}</div>
              ${strongest ? `<div class="text-small kb-cat-top">"${escapeHtml(strongest.insight?.substring(0, 100) || '')}"</div>` : ''}
            ` : '<div class="text-small text-muted">No knowledge yet</div>'}
          </div>
        `;
      }).join('')}
    </div>

    <div class="kb-add-learning mt-2">
      <h4>Teach the Brain</h4>
      <p class="text-small text-muted">Add knowledge manually — things the AI should always know about your business.</p>
      <div class="flex gap-1 mb-1">
        <select id="brainAddLearningCat" class="input" style="width:auto">
          ${allCats.map(c => `<option value="${c}">${capitalize(c)}</option>`).join('')}
        </select>
        <input id="brainAddLearningText" class="input" style="flex:1" placeholder="e.g., Our audience responds best to casual, story-driven content" />
        <button id="brainAddLearningBtn" class="btn btn-ai btn-sm">Add</button>
      </div>
    </div>
  `;

  // Re-wire the add learning button since it was re-rendered
  $('#brainAddLearningBtn')?.addEventListener('click', addManualLearning);
}

async function addManualLearning() {
  const cat = $('#brainAddLearningCat')?.value;
  const text = $('#brainAddLearningText')?.value?.trim();
  if (!cat || !text) { error('Please select a category and enter an insight.'); return; }

  const btn = $('#brainAddLearningBtn');
  if (btn) { btn.classList.add('loading'); btn.disabled = true; }

  try {
    await api('/api/ai/brain/learnings', {
      method: 'POST',
      body: JSON.stringify({ category: cat, insight: text, confidence: 0.9 }),
    });
    success('Knowledge added to the Brain!');
    if ($('#brainAddLearningText')) $('#brainAddLearningText').value = '';
    loadKnowledgeBase();
    loadLearnings();
  } catch (e) {
    error('Failed to add learning: ' + e.message);
  } finally {
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
  }
}

/* ================================================================== */
/*  BRAIN INITIALIZATION                                               */
/* ================================================================== */

async function initializeBrain() {
  const btn = $('#brainInitializeBtn');
  if (btn) { btn.classList.add('loading'); btn.disabled = true; }

  try {
    const data = await api('/api/ai/brain/initialize', { method: 'POST' });
    const result = data.item || {};
    success(`Brain initialized! Seeded ${result.seeded || 0} foundational learnings.`);
    refresh();
  } catch (e) {
    error('Initialization failed: ' + e.message);
  } finally {
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
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
    content: '#6366f1', strategy: '#8b5cf6', analysis: '#a855f7',
    conversation: '#3b82f6', pipeline: '#06b6d4', audience: '#f59e0b',
    brand: '#ec4899', competitor: '#ef4444', channel: '#10b981',
    timing: '#f97316', performance: '#14b8a6', general: '#6b7280',
  };
  return colors[cat] || '#6b7280';
}

function categoryEmoji(cat) {
  const emojis = {
    content: '&#9998;', strategy: '&#128202;', analysis: '&#128300;',
    conversation: '&#128172;', pipeline: '&#9889;', audience: '&#9823;',
    brand: '&#127912;', competitor: '&#9878;', channel: '&#128225;',
    timing: '&#9200;', performance: '&#127919;', general: '&#9733;',
  };
  return emojis[cat] || '&#9733;';
}
