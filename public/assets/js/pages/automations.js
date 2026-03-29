/**
 * Automations page module — visual drag-and-drop workflow builder.
 */
import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, confirm } from '../core/utils.js';
import { toast } from '../core/toast.js';

/** Canvas workflow state */
let workflowBlocks = [];
let selectedBlockIdx = -1;
let dragData = null;

const TRIGGER_LABELS = {
  'form.submitted': 'Form Submitted',
  'contact.created': 'Contact Created',
  'contact.stage_changed': 'Stage Changed',
  'post.published': 'Post Published',
  'post.scheduled': 'Post Scheduled',
  'subscriber.added': 'Subscriber Added',
  'email.sent': 'Email Sent',
  'landing_page.conversion': 'Page Conversion',
  'link.clicked': 'Link Clicked',
};

const ACTION_LABELS = {
  'tag_contact': 'Add Tag',
  'update_contact_stage': 'Update Stage',
  'add_score': 'Add Score',
  'add_to_list': 'Add to List',
  'send_email': 'Send Email',
  'send_sms': 'Send SMS (Twilio)',
  'send_webhook': 'Send Webhook',
  'log_activity': 'Log Activity',
};

const CONDITION_LABELS = {
  'filter_field': 'Filter by Field',
  'match_tag': 'Has Tag',
  'match_stage': 'In Stage',
};

const ACTION_CONFIG_FIELDS = {
  tag_contact: [{ name: 'tag', label: 'Tag Name', placeholder: 'new-lead' }],
  update_contact_stage: [{ name: 'stage', label: 'Stage', placeholder: 'mql', type: 'select', options: ['lead', 'mql', 'sql', 'opportunity', 'customer'] }],
  add_score: [{ name: 'points', label: 'Points', placeholder: '10', type: 'number' }],
  add_to_list: [{ name: 'list_id', label: 'List ID', placeholder: '1', type: 'number' }],
  send_email: [
    { name: 'subject', label: 'Subject', placeholder: 'Thanks for joining, {{first_name}}' },
    { name: 'body_html', label: 'HTML Body', placeholder: '<p>Hi {{first_name}}, welcome!</p>' },
    { name: 'body_text', label: 'Text Body (optional)', placeholder: 'Hi {{first_name}}, welcome!' },
  ],
  send_sms: [{ name: 'message', label: 'SMS Message', placeholder: 'Hi {{first_name}}, thanks for your interest!' }],
  send_webhook: [{ name: 'url', label: 'Webhook URL', placeholder: 'https://example.com/webhook' }],
  log_activity: [{ name: 'message', label: 'Message', placeholder: 'Automation triggered' }],
};

const CONDITION_CONFIG_FIELDS = {
  filter_field: [{ name: 'field', label: 'Field Name', placeholder: 'source' }, { name: 'value', label: 'Equals', placeholder: 'form' }],
  match_tag: [{ name: 'tag', label: 'Tag Name', placeholder: 'vip' }],
  match_stage: [{ name: 'stage', label: 'Stage', placeholder: 'mql' }],
};

const TRIGGER_CONDITION_FIELDS = {
  'form.submitted': [{ name: 'source', label: 'Source', placeholder: 'form' }],
  'contact.created': [{ name: 'source', label: 'Source', placeholder: 'manual' }],
  'contact.stage_changed': [{ name: 'new_stage', label: 'New Stage', placeholder: 'mql' }],
  'post.published': [{ name: 'platform', label: 'Platform', placeholder: 'instagram' }],
  'subscriber.added': [{ name: 'list_id', label: 'List ID', placeholder: '1' }],
  'email.sent': [{ name: 'campaign_id', label: 'Campaign ID', placeholder: '1' }],
  'landing_page.conversion': [{ name: 'landing_page_id', label: 'Page ID', placeholder: '1' }],
  'link.clicked': [{ name: 'code', label: 'Link Code', placeholder: 'abc123' }],
};

export function init() {
  initDragDrop();
  initCanvasEvents();
  initToolbar();
  renderCanvas();
}

export async function refresh() {
  await loadAutomations();
}

/* ---- Drag & Drop from palette ---- */
function initDragDrop() {
  document.querySelectorAll('.automation-block-template').forEach(tpl => {
    const addFromTemplate = () => addBlock(tpl.dataset.blockType, tpl.dataset.blockEvent);

    tpl.addEventListener('dragstart', (e) => {
      dragData = {
        type: tpl.dataset.blockType,
        event: tpl.dataset.blockEvent,
      };
      e.dataTransfer.effectAllowed = 'copy';
      e.dataTransfer.setData('text/plain', JSON.stringify(dragData));
      tpl.style.opacity = '0.5';
    });
    tpl.addEventListener('dragend', () => {
      tpl.style.opacity = '';
      dragData = null;
    });

    // Click/keyboard fallback for touch devices and keyboard users.
    tpl.addEventListener('click', addFromTemplate);
    tpl.tabIndex = 0;
    tpl.setAttribute('role', 'button');
    tpl.setAttribute('aria-label', `Add ${getBlockLabel(tpl.dataset.blockType, tpl.dataset.blockEvent)} block`);
    tpl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        addFromTemplate();
      }
    });
  });

  const dropZone = $('automationDropZone');
  const canvas = $('automationCanvas');
  if (!dropZone || !canvas) return;

  canvas.addEventListener('dragover', (e) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = e.dataTransfer.effectAllowed === 'move' ? 'move' : 'copy';
    dropZone.classList.add('drag-over');

    // Show drop position indicator for reordering
    if (e.dataTransfer.effectAllowed === 'move') {
      const blocks = dropZone.querySelectorAll('.workflow-block');
      blocks.forEach(b => b.classList.remove('drop-above', 'drop-below'));
      const target = e.target.closest('.workflow-block');
      if (target && !target.classList.contains('dragging')) {
        const rect = target.getBoundingClientRect();
        const mid = rect.top + rect.height / 2;
        target.classList.add(e.clientY < mid ? 'drop-above' : 'drop-below');
      }
    }
  });

  canvas.addEventListener('dragleave', (e) => {
    if (!canvas.contains(e.relatedTarget)) {
      dropZone.classList.remove('drag-over');
      dropZone.querySelectorAll('.workflow-block').forEach(b => b.classList.remove('drop-above', 'drop-below'));
    }
  });

  canvas.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    dropZone.querySelectorAll('.workflow-block').forEach(b => b.classList.remove('drop-above', 'drop-below'));
    try {
      const data = JSON.parse(e.dataTransfer.getData('text/plain'));

      if (data.reorder) {
        // Handle block reordering
        const fromIdx = data.idx;
        let toIdx = workflowBlocks.length - 1;
        const target = e.target.closest('.workflow-block');
        if (target && !target.classList.contains('dragging')) {
          const targetIdx = parseInt(target.dataset.idx);
          const rect = target.getBoundingClientRect();
          const mid = rect.top + rect.height / 2;
          toIdx = e.clientY < mid ? targetIdx : targetIdx + 1;
          if (toIdx > fromIdx) toIdx--;
        }
        if (fromIdx !== toIdx && toIdx >= 0 && toIdx < workflowBlocks.length) {
          const [block] = workflowBlocks.splice(fromIdx, 1);
          workflowBlocks.splice(toIdx, 0, block);
          selectedBlockIdx = toIdx;
          renderCanvas();
        }
      } else {
        addBlock(data.type, data.event);
      }
    } catch (err) {
      console.warn('Automation drop: invalid drag data', err);
    }
  });
}

/* ---- Canvas events ---- */
function initCanvasEvents() {
  const canvas = $('automationCanvas');
  if (!canvas) return;

  canvas.addEventListener('click', (e) => {
    // Remove button (handle first so block selection doesn't swallow it)
    const removeBtn = e.target.closest('.workflow-block-remove');
    if (removeBtn) {
      const block = removeBtn.closest('.workflow-block');
      if (block) {
        const idx = parseInt(block.dataset.idx);
        workflowBlocks.splice(idx, 1);
        selectedBlockIdx = -1;
        renderCanvas();
      }
      return;
    }

    // Keep interactive controls usable without forcing a rerender first
    if (e.target.closest('input, select, textarea, button, label')) {
      return;
    }

    // Block selection
    const block = e.target.closest('.workflow-block');
    if (block) {
      const idx = parseInt(block.dataset.idx);
      selectedBlockIdx = idx;
      renderCanvas();
      return;
    }

    // Deselect
    if (e.target === canvas || e.target.closest('.workflow-drop-zone')) {
      selectedBlockIdx = -1;
      renderCanvas();
    }
  });

  // Config field changes
  canvas.addEventListener('input', (e) => {
    const field = e.target.closest('[data-block-field]');
    if (!field) return;
    const blockEl = field.closest('.workflow-block');
    if (!blockEl) return;
    const idx = parseInt(blockEl.dataset.idx);
    const fieldName = field.dataset.blockField;
    if (workflowBlocks[idx]) {
      workflowBlocks[idx].config[fieldName] = field.value;
    }
  });

  // Reorder via drag on canvas blocks
  canvas.addEventListener('dragstart', (e) => {
    const block = e.target.closest('.workflow-block');
    if (!block) return;
    block.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', JSON.stringify({ reorder: true, idx: parseInt(block.dataset.idx) }));
  });

  canvas.addEventListener('dragend', (e) => {
    const block = e.target.closest('.workflow-block');
    if (block) block.classList.remove('dragging');
  });
}

/* ---- Toolbar ---- */
function initToolbar() {
  const saveBtn = $('automationSaveBtn');
  const clearBtn = $('automationClearBtn');
  const newBtn = $('automationNewBtn');

  if (saveBtn) saveBtn.addEventListener('click', saveAutomation);
  if (clearBtn) clearBtn.addEventListener('click', () => {
    if (workflowBlocks.length === 0) return;
    workflowBlocks = [];
    selectedBlockIdx = -1;
    renderCanvas();
    const nameInput = $('automationName');
    if (nameInput) nameInput.value = '';
    toast('Canvas cleared', 'info');
  });
  if (newBtn) newBtn.addEventListener('click', () => {
    workflowBlocks = [];
    selectedBlockIdx = -1;
    renderCanvas();
    const nameInput = $('automationName');
    if (nameInput) nameInput.value = '';
    // Switch to builder tab
    document.querySelector('[data-tab="automations-builder"]')?.click();
  });
}

/* ---- Block operations ---- */
function addBlock(type, event) {
  // Validate: only one trigger allowed
  if (type === 'trigger' && workflowBlocks.some(b => b.type === 'trigger')) {
    toast('Only one trigger per automation', 'error');
    return;
  }

  // Default config
  const config = {};
  const fields = type === 'trigger' ? (TRIGGER_CONDITION_FIELDS[event] || [])
    : type === 'condition' ? (CONDITION_CONFIG_FIELDS[event] || [])
    : (ACTION_CONFIG_FIELDS[event] || []);
  fields.forEach(f => { config[f.name] = ''; });

  const block = { type, event, config };

  // Insert trigger at start, others at end
  if (type === 'trigger') {
    workflowBlocks.unshift(block);
  } else {
    workflowBlocks.push(block);
  }

  selectedBlockIdx = type === 'trigger' ? 0 : workflowBlocks.length - 1;
  renderCanvas();
  toast(`Added ${type}: ${getBlockLabel(type, event)}`, 'success');
}

function getBlockLabel(type, event) {
  if (type === 'trigger') return TRIGGER_LABELS[event] || event;
  if (type === 'condition') return CONDITION_LABELS[event] || event;
  return ACTION_LABELS[event] || event;
}

function getBlockFields(type, event) {
  if (type === 'trigger') return TRIGGER_CONDITION_FIELDS[event] || [];
  if (type === 'condition') return CONDITION_CONFIG_FIELDS[event] || [];
  return ACTION_CONFIG_FIELDS[event] || [];
}

/* ---- Render canvas ---- */
function renderCanvas() {
  const dropZone = $('automationDropZone');
  const empty = $('automationEmpty');
  if (!dropZone) return;

  updateBuilderStatus();

  if (workflowBlocks.length === 0) {
    dropZone.innerHTML = '';
    if (empty) {
      dropZone.appendChild(createEmptyState());
    }
    return;
  }

  dropZone.innerHTML = '';
  workflowBlocks.forEach((block, idx) => {
    // Connector line between blocks
    if (idx > 0) {
      const connector = document.createElement('div');
      connector.className = 'workflow-connector';
      connector.innerHTML = '<div class="workflow-connector-line"></div>';
      dropZone.appendChild(connector);
    }

    const el = createBlockElement(block, idx);
    dropZone.appendChild(el);
  });

  // Add "+" button at the end
  const addBtn = document.createElement('div');
  addBtn.className = 'workflow-connector';
  addBtn.innerHTML = '<button class="workflow-add-btn" title="Add block">+</button>';
  addBtn.querySelector('.workflow-add-btn').addEventListener('click', showAddMenu);
  dropZone.appendChild(addBtn);
}

function createEmptyState() {
  const div = document.createElement('div');
  div.className = 'automation-canvas-empty';
  div.id = 'automationEmpty';
  div.innerHTML = `
    <div class="empty-icon">&#9889;</div>
    <strong>Build your automation</strong>
    <span>Drag or click trigger, condition, and action blocks from the left panel</span>
  `;
  return div;
}

function createBlockElement(block, idx) {
  const el = document.createElement('div');
  el.className = `workflow-block ${block.type}-block${idx === selectedBlockIdx ? ' selected' : ''}`;
  el.dataset.idx = idx;
  el.draggable = true;

  const typeLabel = block.type.charAt(0).toUpperCase() + block.type.slice(1);
  const label = getBlockLabel(block.type, block.event);
  const fields = getBlockFields(block.type, block.event);

  let fieldsHtml = '';
  fields.forEach(f => {
    const val = escapeHtml(block.config[f.name] || '');
    if (f.type === 'select') {
      const opts = (f.options || []).map(o =>
        `<option value="${o}"${block.config[f.name] === o ? ' selected' : ''}>${o}</option>`
      ).join('');
      fieldsHtml += `<div><label>${escapeHtml(f.label)}</label><select data-block-field="${f.name}">${opts}</select></div>`;
    } else if (f.type === 'textarea' || f.name === 'body_html' || f.name === 'body_text' || f.name === 'message') {
      fieldsHtml += `<div><label>${escapeHtml(f.label)}</label><textarea data-block-field="${f.name}" rows="3" placeholder="${escapeHtml(f.placeholder || '')}">${val}</textarea></div>`;
    } else {
      const inputType = f.type === 'number' ? 'number' : 'text';
      fieldsHtml += `<div><label>${escapeHtml(f.label)}</label><input type="${inputType}" data-block-field="${f.name}" value="${val}" placeholder="${escapeHtml(f.placeholder || '')}" /></div>`;
    }
  });

  el.innerHTML = `
    <div class="workflow-block-header">
      <span class="workflow-block-type">${typeLabel}</span>
      <span class="workflow-block-title">${escapeHtml(label)}</span>
      <button class="workflow-block-remove" title="Remove block">&times;</button>
    </div>
    <div class="workflow-block-body">
      ${fieldsHtml || '<span class="text-small text-muted">No configuration needed</span>'}
    </div>
  `;

  return el;
}

function showAddMenu() {
  // Quick add: provide guidance
  toast('Add blocks from the left panel (drag, click, or Enter)', 'info');
}

function updateBuilderStatus() {
  const status = $('automationBuilderStatus');
  if (!status) return;

  const triggerCount = workflowBlocks.filter(b => b.type === 'trigger').length;
  const conditionCount = workflowBlocks.filter(b => b.type === 'condition').length;
  const actionCount = workflowBlocks.filter(b => b.type === 'action').length;
  const isReady = triggerCount === 1 && actionCount > 0;

  status.innerHTML = `
    <span class="status-chip ${triggerCount === 1 ? 'ok' : 'warn'}">Trigger ${triggerCount}/1</span>
    <span class="status-chip ${conditionCount > 0 ? 'ok' : ''}">Conditions ${conditionCount}</span>
    <span class="status-chip ${actionCount > 0 ? 'ok' : 'warn'}">Actions ${actionCount}</span>
    <span class="status-chip ${isReady ? 'ok' : 'warn'}">${isReady ? 'Ready to save' : 'Needs trigger + action'}</span>
  `;
}

/* ---- Save automation ---- */
async function saveAutomation() {
  const nameInput = $('automationName');
  const name = nameInput?.value?.trim() || 'Untitled Automation';

  // Validate
  const trigger = workflowBlocks.find(b => b.type === 'trigger');
  const actions = workflowBlocks.filter(b => b.type === 'action');
  const conditions = workflowBlocks.filter(b => b.type === 'condition');

  if (!trigger) {
    toast('Add a trigger block first (the "When..." event)', 'error');
    return;
  }
  if (actions.length === 0) {
    toast('Add at least one action block (the "Then..." step)', 'error');
    return;
  }

  // Build conditions object from condition blocks
  const conditionsObj = {};
  conditions.forEach(c => {
    Object.entries(c.config).forEach(([k, v]) => {
      if (v) conditionsObj[k] = v;
    });
  });
  // Also merge trigger config as conditions
  Object.entries(trigger.config).forEach(([k, v]) => {
    if (v) conditionsObj[k] = v;
  });

  // For each action, create an automation
  const saveBtn = $('automationSaveBtn');
  if (saveBtn) { saveBtn.classList.add('loading'); saveBtn.disabled = true; }

  try {
    for (const action of actions) {
      await api('/api/automations', {
        method: 'POST',
        body: JSON.stringify({
          name: actions.length > 1 ? `${name} (${getBlockLabel('action', action.event)})` : name,
          trigger_event: trigger.event,
          action_type: action.event,
          conditions: conditionsObj,
          action_config: action.config,
        }),
      });
    }
    toast(`Automation${actions.length > 1 ? 's' : ''} saved!`, 'success');
    workflowBlocks = [];
    selectedBlockIdx = -1;
    if (nameInput) nameInput.value = '';
    renderCanvas();
    loadAutomations();
  } catch (err) {
    toast('Save failed: ' + err.message, 'error');
  } finally {
    if (saveBtn) { saveBtn.classList.remove('loading'); saveBtn.disabled = false; }
  }
}

/* ---- Load saved automations ---- */
async function loadAutomations() {
  try {
    const data = await api('/api/automations');
    const items = data.items || data;
    const listView = $('automationListView');
    if (!listView) return;

    if (items.length === 0) {
      listView.innerHTML = `
        <div class="empty-state">
          <div class="empty-state-icon">&#9889;</div>
          <h3>No automations yet</h3>
          <p>Use the Visual Builder to create trigger-action workflows that automate your marketing.</p>
        </div>
      `;
      return;
    }

    listView.innerHTML = items.map(a => `
      <div class="automation-list-card">
        <div class="automation-list-icon">&#9889;</div>
        <div class="automation-list-info">
          <strong>${escapeHtml(a.name)}</strong>
          <span>
            <span class="badge badge-dot" style="color:${a.is_active ? 'var(--success)' : 'var(--text-muted)'}">${a.is_active ? 'Active' : 'Paused'}</span>
            &nbsp; ${escapeHtml(TRIGGER_LABELS[a.trigger_event] || a.trigger_event)} &rarr; ${escapeHtml(ACTION_LABELS[a.action_type] || a.action_type)}
          </span>
        </div>
        <div class="automation-list-stats">
          <span>${a.run_count} runs</span>
          <span>${a.last_run ? formatDate(a.last_run) : 'Never run'}</span>
        </div>
        <div class="automation-list-actions">
          <button class="btn btn-sm ${a.is_active ? 'btn-success' : 'btn-outline'}" data-toggle-auto="${a.id}" data-active="${a.is_active ? 0 : 1}">
            ${a.is_active ? 'Active' : 'Paused'}
          </button>
          <button class="btn btn-sm btn-danger" data-delete-auto="${a.id}">Del</button>
        </div>
      </div>
    `).join('');

    // Event delegation (use onclick to prevent accumulation on refresh)
    listView.onclick = handleListClick;
  } catch (err) {
    toast('Failed to load automations: ' + err.message, 'error');
  }
}

async function handleListClick(e) {
  const toggleBtn = e.target.closest('[data-toggle-auto]');
  if (toggleBtn) {
    const id = toggleBtn.dataset.toggleAuto;
    const active = parseInt(toggleBtn.dataset.active);
    if (!active && !await confirm('Pause Automation', 'Are you sure you want to pause this automation?', { okText: 'Pause', okClass: 'btn-warning' })) return;
    try {
      await api(`/api/automations/${id}`, { method: 'PATCH', body: JSON.stringify({ is_active: active }) });
      toast(active ? 'Activated' : 'Paused', 'success');
      loadAutomations();
    } catch (err) { toast(err.message, 'error'); }
    return;
  }

  const deleteBtn = e.target.closest('[data-delete-auto]');
  if (deleteBtn) {
    const id = deleteBtn.dataset.deleteAuto;
    if (!await confirm('Delete Automation', 'This cannot be undone.')) return;
    try {
      await api(`/api/automations/${id}`, { method: 'DELETE' });
      toast('Deleted', 'success');
      loadAutomations();
    } catch (err) { toast(err.message, 'error'); }
  }
}
