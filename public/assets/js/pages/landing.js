/**
 * Landing Pages page module.
 * Section-based builder with pre-built blocks (features, testimonials, FAQ, pricing, CTA).
 */
import { api, getBasePath } from '../core/api.js';
import { $, escapeHtml, formatDate, emptyState, confirm, copyToClipboard } from '../core/utils.js';
import { toast } from '../core/toast.js';

let pageSections = [];
let sectionTemplates = [];

export function init() {
  $('landingPageForm')?.addEventListener('submit', handleCreate);
  $('addLpSection')?.addEventListener('click', toggleSectionPicker);
  loadSectionTemplates();

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

// =========================================================================
// Section Builder
// =========================================================================

const DEFAULT_SECTION_TEMPLATES = [
  { label: 'Features Grid', description: 'Highlight key features or benefits', default: { type: 'features', heading: 'Why Choose Us', items: [{ title: 'Feature 1', description: 'Description of your first key feature.' }, { title: 'Feature 2', description: 'Description of your second key feature.' }, { title: 'Feature 3', description: 'Description of your third key feature.' }] } },
  { label: 'Testimonials', description: 'Social proof from happy customers', default: { type: 'testimonials', heading: 'What Our Customers Say', items: [{ quote: 'This product changed everything for us.', author: 'Jane Doe', role: 'CEO, Acme Inc.' }] } },
  { label: 'FAQ', description: 'Frequently asked questions', default: { type: 'faq', heading: 'Frequently Asked Questions', items: [{ question: 'How does it work?', answer: 'Simply sign up and follow the setup wizard.' }, { question: 'Is there a free trial?', answer: 'Yes, we offer a 14-day free trial.' }] } },
  { label: 'Pricing Table', description: 'Show your pricing plans', default: { type: 'pricing', heading: 'Simple Pricing', items: [{ name: 'Starter', price: '$9/mo', features: 'Core features, email support' }, { name: 'Pro', price: '$29/mo', features: 'All features, priority support' }] } },
  { label: 'CTA Banner', description: 'Call to action with button', default: { type: 'cta', heading: 'Ready to Get Started?', subheading: 'Join thousands of happy customers today.', cta_text: 'Start Free Trial', cta_url: '#' } },
  { label: 'Text Block', description: 'Free-form text content section', default: { type: 'text', heading: 'About Us', body: 'Tell your story here. Share your mission, values, or any additional information visitors need.' } },
];

async function loadSectionTemplates() {
  try {
    const resp = await api('/api/landing-pages/section-templates');
    sectionTemplates = resp.items || [];
  } catch {
    sectionTemplates = [];
  }
  // Use built-in fallback templates if API returned none
  if (sectionTemplates.length === 0) {
    sectionTemplates = DEFAULT_SECTION_TEMPLATES;
  }
}

function toggleSectionPicker() {
  const picker = $('lpSectionTemplates');
  if (!picker) return;

  if (!picker.classList.contains('hidden')) {
    picker.classList.add('hidden');
    return;
  }

  picker.innerHTML = sectionTemplates.map((t, i) => `
    <button type="button" class="btn btn-outline btn-sm" data-add-section="${i}" style="text-align:left;padding:.6rem">
      <strong>${escapeHtml(t.label)}</strong><br>
      <span class="text-muted text-small">${escapeHtml(t.description)}</span>
    </button>
  `).join('');

  picker.onclick = (e) => {
    const btn = e.target.closest('[data-add-section]');
    if (!btn) return;
    const idx = parseInt(btn.dataset.addSection);
    const template = sectionTemplates[idx];
    if (template) {
      pageSections.push(JSON.parse(JSON.stringify(template.default)));
      renderSections();
      picker.classList.add('hidden');
    }
  };

  picker.classList.remove('hidden');
}

function renderSections() {
  const container = $('lpSectionsList');
  if (!container) return;

  if (!pageSections.length) {
    container.innerHTML = '<p class="text-muted text-small">No sections added. Click "+ Add Section" to add features, testimonials, FAQ, pricing, or CTA blocks.</p>';
    syncSectionsJson();
    return;
  }

  const typeLabels = { features: 'Features Grid', testimonials: 'Testimonials', faq: 'FAQ', pricing: 'Pricing Table', cta: 'CTA Banner', text: 'Text Block' };

  container.innerHTML = pageSections.map((s, i) => {
    const label = typeLabels[s.type] || s.type;
    const itemCount = (s.items || []).length;
    const summary = s.type === 'cta' ? escapeHtml(s.heading || '') :
                    s.type === 'text' ? escapeHtml((s.heading || s.body || '').slice(0, 50)) :
                    `${itemCount} item${itemCount !== 1 ? 's' : ''}`;

    return `<div class="form-field-item lp-section-item" data-idx="${i}" draggable="true" style="background:var(--panel);border:1px solid var(--line);border-radius:6px;padding:.6rem .8rem">
      <div class="flex-between">
        <div style="display:flex;align-items:center;gap:.5rem">
          <span class="text-muted" style="cursor:grab">&#9776;</span>
          <span class="badge badge-info text-small">${escapeHtml(label)}</span>
          <span class="text-small text-muted">${escapeHtml(s.heading || '')} &middot; ${summary}</span>
        </div>
        <div class="btn-group">
          <button type="button" class="btn btn-sm btn-ghost" data-edit-section="${i}">&#9881;</button>
          ${i > 0 ? `<button type="button" class="btn btn-sm btn-ghost" data-section-up="${i}">&#9650;</button>` : ''}
          ${i < pageSections.length - 1 ? `<button type="button" class="btn btn-sm btn-ghost" data-section-down="${i}">&#9660;</button>` : ''}
          <button type="button" class="btn btn-sm btn-ghost text-danger" data-remove-section="${i}">&#10005;</button>
        </div>
      </div>
      <div class="section-editor hidden" id="sectionEditor${i}" style="margin-top:.5rem;padding-top:.5rem;border-top:1px solid var(--line)">
        ${renderSectionEditor(s, i)}
      </div>
    </div>`;
  }).join('');

  // Wire events
  container.querySelectorAll('[data-edit-section]').forEach(btn => {
    btn.addEventListener('click', () => {
      $('sectionEditor' + btn.dataset.editSection)?.classList.toggle('hidden');
    });
  });

  container.querySelectorAll('[data-remove-section]').forEach(btn => {
    btn.addEventListener('click', () => {
      pageSections.splice(parseInt(btn.dataset.removeSection), 1);
      renderSections();
    });
  });

  container.querySelectorAll('[data-section-up]').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = parseInt(btn.dataset.sectionUp);
      [pageSections[i - 1], pageSections[i]] = [pageSections[i], pageSections[i - 1]];
      renderSections();
    });
  });

  container.querySelectorAll('[data-section-down]').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = parseInt(btn.dataset.sectionDown);
      [pageSections[i], pageSections[i + 1]] = [pageSections[i + 1], pageSections[i]];
      renderSections();
    });
  });

  // Wire inline editors
  container.querySelectorAll('[data-section-field]').forEach(input => {
    input.addEventListener('change', () => {
      const idx = parseInt(input.dataset.sectionIdx);
      const field = input.dataset.sectionField;
      pageSections[idx][field] = input.value;
      syncSectionsJson();
    });
  });

  container.querySelectorAll('[data-section-items]').forEach(textarea => {
    textarea.addEventListener('change', () => {
      const idx = parseInt(textarea.dataset.sectionIdx);
      try {
        pageSections[idx].items = JSON.parse(textarea.value);
        syncSectionsJson();
      } catch { toast('Invalid JSON for section items', 'error'); }
    });
  });

  // Drag-and-drop reorder for sections
  let dragIdx = null;
  container.querySelectorAll('.lp-section-item').forEach(item => {
    item.addEventListener('dragstart', (e) => {
      dragIdx = parseInt(item.dataset.idx);
      item.style.opacity = '0.4';
      item.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    item.addEventListener('dragend', () => {
      item.style.opacity = '1';
      item.classList.remove('dragging');
      container.querySelectorAll('.lp-section-item').forEach(el => el.classList.remove('drop-above', 'drop-below'));
      dragIdx = null;
    });
    item.addEventListener('dragover', (e) => {
      e.preventDefault();
      if (dragIdx === null) return;
      container.querySelectorAll('.lp-section-item').forEach(el => el.classList.remove('drop-above', 'drop-below'));
      const rect = item.getBoundingClientRect();
      const mid = rect.top + rect.height / 2;
      item.classList.add(e.clientY < mid ? 'drop-above' : 'drop-below');
    });
    item.addEventListener('dragleave', () => {
      item.classList.remove('drop-above', 'drop-below');
    });
    item.addEventListener('drop', (e) => {
      e.preventDefault();
      const dropIdx = parseInt(item.dataset.idx);
      if (dragIdx !== null && dragIdx !== dropIdx) {
        const moved = pageSections.splice(dragIdx, 1)[0];
        pageSections.splice(dropIdx, 0, moved);
        renderSections();
      }
    });
  });

  syncSectionsJson();
}

function renderSectionEditor(section, idx) {
  let html = `<div style="margin-bottom:.4rem"><label class="text-small">Heading</label><input class="w-full" value="${escapeHtml(section.heading || '')}" data-section-field="heading" data-section-idx="${idx}" /></div>`;

  if (section.type === 'cta') {
    html += `<div class="row2" style="gap:.5rem"><div><label class="text-small">Subheading</label><input class="w-full" value="${escapeHtml(section.subheading || '')}" data-section-field="subheading" data-section-idx="${idx}" /></div>
    <div><label class="text-small">CTA Text</label><input class="w-full" value="${escapeHtml(section.cta_text || '')}" data-section-field="cta_text" data-section-idx="${idx}" /></div></div>
    <div><label class="text-small">CTA URL</label><input class="w-full" value="${escapeHtml(section.cta_url || '')}" data-section-field="cta_url" data-section-idx="${idx}" /></div>`;
  } else if (section.type === 'text') {
    html += `<div><label class="text-small">Body Text</label><textarea class="w-full" rows="3" data-section-field="body" data-section-idx="${idx}">${escapeHtml(section.body || '')}</textarea></div>`;
  } else if (section.items) {
    html += `<div><label class="text-small">Items (JSON)</label><textarea class="w-full" rows="6" data-section-items="${idx}" style="font-family:monospace;font-size:.8rem">${escapeHtml(JSON.stringify(section.items, null, 2))}</textarea></div>`;
  }

  return html;
}

function syncSectionsJson() {
  const input = $('lpSectionsJson');
  if (input) input.value = JSON.stringify(pageSections);
}

// =========================================================================
// Page List
// =========================================================================

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
      const sectionCount = (() => { try { return JSON.parse(p.sections_json || '[]').length; } catch { return 0; } })();
      return `<div class="card">
        <div class="flex-between"><h3>${escapeHtml(p.title)}</h3><span class="badge badge-${p.status === 'published' ? 'success' : 'muted'}">${p.status}</span></div>
        <p class="text-muted text-small mt-1">${escapeHtml(p.template)} template${sectionCount ? ' &middot; ' + sectionCount + ' sections' : ''}${p.campaign_name ? ' &middot; ' + escapeHtml(p.campaign_name) : ''}</p>
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

    el.onclick = handlePageListClick;
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

// =========================================================================
// Supporting
// =========================================================================

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
  // Parse sections
  try {
    data.sections_json = JSON.parse(data.sections_json || '[]');
  } catch {
    data.sections_json = [];
  }
  try {
    await api('/api/landing-pages', { method: 'POST', body: JSON.stringify(data) });
    toast('Landing page created', 'success');
    e.target.reset();
    pageSections = [];
    renderSections();
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}
