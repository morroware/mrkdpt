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
