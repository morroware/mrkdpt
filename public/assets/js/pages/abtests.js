/**
 * A/B Testing page module.
 */
import { api } from '../core/api.js';
import { $, escapeHtml, formatDate } from '../core/utils.js';
import { toast } from '../core/toast.js';

export function init() {
  $('abTestForm')?.addEventListener('submit', handleCreate);

  // AI Analyze button in the form area
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
          // Fill variant B with the first AI variation
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
              <div style="background:var(--bg-tertiary);border-radius:4px;height:8px;margin-top:4px"><div style="background:var(--accent);height:100%;border-radius:4px;width:${pct}%;transition:width .3s"></div></div>
              ${v.content ? `<p class="text-small text-muted mt-1" style="max-height:60px;overflow:hidden">${escapeHtml(v.content)}</p>` : ''}
              <div class="btn-group mt-1">
                <button class="btn btn-sm btn-outline" onclick="window._abImpression(${v.id})">+Impression</button>
                <button class="btn btn-sm btn-success" onclick="window._abConversion(${v.id})">+Conversion</button>
              </div>
            </div>`;
          }).join('')}
        </div>
        <div class="btn-group mt-1">
          ${t.status === 'running' ? `<button class="btn btn-sm btn-outline" onclick="window._completeTest(${t.id})">Complete Test</button>` : ''}
          <button class="btn btn-sm btn-ai" onclick="window._aiAnalyzeTest(${t.id})"><span class="btn-ai-icon">&#9733;</span> AI Analyze</button>
          <button class="btn btn-sm btn-danger" onclick="window._deleteTest(${t.id})">Delete</button>
        </div>
      </div>`;
    }).join('') || '<p class="text-muted">No A/B tests yet</p>';
  } catch (err) {
    toast('Failed to load A/B tests: ' + err.message, 'error');
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

window._abImpression = async (variantId) => {
  try {
    await api(`/api/ab-tests/variants/${variantId}/impression`, { method: 'POST' });
    refresh();
  } catch (err) {
    toast('Failed to record impression: ' + err.message, 'error');
  }
};

window._abConversion = async (variantId) => {
  try {
    await api(`/api/ab-tests/variants/${variantId}/conversion`, { method: 'POST' });
    toast('Conversion recorded', 'success');
    refresh();
  } catch (err) {
    toast('Failed to record conversion: ' + err.message, 'error');
  }
};

window._completeTest = async (id) => {
  const winner = prompt('Enter winning variant name (or leave empty):');
  try {
    await api(`/api/ab-tests/${id}`, { method: 'PATCH', body: JSON.stringify({ status: 'completed', winner_variant: winner || '' }) });
    toast('Test completed', 'success');
    refresh();
  } catch (err) { toast(err.message, 'error'); }
};

window._deleteTest = async (id) => {
  if (!confirm('Delete this test?')) return;
  try { await api(`/api/ab-tests/${id}`, { method: 'DELETE' }); toast('Deleted', 'success'); refresh(); } catch (e) { toast(e.message, 'error'); }
};

window._aiAnalyzeTest = async (id) => {
  try {
    const { item } = await api('/api/ai/ab-analyze', { method: 'POST', body: JSON.stringify({ test_id: id }) });
    if (item?.analysis) {
      alert(item.analysis.slice(0, 2000));
    }
  } catch (e) { toast(e.message, 'error'); }
};

// AI Analyze button - wired in init() via event delegation

