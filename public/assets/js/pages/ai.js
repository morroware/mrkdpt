/**
 * AI Studio — 35+ AI-powered tools with categories, rich output, multi-provider,
 * multi-model selection, copy/use actions, and image generation.
 */

import { api } from '../core/api.js';
import { $, onClick, copyToClipboard, escapeHtml } from '../core/utils.js';
import { success, error } from '../core/toast.js';
import { navigate } from '../core/router.js';

let lastOutput = '';
let lastProvider = '';
let lastTool = '';
let lastBrandProfile = null;
let outputView = 'rendered'; // 'rendered' or 'raw'

function setOutputMeta(provider, tool) {
  lastProvider = provider || '';
  lastTool = tool || '';
  const meta = $('aiOutputMeta');
  if (meta) {
    const parts = [];
    if (provider) parts.push(`Provider: ${provider}`);
    if (tool) parts.push(`Tool: ${tool}`);
    parts.push(`Generated: ${new Date().toLocaleTimeString()}`);
    meta.textContent = parts.join('  |  ');
  }
}

/** Simple markdown-to-HTML renderer */
function renderMarkdown(text) {
  if (!text) return '';
  const sanitized = escapeHtml(String(text));
  let html = sanitized
    // Code blocks
    .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
    // Inline code
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    // Headers
    .replace(/^### (.+)$/gm, '<h3>$1</h3>')
    .replace(/^## (.+)$/gm, '<h2>$1</h2>')
    .replace(/^# (.+)$/gm, '<h1>$1</h1>')
    // Bold + italic
    .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    // Blockquotes
    .replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>')
    // Horizontal rules
    .replace(/^---$/gm, '<hr>')
    // Unordered lists — mark with data attribute to distinguish
    .replace(/^[*-] (.+)$/gm, '<li data-list="ul">$1</li>')
    // Ordered lists — mark with data attribute
    .replace(/^\d+\. (.+)$/gm, '<li data-list="ol">$1</li>')
    // Wrap consecutive unordered <li> in <ul>
    .replace(/((?:<li data-list="ul">.*<\/li>\n?)+)/g, (m) => '<ul>' + m.replace(/ data-list="ul"/g, '') + '</ul>')
    // Wrap consecutive ordered <li> in <ol>
    .replace(/((?:<li data-list="ol">.*<\/li>\n?)+)/g, (m) => '<ol>' + m.replace(/ data-list="ol"/g, '') + '</ol>')
    // Line breaks (paragraphs)
    .replace(/\n\n/g, '</p><p>')
    .replace(/\n/g, '<br>');

  // Wrap in paragraph if not already wrapped
  if (!html.startsWith('<')) html = '<p>' + html + '</p>';
  return html;
}

function output(text, provider, tool) {
  const el = $('aiOutput');
  const richEl = $('aiOutputRich');
  const raw = typeof text === 'string' ? text : JSON.stringify(text, null, 2);
  lastOutput = raw;
  if (el) el.textContent = raw;
  if (richEl) richEl.innerHTML = renderMarkdown(raw);
  setOutputMeta(provider, tool);
  // Show the active view
  showOutputView(outputView);
  const panel = $('aiOutputPanel');
  if (panel && window.innerWidth <= 1024) {
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

function showOutputView(view) {
  outputView = view;
  const raw = $('aiOutput');
  const rich = $('aiOutputRich');
  if (view === 'rendered') {
    if (raw) raw.classList.add('hidden');
    if (rich) rich.classList.remove('hidden');
  } else {
    if (raw) raw.classList.remove('hidden');
    if (rich) rich.classList.add('hidden');
  }
  document.querySelectorAll('.ai-output-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.outputView === view);
  });
}

function loading(toolName) {
  const el = $('aiOutput');
  const richEl = $('aiOutputRich');
  if (el) el.textContent = `Generating ${toolName || ''}... please wait.`;
  if (richEl) richEl.innerHTML = `<p class="text-muted" style="animation: subtlePulse 1.5s infinite">Generating ${escapeHtml(toolName || '')}... please wait.</p>`;
  const meta = $('aiOutputMeta');
  if (meta) meta.textContent = 'Processing...';
}

function setButtonLoading(btnId, isLoading) {
  const btn = $(btnId);
  if (!btn) return;
  if (isLoading) { btn.classList.add('loading'); btn.disabled = true; }
  else { btn.classList.remove('loading'); btn.disabled = false; }
}

async function run(endpoint, payload, resultKey, btnId, toolName) {
  loading(toolName);
  if (btnId) setButtonLoading(btnId, true);
  const selectedProvider = $('aiProviderSelect')?.value;
  if (selectedProvider && selectedProvider !== 'multi') {
    payload.provider = selectedProvider;
  }
  try {
    const { item } = await api(endpoint, { method: 'POST', body: JSON.stringify(payload) });
    const text = resultKey ? item[resultKey] : item;
    output(typeof text === 'string' ? text : JSON.stringify(text, null, 2), item?.provider, toolName);
    success('Generated successfully');
    // Fetch and show next-action suggestions
    loadNextActions(toolName);
    return item;
  } catch (err) {
    output('Error: ' + err.message, '', toolName);
    error(err.message);
    return null;
  } finally {
    if (btnId) setButtonLoading(btnId, false);
  }
}

/** Show suggested next AI tools after a tool run. */
async function loadNextActions(toolName) {
  const el = $('aiNextActions');
  if (!el) return;
  // Map display tool names to API tool identifiers
  const toolMap = {
    'Content': 'content', 'Blog Post': 'blog-post', 'Video Script': 'video-script',
    'Caption Batch': 'caption-batch', 'Repurpose': 'repurpose', 'Ad Variations': 'ad-variations',
    'Subject Lines': 'subject-lines', 'Content Brief': 'brief', 'Headlines': 'headlines',
    'Refine': 'refine', 'Workflow': 'workflow', 'Research': 'research', 'Ideas': 'ideas',
    'Persona': 'persona', 'Competitor Analysis': 'competitor-analysis',
    'Social Strategy': 'social-strategy', 'Calendar': 'calendar', 'Calendar Month': 'calendar-month',
    'Smart Times': 'smart-times', 'Campaign Optimizer': 'campaign-optimizer',
    'Score': 'score', 'Tone Analysis': 'tone-analysis', 'SEO Keywords': 'seo-keywords',
    'Hashtags': 'hashtags', 'SEO Audit': 'seo-audit', 'Pre-Flight': 'preflight',
    'Predict': 'predict', 'Competitor Radar': 'competitor-radar',
  };
  const apiTool = toolMap[toolName] || toolName?.toLowerCase()?.replace(/\s+/g, '-') || '';
  if (!apiTool) { el.innerHTML = ''; return; }
  try {
    const data = await api(`/api/ai/pipelines/next-actions?tool=${encodeURIComponent(apiTool)}`);
    const items = data.items || [];
    if (items.length === 0) { el.innerHTML = ''; return; }
    el.innerHTML = `<div style="margin-top:8px;padding:8px 12px;background:var(--input-bg);border-radius:var(--radius)">
      <span class="text-small text-muted" style="font-weight:600">Suggested next:</span>
      <div class="flex flex-wrap gap-1" style="margin-top:4px">
        ${items.map(a => `<button class="btn btn-ai btn-sm" title="${escapeHtml(a.reason)}" style="font-size:11px;padding:3px 10px">${escapeHtml(a.tool)}</button>`).join('')}
      </div>
    </div>`;
    el.querySelectorAll('.btn-ai').forEach(btn => {
      btn.addEventListener('click', () => {
        const name = btn.textContent.trim().toLowerCase();
        const cards = document.querySelectorAll('.ai-tool-card');
        for (const card of cards) {
          const h3 = card.querySelector('h3');
          if (h3 && h3.textContent.trim().toLowerCase() === name) {
            expandCard(card);
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            break;
          }
        }
      });
    });
  } catch {
    el.innerHTML = '';
  }
}

function initCategoryTabs() {
  document.querySelectorAll('.ai-cat-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.ai-cat-btn').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      const cat = btn.dataset.aiCat;
      const searchVal = $('aiToolSearch')?.value?.toLowerCase() || '';
      document.querySelectorAll('.ai-tool-card').forEach((card) => {
        const matchesCat = cat === 'all' || card.dataset.aiCat === cat;
        const matchesSearch = !searchVal || cardMatchesSearch(card, searchVal);
        card.style.display = (matchesCat && matchesSearch) ? '' : 'none';
      });
      updateToolCount();
    });
  });
}

/** Tool search functionality */
function initToolSearch() {
  const searchInput = $('aiToolSearch');
  if (!searchInput) return;

  searchInput.addEventListener('input', () => {
    const val = searchInput.value.toLowerCase().trim();
    const activeCat = document.querySelector('.ai-cat-btn.active')?.dataset.aiCat || 'all';

    document.querySelectorAll('.ai-tool-card').forEach((card) => {
      const matchesCat = activeCat === 'all' || card.dataset.aiCat === activeCat;
      const matchesSearch = !val || cardMatchesSearch(card, val);
      card.style.display = (matchesCat && matchesSearch) ? '' : 'none';
    });
    updateToolCount();
  });

  // Keyboard shortcut: / to focus search
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.ctrlKey && !e.metaKey) {
      const active = document.activeElement;
      if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) return;
      const page = document.querySelector('#page-ai.active');
      if (!page) return;
      e.preventDefault();
      searchInput.focus();
    }
  });
}

function cardMatchesSearch(card, query) {
  const title = card.querySelector('h3')?.textContent?.toLowerCase() || '';
  const desc = card.querySelector('.ai-tool-desc')?.textContent?.toLowerCase() || '';
  return title.includes(query) || desc.includes(query);
}

function updateToolCount() {
  const countEl = $('aiToolCount');
  if (!countEl) return;
  const visible = document.querySelectorAll('.ai-tool-card:not([style*="display: none"])').length;
  const total = document.querySelectorAll('.ai-tool-card').length;
  countEl.textContent = visible < total ? `${visible}/${total}` : `${total} tools`;
}

/** Collapsible tool cards */
function initCollapsibleCards() {
  document.querySelectorAll('.ai-tool-card').forEach((card) => {
    const header = card.querySelector('.ai-tool-header');
    if (!header) return;

    // Add chevron indicator
    if (!header.querySelector('.ai-tool-chevron')) {
      const chevron = document.createElement('span');
      chevron.className = 'ai-tool-chevron';
      chevron.innerHTML = '&#9660;';
      header.appendChild(chevron);
    }

    // Wrap body content if not already wrapped
    let body = card.querySelector('.ai-tool-card-body');
    if (!body) {
      const desc = card.querySelector('.ai-tool-desc');
      const bodyContent = [];
      let sibling = desc?.nextElementSibling;
      while (sibling) {
        bodyContent.push(sibling);
        sibling = sibling.nextElementSibling;
      }
      if (bodyContent.length > 0) {
        const wrapper = document.createElement('div');
        wrapper.className = 'ai-tool-card-body';
        bodyContent.forEach(el => wrapper.appendChild(el));
        card.appendChild(wrapper);
        body = wrapper;
      }
    }

    // Set initial max-height after layout is complete
    if (body) {
      requestAnimationFrame(() => {
        if (!card.classList.contains('collapsed')) {
          body.style.maxHeight = body.scrollHeight + 'px';
        }
      });
    }

    // Toggle collapse on header click
    header.addEventListener('click', (e) => {
      // Don't collapse if clicking a button/input inside header
      if (e.target.closest('button') || e.target.closest('input') || e.target.closest('select')) return;
      card.classList.toggle('collapsed');
      const bodyEl = card.querySelector('.ai-tool-card-body');
      if (bodyEl) {
        if (card.classList.contains('collapsed')) {
          bodyEl.style.maxHeight = '0';
        } else {
          // Use requestAnimationFrame to get correct scrollHeight after class toggle
          requestAnimationFrame(() => {
            bodyEl.style.maxHeight = bodyEl.scrollHeight + 'px';
          });
        }
      }
    });
  });
}

/** Expand a card by removing collapsed state and recalculating height */
function expandCard(card) {
  if (!card) return;
  card.classList.remove('collapsed');
  card.style.display = '';
  const body = card.querySelector('.ai-tool-card-body');
  if (body) {
    requestAnimationFrame(() => {
      body.style.maxHeight = body.scrollHeight + 'px';
    });
  }
}

/** Output view tabs */
function initOutputTabs() {
  document.querySelectorAll('.ai-output-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      showOutputView(tab.dataset.outputView);
    });
  });
}

export function refresh() {
  api('/api/ai/providers').then((status) => {
    const badge = $('providerBadge');
    if (badge && status.active_provider) badge.textContent = `Provider: ${status.active_provider}`;
    // Show image gen section if any image provider is available
    const imgSection = $('imageGenSection');
    if (imgSection && (status.has_banana_key || status.has_openai_key)) {
      imgSection.classList.remove('hidden');
    }
  }).catch(() => {});

  api('/api/campaigns').then(({ items }) => {
    const sel = $('aiOptCampaignSelect');
    if (sel) {
      sel.innerHTML = '<option value="">Select a campaign...</option>' +
        (items || []).map((c) => `<option value="${c.id}">${c.name} (${c.channel})</option>`).join('');
    }
  }).catch(() => {});
}

export function init() {
  initCategoryTabs();
  initToolSearch();
  initCollapsibleCards();
  initOutputTabs();
  updateToolCount();

  // ---- Original Content Creation Tools ----
  onClick('runContent', () => {
    run('/api/ai/content', {
      content_type: $('aiContentType')?.value || 'social_post',
      tone: $('aiTone')?.value || 'professional',
      platform: $('aiContentPlatform')?.value || 'facebook',
      topic: $('aiContentTopic')?.value || '',
      audience: $('aiContentAudience')?.value || '',
      goal: $('aiContentGoal')?.value || '',
      quality_mode: $('aiContentQualityMode')?.value || 'enhanced',
    }, 'content', 'runContent', 'Content Writer');
  });

  onClick('runBlog', () => {
    run('/api/ai/blog-post', {
      title: $('aiBlogTitle')?.value || '',
      keywords: $('aiBlogKeywords')?.value || '',
      outline: $('aiBlogOutline')?.value || null,
    }, 'post', 'runBlog', 'Blog Post');
  });

  onClick('runVideoScript', () => {
    run('/api/ai/video-script', {
      topic: $('aiVideoTopic')?.value || '',
      platform: $('aiVideoPlatform')?.value || 'tiktok',
      duration: parseInt($('aiVideoDuration')?.value || '60'),
    }, 'script', 'runVideoScript', 'Video Script');
  });

  onClick('runCaptionBatch', () => {
    const platforms = [...document.querySelectorAll('.caption-platform:checked')].map((c) => c.value);
    run('/api/ai/caption-batch', { topic: $('aiCaptionTopic')?.value || '', platforms, count: 3 }, 'captions', 'runCaptionBatch', 'Caption Batch');
  });

  onClick('runRepurpose', () => {
    const formats = [...document.querySelectorAll('.repurpose-fmt:checked')].map((c) => c.value);
    run('/api/ai/repurpose', { content: $('aiRepurposeContent')?.value || '', formats }, 'results', 'runRepurpose', 'Content Repurpose');
  });

  onClick('runAdVariations', () => {
    run('/api/ai/ad-variations', { base_ad: $('aiBaseAd')?.value || '', count: parseInt($('aiAdCount')?.value || '5') }, 'variations', 'runAdVariations', 'Ad Variations');
  });

  onClick('runSubjectLines', () => {
    run('/api/ai/subject-lines', { topic: $('aiSubjectTopic')?.value || '', count: parseInt($('aiSubjectCount')?.value || '10') }, 'subjects', 'runSubjectLines', 'Email Subject Lines');
  });

  onClick('runBrief', () => {
    run('/api/ai/brief', { topic: $('aiBriefTopic')?.value || '', content_type: $('aiBriefType')?.value || 'blog_post', goal: $('aiBriefGoal')?.value || '' }, 'brief', 'runBrief', 'Content Brief');
  });

  onClick('runHeadlines', () => {
    run('/api/ai/headlines', { headline: $('aiHeadlineText')?.value || '', platform: $('aiHeadlinePlatform')?.value || 'blog' }, 'headlines', 'runHeadlines', 'Headline Optimizer');
  });

  // ---- Research & Strategy ----
  onClick('runResearch', () => {
    run('/api/ai/research', { audience: $('aiAudience')?.value || '', goal: $('aiGoal')?.value || '' }, 'brief', 'runResearch', 'Market Research');
  });

  onClick('runIdeas', () => {
    run('/api/ai/ideas', { topic: $('aiTopic')?.value || '', platform: $('aiIdeasPlatform')?.value || 'instagram' }, 'ideas', 'runIdeas', 'Content Ideas');
  });

  onClick('runPersona', () => {
    run('/api/ai/persona', { demographics: $('aiPersonaDemographics')?.value || '', behaviors: $('aiPersonaBehaviors')?.value || '' }, 'persona', 'runPersona', 'Audience Persona');
  });

  onClick('runCompAnalysis', () => {
    run('/api/ai/competitor-analysis', { name: $('aiCompName')?.value || '', notes: $('aiCompNotes')?.value || '' }, 'analysis', 'runCompAnalysis', 'Competitor Analysis');
  });

  onClick('runSocialStrategy', () => {
    run('/api/ai/social-strategy', { goals: $('aiStrategyGoals')?.value || '', current_state: $('aiStrategyState')?.value || '' }, 'strategy', 'runSocialStrategy', 'Social Strategy');
  });

  // ---- Optimization ----
  onClick('runSeoKeywords', () => {
    run('/api/ai/seo-keywords', { topic: $('aiSeoTopic')?.value || '', niche: $('aiSeoNiche')?.value || '' }, 'keywords', 'runSeoKeywords', 'SEO Keywords');
  });

  onClick('runHashtags', () => {
    run('/api/ai/hashtags', { topic: $('aiHashtagTopic')?.value || '', platform: $('aiHashtagPlatform')?.value || 'instagram' }, 'hashtags', 'runHashtags', 'Hashtag Research');
  });

  onClick('runScore', () => {
    run('/api/ai/score', { content: $('aiScoreContent')?.value || '', platform: $('aiScorePlatform')?.value || 'instagram' }, 'score', 'runScore', 'Content Scorer');
  });

  onClick('runSeoAudit', () => {
    run('/api/ai/seo-audit', { url: $('aiAuditUrl')?.value || '', description: $('aiAuditDescription')?.value || '' }, 'audit', 'runSeoAudit', 'SEO Audit');
  });

  // ---- Analytics & Reports ----
  onClick('runCalendar', () => {
    run('/api/ai/calendar', { objective: $('aiCalendarGoal')?.value || '' }, 'schedule', 'runCalendar', 'Posting Calendar');
  });

  onClick('runWeeklyReport', () => {
    run('/api/ai/report', {}, 'report', 'runWeeklyReport', 'Weekly Report');
  });

  onClick('runCalendarMonth', () => {
    run('/api/ai/calendar-month', {
      month: $('aiCalMonthInput')?.value || '',
      goals: $('aiCalMonthGoals')?.value || '',
      channels: $('aiCalMonthChannels')?.value || 'instagram, twitter, linkedin, email',
    }, 'calendar', 'runCalendarMonth', 'Monthly Calendar');
  });

  onClick('runSmartTimes', () => {
    run('/api/ai/smart-times', {
      platform: $('aiSmartTimePlatform')?.value || 'instagram',
      audience: $('aiSmartTimeAudience')?.value || '',
      content_type: $('aiSmartTimeType')?.value || 'social_post',
    }, 'schedule', 'runSmartTimes', 'Smart Posting Times');
  });

  onClick('runToneAnalysis', () => {
    run('/api/ai/tone-analysis', { content: $('aiToneContent')?.value || '' }, 'analysis', 'runToneAnalysis', 'Tone Analyzer');
  });

  onClick('runCampaignOptimizer', () => {
    const payload = { campaign_data: $('aiOptCampaignData')?.value || '', goals: $('aiOptGoals')?.value || '' };
    const sel = $('aiOptCampaignSelect');
    if (sel?.value) payload.campaign_id = parseInt(sel.value);
    run('/api/ai/campaign-optimizer', payload, 'optimization', 'runCampaignOptimizer', 'Campaign Optimizer');
  });

  // ---- NEW: Content Workflow Engine ----
  onClick('runWorkflow', () => {
    const platforms = [...document.querySelectorAll('.workflow-platform:checked')].map((c) => c.value);
    run('/api/ai/workflow', {
      topic: $('aiWorkflowTopic')?.value || '',
      goal: $('aiWorkflowGoal')?.value || '',
      platforms,
      days: parseInt($('aiWorkflowDays')?.value || '7'),
    }, 'workflow', 'runWorkflow', 'Content Workflow');
  });

  // ---- NEW: Brand Voice Auto-Builder ----
  onClick('runBrandVoice', async () => {
    const item = await run('/api/ai/build-brand-voice', {
      examples: $('aiBrandExamples')?.value || '',
    }, 'raw', 'runBrandVoice', 'Brand Voice Builder');

    if (item?.profile) {
      lastBrandProfile = item.profile;
      const saveBtn = $('saveBrandVoice');
      if (saveBtn) saveBtn.classList.remove('hidden');
    }
  });

  onClick('saveBrandVoice', async () => {
    if (!lastBrandProfile) { error('Generate a brand voice first'); return; }
    try {
      await api('/api/brand-profiles', {
        method: 'POST',
        body: JSON.stringify({
          name: 'AI-Generated Voice',
          voice_tone: lastBrandProfile.voice_tone || '',
          vocabulary: lastBrandProfile.vocabulary || '',
          avoid_words: lastBrandProfile.avoid_words || '',
          example_content: lastBrandProfile.example_content || '',
          target_audience: lastBrandProfile.target_audience || '',
        }),
      });
      success('Brand voice profile saved! Activate it in Content Library > Brand Voice.');
    } catch (err) { error(err.message); }
  });

  // ---- NEW: Email Drip Sequence ----
  onClick('runDripSequence', () => {
    run('/api/ai/drip-sequence', {
      goal: $('aiDripGoal')?.value || '',
      audience: $('aiDripAudience')?.value || '',
      count: parseInt($('aiDripCount')?.value || '5'),
    }, 'sequence', 'runDripSequence', 'Email Drip Sequence');
  });

  // ---- NEW: Content Localization ----
  onClick('runLocalize', () => {
    run('/api/ai/localize', {
      content: $('aiLocalizeContent')?.value || '',
      language: $('aiLocalizeLanguage')?.value || 'Spanish',
      platform: $('aiLocalizePlatform')?.value || 'instagram',
    }, 'localized', 'runLocalize', 'Content Localization');
  });

  // ---- NEW: Performance Predictor ----
  onClick('runPredict', () => {
    run('/api/ai/predict', {
      content: $('aiPredictContent')?.value || '',
      platform: $('aiPredictPlatform')?.value || 'instagram',
      scheduled_time: $('aiPredictTime')?.value || null,
    }, null, 'runPredict', 'Performance Predictor');
  });

  // ---- NEW: Pre-Flight Check ----
  onClick('runPreflight', () => {
    run('/api/ai/preflight', {
      content: $('aiPreflightContent')?.value || '',
      platform: $('aiPreflightPlatform')?.value || 'instagram',
    }, null, 'runPreflight', 'Pre-Flight Check');
  });

  // ---- NEW: Image Prompt Generator ----
  onClick('runImagePrompts', () => {
    run('/api/ai/image-prompts', {
      content: $('aiImageContent')?.value || '',
      platform: $('aiImagePlatform')?.value || 'instagram',
      style: $('aiImageStyle')?.value || 'modern',
    }, 'prompts', 'runImagePrompts', 'Image Prompts');
  });

  // ---- NEW: Image Generation ----
  onClick('runGenerateImage', async () => {
    const prompt = $('aiImagePromptDirect')?.value || '';
    if (!prompt) { error('Enter an image prompt first'); return; }
    setButtonLoading('runGenerateImage', true);
    loading('Image Generation');
    try {
      const { item } = await api('/api/ai/generate-image', {
        method: 'POST',
        body: JSON.stringify({
          prompt,
          provider: $('aiImageProvider')?.value || 'auto',
          size: $('aiImageSize')?.value || '1024x1024',
        }),
      });
      if (item?.error) {
        output('Error: ' + item.error, '', 'Image Generation');
        error(item.error);
      } else if (item?.image_base64) {
        output(`Image generated via ${item.provider}. Prompt: ${item.prompt}`, item.provider, 'Image Generation');
        const preview = $('aiImagePreview');
        if (preview) {
          preview.classList.remove('hidden');
          preview.textContent = '';
          const img = document.createElement('img');
          img.src = `data:image/png;base64,${item.image_base64}`;
          img.style.cssText = 'max-width:100%;border-radius:8px';
          img.alt = 'AI Generated';
          preview.appendChild(img);
        }
        success('Image generated');
      } else if (item?.url) {
        output(`Image URL: ${item.url}`, item.provider, 'Image Generation');
        success('Image generated');
      }
    } catch (err) {
      output('Error: ' + err.message, '', 'Image Generation');
      error(err.message);
    } finally {
      setButtonLoading('runGenerateImage', false);
    }
  });

  // ---- NEW: Multi-Source Copy + Visual Pipeline ----
  onClick('runMultiSourceContent', async () => {
    const topic = $('aiMultiSourceTopic')?.value?.trim() || '';
    if (!topic) { error('Enter a topic first'); return; }
    setButtonLoading('runMultiSourceContent', true);
    loading('Multi-Source Creative Pipeline');
    try {
      const { item } = await api('/api/ai/multi-source-content', {
        method: 'POST',
        body: JSON.stringify({
          topic,
          content_type: $('aiMultiSourceType')?.value || 'social_post',
          platform: $('aiMultiSourcePlatform')?.value || 'instagram',
          tone: $('aiMultiSourceTone')?.value || 'professional',
          goal: $('aiMultiSourceGoal')?.value || 'drive engagement',
          audience: $('aiMultiSourceAudience')?.value || '',
          copy_provider: $('aiCopyProviderSelect')?.value || '',
          image_prompt_provider: $('aiImagePromptProviderSelect')?.value || '',
          image_provider: $('aiMultiSourceImageProvider')?.value || 'auto',
          image_size: $('aiMultiSourceImageSize')?.value || '1024x1024',
        }),
      });

      output(JSON.stringify({
        copy: item?.copy || '',
        image_prompt: item?.image_prompt || '',
        providers: item?.providers || {},
      }, null, 2), (item?.providers?.copy || '') + ' + ' + (item?.providers?.image || ''), 'Multi-Source Creative Pipeline');

      const preview = $('aiMultiSourceImagePreview');
      if (preview) {
        preview.classList.add('hidden');
        preview.textContent = '';
        const image = item?.image || {};
        if (image.image_base64) {
          const img = document.createElement('img');
          img.src = `data:image/png;base64,${image.image_base64}`;
          img.alt = 'Multi-source AI generated visual';
          img.style.cssText = 'max-width:100%;border-radius:8px';
          preview.appendChild(img);
          preview.classList.remove('hidden');
        } else if (image.url) {
          const link = document.createElement('a');
          link.href = image.url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.textContent = `Open generated image (${image.provider || 'provider'})`;
          preview.appendChild(link);
          preview.classList.remove('hidden');
        }
      }
      success('Copy + visual generated.');
    } catch (err) {
      output('Error: ' + err.message, '', 'Multi-Source Creative Pipeline');
      error(err.message);
    } finally {
      setButtonLoading('runMultiSourceContent', false);
    }
  });

  // ---- NEW: Weekly Standup Digest ----
  onClick('runStandup', () => {
    run('/api/ai/standup', {}, 'digest', 'runStandup', 'Weekly Standup');
  });

  // ---- Output Actions ----
  onClick('aiCopyOutput', () => {
    if (!lastOutput) { error('Nothing to copy'); return; }
    copyToClipboard(lastOutput, $('aiCopyOutput'));
  });

  onClick('aiUseInPost', () => {
    if (!lastOutput) { error('Nothing to use'); return; }
    sessionStorage.setItem('ai_generated_content', lastOutput);
    sessionStorage.setItem('ai_generated_tool', lastTool);
    navigate('content');
    setTimeout(() => {
      document.querySelector('[data-tab="content-create"]')?.click();
      const bodyField = document.querySelector('#postForm [name="body"]');
      if (bodyField) { bodyField.value = lastOutput; success('AI content loaded into post form'); }
    }, 200);
  });

  onClick('aiClearOutput', () => {
    const el = $('aiOutput');
    if (el) el.textContent = 'Select a tool and click generate to see AI output here.';
    const richEl = $('aiOutputRich');
    if (richEl) richEl.innerHTML = '<p class="text-muted">Select a tool and click generate to see AI output here.</p>';
    const meta = $('aiOutputMeta');
    if (meta) meta.textContent = '';
    lastOutput = '';
    const preview = $('aiImagePreview');
    if (preview) { preview.classList.add('hidden'); preview.innerHTML = ''; }
  });
}
