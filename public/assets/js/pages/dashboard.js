/**
 * Dashboard page — metrics cards, recent posts/ideas, autopilot integration.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDateTime, truncate } from '../core/utils.js';
import { error, success } from '../core/toast.js';
import { navigate } from '../core/router.js';

let onboardingChecked = false;

export async function refresh() {
  // Check onboarding status on first load
  if (!onboardingChecked) {
    onboardingChecked = true;
    try {
      const status = await api('/api/onboarding/status');
      if (!status.onboarding_completed) {
        navigate('onboarding');
        return;
      }
    } catch (_) {
      // If endpoint fails (e.g. fresh install), continue to dashboard
    }
  }

  try {
    const [dashData, settings] = await Promise.all([
      api('/api/dashboard'),
      api('/api/settings'),
    ]);

    // Settings summary
    const summary = $('settingsSummary');
    if (summary) {
      summary.textContent = `${settings.business_name} \u2022 ${settings.business_industry} \u2022 ${settings.timezone}`;
    }
    const badge = $('providerBadge');
    if (badge) {
      badge.textContent = `Provider: ${settings.ai?.active_provider || '--'}`;
    }

    // Metrics
    const metrics = dashData.metrics || {};
    const cards = [
      ['Total Posts', metrics.posts ?? 0, ''],
      ['Scheduled', metrics.scheduled ?? 0, 'info'],
      ['Published', metrics.published ?? 0, 'success'],
      ['Avg AI Score', metrics.avg_score ?? 0, ''],
      ['Campaigns', dashData.campaigns ?? 0, ''],
    ];
    const metricsEl = $('dashboardMetrics');
    if (metricsEl) {
      metricsEl.innerHTML = cards.map(([label, value, cls]) =>
        `<div class="metric-card${cls ? ' metric-' + cls : ''}"><div class="metric-value">${escapeHtml(value)}</div><div class="metric-label">${label}</div></div>`
      ).join('');
    }

    // Recent posts
    const postsEl = $('recentPosts');
    if (postsEl) {
      const posts = dashData.recent_posts || [];
      postsEl.innerHTML = posts.length
        ? posts.map((p) =>
            `<div class="list-item"><strong>${escapeHtml(p.title)}</strong> <span class="badge">${escapeHtml(p.platform)}</span> <span class="badge">${escapeHtml(p.status)}</span><div class="text-small text-muted">${formatDateTime(p.scheduled_for || p.created_at)}</div></div>`
          ).join('')
        : '<p class="text-muted">No posts yet</p>';
    }

    // Recent ideas
    const ideasEl = $('recentIdeas');
    if (ideasEl) {
      const ideas = dashData.recent_ideas || [];
      ideasEl.innerHTML = ideas.length
        ? ideas.map((i) =>
            `<div class="list-item"><strong>${escapeHtml(i.topic)}</strong> <span class="badge">${escapeHtml(i.platform)}</span><div class="text-small text-muted">${escapeHtml(truncate(i.output, 120))}</div></div>`
          ).join('')
        : '<p class="text-muted">No ideas generated yet</p>';
    }
  } catch (err) {
    error('Failed to load dashboard: ' + err.message);
  }

  // Load autopilot summary and assets
  loadAutopilotSummary();
  loadAssets();

  // Auto-load AI insights on dashboard refresh
  loadAiInsights();
}

async function loadAutopilotSummary() {
  try {
    const { task } = await api('/api/autopilot/status?type=onboarding');
    const card = $('autopilotSummary');
    const content = $('autopilotSummaryContent');
    if (!card || !content || !task) return;

    if (task.status === 'completed') {
      const results = task.results || {};
      const steps = task.steps_config || [];
      const labels = task.step_labels || [];
      const errors = (task.error || '').trim();

      let html = '<div class="autopilot-results">';
      html += `<p class="text-small text-muted">Completed ${steps.length} steps</p>`;
      html += '<div class="autopilot-steps-summary">';
      for (let i = 0; i < steps.length; i++) {
        const key = steps[i];
        const hasResult = results[key] && results[key].length > 0;
        const icon = hasResult ? '&#10003;' : '&#10007;';
        const cls = hasResult ? 'step-ok' : 'step-warn';
        html += `<span class="autopilot-step-badge ${cls}" title="${escapeHtml(labels[i] || key)}">${icon} ${escapeHtml(labels[i] || key)}</span>`;
      }
      html += '</div>';
      if (errors) {
        html += `<details class="mt-1"><summary class="text-small text-muted">Some steps had issues</summary><pre class="text-small">${escapeHtml(errors)}</pre></details>`;
      }
      html += '</div>';

      content.innerHTML = html;
      card.style.display = '';
    }
  } catch (_) {
    // No autopilot data yet — that's fine
  }
}

async function loadAssets() {
  try {
    const { items } = await api('/api/autopilot/assets?status=pending_review');
    const card = $('aiAssetsCard');
    const list = $('aiAssetsList');
    const countBadge = $('assetCount');
    if (!card || !list) return;

    if (!items || items.length === 0) {
      card.style.display = 'none';
      return;
    }

    card.style.display = '';
    if (countBadge) countBadge.textContent = items.length;

    list.innerHTML = items.map(asset => `
      <div class="ai-asset-item" data-asset-id="${asset.id}">
        <div class="ai-asset-header">
          <span class="badge badge-info">${escapeHtml(asset.asset_type)}</span>
          <strong>${escapeHtml(asset.title)}</strong>
        </div>
        <div class="ai-asset-preview text-small">${escapeHtml(truncate(asset.content, 200))}</div>
        <div class="ai-asset-actions mt-1">
          <button class="btn btn-sm btn-ai asset-approve-btn" data-id="${asset.id}">Approve</button>
          <button class="btn btn-sm btn-ghost asset-reject-btn" data-id="${asset.id}">Dismiss</button>
          <button class="btn btn-sm btn-outline asset-expand-btn" data-id="${asset.id}">View Full</button>
        </div>
      </div>
    `).join('');

    // Wire asset action buttons
    list.querySelectorAll('.asset-approve-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = parseInt(btn.dataset.id);
        try {
          await api('/api/autopilot/assets/approve', { method: 'POST', body: JSON.stringify({ id }) });
          btn.closest('.ai-asset-item')?.remove();
          success('Asset approved');
          updateAssetCount(-1);
        } catch (e) { error(e.message); }
      });
    });

    list.querySelectorAll('.asset-reject-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = parseInt(btn.dataset.id);
        try {
          await api('/api/autopilot/assets/reject', { method: 'POST', body: JSON.stringify({ id }) });
          btn.closest('.ai-asset-item')?.remove();
          updateAssetCount(-1);
        } catch (e) { error(e.message); }
      });
    });

    list.querySelectorAll('.asset-expand-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const item = btn.closest('.ai-asset-item');
        const preview = item?.querySelector('.ai-asset-preview');
        if (!preview) return;
        const asset = items.find(a => a.id == btn.dataset.id);
        if (asset) {
          const isExpanded = preview.dataset.expanded === '1';
          preview.textContent = isExpanded ? truncate(asset.content, 200) : asset.content;
          preview.dataset.expanded = isExpanded ? '0' : '1';
          btn.textContent = isExpanded ? 'View Full' : 'Collapse';
        }
      });
    });
  } catch (_) {
    // No assets yet
  }
}

function updateAssetCount(delta) {
  const badge = $('assetCount');
  if (badge) {
    const count = Math.max(0, parseInt(badge.textContent || '0') + delta);
    badge.textContent = count;
    if (count === 0) {
      const card = $('aiAssetsCard');
      if (card) card.style.display = 'none';
    }
  }
}

async function loadAiInsights() {
  const list = $('aiInsightsList');
  if (!list) return;
  list.innerHTML = '<div class="flex gap-1"><div class="loading-spinner"></div> <span class="text-muted">Generating AI insights...</span></div>';
  try {
    const { item } = await api('/api/ai/insights', { method: 'POST', body: '{}' });
    if (item?.insights && Array.isArray(item.insights)) {
      const priorityColors = { high: 'danger', medium: 'warning', low: 'info' };
      const categoryIcons = { content: '&#9998;', engagement: '&#128172;', growth: '&#128200;', optimization: '&#9881;' };
      list.innerHTML = item.insights.map((ins) => `
        <div class="ai-insight-item">
          <div class="ai-insight-header">
            <span class="badge badge-${priorityColors[ins.priority] || 'info'}">${escapeHtml(ins.priority || 'medium')}</span>
            <span class="ai-insight-category">${categoryIcons[ins.category] || '&#9733;'} ${escapeHtml(ins.category || 'general')}</span>
          </div>
          <strong>${escapeHtml(ins.title || '')}</strong>
          <p class="text-small text-muted">${escapeHtml(ins.description || '')}</p>
          <div class="ai-insight-action text-small"><strong>Action:</strong> ${escapeHtml(ins.action || '')}</div>
        </div>
      `).join('');
    } else {
      list.innerHTML = '<p class="text-muted">No insights available. Configure your AI provider to enable insights.</p>';
    }
  } catch (err) {
    list.innerHTML = '<p class="text-muted">Configure an AI provider in settings to get AI-powered insights.</p>';
  }
}

export function init() {
  // Navigation buttons on dashboard
  window.navigate = navigate;

  // AI Quick Action buttons on dashboard
  document.querySelectorAll('.ai-quick-btn[data-quick-action]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const action = btn.dataset.quickAction;
      switch (action) {
        case 'ideas':
          navigate('ai');
          break;
        case 'post':
          navigate('content');
          setTimeout(() => document.querySelector('[data-tab="content-create"]')?.click(), 100);
          break;
        case 'blog':
          navigate('ai');
          setTimeout(() => {
            document.querySelector('.ai-cat-btn[data-ai-cat="creation"]')?.click();
            document.getElementById('aiBlogTitle')?.focus();
          }, 100);
          break;
        case 'email':
          navigate('email');
          setTimeout(() => document.querySelector('[data-tab="email-compose"]')?.click(), 100);
          break;
        case 'report':
          navigate('ai');
          setTimeout(() => {
            document.querySelector('.ai-cat-btn[data-ai-cat="analytics"]')?.click();
            document.getElementById('runWeeklyReport')?.click();
          }, 100);
          break;
        case 'calendar':
          navigate('ai');
          setTimeout(() => {
            document.querySelector('.ai-cat-btn[data-ai-cat="analytics"]')?.click();
            document.getElementById('aiCalendarGoal')?.focus();
          }, 100);
          break;
        case 'chat':
          navigate('chat');
          break;
        case 'standup':
          navigate('ai');
          setTimeout(() => {
            document.querySelector('.ai-cat-btn[data-ai-cat="analytics"]')?.click();
            document.getElementById('runStandup')?.click();
          }, 100);
          break;
        default:
          navigate('ai');
      }
    });
  });

  // AI Insights refresh button
  const refreshInsightsBtn = $('refreshAiInsights');
  if (refreshInsightsBtn) {
    refreshInsightsBtn.addEventListener('click', () => {
      refreshInsightsBtn.classList.add('loading');
      refreshInsightsBtn.disabled = true;
      loadAiInsights().finally(() => {
        refreshInsightsBtn.classList.remove('loading');
        refreshInsightsBtn.disabled = false;
      });
    });
  }

  // Dismiss autopilot summary
  const dismissBtn = $('dismissAutopilotSummary');
  if (dismissBtn) {
    dismissBtn.addEventListener('click', () => {
      const card = $('autopilotSummary');
      if (card) card.style.display = 'none';
    });
  }
}
