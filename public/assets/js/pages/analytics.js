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
  const cards = [
    ['Total Posts', posts.total ?? 0],
    ['Published', posts.published ?? 0],
    ['Scheduled', posts.scheduled ?? 0],
    ['Draft', posts.draft ?? 0],
  ];

  el.innerHTML = cards.map(([label, value]) =>
    `<div class="metric-card"><div class="metric-value">${escapeHtml(value)}</div><div class="metric-label">${label}</div></div>`
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

  const svg = `<svg viewBox="0 0 ${width} ${height}" class="chart-svg">
    <path d="${pathD}" fill="none" stroke="var(--primary)" stroke-width="2" />
    ${points.map((p) => `<circle cx="${p.x}" cy="${p.y}" r="3" fill="var(--primary)"><title>${escapeHtml(p.label)}: ${p.value}</title></circle>`).join('')}
    <text x="${width / 2}" y="${height - 5}" text-anchor="middle" class="chart-label">${escapeHtml(label)}</text>
  </svg>`;

  container.innerHTML = svg;
}

export async function refresh() {
  const days = parseInt($('analyticsPeriod')?.value || '30');
  try {
    const overview = await api(`/api/analytics/overview?days=${days}`);
    renderMetrics(overview);

    // Load chart data
    const chartEl = $('analyticsCharts');
    if (chartEl) {
      try {
        const { data } = await api(`/api/analytics/chart/posts_by_day?days=${days}`);
        renderChart(chartEl, data, 'Posts by Day');
      } catch {
        chartEl.innerHTML = '<p class="text-muted">No chart data available</p>';
      }
    }
  } catch (err) {
    error('Failed to load analytics: ' + err.message);
  }
}

export function init() {
  const period = $('analyticsPeriod');
  if (period) period.addEventListener('change', refresh);

  // CSV exports
  window.exportCsv = async function (type) {
    try {
      const response = await api(`/api/analytics/export/${type}`);
      // response is a raw Response for non-JSON
      const blob = await response.blob();
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
}
