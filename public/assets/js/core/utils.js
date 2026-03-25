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
    published: 'badge badge-success',
    scheduled: 'badge badge-info',
    draft: 'badge',
    failed: 'badge badge-danger',
    sent: 'badge badge-success',
    active: 'badge badge-success',
    unsubscribed: 'badge badge-muted',
  };
  return `<span class="${cls[status] || 'badge'}">${escapeHtml(status)}</span>`;
}
