/**
 * Email Marketing — lists, subscribers, campaigns, compose.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDateTime, onSubmit, formData, statusBadge, onClick, tableEmpty, emptyState, confirm, promptInput } from '../core/utils.js';
import { success, error } from '../core/toast.js';

async function refreshLists() {
  try {
    const { items } = await api('/api/email-lists');
    const el = $('emailListItems');
    if (el) {
      el.innerHTML = items.length
        ? items.map((l) => `<div class="card">
            <div class="flex-between">
              <h4>${escapeHtml(l.name)}</h4>
              <button class="btn btn-sm btn-danger" data-delete-list="${l.id}">Delete</button>
            </div>
            <p class="text-small text-muted">${escapeHtml(l.description || '')} &mdash; ${l.subscriber_count || 0} subscribers</p>
          </div>`).join('')
        : emptyState('&#9993;', 'No email lists', 'Create your first email list to start collecting subscribers.');

      el.onclick = async (e) => {
        const deleteBtn = e.target.closest('[data-delete-list]');
        if (deleteBtn) {
          if (!await confirm('Delete List', 'Delete list and all its subscribers?')) return;
          try {
            await api(`/api/email-lists/${deleteBtn.dataset.deleteList}`, { method: 'DELETE' });
            success('List deleted');
            refresh();
          } catch (err) { error(err.message); }
        }
      };
    }

    // Populate list selects
    const selects = ['subListSelect', 'csvListSelect', 'composeListSelect'];
    selects.forEach((id) => {
      const sel = $(id);
      if (sel) {
        sel.innerHTML = items.map((l) => `<option value="${l.id}">${escapeHtml(l.name)}</option>`).join('');
      }
    });
  } catch (err) {
    error('Failed to load email lists: ' + err.message);
  }
}

async function refreshSubscribers() {
  try {
    const { items } = await api('/api/subscribers');
    const table = $('subscriberTable');
    if (!table) return;

    table.innerHTML = items.length
      ? items.map((s) => `<tr>
      <td>${escapeHtml(s.email)}</td>
      <td>${escapeHtml(s.name || '')}</td>
      <td>${escapeHtml(s.list_name || '')}</td>
      <td>${statusBadge(s.status)}</td>
      <td><button class="btn btn-sm btn-danger" data-delete-sub="${s.id}">Del</button></td>
    </tr>`).join('')
      : tableEmpty(5, 'No subscribers in this list yet.');

    table.onclick = async (e) => {
      const deleteBtn = e.target.closest('[data-delete-sub]');
      if (deleteBtn) {
        try {
          await api(`/api/subscribers/${deleteBtn.dataset.deleteSub}`, { method: 'DELETE' });
          success('Subscriber removed');
          refreshSubscribers();
        } catch (err) { error(err.message); }
      }
    };
  } catch (err) {
    error('Failed to load subscribers: ' + err.message);
  }
}

async function refreshEmailCampaigns() {
  try {
    const { items } = await api('/api/email-campaigns');
    const table = $('emailCampaignTable');
    if (!table) return;

    table.innerHTML = items.length
      ? items.map((c) => `<tr>
      <td>${escapeHtml(c.name)}</td>
      <td>${escapeHtml(c.subject)}</td>
      <td>${escapeHtml(c.list_name || '-')}</td>
      <td>${statusBadge(c.status)}</td>
      <td>${c.sent_count || 0}</td>
      <td>
        ${c.status === 'draft' ? `<button class="btn btn-sm btn-success" data-send="${c.id}">Send</button>` : ''}
        <button class="btn btn-sm btn-danger" data-delete-ec="${c.id}">Del</button>
      </td>
    </tr>`).join('')
      : tableEmpty(6, 'No email campaigns yet. Compose your first campaign to get started.');

    table.onclick = async (e) => {
      const sendBtn = e.target.closest('[data-send]');
      if (sendBtn) {
        if (!await confirm('Send Campaign', 'Send this campaign to all subscribers?', { okText: 'Send', okClass: 'btn-success' })) return;
        try {
          const result = await api(`/api/email-campaigns/${sendBtn.dataset.send}/send`, { method: 'POST' });
          success(`Sent to ${result.sent || 0} subscribers`);
          refreshEmailCampaigns();
        } catch (err) { error(err.message); }
        return;
      }
      const deleteBtn = e.target.closest('[data-delete-ec]');
      if (deleteBtn) {
        if (!await confirm('Delete Campaign', 'Delete this email campaign?')) return;
        try {
          await api(`/api/email-campaigns/${deleteBtn.dataset.deleteEc}`, { method: 'DELETE' });
          success('Email campaign deleted');
          refreshEmailCampaigns();
        } catch (err) { error(err.message); }
      }
    };
  } catch (err) {
    error('Failed to load email campaigns: ' + err.message);
  }
}

async function refreshEmailTemplates() {
  try {
    const { items } = await api('/api/email-templates');
    const list = $('emailTemplateList');
    if (!list) return;

    list.innerHTML = items.map((t) => `<div class="card">
      <div class="flex-between">
        <h4>${escapeHtml(t.name)}</h4>
        <span class="badge">${escapeHtml(t.category)}</span>
      </div>
      <p class="text-small text-muted">Subject: ${escapeHtml(t.subject_template || '-')}</p>
      <div style="height:8px;background:${t.thumbnail_color || '#4c8dff'};border-radius:4px;margin:8px 0"></div>
      <div class="btn-group mt-1">
        <button class="btn btn-sm" data-preview-tpl="${t.id}">Preview</button>
        <button class="btn btn-sm btn-outline" data-use-tpl="${t.id}" data-html="${encodeURIComponent(t.html_template || '')}" data-text="${encodeURIComponent(t.text_template || '')}" data-subject="${encodeURIComponent(t.subject_template || '')}">Use in Campaign</button>
        ${!t.is_builtin ? `<button class="btn btn-sm btn-danger" data-del-tpl="${t.id}">Delete</button>` : '<span class="text-small text-muted">Built-in</span>'}
      </div>
    </div>`).join('') || '<p class="text-muted">No email templates found</p>';

    list.onclick = async (e) => {
      const previewBtn = e.target.closest('[data-preview-tpl]');
      if (previewBtn) {
        try {
          const tpl = await api(`/api/email-templates/${previewBtn.dataset.previewTpl}`);
          const modal = $('emailTplModal');
          const body = $('emailTplModalBody');
          if (modal && body) {
            const iframe = document.createElement('iframe');
            iframe.style.cssText = 'width:100%;min-height:400px;border:none;border-radius:8px;background:#fff';
            iframe.sandbox = '';
            iframe.srcdoc = tpl.html_template || '<p>No HTML content</p>';
            body.innerHTML = '';
            body.appendChild(iframe);
            modal.classList.add('visible');
          }
        } catch (e) { error(e.message); }
        return;
      }
      const useBtn = e.target.closest('[data-use-tpl]');
      if (useBtn) {
        const htmlField = document.querySelector('#emailComposeForm [name="body_html"]');
        const textField = document.querySelector('#emailComposeForm [name="body_text"]');
        const subjectField = document.querySelector('#emailComposeForm [name="subject"]');
        if (htmlField) htmlField.value = decodeURIComponent(useBtn.dataset.html || '');
        if (textField) textField.value = decodeURIComponent(useBtn.dataset.text || '');
        if (subjectField && !subjectField.value) subjectField.value = decodeURIComponent(useBtn.dataset.subject || '');
        document.querySelector('[data-tab="email-compose"]')?.click();
        success('Template loaded into composer');
        return;
      }
      const delBtn = e.target.closest('[data-del-tpl]');
      if (delBtn) {
        if (!await confirm('Delete Template', 'Delete this template?')) return;
        try {
          await api(`/api/email-templates/${delBtn.dataset.delTpl}`, { method: 'DELETE' });
          success('Template deleted');
          refreshEmailTemplates();
        } catch (e) { error(e.message); }
      }
    };
  } catch (e) {
    error('Failed to load email templates: ' + e.message);
  }
}

export async function refresh() {
  await refreshLists();
  await Promise.all([refreshSubscribers(), refreshEmailCampaigns(), refreshEmailTemplates()]);
}

export function init() {
  // Create list
  onSubmit('emailListForm', async (e) => {
    try {
      await api('/api/email-lists', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Email list created');
      refresh();
    } catch (err) { error(err.message); }
  });

  // Add subscriber
  onSubmit('subscriberForm', async (e) => {
    try {
      await api('/api/subscribers', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Subscriber added');
      refreshSubscribers();
    } catch (err) { error(err.message); }
  });

  // CSV import
  onClick('importCsv', async () => {
    const csv = $('csvImport')?.value || '';
    const listId = $('csvListSelect')?.value;
    if (!csv || !listId) { error('Please enter CSV data and select a list'); return; }
    try {
      const result = await api('/api/subscribers/import', {
        method: 'POST',
        body: JSON.stringify({ list_id: parseInt(listId), csv }),
      });
      success(`Imported: ${result.imported}, Skipped: ${result.skipped}`);
      const csvInput = $('csvImport');
      if (csvInput) csvInput.value = '';
      refreshSubscribers();
    } catch (err) { error(err.message); }
  });

  // Compose email campaign
  onSubmit('emailComposeForm', async (e) => {
    try {
      await api('/api/email-campaigns', { method: 'POST', body: JSON.stringify(formData(e)) });
      e.target.reset();
      success('Email campaign saved');
      refreshEmailCampaigns();
    } catch (err) { error(err.message); }
  });

  // Close template modal
  onClick('closeEmailTplModal', () => {
    $('emailTplModal')?.classList.remove('visible');
  });

  // Send test email
  onClick('sendTestEmail', async () => {
    const to = await promptInput('Send Test Email', 'Recipient email address', { placeholder: 'test@example.com', inputType: 'email' });
    if (!to) return;
    const form = $('emailComposeForm');
    if (!form) return;
    const subject = form.querySelector('[name="subject"]')?.value;
    const bodyHtml = form.querySelector('[name="body_html"]')?.value;
    const bodyText = form.querySelector('[name="body_text"]')?.value;
    if (!subject || (!bodyHtml && !bodyText)) {
      error('Please fill in the subject and email body before sending a test.');
      return;
    }
    try {
      await api('/api/email-campaigns/test', {
        method: 'POST',
        body: JSON.stringify({ to, subject, body_html: bodyHtml || '', body_text: bodyText || '' }),
      });
      success(`Test email sent to ${to}`);
    } catch (err) {
      error('Could not send test email: ' + err.message);
    }
  });

  // AI generate email body
  onClick('aiGenerateEmail', async () => {
    const form = $('emailComposeForm');
    if (!form) return;
    const btn = $('aiGenerateEmail');
    const subject = form.querySelector('[name="subject"]')?.value || 'marketing email';
    const name = form.querySelector('[name="name"]')?.value || '';
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/content', {
        method: 'POST',
        body: JSON.stringify({
          content_type: 'email',
          platform: 'email',
          topic: subject,
          tone: 'professional',
          goal: name ? `Campaign: ${name}` : 'drive engagement',
        }),
      });
      if (item?.content) {
        const htmlField = form.querySelector('[name="body_html"]');
        const textField = form.querySelector('[name="body_text"]');
        if (htmlField) htmlField.value = `<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">${item.content.replace(/\n/g, '<br>')}</div>`;
        if (textField) textField.value = item.content;
        success('Email body generated with AI');
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // AI generate subject lines
  onClick('aiGenerateSubject', async () => {
    const form = $('emailComposeForm');
    if (!form) return;
    const btn = $('aiGenerateSubject');
    const name = form.querySelector('[name="name"]')?.value || '';
    const body = form.querySelector('[name="body_text"]')?.value || '';
    const topic = name || body?.slice(0, 100) || 'marketing';
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    try {
      const { item } = await api('/api/ai/subject-lines', {
        method: 'POST',
        body: JSON.stringify({ topic, count: 5 }),
      });
      if (item?.subjects) {
        // Extract first subject line
        const lines = item.subjects.split('\n').filter((l) => l.trim());
        const firstSubject = lines[0]?.replace(/^\d+[\.\)]\s*/, '').replace(/^["*]+|["*]+$/g, '').replace(/^Subject:\s*/i, '').replace(/^-\s*/, '').trim();
        if (firstSubject) {
          const subjectField = form.querySelector('[name="subject"]');
          if (subjectField) subjectField.value = firstSubject.slice(0, 80);
          success('Subject line generated (5 options in AI Studio)');
        }
      }
    } catch (err) { error(err.message); }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // Email Intelligence
  onClick('aiEmailIntelligence', async () => {
    const btn = $('aiEmailIntelligence');
    const results = $('emailIntelResults');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    if (results) results.innerHTML = '<span class="text-muted">Analyzing email history...</span>';
    try {
      const { item } = await api('/api/ai/email-intelligence', { method: 'POST', body: '{}' });
      if (results) {
        const analysis = item?.analysis || item?.raw || 'No analysis available';
        results.innerHTML = `<div class="ai-output text-small">${escapeHtml(typeof analysis === 'string' ? analysis : JSON.stringify(analysis, null, 2))}</div>`;
      }
      success('Email intelligence analysis complete');
    } catch (err) { error(err.message); if (results) results.innerHTML = ''; }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // Send Time Optimizer
  onClick('aiSendTimeOptimize', async () => {
    const btn = $('aiSendTimeOptimize');
    const results = $('sendTimeResults');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    if (results) results.innerHTML = '<span class="text-muted">Calculating optimal send times...</span>';
    try {
      const { item } = await api('/api/ai/smart-times', { method: 'POST', body: JSON.stringify({ platform: 'email' }) });
      if (results) {
        const times = item?.times || item?.raw || 'No data available';
        results.innerHTML = `<div class="ai-output text-small">${escapeHtml(typeof times === 'string' ? times : JSON.stringify(times, null, 2))}</div>`;
      }
      success('Send time analysis complete');
    } catch (err) { error(err.message); if (results) results.innerHTML = ''; }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });

  // Deliverability Check
  onClick('aiDeliverabilityCheck', async () => {
    const btn = $('aiDeliverabilityCheck');
    const results = $('deliverabilityResults');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    if (results) results.innerHTML = '<span class="text-muted">Checking deliverability...</span>';
    try {
      const { item } = await api('/api/ai/deliverability-check', { method: 'POST', body: '{}' });
      if (results) {
        const check = item?.analysis || item?.raw || 'No data available';
        results.innerHTML = `<div class="ai-output text-small">${escapeHtml(typeof check === 'string' ? check : JSON.stringify(check, null, 2))}</div>`;
      }
      success('Deliverability check complete');
    } catch (err) { error(err.message); if (results) results.innerHTML = ''; }
    finally { if (btn) { btn.classList.remove('loading'); btn.disabled = false; } }
  });
}
