/**
 * Login / session management.
 */

import { api, setCsrfToken, setApiToken } from '../core/api.js';
import { $, onSubmit } from '../core/utils.js';
import { error } from '../core/toast.js';
import { navigate, initRouter } from '../core/router.js';

function showApp() {
  $('page-login').style.display = 'none';
  $('appLayout').classList.remove('hidden');
  initRouter();
}

function showLogin() {
  $('page-login').style.display = '';
  $('appLayout').classList.add('hidden');
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
        showApp();
        return true;
      }
    } catch { /* ignore */ }
    showLogin();
    return false;
  }
}

export function init() {
  onSubmit('loginForm', async (e) => {
    const form = e.target;
    const username = form.querySelector('[name="username"]').value;
    const password = form.querySelector('[name="password"]').value;

    try {
      const data = await api('/api/login', {
        method: 'POST',
        body: JSON.stringify({ username, password }),
      });
      if (data.csrf_token) setCsrfToken(data.csrf_token);
      if (data.api_token) setApiToken(data.api_token);
      showApp();
    } catch (err) {
      error(err.message || 'Login failed');
    }
  });

  const logoutBtn = $('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      try { await api('/api/logout', { method: 'POST' }); } catch { /* ignore */ }
      setCsrfToken('');
      setApiToken('');
      showLogin();
    });
  }
}
