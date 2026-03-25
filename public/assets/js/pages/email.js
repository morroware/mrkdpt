/**
 * Email Marketing — lists, subscribers, campaigns, compose.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDateTime, onSubmit, formData, statusBadge, onClick } from '../core/utils.js';
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
        : '<p class="text-muted">No email lists</p>';

      el.querySelectorAll('[data-delete-list]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!confirm('Delete list and all its subscribers?')) return;
          try {
            await api(`/api/email-lists/${btn.dataset.deleteList}`, { method: 'DELETE' });
            success('List deleted');
            refresh();
          } catch (err) { error(err.message); }
        });
      });
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

    table.innerHTML = items.map((s) => `<tr>
      <td>${escapeHtml(s.email)}</td>
      <td>${escapeHtml(s.name || '')}</td>
      <td>${escapeHtml(s.list_name || '')}</td>
      <td>${statusBadge(s.status)}</td>
      <td><button class="btn btn-sm btn-danger" data-delete-sub="${s.id}">Del</button></td>
    </tr>`).join('');

    table.querySelectorAll('[data-delete-sub]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api(`/api/subscribers/${btn.dataset.deleteSub}`, { method: 'DELETE' });
          success('Subscriber removed');
          refreshSubscribers();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load subscribers: ' + err.message);
  }
}

async function refreshEmailCampaigns() {
  try {
    const { items } = await api('/api/email-campaigns');
    const table = $('emailCampaignTable');
    if (!table) return;

    table.innerHTML = items.map((c) => `<tr>
      <td>${escapeHtml(c.name)}</td>
      <td>${escapeHtml(c.subject)}</td>
      <td>${escapeHtml(c.list_name || '-')}</td>
      <td>${statusBadge(c.status)}</td>
      <td>${c.sent_count || 0}</td>
      <td>
        ${c.status === 'draft' ? `<button class="btn btn-sm btn-success" data-send="${c.id}">Send</button>` : ''}
        <button class="btn btn-sm btn-danger" data-delete-ec="${c.id}">Del</button>
      </td>
    </tr>`).join('');

    table.querySelectorAll('[data-send]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Send this campaign to all subscribers?')) return;
        try {
          const result = await api(`/api/email-campaigns/${btn.dataset.send}/send`, { method: 'POST' });
          success(`Sent to ${result.sent || 0} subscribers`);
          refreshEmailCampaigns();
        } catch (err) { error(err.message); }
      });
    });

    table.querySelectorAll('[data-delete-ec]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this email campaign?')) return;
        try {
          await api(`/api/email-campaigns/${btn.dataset.deleteEc}`, { method: 'DELETE' });
          success('Email campaign deleted');
          refreshEmailCampaigns();
        } catch (err) { error(err.message); }
      });
    });
  } catch (err) {
    error('Failed to load email campaigns: ' + err.message);
  }
}

export async function refresh() {
  await refreshLists();
  await Promise.all([refreshSubscribers(), refreshEmailCampaigns()]);
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
      $('csvImport').value = '';
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

  // Send test email
  onClick('sendTestEmail', async () => {
    const to = prompt('Send test to email address:');
    if (!to) return;
    // We need an existing campaign — for now just alert
    error('Save the campaign first, then use the Send Test button from the campaigns list');
  });
}
