/**
 * API client — handles fetch calls, CSRF tokens, and auth headers.
 */

let csrfToken = '';
let apiToken = '';

export function setCsrfToken(token) {
  csrfToken = token;
}

export function setApiToken(token) {
  apiToken = token;
}

export function getCsrfToken() {
  return csrfToken;
}

export async function api(path, options = {}) {
  const headers = { ...(options.headers || {}) };

  // Don't set Content-Type for FormData (browser sets boundary automatically)
  if (!(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }

  if (csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }
  if (apiToken) {
    headers['Authorization'] = `Bearer ${apiToken}`;
  }

  const response = await fetch(path, { ...options, headers });

  // Handle non-JSON responses (CSV downloads, etc.)
  const ct = response.headers.get('Content-Type') || '';
  if (!ct.includes('application/json')) {
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    return response;
  }

  const data = await response.json();
  if (!response.ok) {
    throw new Error(data.error || `HTTP ${response.status}`);
  }
  return data;
}
