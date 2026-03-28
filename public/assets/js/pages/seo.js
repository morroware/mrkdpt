/**
 * SEO Tools page — keyword research and blog generation.
 */

import { api } from '../core/api.js';
import { $, onClick, escapeHtml } from '../core/utils.js';
import { success, error } from '../core/toast.js';

function output(text) {
  const el = $('seoOutput');
  if (!el) return;
  const raw = typeof text === 'string' ? text : JSON.stringify(text, null, 2);
  // Render as formatted content instead of raw text
  el.innerHTML = formatOutput(raw);
}

function formatOutput(text) {
  // Simple markdown-like rendering
  return escapeHtml(text)
    .replace(/^### (.+)$/gm, '<h3 style="margin:0.5rem 0 0.25rem;font-size:0.95rem;font-weight:700">$1</h3>')
    .replace(/^## (.+)$/gm, '<h2 style="margin:0.75rem 0 0.35rem;font-size:1.05rem;font-weight:700">$1</h2>')
    .replace(/^# (.+)$/gm, '<h1 style="margin:0.75rem 0 0.35rem;font-size:1.15rem;font-weight:700">$1</h1>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/^[*-] (.+)$/gm, '<li style="margin-left:1rem">$1</li>')
    .replace(/^\d+\. (.+)$/gm, '<li style="margin-left:1rem">$1</li>')
    .replace(/\n\n/g, '<br><br>')
    .replace(/\n/g, '<br>');
}

function loading() {
  const el = $('seoOutput');
  if (el) el.innerHTML = '<span class="text-muted" style="animation: subtlePulse 1.5s infinite">Generating... please wait.</span>';
}

export function refresh() {
  // Nothing to load
}

export function init() {
  onClick('seoRunKeywords', async () => {
    const btn = $('seoRunKeywords');
    loading();
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/seo-keywords', {
        method: 'POST',
        body: JSON.stringify({
          topic: $('seoTopic')?.value || '',
          niche: $('seoNiche')?.value || '',
        }),
      });
      output(item.keywords || item);
      success('Keywords generated');
    } catch (err) {
      output('Error: ' + err.message);
      error(err.message);
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });

  onClick('seoRunBlog', async () => {
    const btn = $('seoRunBlog');
    loading();
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/blog-post', {
        method: 'POST',
        body: JSON.stringify({
          title: $('seoBlogTitle')?.value || '',
          keywords: $('seoBlogKeywords')?.value || '',
          outline: $('seoBlogOutline')?.value || null,
        }),
      });
      output(item.post || item.content || item);
      success('Blog post generated');
    } catch (err) {
      output('Error: ' + err.message);
      error(err.message);
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });

  // SEO Opportunities
  onClick('seoRunOpportunities', async () => {
    const btn = $('seoRunOpportunities');
    loading();
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/seo-opportunities', {
        method: 'POST',
        body: JSON.stringify({ topic: $('seoOppTopic')?.value || '' }),
      });
      output(item?.opportunities || item?.raw || item);
      success('SEO opportunities found');
    } catch (err) {
      output('Error: ' + err.message);
      error(err.message);
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });

  // Content Freshness Check
  onClick('seoRunFreshness', async () => {
    const btn = $('seoRunFreshness');
    loading();
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/content-freshness', {
        method: 'POST',
        body: '{}',
      });
      output(item?.analysis || item?.raw || item);
      success('Content freshness check complete');
    } catch (err) {
      output('Error: ' + err.message);
      error(err.message);
    } finally {
      if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    }
  });
}
