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

export function init() {
  // Navigation buttons on dashboard
  window.navigate = navigate;
}
