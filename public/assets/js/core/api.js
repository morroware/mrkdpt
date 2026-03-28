/**
 * API client — handles fetch calls, CSRF tokens, auth headers, and timeouts.
 */

// Derive the application base path from this module's URL.
// This module lives at <basePath>/assets/js/core/api.js, so we strip that suffix.
const _basePath = new URL(import.meta.url).pathname.replace(/\/assets\/js\/core\/api\.js$/, '') || '';

export function getBasePath() {
  return _basePath;
}

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

/**
 * @param {string} path - API endpoint path
 * @param {object} options - fetch options + optional `timeout` in ms (default 60000, AI calls 120000)
 */
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

  // Request timeout via AbortController
  const isAiCall = path.includes('/api/ai/');
  const timeoutMs = options.timeout || (isAiCall ? 120000 : 60000);
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  const url = path.startsWith('/') ? _basePath + path : path;
  let response;
  try {
    response = await fetch(url, { ...options, headers, signal: controller.signal });
  } catch (err) {
    clearTimeout(timeoutId);
    if (err.name === 'AbortError') {
      throw new Error(`Request timed out after ${Math.round(timeoutMs / 1000)}s`);
    }
    throw err;
  }
  clearTimeout(timeoutId);

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
    if (typeof data?.retry_after === 'number' && data.retry_after > 0) {
      throw new Error(`${data.error || `HTTP ${response.status}`} (retry in ${data.retry_after}s)`);
    }
    throw new Error(data.error || `HTTP ${response.status}`);
  }
  return data;
}
