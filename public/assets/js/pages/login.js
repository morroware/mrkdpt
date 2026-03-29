/**
 * Login / session management.
 */

import { api, setCsrfToken, setApiToken } from '../core/api.js';
import { $, onSubmit, confirm } from '../core/utils.js';
import { error } from '../core/toast.js';
import { initRouter } from '../core/router.js';

let onAuthenticated = null;
let routerInitialized = false;

function showApp() {
  $('page-login').style.display = 'none';
  $('appLayout').classList.remove('hidden');
  if (!routerInitialized) {
    initRouter();
    routerInitialized = true;
  }
}

function showLogin() {
  $('page-login').style.display = '';
  $('appLayout').classList.add('hidden');
}

/**
 * Register a callback that runs once after successful authentication.
 * Used by app.js to pass initAll() so page handlers bind after login.
 */
export function setOnAuthenticated(fn) {
  onAuthenticated = fn;
}

export async function checkSession() {
  try {
    const data = await api('/api/me');
    if (data.csrf_token) setCsrfToken(data.csrf_token);
    if (data.api_token) setApiToken(data.api_token);
    showApp();
    return true;
  } catch {
    // Check if setup is needed (no users yet)
    try {
      const status = await api('/api/setup-status');
      if (status.needs_setup) {
        // Show login page with setup message directing to install.php
        showLogin();
        const form = $('loginForm');
        if (form) {
          const banner = document.createElement('div');
          banner.className = 'setup-banner';
          banner.innerHTML = '<p><strong>Welcome!</strong> No admin account exists yet.</p>'
            + '<p>Please run the <a href="install.php">web installer</a> to complete setup.</p>';
          form.insertBefore(banner, form.firstChild);
        }
        return false;
      }
    } catch (err) { error('Failed to check setup status: ' + err.message); }
    showLogin();
    return false;
  }
}

export function init() {
  onSubmit('loginForm', async (e) => {
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const username = form.querySelector('[name="username"]')?.value || '';
    const password = form.querySelector('[name="password"]')?.value || '';

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Signing In...';
    }
    try {
      const data = await api('/api/login', {
        method: 'POST',
        body: JSON.stringify({ username, password }),
      });
      if (data.csrf_token) setCsrfToken(data.csrf_token);
      if (data.api_token) setApiToken(data.api_token);
      showApp();
      if (onAuthenticated) onAuthenticated();
    } catch (err) {
      let message = err.message || 'Login failed';
      const retryMatch = message.match(/retry in (\d+)s/i);
      if (retryMatch) {
        const seconds = Number(retryMatch[1]);
        const minutes = Math.ceil(seconds / 60);
        message = `Too many attempts. Try again in about ${minutes} minute${minutes === 1 ? '' : 's'}.`;
      }
      error(message);
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Sign In';
      }
    }
  });

  const logoutBtn = $('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      const ok = await confirm('Sign Out', 'Are you sure you want to sign out?', { icon: '&#128682;', okText: 'Sign Out', okClass: 'btn-outline' });
      if (!ok) return;
      try { await api('/api/logout', { method: 'POST' }); } catch (err) { error('Logout request failed: ' + err.message); }
      setCsrfToken('');
      setApiToken('');
      showLogin();
    });
  }
}
