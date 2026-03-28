/**
 * Analytics page — overview metrics, charts (SVG), CSV exports.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, onClick } from '../core/utils.js';
import { success, error } from '../core/toast.js';

function renderMetrics(data) {
  const el = $('analyticsMetrics');
  if (!el) return;

  const posts = data.posts || {};
  const campaigns = data.campaigns?.total ?? 0;
  const aiUsage = data.ai_usage || {};
  const cards = [
    ['Total Posts', posts.total ?? 0, '&#9998;'],
    ['Published', posts.published ?? 0, '&#10003;'],
    ['Scheduled', posts.scheduled ?? 0, '&#128197;'],
    ['Drafts', posts.drafts ?? 0, '&#128221;'],
    ['Campaigns', campaigns, '&#9776;'],
    ['AI Ideas', aiUsage.ideas_count ?? 0, '&#128161;'],
  ];

  el.innerHTML = cards.map(([label, value, icon]) =>
    `<div class="metric-card"><div class="metric-icon">${icon}</div><div class="metric-value">${escapeHtml(value)}</div><div class="metric-label">${label}</div></div>`
  ).join('');
}

function renderChart(container, data, label) {
  if (!container || !data || !data.length) {
    if (container) container.innerHTML = '<p class="text-muted">No chart data available</p>';
    return;
  }

  const width = 600, height = 200, pad = 40;
  const maxVal = Math.max(...data.map((d) => d.value), 1);
  const stepX = (width - pad * 2) / Math.max(data.length - 1, 1);

  const points = data.map((d, i) => {
    const x = pad + i * stepX;
    const y = height - pad - ((d.value / maxVal) * (height - pad * 2));
    return { x, y, ...d };
  });

  const pathD = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');

  // Area fill
  const areaD = pathD + ` L${points[points.length - 1].x},${height - pad} L${points[0].x},${height - pad} Z`;

  const svg = `<svg viewBox="0 0 ${width} ${height}" class="chart-svg" style="width:100%;max-width:${width}px">
    <defs>
      <linearGradient id="chartGrad" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="var(--accent)" stop-opacity="0.3"/>
        <stop offset="100%" stop-color="var(--accent)" stop-opacity="0.02"/>
      </linearGradient>
    </defs>
    <path d="${areaD}" fill="url(#chartGrad)" />
    <path d="${pathD}" fill="none" stroke="var(--accent)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
    ${points.map((p) => `<circle cx="${p.x}" cy="${p.y}" r="4" fill="var(--accent)" stroke="var(--panel)" stroke-width="2"><title>${escapeHtml(p.label)}: ${p.value}</title></circle>`).join('')}
    <text x="${width / 2}" y="${height - 5}" text-anchor="middle" fill="var(--text-muted)" font-size="12">${escapeHtml(label)}</text>
  </svg>`;

  container.innerHTML = svg;
}

async function loadChannelAttribution() {
  const el = $('channelAttribution');
  if (!el) return;
  try {
    const overview = await api('/api/analytics/overview?days=30');
    const platforms = overview.by_platform || [];
    if (platforms.length === 0) {
      el.innerHTML = '<p class="text-muted text-small">No platform data yet. Publish content to see attribution.</p>';
      return;
    }
    const total = platforms.reduce((sum, p) => sum + p.count, 0);
    el.innerHTML = `<div class="activity-bar-chart">${platforms.map(p => {
      const pct = Math.round((p.count / total) * 100);
      return `<div class="activity-bar-row">
        <span class="activity-bar-label">${escapeHtml(p.platform)}</span>
        <div class="activity-bar-track">
          <div class="activity-bar-fill" style="width:${pct}%"></div>
        </div>
        <span class="activity-bar-count">${pct}% (${p.count})</span>
      </div>`;
    }).join('')}</div>`;
  } catch (_) {
    el.innerHTML = '<p class="text-muted text-small">Attribution data unavailable.</p>';
  }
}

function renderPlatformBreakdown(data) {
  const posts = data.by_platform || [];
  const el = $('analyticsCharts');
  if (!el || posts.length === 0) return;

  const maxCount = Math.max(...posts.map(p => p.count), 1);
  const platformColors = {
    instagram: '#E4405F', facebook: '#1877F2', twitter: '#1DA1F2', linkedin: '#0A66C2',
    bluesky: '#0085FF', mastodon: '#6364FF', tiktok: '#000000', youtube: '#FF0000',
    pinterest: '#BD081C', reddit: '#FF4500', telegram: '#0088CC', discord: '#5865F2',
  };

  const barsHtml = posts.map(p => {
    const color = platformColors[p.platform] || 'var(--accent)';
    const pct = Math.max(5, Math.round((p.count / maxCount) * 100));
    return `<div class="activity-bar-row">
      <span class="activity-bar-label">${escapeHtml(p.platform)}</span>
      <div class="activity-bar-track">
        <div class="activity-bar-fill" style="width:${pct}%;background:${color}"></div>
      </div>
      <span class="activity-bar-count">${p.count}</span>
    </div>`;
  }).join('');

  // Append after chart
  const breakdown = document.createElement('div');
  breakdown.className = 'card mt-1';
  breakdown.innerHTML = `<h3>Posts by Platform</h3><div class="activity-bar-chart">${barsHtml}</div>`;
  el.parentElement?.insertBefore(breakdown, el.nextSibling);
}

export async function refresh() {
  // Remove any previously rendered platform breakdown
  document.querySelectorAll('#page-analytics .card.mt-1').forEach(el => {
    if (el.querySelector('.activity-bar-chart') && !el.querySelector('[onclick]')) el.remove();
  });

  const days = parseInt($('analyticsPeriod')?.value || '30');
  try {
    const overview = await api(`/api/analytics/overview?days=${days}`);
    renderMetrics(overview);
    renderPlatformBreakdown(overview);

    // Load chart data
    const chartEl = $('analyticsCharts');
    if (chartEl) {
      try {
        const { data } = await api(`/api/analytics/chart/posts_by_day?days=${days}`);
        renderChart(chartEl, data, 'Posts by Day');
      } catch (err) {
        chartEl.innerHTML = '<p class="text-muted">No chart data available</p>';
        error('Unable to load chart data: ' + err.message);
      }
    }
  } catch (err) {
    error('Failed to load analytics: ' + err.message);
  }

  // Channel attribution
  loadChannelAttribution();
}

export function init() {
  const period = $('analyticsPeriod');
  if (period) period.addEventListener('change', refresh);

  // CSV exports
  window.exportCsv = async function (type) {
    try {
      const response = await api(`/api/analytics/export/${type}`);
      // api() returns raw Response for non-JSON content types
      const text = await response.text();
      const blob = new Blob([text], { type: 'text/csv' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${type}-export.csv`;
      a.click();
      URL.revokeObjectURL(url);
      success(`${type} data exported`);
    } catch (err) {
      error('Export failed: ' + err.message);
    }
  };

  // AI Analytics Summary
  onClick('aiAnalyticsSummary', async () => {
    const btn = $('aiAnalyticsSummary');
    const results = $('aiAnalyticsResults');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    if (results) results.innerHTML = '<span class="text-muted">Generating AI analysis...</span>';
    try {
      const days = parseInt($('analyticsPeriod')?.value || '30');
      const { item } = await api('/api/ai/monthly-review', {
        method: 'POST',
        body: JSON.stringify({ days }),
      });
      if (results) {
        const review = item?.review || item?.raw || 'No analysis available';
        results.innerHTML = `<div class="ai-output text-small">${escapeHtml(typeof review === 'string' ? review : JSON.stringify(review, null, 2))}</div>`;
      }
      success('Analytics summary generated');
    } catch (err) {
      error(err.message);
      if (results) results.innerHTML = '<p class="text-muted">Configure an AI provider to generate analytics summaries.</p>';
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });
}
