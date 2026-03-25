/**
 * Links & UTM Builder page module.
 */
import { api, getBasePath } from '../core/api.js';
import { $, formatDate } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('utmForm')?.addEventListener('submit', handleUtmCreate);
  $('shortLinkForm')?.addEventListener('submit', handleShortCreate);
}

export async function refresh() {
  await Promise.all([loadUtmLinks(), loadShortLinks()]);
}

async function loadUtmLinks() {
  try {
    const data = await api('/api/utm');
    const tb = $('utmTable');
    if (!tb) return;
    tb.innerHTML = data.map(l => `<tr>
      <td>${esc(l.campaign_name)}</td>
      <td>${esc(l.utm_source)}</td>
      <td>${esc(l.utm_medium)}</td>
      <td class="text-small" style="max-width:300px;overflow:hidden;text-overflow:ellipsis">${esc(l.full_url)}</td>
      <td><strong>${l.clicks}</strong></td>
      <td>
        <button class="btn btn-sm btn-outline" onclick="window._copyText('${esc(l.full_url)}')">Copy</button>
        <button class="btn btn-sm btn-danger" onclick="window._deleteUtm(${l.id})">Del</button>
      </td>
    </tr>`).join('');
  } catch {}
}

async function loadShortLinks() {
  try {
    const data = await api('/api/links');
    const tb = $('shortLinkTable');
    if (!tb) return;
    const base = window.location.origin + getBasePath();
    tb.innerHTML = data.map(l => {
      const shortUrl = `${base}/s/${l.code}`;
      return `<tr>
        <td><a href="${shortUrl}" target="_blank">${getBasePath()}/s/${esc(l.code)}</a></td>
        <td>${esc(l.title)}</td>
        <td class="text-small" style="max-width:250px;overflow:hidden;text-overflow:ellipsis">${esc(l.destination_url)}</td>
        <td><strong>${l.clicks}</strong></td>
        <td class="text-muted text-small">${formatDate(l.created_at)}</td>
        <td>
          <button class="btn btn-sm btn-outline" onclick="window._copyText('${shortUrl}')">Copy</button>
          <button class="btn btn-sm btn-danger" onclick="window._deleteShortLink(${l.id})">Del</button>
        </td>
      </tr>`;
    }).join('');
  } catch {}
}

async function handleUtmCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  data.create_short_link = fd.has('create_short_link');
  try {
    const result = await api('/api/utm', { method: 'POST', body: JSON.stringify(data) });
    toast('UTM link created', 'success');

    $('utmResult')?.classList.remove('hidden');
    $('utmUrl').textContent = result.full_url;

    const shortEl = $('utmShortUrl');
    if (shortEl && result.short_link) {
      const shortUrl = `${window.location.origin}${getBasePath()}/s/${result.short_link.code}`;
      shortEl.innerHTML = `<strong>Short URL:</strong> <span class="token-display">${shortUrl}</span>`;
    } else if (shortEl) {
      shortEl.innerHTML = '';
    }

    e.target.reset();
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

async function handleShortCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  if (!data.code) delete data.code;
  try {
    await api('/api/links', { method: 'POST', body: JSON.stringify(data) });
    toast('Short link created', 'success');
    e.target.reset();
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

window._copyText = (text) => {
  navigator.clipboard.writeText(text).then(() => toast('Copied to clipboard', 'info'));
};

window._deleteUtm = async (id) => {
  if (!confirm('Delete this UTM link?')) return;
  try { await api(`/api/utm/${id}`, { method: 'DELETE' }); toast('Deleted', 'success'); refresh(); } catch (e) { toast(e.message, 'error'); }
};

window._deleteShortLink = async (id) => {
  if (!confirm('Delete this short link?')) return;
  try { await api(`/api/links/${id}`, { method: 'DELETE' }); toast('Deleted', 'success'); refresh(); } catch (e) { toast(e.message, 'error'); }
};

function esc(s) { return (s || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/'/g, '&#39;'); }
