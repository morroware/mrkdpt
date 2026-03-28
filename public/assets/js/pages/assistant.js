/**
 * AI Writing Assistant v2 — floating panel with diff preview, undo history,
 * keyboard shortcuts, and better UX.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, copyToClipboard } from '../core/utils.js';
import { success, error } from '../core/toast.js';

let activeTextarea = null;
let lastResult = '';
let lastOriginal = '';
let assistView = 'diff'; // 'diff' or 'result'
let history = []; // { action, original, result, time }
const MAX_HISTORY = 10;

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
      copyToClipboard(lastResult, copyBtn);
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

  // Undo button
  const undoBtn = $('aiAssistUndo');
  if (undoBtn) {
    undoBtn.addEventListener('click', () => {
      if (!lastOriginal || !activeTextarea) {
        error('Nothing to undo');
        return;
      }
      activeTextarea.value = lastOriginal;
      activeTextarea.dispatchEvent(new Event('input'));
      success('Reverted to original');
    });
  }

  // View toggle (diff vs result)
  panel.querySelectorAll('.ai-assist-view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      assistView = btn.dataset.assistView;
      panel.querySelectorAll('.ai-assist-view-btn').forEach(b => b.classList.toggle('active', b === btn));
      updateOutputView();
    });
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', (e) => {
    // Alt+A: toggle assistant
    if (e.altKey && e.key === 'a') {
      e.preventDefault();
      panel.classList.toggle('open');
      if (panel.classList.contains('open')) detectActiveTextarea();
      return;
    }
    // Only handle shortcuts when panel is open
    if (!panel.classList.contains('open')) return;

    if (e.altKey) {
      const shortcutMap = {
        'i': 'improve', 'e': 'expand', 's': 'shorten',
        'f': 'formal', 'c': 'casual', 'p': 'persuasive',
      };
      const action = shortcutMap[e.key];
      if (action) {
        e.preventDefault();
        runRefine(action);
      }
    }
  });
}

function detectActiveTextarea() {
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

/** Build diff view comparing original and result */
function buildDiffView(original, result) {
  const diffEl = $('aiAssistantDiff');
  if (!diffEl) return;

  const origLines = (original || '').split('\n');
  const resultLines = (result || '').split('\n');

  let html = '';

  // Simple line-by-line diff
  const maxLen = Math.max(origLines.length, resultLines.length);
  for (let i = 0; i < maxLen; i++) {
    const origLine = origLines[i];
    const newLine = resultLines[i];

    if (origLine === undefined) {
      // Added line
      html += `<div class="ai-assist-diff-line ai-assist-diff-added">+ ${escapeHtml(newLine)}</div>`;
    } else if (newLine === undefined) {
      // Removed line
      html += `<div class="ai-assist-diff-line ai-assist-diff-removed">- ${escapeHtml(origLine)}</div>`;
    } else if (origLine !== newLine) {
      // Changed line
      html += `<div class="ai-assist-diff-line ai-assist-diff-removed">- ${escapeHtml(origLine)}</div>`;
      html += `<div class="ai-assist-diff-line ai-assist-diff-added">+ ${escapeHtml(newLine)}</div>`;
    } else {
      // Unchanged
      html += `<div class="ai-assist-diff-line ai-assist-diff-unchanged">  ${escapeHtml(origLine)}</div>`;
    }
  }

  diffEl.innerHTML = html || '<div class="text-muted text-small p-1">No changes detected</div>';
}

function updateOutputView() {
  const diffEl = $('aiAssistantDiff');
  const resultEl = $('aiAssistantResult');
  if (assistView === 'diff') {
    if (diffEl) diffEl.classList.remove('hidden');
    if (resultEl) resultEl.classList.add('hidden');
  } else {
    if (diffEl) diffEl.classList.add('hidden');
    if (resultEl) resultEl.classList.remove('hidden');
  }
}

function showOutput(text, meta) {
  const output = $('aiAssistantOutput');
  const result = $('aiAssistantResult');
  const metaEl = $('aiAssistantOutputMeta');
  if (output) output.classList.remove('hidden');
  if (result) result.textContent = text;
  if (metaEl) metaEl.textContent = meta;
  lastResult = text;

  // Build diff from original
  buildDiffView(lastOriginal, text);
  updateOutputView();
}

function addToHistory(action, original, result) {
  history.unshift({
    action,
    original,
    result,
    time: new Date().toLocaleTimeString(),
  });
  if (history.length > MAX_HISTORY) history.pop();
  renderHistory();
}

function renderHistory() {
  const list = $('aiAssistHistoryList');
  if (!list) return;

  if (history.length === 0) {
    list.innerHTML = '<span class="text-small text-muted">No actions yet</span>';
    return;
  }

  list.innerHTML = history.map((h, i) => `
    <div class="ai-assist-history-item" data-history-idx="${i}">
      <span class="action-name">${escapeHtml(h.action)}</span>
      <span class="action-time">${h.time}</span>
    </div>
  `).join('');

  // Click to restore a past result
  list.querySelectorAll('.ai-assist-history-item').forEach(item => {
    item.addEventListener('click', () => {
      const idx = parseInt(item.dataset.historyIdx);
      const entry = history[idx];
      if (entry) {
        lastResult = entry.result;
        lastOriginal = entry.original;
        showOutput(entry.result, `Restored: ${entry.action} (${entry.time})`);
      }
    });
  });
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

  lastOriginal = content;

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
      addToHistory(action, content, item.content);
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

  lastOriginal = content;
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
      addToHistory('Tone Analysis', content, item.analysis);
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

  lastOriginal = content;
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
      addToHistory('Content Score', content, item.score);
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

  lastOriginal = content;
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
      addToHistory('Headlines', content, item.headlines);
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
