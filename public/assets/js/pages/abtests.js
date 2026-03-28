/**
 * A/B Testing page module.
 */
import { api } from '../core/api.js';
import { $, escapeHtml, formatDate, emptyState, confirm } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('abTestForm')?.addEventListener('submit', handleCreate);

  const aiAnalyzeBtn = document.getElementById('aiAnalyzeAb');
  if (aiAnalyzeBtn) {
    aiAnalyzeBtn.addEventListener('click', () => {
      toast('Select a test from the list below and click "AI Analyze" on it', 'info');
    });
  }

  // AI Generate A/B Test Variants
  const aiBtn = document.getElementById('aiGenerateVariant');
  if (aiBtn) {
    aiBtn.addEventListener('click', async () => {
      const form = $('abTestForm');
      if (!form) return;
      const name = form.querySelector('[name="name"]')?.value || '';
      const testType = form.querySelector('[name="test_type"]')?.value || 'content';
      const controlContent = form.querySelector('[name="variant_a_content"]')?.value || '';
      if (!name && !controlContent) { toast('Enter a test name or control content first', 'error'); return; }
      aiBtn.classList.add('loading');
      aiBtn.disabled = true;
      try {
        const { item } = await api('/api/ai/ad-variations', {
          method: 'POST',
          body: JSON.stringify({
            base_ad: controlContent || `${testType} for: ${name}`,
            count: 2,
          }),
        });
        if (item?.variations) {
          const varBContent = form.querySelector('[name="variant_b_content"]');
          const varBName = form.querySelector('[name="variant_b_name"]');
          if (varBContent) varBContent.value = item.variations.slice(0, 500);
          if (varBName && varBName.value === 'Variation') varBName.value = 'AI Variation';
          toast('AI variant generated', 'success');
        }
      } catch (err) { toast(err.message, 'error'); }
      finally { aiBtn.classList.remove('loading'); aiBtn.disabled = false; }
    });
  }

  // Event delegation for test list actions
  const testList = $('abTestList');
  if (testList) {
    testList.addEventListener('click', handleTestListClick);
  }
}

export async function refresh() {
  await loadTests();
}

async function loadTests() {
  try {
    const data = await api('/api/ab-tests');
    const items = data.items || data;
    const el = $('abTestList');
    if (!el) return;
    el.innerHTML = items.map(t => {
      const variants = t.variants || [];
      const maxConv = Math.max(...variants.map(v => v.conversion_rate || 0), 1);
      return `<div class="card">
        <div class="flex-between">
          <h3>${escapeHtml(t.name)}</h3>
          <span class="badge badge-${t.status === 'running' ? 'success' : t.status === 'completed' ? 'info' : 'muted'}">${t.status}</span>
        </div>
        <p class="text-muted text-small">${escapeHtml(t.test_type)} test &middot; Metric: ${escapeHtml(t.metric)} &middot; Started ${formatDate(t.started_at)}${t.winner_variant ? ' &middot; Winner: ' + escapeHtml(t.winner_variant) : ''}</p>
        ${t.notes ? `<p class="text-small mt-1">${escapeHtml(t.notes)}</p>` : ''}
        <div class="mt-1">
          ${variants.map(v => {
            const pct = maxConv > 0 ? Math.round((v.conversion_rate / maxConv) * 100) : 0;
            return `<div class="mb-1">
              <div class="flex-between"><span><strong>${escapeHtml(v.variant_name)}</strong></span><span>${v.conversions}/${v.impressions} (${v.conversion_rate}%)</span></div>
              <div class="progress"><div class="progress-bar" style="width:${pct}%"></div></div>
              ${v.content ? `<p class="text-small text-muted mt-1" style="max-height:60px;overflow:hidden">${escapeHtml(v.content)}</p>` : ''}
              <div class="btn-group mt-1">
                <button class="btn btn-sm btn-outline" data-ab-impression="${v.id}">+Impression</button>
                <button class="btn btn-sm btn-success" data-ab-conversion="${v.id}">+Conversion</button>
              </div>
            </div>`;
          }).join('')}
        </div>
        <div class="btn-group mt-1">
          ${t.status === 'running' ? `<button class="btn btn-sm btn-outline" data-ab-complete="${t.id}">Complete Test</button>` : ''}
          <button class="btn btn-sm btn-ai" data-ab-analyze="${t.id}"><span class="btn-ai-icon">&#9733;</span> AI Analyze</button>
          <button class="btn btn-sm btn-danger" data-ab-delete="${t.id}">Delete</button>
        </div>
      </div>`;
    }).join('') || emptyState('&#9878;', 'No A/B tests yet', 'Create your first test to compare content variants and optimize performance.');
  } catch (err) {
    toast('Failed to load A/B tests: ' + err.message, 'error');
  }
}

async function handleTestListClick(e) {
  const impressionBtn = e.target.closest('[data-ab-impression]');
  if (impressionBtn) {
    try {
      await api(`/api/ab-tests/variants/${impressionBtn.dataset.abImpression}/impression`, { method: 'POST' });
      refresh();
    } catch (err) { toast('Failed to record impression: ' + err.message, 'error'); }
    return;
  }

  const conversionBtn = e.target.closest('[data-ab-conversion]');
  if (conversionBtn) {
    try {
      await api(`/api/ab-tests/variants/${conversionBtn.dataset.abConversion}/conversion`, { method: 'POST' });
      toast('Conversion recorded', 'success');
      refresh();
    } catch (err) { toast('Failed to record conversion: ' + err.message, 'error'); }
    return;
  }

  const completeBtn = e.target.closest('[data-ab-complete]');
  if (completeBtn) {
    const id = completeBtn.dataset.abComplete;
    let variantNames = [];
    try {
      const resp = await api(`/api/ab-tests/${id}`);
      const test = resp.item || resp;
      variantNames = (test.variants || []).map(v => v.variant_name);
    } catch (err) { toast('Could not load variant details: ' + err.message, 'error'); }

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay visible';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-label', 'Complete A/B Test');
    overlay.innerHTML = `<div class="modal-content">
      <div class="modal-header"><h3>Complete A/B Test</h3><button class="modal-close" aria-label="Close">&times;</button></div>
      <div class="modal-body">
        <label>Winner (optional)</label>
        ${variantNames.length ? `<select id="completeTestWinner">
          <option value="">No winner / Inconclusive</option>
          ${variantNames.map(n => `<option value="${escapeHtml(n)}">${escapeHtml(n)}</option>`).join('')}
        </select>` : `<input type="text" id="completeTestWinner" placeholder="Enter winning variant name (or leave empty)">`}
        <div class="btn-group mt-1" style="justify-content:flex-end">
          <button class="btn btn-outline" id="cancelCompleteTest">Cancel</button>
          <button class="btn btn-success" id="confirmCompleteTest">Complete Test</button>
        </div>
      </div>
    </div>`;
    document.body.appendChild(overlay);

    const cleanup = () => overlay.remove();
    overlay.querySelector('.modal-close').addEventListener('click', cleanup);
    overlay.querySelector('#cancelCompleteTest').addEventListener('click', cleanup);
    overlay.addEventListener('click', (ev) => { if (ev.target === overlay) cleanup(); });
    overlay.querySelector('#confirmCompleteTest').addEventListener('click', async () => {
      const winner = document.getElementById('completeTestWinner')?.value || '';
      cleanup();
      try {
        await api(`/api/ab-tests/${id}`, { method: 'PATCH', body: JSON.stringify({ status: 'completed', winner_variant: winner }) });
        toast('Test completed', 'success');
        refresh();
      } catch (err) { toast(err.message, 'error'); }
    });
    return;
  }

  const analyzeBtn = e.target.closest('[data-ab-analyze]');
  if (analyzeBtn) {
    const id = analyzeBtn.dataset.abAnalyze;
    const card = document.getElementById('abAnalysisCard');
    const output = document.getElementById('abAnalysisOutput');
    if (card) card.classList.remove('hidden');
    if (output) output.textContent = 'Analyzing test... please wait.';
    analyzeBtn.classList.add('loading');
    analyzeBtn.disabled = true;
    try {
      const { item } = await api('/api/ai/ab-analyze', { method: 'POST', body: JSON.stringify({ test_id: id }) });
      if (item?.analysis) {
        if (output) { output.textContent = item.analysis; card?.scrollIntoView({ behavior: 'smooth' }); }
      } else {
        if (output) output.textContent = 'No analysis available.';
      }
    } catch (err) {
      if (output) output.textContent = 'Error: ' + err.message;
      toast(err.message, 'error');
    } finally {
      analyzeBtn.classList.remove('loading');
      analyzeBtn.disabled = false;
    }
    return;
  }

  const deleteBtn = e.target.closest('[data-ab-delete]');
  if (deleteBtn) {
    if (!await confirm('Delete Test', 'Are you sure you want to delete this A/B test? This cannot be undone.')) return;
    try {
      await api(`/api/ab-tests/${deleteBtn.dataset.abDelete}`, { method: 'DELETE' });
      toast('Deleted', 'success');
      refresh();
    } catch (err) { toast(err.message, 'error'); }
  }
}

async function handleCreate(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());

  const variants = [];
  if (data.variant_a_name) variants.push({ variant_name: data.variant_a_name, content: data.variant_a_content || '' });
  if (data.variant_b_name) variants.push({ variant_name: data.variant_b_name, content: data.variant_b_content || '' });
  delete data.variant_a_name; delete data.variant_a_content;
  delete data.variant_b_name; delete data.variant_b_content;
  data.variants = variants;

  try {
    await api('/api/ab-tests', { method: 'POST', body: JSON.stringify(data) });
    toast('A/B test created', 'success');
    e.target.reset();
    refresh();
  } catch (err) {
    toast(err.message, 'error');
  }
}
