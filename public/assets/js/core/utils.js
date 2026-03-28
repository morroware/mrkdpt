/**
 * Shared utility functions.
 */

export function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

export function formatDate(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatDateTime(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function truncate(str, len = 80) {
  str = String(str ?? '');
  return str.length > len ? str.slice(0, len) + '...' : str;
}

export function $(id) {
  return document.getElementById(id);
}

export function $$(selector, root = document) {
  return root.querySelectorAll(selector);
}

export function onSubmit(formId, handler) {
  const form = $(formId);
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    try {
      await handler(e);
    } finally {
      if (btn) btn.disabled = false;
    }
  });
}

export function onClick(id, handler) {
  const el = $(id);
  if (!el) return;
  el.addEventListener('click', handler);
}

export function formData(e) {
  return Object.fromEntries(new FormData(e.target).entries());
}

export function statusBadge(status) {
  const cls = {
    published: 'badge badge-published',
    scheduled: 'badge badge-scheduled',
    draft: 'badge badge-draft',
    failed: 'badge badge-failed',
    sent: 'badge badge-success',
    active: 'badge badge-active',
    paused: 'badge badge-paused',
    pending: 'badge badge-pending',
    unsubscribed: 'badge badge-muted',
  };
  return `<span class="${cls[status] || 'badge'}">${escapeHtml(status)}</span>`;
}

/**
 * Debounce a function call.
 */
export function debounce(fn, ms = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
}

/**
 * Copy text to clipboard with visual feedback on a button.
 */
export async function copyToClipboard(text, btn) {
  try {
    await navigator.clipboard.writeText(text);
    if (btn) {
      const original = btn.textContent;
      btn.textContent = 'Copied!';
      btn.classList.add('btn-copied');
      setTimeout(() => {
        btn.textContent = original;
        btn.classList.remove('btn-copied');
      }, 1500);
    }
    return true;
  } catch {
    // Fallback for older browsers
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
    if (btn) {
      const original = btn.textContent;
      btn.textContent = 'Copied!';
      btn.classList.add('btn-copied');
      setTimeout(() => {
        btn.textContent = original;
        btn.classList.remove('btn-copied');
      }, 1500);
    }
    return true;
  }
}

/**
 * Show a confirmation dialog. Returns a promise that resolves true/false.
 */
export function confirm(title, message, { icon = '&#9888;', okText = 'Confirm', okClass = 'btn-danger' } = {}) {
  return new Promise((resolve) => {
    const overlay = $('confirmDialog');
    if (!overlay) { resolve(window.confirm(message)); return; }
    $('confirmIcon').innerHTML = icon;
    $('confirmTitle').textContent = title;
    $('confirmMessage').textContent = message;
    const okBtn = $('confirmOk');
    okBtn.textContent = okText;
    okBtn.className = `btn ${okClass}`;
    overlay.classList.add('open');

    function cleanup(result) {
      overlay.classList.remove('open');
      $('confirmCancel').removeEventListener('click', onCancel);
      okBtn.removeEventListener('click', onOk);
      overlay.removeEventListener('click', onBg);
      resolve(result);
    }
    function onCancel() { cleanup(false); }
    function onOk() { cleanup(true); }
    function onBg(e) { if (e.target === overlay) cleanup(false); }

    $('confirmCancel').addEventListener('click', onCancel);
    okBtn.addEventListener('click', onOk);
    overlay.addEventListener('click', onBg);
  });
}

/**
 * Show a prompt input dialog. Returns a promise that resolves to the input value or null.
 */
export function promptInput(title, label, { defaultValue = '', placeholder = '', inputType = 'text' } = {}) {
  return new Promise((resolve) => {
    const overlay = $('confirmDialog');
    if (!overlay) { resolve(window.prompt(label, defaultValue)); return; }
    $('confirmIcon').innerHTML = '&#9998;';
    $('confirmTitle').textContent = title;
    // Replace the message paragraph with an input field
    const msgEl = $('confirmMessage');
    msgEl.innerHTML = `<label style="display:block;font-size:0.82rem;color:var(--text-secondary);margin-bottom:0.35rem">${escapeHtml(label)}</label><input type="${inputType}" id="promptDialogInput" value="${escapeHtml(defaultValue)}" placeholder="${escapeHtml(placeholder)}" style="width:100%" />`;
    const okBtn = $('confirmOk');
    okBtn.textContent = 'OK';
    okBtn.className = 'btn';
    overlay.classList.add('open');
    const input = $('promptDialogInput');
    if (input) { input.focus(); input.select(); }

    function cleanup(result) {
      overlay.classList.remove('open');
      $('confirmCancel').removeEventListener('click', onCancel);
      okBtn.removeEventListener('click', onOk);
      overlay.removeEventListener('click', onBg);
      if (input) input.removeEventListener('keydown', onKey);
      msgEl.textContent = '';
      resolve(result);
    }
    function onCancel() { cleanup(null); }
    function onOk() { cleanup(input ? input.value : null); }
    function onBg(e) { if (e.target === overlay) cleanup(null); }
    function onKey(e) { if (e.key === 'Enter') onOk(); if (e.key === 'Escape') onCancel(); }

    $('confirmCancel').addEventListener('click', onCancel);
    okBtn.addEventListener('click', onOk);
    overlay.addEventListener('click', onBg);
    if (input) input.addEventListener('keydown', onKey);
  });
}

/**
 * Show an info modal with rich text content. Returns a promise that resolves when closed.
 */
export function infoModal(title, content, { icon = '&#128196;' } = {}) {
  return new Promise((resolve) => {
    const overlay = $('confirmDialog');
    if (!overlay) { window.alert(content); resolve(); return; }
    $('confirmIcon').innerHTML = icon;
    $('confirmTitle').textContent = title;
    const msgEl = $('confirmMessage');
    msgEl.style.whiteSpace = 'pre-wrap';
    msgEl.style.textAlign = 'left';
    msgEl.style.maxHeight = '400px';
    msgEl.style.overflowY = 'auto';
    msgEl.style.fontSize = '0.85rem';
    msgEl.textContent = content;
    const okBtn = $('confirmOk');
    okBtn.textContent = 'Close';
    okBtn.className = 'btn';
    const cancelBtn = $('confirmCancel');
    cancelBtn.style.display = 'none';
    overlay.classList.add('open');

    function cleanup() {
      overlay.classList.remove('open');
      okBtn.removeEventListener('click', cleanup);
      overlay.removeEventListener('click', onBg);
      cancelBtn.style.display = '';
      msgEl.style.whiteSpace = '';
      msgEl.style.textAlign = '';
      msgEl.style.maxHeight = '';
      msgEl.style.overflowY = '';
      msgEl.style.fontSize = '';
      resolve();
    }
    function onBg(e) { if (e.target === overlay) cleanup(); }

    okBtn.addEventListener('click', cleanup);
    overlay.addEventListener('click', onBg);
  });
}

/**
 * Render an empty state placeholder.
 */
export function emptyState(icon, title, description, actionHtml = '') {
  return `<div class="empty-state">
    <div class="empty-state-icon">${icon}</div>
    <h4>${escapeHtml(title)}</h4>
    <p>${escapeHtml(description)}</p>
    ${actionHtml}
  </div>`;
}

/**
 * Render a table empty row.
 */
export function tableEmpty(colspan, message = 'No data found') {
  return `<tr class="table-empty"><td colspan="${colspan}">${escapeHtml(message)}</td></tr>`;
}
