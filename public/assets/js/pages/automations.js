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
}

export async function refresh() {
  await loadAutomations();
}

/* ---- Drag & Drop from palette ---- */
function initDragDrop() {
  document.querySelectorAll('.automation-block-template').forEach(tpl => {
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
  });

  const dropZone = $('automationDropZone');
  const canvas = $('automationCanvas');
  if (!dropZone || !canvas) return;

  canvas.addEventListener('dragover', (e) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    dropZone.classList.add('drag-over');
  });

  canvas.addEventListener('dragleave', (e) => {
    if (!canvas.contains(e.relatedTarget)) {
      dropZone.classList.remove('drag-over');
    }
  });

  canvas.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    try {
      const data = JSON.parse(e.dataTransfer.getData('text/plain'));
      addBlock(data.type, data.event);
    } catch {}
  });
}

/* ---- Canvas events ---- */
function initCanvasEvents() {
  const canvas = $('automationCanvas');
  if (!canvas) return;

  canvas.addEventListener('click', (e) => {
    // Block selection
    const block = e.target.closest('.workflow-block');
    if (block) {
      const idx = parseInt(block.dataset.idx);
      selectedBlockIdx = idx;
      renderCanvas();
      return;
    }

    // Remove button
    if (e.target.closest('.workflow-block-remove')) {
      const block = e.target.closest('.workflow-block');
      if (block) {
        const idx = parseInt(block.dataset.idx);
        workflowBlocks.splice(idx, 1);
        selectedBlockIdx = -1;
        renderCanvas();
      }
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
    <span>Drag trigger, condition, and action blocks from the left panel</span>
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
  // Quick add: just show toast with guidance
  toast('Drag a block from the left panel to add it', 'info');
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

    // Event delegation
    listView.addEventListener('click', handleListClick);
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
