/**
 * Lightweight toast notification system.
 */

const DURATION = 4000;

export function toast(message, type = 'info') {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = message;
  container.appendChild(el);

  // Trigger animation
  requestAnimationFrame(() => el.classList.add('toast-visible'));

  setTimeout(() => {
    el.classList.remove('toast-visible');
    el.addEventListener('transitionend', () => el.remove());
    // Fallback removal
    setTimeout(() => el.remove(), 500);
  }, DURATION);
}

export function success(msg) { toast(msg, 'success'); }
export function error(msg)   { toast(msg, 'error'); }
export function info(msg)    { toast(msg, 'info'); }
