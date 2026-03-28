/**
 * AI Marketing Chat — conversational interface grounded in marketing data.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, onClick, confirm, promptInput } from '../core/utils.js';
import { success, error } from '../core/toast.js';

let currentConversationId = 0;
let providerModels = {}; // Fetched from API, not hardcoded

function renderMarkdown(text) {
  if (!text) return '';
  const sanitized = escapeHtml(String(text));
  let html = sanitized
    .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/^### (.+)$/gm, '<h3>$1</h3>')
    .replace(/^## (.+)$/gm, '<h2>$1</h2>')
    .replace(/^# (.+)$/gm, '<h1>$1</h1>')
    .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>')
    .replace(/^---$/gm, '<hr>')
    .replace(/^[*-] (.+)$/gm, '<li data-list="ul">$1</li>')
    .replace(/^\d+\. (.+)$/gm, '<li data-list="ol">$1</li>')
    .replace(/((?:<li data-list="ul">.*<\/li>\n?)+)/g, (m) => '<ul>' + m.replace(/ data-list="ul"/g, '') + '</ul>')
    .replace(/((?:<li data-list="ol">.*<\/li>\n?)+)/g, (m) => '<ol>' + m.replace(/ data-list="ol"/g, '') + '</ol>')
    .replace(/\n\n/g, '</p><p>')
    .replace(/\n/g, '<br>');

  if (!html.startsWith('<')) html = '<p>' + html + '</p>';
  return html;
}

function scrollChatToBottom() {
  const el = $('chatMessages');
  if (!el) return;
  requestAnimationFrame(() => {
    el.scrollTop = el.scrollHeight;
  });
}

function appendMessage(role, content) {
  const el = $('chatMessages');
  if (!el) return;
  // Remove welcome screen
  const welcome = el.querySelector('.chat-welcome');
  if (welcome) welcome.remove();

  const div = document.createElement('div');
  div.className = `chat-msg chat-msg-${role}`;
  if (role === 'assistant') {
    div.innerHTML = `<div class="chat-bubble chat-bubble-rich">${renderMarkdown(content)}</div>`;
  } else {
    div.innerHTML = `<div class="chat-bubble">${escapeHtml(content)}</div>`;
  }
  el.appendChild(div);
  scrollChatToBottom();
}

function showTyping() {
  const el = $('chatMessages');
  if (!el) return;
  const typing = document.createElement('div');
  typing.className = 'chat-msg chat-msg-assistant';
  typing.id = 'chatTyping';
  typing.innerHTML = '<div class="chat-typing"><span></span><span></span><span></span></div>';
  el.appendChild(typing);
  scrollChatToBottom();
}

function hideTyping() {
  const el = document.getElementById('chatTyping');
  if (el) el.remove();
}

async function sendMessage() {
  const input = $('chatInput');
  if (!input) return;
  const message = input.value.trim();
  if (!message) return;

  input.value = '';
  appendMessage('user', message);
  showTyping();

  const sendBtn = $('chatSendBtn');
  if (sendBtn) { sendBtn.disabled = true; sendBtn.classList.add('loading'); }

  try {
    const payload = { message, conversation_id: currentConversationId || undefined };
    const provider = $('chatProviderSelect')?.value || undefined;
    const model = $('chatModelSelect')?.value || undefined;
    const contentType = $('chatContentType')?.value || '';
    const platform = $('chatContentPlatform')?.value || '';
    const tone = $('chatContentTone')?.value || '';
    const audience = $('chatContentAudience')?.value?.trim() || '';
    const goal = $('chatContentGoal')?.value?.trim() || '';
    if (provider) payload.provider = provider;
    if (model) payload.model = model;
    if (contentType || platform || tone || audience || goal) {
      payload.content_brief = { content_type: contentType, platform, tone, audience, goal };
    }

    const { item } = await api('/api/ai/chat', {
      method: 'POST',
      body: JSON.stringify(payload),
    });

    hideTyping();
    appendMessage('assistant', item.reply);
    currentConversationId = item.conversation_id;
    refreshConversations();
  } catch (err) {
    hideTyping();
    appendMessage('assistant', 'Error: ' + err.message);
    error(err.message);
  } finally {
    if (sendBtn) { sendBtn.disabled = false; sendBtn.classList.remove('loading'); }
  }
}

async function loadConversation(id) {
  try {
    const { item, messages } = await api(`/api/ai/conversations/${id}`);
    currentConversationId = id;

    const el = $('chatMessages');
    if (el) {
      el.innerHTML = '';
      (messages || []).forEach((m) => appendMessage(m.role, m.content));
      scrollChatToBottom();
    }

    // Mark active
    document.querySelectorAll('.chat-conv-item').forEach((btn) => {
      btn.classList.toggle('active', parseInt(btn.dataset.convId) === id);
    });
  } catch (err) {
    error(err.message);
  }
}

async function renameConversation(id, currentTitle) {
  const newTitle = await promptInput('Rename Conversation', 'Conversation name', { defaultValue: currentTitle || 'Chat' });
  if (!newTitle || newTitle.trim() === '' || newTitle === currentTitle) return;
  try {
    await api(`/api/ai/conversations/${id}`, {
      method: 'PUT',
      body: JSON.stringify({ title: newTitle.trim() }),
    });
    success('Conversation renamed');
    refreshConversations();
  } catch (err) {
    error(err.message);
  }
}

async function deleteConversation(id) {
  if (!await confirm('Delete Conversation', 'Are you sure you want to delete this conversation?')) return;
  try {
    await api(`/api/ai/conversations/${id}`, { method: 'DELETE' });
    success('Conversation deleted');
    if (currentConversationId === id) newChat();
    refreshConversations();
  } catch (err) {
    error(err.message);
  }
}

async function refreshConversations() {
  try {
    const { items } = await api('/api/ai/conversations');
    const list = $('chatConversationList');
    if (!list) return;

    list.innerHTML = (items || []).map((c) => `
      <div class="chat-conv-item${c.id === currentConversationId ? ' active' : ''}" data-conv-id="${c.id}">
        <div class="conv-title">${escapeHtml(c.title || 'Chat')}</div>
        <div class="conv-meta">${c.message_count || 0} msgs &middot; ${c.provider || ''}</div>
        <div class="conv-actions">
          <button class="btn btn-sm btn-ghost" data-rename-conv="${c.id}" data-conv-title="${escapeHtml(c.title || 'Chat')}" title="Rename" aria-label="Rename">&#9998;</button>
          <button class="btn btn-sm btn-ghost text-danger" data-delete-conv="${c.id}" title="Delete" aria-label="Delete">&times;</button>
        </div>
      </div>
    `).join('') || '<p class="text-muted text-small">No conversations yet</p>';

    // Use event delegation to avoid listener accumulation on refresh
    list.onclick = (e) => {
      const renameBtn = e.target.closest('[data-rename-conv]');
      const deleteBtn = e.target.closest('[data-delete-conv]');
      const convItem = e.target.closest('.chat-conv-item');
      if (renameBtn) {
        e.stopPropagation();
        renameConversation(parseInt(renameBtn.dataset.renameConv, 10), renameBtn.dataset.convTitle);
      } else if (deleteBtn) {
        e.stopPropagation();
        deleteConversation(parseInt(deleteBtn.dataset.deleteConv, 10));
      } else if (convItem) {
        loadConversation(parseInt(convItem.dataset.convId, 10));
      }
    };
  } catch (err) { error('Failed to load conversations: ' + err.message); }
}

function newChat() {
  currentConversationId = 0;
  const el = $('chatMessages');
  if (el) {
    el.innerHTML = `<div class="chat-welcome">
      <div class="chat-welcome-icon">&#9733;</div>
      <h3>AI Marketing Assistant</h3>
      <p class="text-muted">Ask me anything about your marketing data, or have me create content.</p>
      <div class="chat-suggestions">
        <button class="chat-suggest-btn" data-suggest="What was my best performing platform this month?">Best performing platform?</button>
        <button class="chat-suggest-btn" data-suggest="Write me 3 Instagram posts about our latest product">Write 3 Instagram posts</button>
        <button class="chat-suggest-btn" data-suggest="Analyze my campaign performance and suggest improvements">Analyze campaigns</button>
        <button class="chat-suggest-btn" data-suggest="What content should I create this week?">Content ideas for this week</button>
      </div>
    </div>`;
    wiresuggestions();
  }
  document.querySelectorAll('.chat-conv-item').forEach((b) => b.classList.remove('active'));
}

function wiresuggestions() {
  document.querySelectorAll('.chat-suggest-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = $('chatInput');
      if (input) {
        input.value = btn.dataset.suggest;
        sendMessage();
      }
    });
  });
}

async function loadProviderModels() {
  try {
    const data = await api('/api/ai/providers');
    providerModels = {};
    if (data.providers && typeof data.providers === 'object') {
      for (const [key, info] of Object.entries(data.providers)) {
        providerModels[key] = {};
        if (info?.labels && typeof info.labels === 'object') {
          providerModels[key] = { ...info.labels };
          continue;
        }
        if (Array.isArray(info?.models)) {
          info.models.forEach((m) => { providerModels[key][m] = m; });
        }
      }
    } else if (data.models && typeof data.models === 'object') {
      // Backward-compatible fallback.
      providerModels = { ...data.models };
    }
    // Also populate provider select with available providers
    const providerSelect = $('chatProviderSelect');
    if (providerSelect && data.providers) {
      const currentVal = providerSelect.value;
      providerSelect.innerHTML = '<option value="">Auto (Default)</option>';
      for (const [key, info] of Object.entries(data.providers)) {
        if (info?.configured) {
          const opt = document.createElement('option');
          opt.value = key;
          opt.textContent = key.charAt(0).toUpperCase() + key.slice(1) + (info.model ? ` (${info.model})` : '');
          if (key === currentVal) opt.selected = true;
          providerSelect.appendChild(opt);
        }
      }
    }
  } catch (err) {
    error('Failed to load AI providers: ' + err.message);
  }
}

function updateModelSelect() {
  const providerSelect = $('chatProviderSelect');
  const modelSelect = $('chatModelSelect');
  if (!modelSelect || !providerSelect) return;
  const provider = providerSelect.value;
  if (provider && providerModels[provider]) {
    modelSelect.innerHTML = '<option value="">Default Model</option>' +
      Object.entries(providerModels[provider]).map(([k, v]) => `<option value="${k}">${v}</option>`).join('');
  } else {
    modelSelect.innerHTML = '<option value="">Default Model</option>';
  }
}

async function refreshSharedMemory() {
  const list = $('chatMemoryList');
  if (!list) return;
  try {
    const { items } = await api('/api/ai/shared-memory?limit=20');
    list.innerHTML = (items || []).map((item) => `
      <div class="chat-memory-item">
        <div class="chat-memory-title">${escapeHtml(item.memory_key || 'General memory')}</div>
        <div class="chat-memory-body">${escapeHtml(item.content || '')}</div>
        <button class="btn btn-sm btn-outline chat-memory-delete" data-memory-id="${item.id}">Delete</button>
      </div>
    `).join('') || '<p class="text-muted text-small">No shared memory yet. Add context that all AI tools will use.</p>';

    // Event delegation for memory delete buttons
    list.onclick = async (e) => {
      const btn = e.target.closest('.chat-memory-delete');
      if (!btn) return;
      if (!await confirm('Delete Memory', 'Delete this memory item? All AI tools use shared memory.')) return;
      try {
        await api(`/api/ai/shared-memory/${btn.dataset.memoryId}`, { method: 'DELETE' });
        success('Memory deleted');
        refreshSharedMemory();
      } catch (err) {
        error(err.message);
      }
    };
  } catch (err) {
    list.innerHTML = '<p class="text-muted text-small">Memory unavailable.</p>';
    error('Failed to load shared memory: ' + err.message);
  }
}

async function saveSharedMemory() {
  const content = $('chatMemoryContent')?.value?.trim();
  if (!content) {
    error('Please add memory content');
    return;
  }
  const key = $('chatMemoryKey')?.value?.trim() || '';
  const tags = $('chatMemoryTags')?.value?.trim() || '';
  try {
    await api('/api/ai/shared-memory', {
      method: 'POST',
      body: JSON.stringify({
        memory_key: key,
        content,
        tags,
        source: 'manual_ui',
      }),
    });
    if ($('chatMemoryContent')) $('chatMemoryContent').value = '';
    if ($('chatMemoryKey')) $('chatMemoryKey').value = '';
    if ($('chatMemoryTags')) $('chatMemoryTags').value = '';
    success('Shared memory saved for all AI tools');
    refreshSharedMemory();
  } catch (err) {
    error(err.message);
  }
}

export async function refresh() {
  await Promise.all([
    refreshConversations(),
    refreshSharedMemory(),
    loadProviderModels(),
  ]);
}

export function init() {
  onClick('chatSendBtn', sendMessage);
  onClick('newChatBtn', newChat);
  onClick('saveChatMemoryBtn', saveSharedMemory);

  // Enter to send (Shift+Enter for newline)
  const input = $('chatInput');
  if (input) {
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
  }

  // Provider change updates model list (fetched from API)
  const providerSelect = $('chatProviderSelect');
  if (providerSelect) {
    providerSelect.addEventListener('change', updateModelSelect);
  }

  // Wire initial suggestion buttons
  wiresuggestions();
  refreshSharedMemory();
  loadProviderModels();
}
