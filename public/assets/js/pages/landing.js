/**
 * Landing Pages page module.
 */
import { api, getBasePath } from '../core/api.js';
import { $, formatDate } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('landingPageForm')?.addEventListener('submit', handleCreate);
}

export async function refresh() {
  await Promise.all([loadPages(), loadFormOptions(), loadCampaignOptions()]);
}

async function loadPages() {
  try {
    const data = await api('/api/landing-pages');
    const el = $('landingPageList');
    if (!el) return;
    const base = window.location.origin + getBasePath();
    el.innerHTML = data.map(p => {
      const url = `${base}/p/${p.slug}`;
      const rate = p.views > 0 ? ((p.conversions / p.views) * 100).toFixed(1) : '0.0';
      return `<div class="card">
        <div class="flex-between"><h3>${esc(p.title)}</h3><span class="badge badge-${p.status === 'published' ? 'success' : 'muted'}">${p.status}</span></div>
        <p class="text-muted text-small mt-1">${esc(p.template)} template${p.campaign_name ? ' &middot; ' + esc(p.campaign_name) : ''}</p>
        <div class="row3 mt-1">
          <div><strong>${p.views}</strong><br><span class="text-muted text-small">Views</span></div>
          <div><strong>${p.conversions}</strong><br><span class="text-muted text-small">Conversions</span></div>
          <div><strong>${rate}%</strong><br><span class="text-muted text-small">Conv. Rate</span></div>
        </div>
        <div class="btn-group mt-1">
          ${p.status === 'published' ? `<a href="${url}" target="_blank" class="btn btn-sm btn-outline">View</a>` : ''}
          ${p.status === 'draft' ? `<button class="btn btn-sm btn-success" onclick="window._publishLanding(${p.id})">Publish</button>` : ''}
          <button class="btn btn-sm btn-outline" onclick="window._copyText('${url}')">Copy URL</button>
          <button class="btn btn-sm btn-danger" onclick="window._deleteLanding(${p.id})">Delete</button>
        </div>
      </div>`;
    }).join('') || '<p class="text-muted">No landing pages yet</p>';
  } catch {}
}

async function loadFormOptions() {
  try {
    const forms = await api('/api/forms');
    const sel = $('lpFormSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">None</option>' + forms.map(f => `<option value="${f.id}">${esc(f.name)}</option>`).join('');
  } catch {}
}

async function loadCampaignOptions() {
  try {
    const camps = await api('/api/campaigns');
    const sel = $('lpCampaignSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">None</option>' + camps.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  } catch {}
}

async function handleCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  try {
    await api('/api/landing-pages', { method: 'POST', body: JSON.stringify(data) });
    toast('Landing page created', 'success');
    e.target.reset();
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}

window._publishLanding = async (id) => {
  try {
    await api(`/api/landing-pages/${id}`, { method: 'PATCH', body: JSON.stringify({ status: 'published' }) });
    toast('Page published', 'success');
    refresh();
  } catch (err) { toast(err.message, 'error'); }
};

window._deleteLanding = async (id) => {
  if (!confirm('Delete this landing page?')) return;
  try { await api(`/api/landing-pages/${id}`, { method: 'DELETE' }); toast('Deleted', 'success'); refresh(); } catch (e) { toast(e.message, 'error'); }
};

window._copyText = window._copyText || ((text) => {
  navigator.clipboard.writeText(text).then(() => toast('Copied', 'info'));
});

function esc(s) { return (s || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/'/g, '&#39;'); }
