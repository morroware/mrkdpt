/**
 * Links & UTM Builder page module.
 */
import { api, getBasePath } from '../core/api.js';
import { $, escapeHtml, formatDate } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('utmForm')?.addEventListener('submit', handleUtmCreate);
  $('shortLinkForm')?.addEventListener('submit', handleShortCreate);

  // AI Smart UTM
  const aiUtmBtn = $('aiSmartUtm');
  if (aiUtmBtn) {
    aiUtmBtn.addEventListener('click', async () => {
      const form = $('utmForm');
      if (!form) return;
      const campaignName = form.querySelector('[name="campaign_name"]')?.value || '';
      const baseUrl = form.querySelector('[name="base_url"]')?.value || '';
      if (!campaignName || !baseUrl) { toast('Enter campaign name and URL first', 'error'); return; }

      aiUtmBtn.classList.add('loading'); aiUtmBtn.disabled = true;
      try {
        const { item } = await api('/api/ai/smart-utm', {
          method: 'POST',
          body: JSON.stringify({
            campaign_name: campaignName,
            url: baseUrl,
            channel: form.querySelector('[name="utm_source"]')?.value || '',
            description: form.querySelector('[name="utm_content"]')?.value || '',
          }),
        });
        if (item?.utm) {
          const u = item.utm;
          const fill = (name, val) => { const el = form.querySelector(`[name="${name}"]`); if (el && val) el.value = val; };
          fill('utm_source', u.utm_source);
          fill('utm_medium', u.utm_medium);
          fill('utm_campaign', u.utm_campaign);
          fill('utm_term', u.utm_term);
          fill('utm_content', u.utm_content);
          toast('UTM parameters auto-filled by AI', 'success');
        } else {
          toast('Could not parse AI suggestions', 'error');
        }
      } catch (err) { toast(err.message, 'error'); }
      finally { aiUtmBtn.classList.remove('loading'); aiUtmBtn.disabled = false; }
    });
  }
}

export async function refresh() {
  await Promise.all([loadUtmLinks(), loadShortLinks()]);
}

async function loadUtmLinks() {
  try {
    const data = await api('/api/utm');
    const items = data.items || data;
    const tb = $('utmTable');
    if (!tb) return;
    tb.innerHTML = items.map(l => `<tr>
      <td>${escapeHtml(l.campaign_name)}</td>
      <td>${escapeHtml(l.utm_source)}</td>
      <td>${escapeHtml(l.utm_medium)}</td>
      <td class="text-small" style="max-width:300px;overflow:hidden;text-overflow:ellipsis">${escapeHtml(l.full_url)}</td>
      <td><strong>${l.clicks}</strong></td>
      <td>
        <button class="btn btn-sm btn-outline" onclick="window._copyText('${escapeHtml(l.full_url)}')">Copy</button>
        <button class="btn btn-sm btn-danger" onclick="window._deleteUtm(${l.id})">Del</button>
      </td>
    </tr>`).join('');
  } catch (err) {
    toast('Failed to load UTM links: ' + err.message, 'error');
  }
}

async function loadShortLinks() {
  try {
    const data = await api('/api/links');
    const items = data.items || data;
    const tb = $('shortLinkTable');
    if (!tb) return;
    const base = window.location.origin + getBasePath();
    tb.innerHTML = items.map(l => {
      const shortUrl = `${base}/s/${l.code}`;
      return `<tr>
        <td><a href="${shortUrl}" target="_blank">${getBasePath()}/s/${escapeHtml(l.code)}</a></td>
        <td>${escapeHtml(l.title)}</td>
        <td class="text-small" style="max-width:250px;overflow:hidden;text-overflow:ellipsis">${escapeHtml(l.destination_url)}</td>
        <td><strong>${l.clicks}</strong></td>
        <td class="text-muted text-small">${formatDate(l.created_at)}</td>
        <td>
          <button class="btn btn-sm btn-outline" onclick="window._copyText('${shortUrl}')">Copy</button>
          <button class="btn btn-sm btn-danger" onclick="window._deleteShortLink(${l.id})">Del</button>
        </td>
      </tr>`;
    }).join('');
  } catch (err) {
    toast('Failed to load short links: ' + err.message, 'error');
  }
}

async function handleUtmCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  data.create_short_link = fd.has('create_short_link');
  try {
    const resp = await api('/api/utm', { method: 'POST', body: JSON.stringify(data) });
    const result = resp.item || resp;
    toast('UTM link created', 'success');

    $('utmResult')?.classList.remove('hidden');
    const utmUrlEl = $('utmUrl');
    if (utmUrlEl) utmUrlEl.textContent = result.full_url;

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

