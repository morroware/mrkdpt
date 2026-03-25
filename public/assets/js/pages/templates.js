/**
 * Content Library — templates, brand profiles, media library.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, onSubmit, formData } from '../core/utils.js';
import { success, error } from '../core/toast.js';

/* ---- Templates ---- */

async function refreshTemplates() {
  try {
    const { items } = await api('/api/templates');
    const list = $('templateList');
    if (!list) return;

    list.innerHTML = items.length
      ? items.map((t) => `<div class="card">
          <div class="flex-between">
            <h4>${escapeHtml(t.name)} <span class="badge">${escapeHtml(t.type)}</span></h4>
            <div class="btn-group">
              <button class="btn btn-sm btn-outline" data-clone-tpl="${t.id}">Clone</button>
              <button class="btn btn-sm btn-danger" data-delete-tpl="${t.id}">Delete</button>
            </div>
          </div>
          <pre class="text-small">${escapeHtml((t.structure || '').slice(0, 200))}</pre>
          ${t.variables ? `<p class="text-small text-muted">Variables: ${escapeHtml(t.variables)}</p>` : ''}
        </div>`).join('')
      : '<p class="text-muted">No templates yet</p>';

    list.querySelectorAll('[data-clone-tpl]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api(`/api/templates/${btn.dataset.cloneTpl}/clone`, { method: 'POST' });
          success('Template cloned');
          refreshTemplates();
        } catch (err) { error(err.message); }
      });
    });

    list.querySelectorAll('[data-delete-tpl]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this template?')) return;
        try {
          await api(`/api/templates/${btn.dataset.deleteTpl}`, { method: 'DELETE' });
          success('Template deleted');
          refreshTemplates();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load templates: ' + err.message);
  }
}

/* ---- Brand Profiles ---- */

async function refreshBrands() {
  try {
    const { items } = await api('/api/brand-profiles');
    const list = $('brandList');
    if (!list) return;

    list.innerHTML = items.length
      ? items.map((b) => `<div class="card">
          <div class="flex-between">
            <h4>${escapeHtml(b.name)} ${b.is_active ? '<span class="badge badge-success">Active</span>' : ''}</h4>
            <div class="btn-group">
              ${!b.is_active ? `<button class="btn btn-sm btn-outline" data-activate="${b.id}">Activate</button>` : ''}
              <button class="btn btn-sm btn-danger" data-delete-brand="${b.id}">Delete</button>
            </div>
          </div>
          <p class="text-small"><strong>Tone:</strong> ${escapeHtml(b.voice_tone || '')}</p>
          <p class="text-small"><strong>Audience:</strong> ${escapeHtml(b.target_audience || '')}</p>
        </div>`).join('')
      : '<p class="text-muted">No brand profiles yet</p>';

    list.querySelectorAll('[data-activate]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api(`/api/brand-profiles/${btn.dataset.activate}/activate`, { method: 'POST' });
          success('Brand profile activated');
          refreshBrands();
        } catch (err) { error(err.message); }
      });
    });

    list.querySelectorAll('[data-delete-brand]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this brand profile?')) return;
        try {
          await api(`/api/brand-profiles/${btn.dataset.deleteBrand}`, { method: 'DELETE' });
          success('Brand profile deleted');
          refreshBrands();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load brand profiles: ' + err.message);
  }
}

/* ---- Media Library ---- */

async function refreshMedia() {
  try {
    const { items } = await api('/api/media');
    const grid = $('mediaGrid');
    if (!grid) return;

    grid.innerHTML = items.length
      ? items.map((m) => {
          const isImage = (m.mime_type || '').startsWith('image/');
          const thumb = m.thumb_url || m.url;
          return `<div class="media-item">
            ${isImage ? `<img src="${escapeHtml(thumb)}" alt="${escapeHtml(m.alt_text || '')}" loading="lazy" />` : `<div class="media-file">${escapeHtml(m.original_name || 'File')}</div>`}
            <div class="media-info">
              <span class="text-small">${escapeHtml(m.original_name || '')}</span>
              <button class="btn btn-sm btn-danger" data-delete-media="${m.id}">Del</button>
            </div>
          </div>`;
        }).join('')
      : '<p class="text-muted">No media uploaded</p>';

    grid.querySelectorAll('[data-delete-media]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this file?')) return;
        try {
          await api(`/api/media/${btn.dataset.deleteMedia}`, { method: 'DELETE' });
          success('File deleted');
          refreshMedia();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load media: ' + err.message);
  }
}

export async function refresh() {
  await Promise.all([refreshTemplates(), refreshBrands(), refreshMedia()]);
}

export function init() {
  // Template form
  onSubmit('templateForm', async (e) => {
    try {
      await api('/api/templates', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Template saved');
      refreshTemplates();
    } catch (err) { error(err.message); }
  });

  // Brand profile form
  onSubmit('brandForm', async (e) => {
    try {
      await api('/api/brand-profiles', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Brand profile saved');
      refreshBrands();
    } catch (err) { error(err.message); }
  });

  // Media upload form — uses FormData (not JSON)
  const mediaForm = $('mediaForm');
  if (mediaForm) {
    mediaForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(mediaForm);
      try {
        await api('/api/media', { method: 'POST', body: fd });
        mediaForm.reset();
        success('File uploaded');
        refreshMedia();
      } catch (err) { error(err.message); }
    });
  }
}
