/**
 * AI Writing Assistant — floating panel for content refinement.
 * Accessible from any page via the floating action button.
 */

import { api } from '../core/api.js';
import { $, escapeHtml } from '../core/utils.js';
import { success, error } from '../core/toast.js';

let activeTextarea = null;
let lastResult = '';

export function init() {
  const panel = $('aiAssistantPanel');
  const fab = $('aiAssistantFab');
  if (!panel || !fab) return;

  // Toggle panel
  fab.addEventListener('click', () => {
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) {
      detectActiveTextarea();
    }
  });

  // Close/minimize
  const closeBtn = $('aiAssistantClose');
  const minBtn = $('aiAssistantMinimize');
  if (closeBtn) closeBtn.addEventListener('click', () => panel.classList.remove('open'));
  if (minBtn) minBtn.addEventListener('click', () => panel.classList.toggle('minimized'));

  // Track which textarea was last focused
  document.addEventListener('focusin', (e) => {
    if (e.target.tagName === 'TEXTAREA') {
      activeTextarea = e.target;
      updateContext();
    }
  });

  // Refine action buttons
  panel.querySelectorAll('[data-refine]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const action = btn.dataset.refine;
      runRefine(action);
    });
  });

  // Tone analysis
  const toneBtn = $('aiAssistTone');
  if (toneBtn) toneBtn.addEventListener('click', runToneAnalysis);

  // Score content
  const scoreBtn = $('aiAssistScore');
  if (scoreBtn) scoreBtn.addEventListener('click', runScoreContent);

  // Headline ideas
  const headlineBtn = $('aiAssistHeadlines');
  if (headlineBtn) headlineBtn.addEventListener('click', runHeadlines);

  // Custom instruction
  const customBtn = $('aiAssistCustomBtn');
  const customInput = $('aiAssistCustomInput');
  if (customBtn && customInput) {
    customBtn.addEventListener('click', () => {
      if (customInput.value.trim()) {
        runRefine('improve', customInput.value.trim());
      }
    });
    customInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && customInput.value.trim()) {
        runRefine('improve', customInput.value.trim());
      }
    });
  }

  // Copy result
  const copyBtn = $('aiAssistCopy');
  if (copyBtn) {
    copyBtn.addEventListener('click', () => {
      navigator.clipboard.writeText(lastResult).then(() => success('Copied!')).catch(() => {});
    });
  }

  // Apply result to field
  const applyBtn = $('aiAssistApply');
  if (applyBtn) {
    applyBtn.addEventListener('click', () => {
      if (activeTextarea && lastResult) {
        activeTextarea.value = lastResult;
        activeTextarea.dispatchEvent(new Event('input'));
        success('Applied to field');
      } else {
        error('No active text field to apply to');
      }
    });
  }
}

function detectActiveTextarea() {
  // Try to find the most recently focused textarea
  if (!activeTextarea) {
    const textareas = document.querySelectorAll('.page.active textarea');
    if (textareas.length > 0) {
      activeTextarea = textareas[0];
    }
  }
  updateContext();
}

function updateContext() {
  const ctx = $('aiAssistantContext');
  if (!ctx) return;

  if (activeTextarea) {
    const content = activeTextarea.value || '';
    const label = activeTextarea.name || activeTextarea.id || 'text field';
    const preview = content ? content.slice(0, 100) + (content.length > 100 ? '...' : '') : '(empty)';
    ctx.innerHTML = `<span class="badge badge-info">Active: ${escapeHtml(label)}</span> <span class="text-small text-muted">${escapeHtml(preview)}</span>`;
  } else {
    ctx.innerHTML = '<span class="text-small text-muted">Click on a text field first, then use AI actions</span>';
  }
}

function detectPlatform() {
  // Detect platform from current page context
  const activePage = document.querySelector('.page.active');
  if (!activePage) return 'general';
  const pageId = activePage.id || '';
  if (pageId.includes('email')) return 'email';
  if (pageId.includes('content') || pageId.includes('post')) {
    const platformSelect = activePage.querySelector('[name="platform"]');
    if (platformSelect?.value) return platformSelect.value;
  }
  if (pageId.includes('landing')) return 'landing_page';
  if (pageId.includes('seo') || pageId.includes('blog')) return 'blog';
  return 'general';
}

function getContent() {
  if (activeTextarea && activeTextarea.value.trim()) {
    return activeTextarea.value.trim();
  }
  return null;
}

function showOutput(text, meta) {
  const output = $('aiAssistantOutput');
  const result = $('aiAssistantResult');
  const metaEl = $('aiAssistantOutputMeta');
  if (output) output.classList.remove('hidden');
  if (result) result.textContent = text;
  if (metaEl) metaEl.textContent = meta;
  lastResult = text;
}

function setLoading(btn, loading) {
  if (!btn) return;
  if (loading) {
    btn.classList.add('loading');
    btn.disabled = true;
  } else {
    btn.classList.remove('loading');
    btn.disabled = false;
  }
}

async function runRefine(action, customContext) {
  const content = getContent();
  if (!content) {
    error('Select a text field with content first');
    return;
  }

  const panel = $('aiAssistantPanel');
  if (panel && !panel.classList.contains('open')) panel.classList.add('open');

  const btn = document.querySelector(`[data-refine="${action}"]`);
  setLoading(btn, true);
  showOutput('Generating...', `Action: ${action}`);

  try {
    const { item } = await api('/api/ai/refine', {
      method: 'POST',
      body: JSON.stringify({ content, action, context: customContext || null }),
    });
    if (item?.content) {
      showOutput(item.content, `${action} | Provider: ${item.provider || 'default'} | ${new Date().toLocaleTimeString()}`);
    }
  } catch (err) {
    showOutput('Error: ' + err.message, 'Failed');
    error(err.message);
  } finally {
    setLoading(btn, false);
  }
}

async function runToneAnalysis() {
  const content = getContent();
  if (!content) { error('Select a text field with content first'); return; }

  const btn = $('aiAssistTone');
  setLoading(btn, true);
  showOutput('Analyzing tone...', 'Tone Analysis');

  try {
    const { item } = await api('/api/ai/tone-analysis', {
      method: 'POST',
      body: JSON.stringify({ content }),
    });
    if (item?.analysis) {
      showOutput(item.analysis, `Tone Analysis | Provider: ${item.provider || 'default'}`);
    }
  } catch (err) {
    showOutput('Error: ' + err.message, 'Failed');
  } finally {
    setLoading(btn, false);
  }
}

async function runScoreContent() {
  const content = getContent();
  if (!content) { error('Select a text field with content first'); return; }

  const btn = $('aiAssistScore');
  setLoading(btn, true);
  showOutput('Scoring content...', 'Content Score');

  try {
    const { item } = await api('/api/ai/score', {
      method: 'POST',
      body: JSON.stringify({ content, platform: detectPlatform() }),
    });
    if (item?.score) {
      showOutput(item.score, `Content Score | Provider: ${item.provider || 'default'}`);
    }
  } catch (err) {
    showOutput('Error: ' + err.message, 'Failed');
  } finally {
    setLoading(btn, false);
  }
}

async function runHeadlines() {
  const content = getContent();
  if (!content) { error('Select a text field with content first'); return; }

  // Use first line as the headline to optimize
  const headline = content.split('\n')[0].slice(0, 200);
  const btn = $('aiAssistHeadlines');
  setLoading(btn, true);
  showOutput('Generating headline ideas...', 'Headline Optimizer');

  try {
    const { item } = await api('/api/ai/headlines', {
      method: 'POST',
      body: JSON.stringify({ headline, platform: detectPlatform() }),
    });
    if (item?.headlines) {
      showOutput(item.headlines, `Headlines | Provider: ${item.provider || 'default'}`);
    }
  } catch (err) {
    showOutput('Error: ' + err.message, 'Failed');
  } finally {
    setLoading(btn, false);
  }
}

export function refresh() {
  // No data to load for assistant
}
