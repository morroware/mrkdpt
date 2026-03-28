/**
 * Landing Pages page module.
 */
import { api, getBasePath } from '../core/api.js';
import { $, escapeHtml, formatDate, emptyState, confirm, copyToClipboard } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('landingPageForm')?.addEventListener('submit', handleCreate);

  // AI Generate Landing Page Copy
  const aiBtn = document.getElementById('aiGenerateLanding');
  if (aiBtn) {
    aiBtn.addEventListener('click', async () => {
      const form = $('landingPageForm');
      if (!form) return;
      const title = form.querySelector('[name="title"]')?.value || '';
      const heading = form.querySelector('[name="hero_heading"]')?.value || title;
      if (!heading && !title) { toast('Enter a title or heading first', 'error'); return; }
      aiBtn.classList.add('loading');
      aiBtn.disabled = true;
      try {
        const resp = await api('/api/ai/content', {
          method: 'POST',
          body: JSON.stringify({
            content_type: 'social_post',
            platform: 'website',
            topic: `Landing page for: ${heading || title}`,
            tone: 'professional',
            goal: 'Generate landing page copy including: hero heading, hero subheading, CTA text, and body content with value propositions and social proof sections. Format with HTML tags.',
          }),
        });
        if (resp?.item?.content) {
          const content = resp.item.content;
          const headingField = form.querySelector('[name="hero_heading"]');
          const subField = form.querySelector('[name="hero_subheading"]');
          const bodyField = form.querySelector('[name="body_html"]');
          const metaDesc = form.querySelector('[name="meta_description"]');

          if (bodyField) bodyField.value = content;
          if (!headingField?.value) {
            const h1Match = content.match(/<h1[^>]*>(.*?)<\/h1>/i) || content.match(/^#\s*(.+)/m);
            if (h1Match) headingField.value = h1Match[1].replace(/<[^>]*>/g, '').slice(0, 100);
          }
          if (!subField?.value) {
            const subMatch = content.match(/<(?:p|h2)[^>]*>(.*?)<\/(?:p|h2)>/i);
            if (subMatch) subField.value = subMatch[1].replace(/<[^>]*>/g, '').slice(0, 150);
          }
          if (!metaDesc?.value) {
            metaDesc.value = content.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 155);
          }
          toast('Landing page copy generated with AI', 'success');
        }
      } catch (err) { toast(err.message, 'error'); }
      finally { aiBtn.classList.remove('loading'); aiBtn.disabled = false; }
    });
  }
}

export async function refresh() {
  await Promise.all([loadPages(), loadFormOptions(), loadCampaignOptions()]);
}

async function loadPages() {
  try {
    const data = await api('/api/landing-pages');
    const items = data.items || data;
    const el = $('landingPageList');
    if (!el) return;
    const base = window.location.origin + getBasePath();
    el.innerHTML = items.map(p => {
      const url = `${base}/p/${p.slug}`;
      const rate = p.views > 0 ? ((p.conversions / p.views) * 100).toFixed(1) : '0.0';
      return `<div class="card">
        <div class="flex-between"><h3>${escapeHtml(p.title)}</h3><span class="badge badge-${p.status === 'published' ? 'success' : 'muted'}">${p.status}</span></div>
        <p class="text-muted text-small mt-1">${escapeHtml(p.template)} template${p.campaign_name ? ' &middot; ' + escapeHtml(p.campaign_name) : ''}</p>
        <div class="row3 mt-1">
          <div><strong>${p.views}</strong><br><span class="text-muted text-small">Views</span></div>
          <div><strong>${p.conversions}</strong><br><span class="text-muted text-small">Conversions</span></div>
          <div><strong>${rate}%</strong><br><span class="text-muted text-small">Conv. Rate</span></div>
        </div>
        <div class="btn-group mt-1">
          ${p.status === 'published' ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline">View</a>` : ''}
          ${p.status === 'draft' ? `<button class="btn btn-sm btn-success" data-publish-landing="${p.id}">Publish</button>` : ''}
          <button class="btn btn-sm btn-outline" data-copy-url="${escapeHtml(url)}">Copy URL</button>
          <button class="btn btn-sm btn-danger" data-delete-landing="${p.id}">Delete</button>
        </div>
      </div>`;
    }).join('') || emptyState('&#128196;', 'No landing pages yet', 'Create your first landing page to capture leads and drive conversions.');

    // Event delegation
    el.addEventListener('click', handlePageListClick);
  } catch (err) {
    toast('Failed to load landing pages: ' + err.message, 'error');
  }
}

async function handlePageListClick(e) {
  const publishBtn = e.target.closest('[data-publish-landing]');
  if (publishBtn) {
    publishBtn.classList.add('loading');
    publishBtn.disabled = true;
    try {
      await api(`/api/landing-pages/${publishBtn.dataset.publishLanding}`, { method: 'PATCH', body: JSON.stringify({ status: 'published' }) });
      toast('Page published', 'success');
      refresh();
    } catch (err) { toast(err.message, 'error'); }
    finally { publishBtn.classList.remove('loading'); publishBtn.disabled = false; }
    return;
  }

  const copyBtn = e.target.closest('[data-copy-url]');
  if (copyBtn) {
    await copyToClipboard(copyBtn.dataset.copyUrl, copyBtn);
    return;
  }

  const deleteBtn = e.target.closest('[data-delete-landing]');
  if (deleteBtn) {
    if (!await confirm('Delete Landing Page', 'Are you sure you want to delete this landing page? This cannot be undone.')) return;
    try {
      await api(`/api/landing-pages/${deleteBtn.dataset.deleteLanding}`, { method: 'DELETE' });
      toast('Deleted', 'success');
      refresh();
    } catch (err) { toast(err.message, 'error'); }
  }
}

async function loadFormOptions() {
  try {
    const resp = await api('/api/forms');
    const forms = resp.items || resp;
    const sel = $('lpFormSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">None</option>' + forms.map(f => `<option value="${f.id}">${escapeHtml(f.name)}</option>`).join('');
  } catch (err) {
    toast('Failed to load form options: ' + err.message, 'error');
  }
}

async function loadCampaignOptions() {
  try {
    const resp = await api('/api/campaigns');
    const camps = resp.items || resp;
    const sel = $('lpCampaignSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">None</option>' + camps.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
  } catch (err) {
    toast('Failed to load campaign options: ' + err.message, 'error');
  }
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
