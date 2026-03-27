/**
 * SEO Tools page — keyword research and blog generation.
 */

import { api } from '../core/api.js';
import { $, onClick } from '../core/utils.js';
import { success, error } from '../core/toast.js';

function output(text) {
  const el = $('seoOutput');
  if (el) el.textContent = typeof text === 'string' ? text : JSON.stringify(text, null, 2);
}

function loading() {
  output('Generating... please wait.');
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
}
