/**
 * Dashboard page — metrics cards, recent posts/ideas.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDateTime, truncate } from '../core/utils.js';
import { error } from '../core/toast.js';
import { navigate } from '../core/router.js';

export async function refresh() {
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
            `<div class="list-item"><strong>${escapeHtml(i.topic)}</strong> <span class="badge">${escapeHtml(i.platform)}</span><div class="text-small text-muted">${truncate(i.output, 120)}</div></div>`
          ).join('')
        : '<p class="text-muted">No ideas generated yet</p>';
    }
  } catch (err) {
    error('Failed to load dashboard: ' + err.message);
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
          // Switch to "Content Ideas" tool after nav
          break;
        case 'post':
          navigate('content');
          setTimeout(() => document.querySelector('[data-tab="content-create"]')?.click(), 100);
          break;
        case 'blog':
          navigate('ai');
          setTimeout(() => {
            // Filter to creation category and scroll to blog tool
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
}
