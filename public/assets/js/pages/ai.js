/**
 * AI Studio — 18 AI-powered tools with categories, rich output, multi-provider,
 * copy/use actions, and global AI command bar.
 */

import { api } from '../core/api.js';
import { $, onClick } from '../core/utils.js';
import { success, error } from '../core/toast.js';
import { navigate } from '../core/router.js';

let lastOutput = '';
let lastProvider = '';
let lastTool = '';

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

function output(text, provider, tool) {
  const el = $('aiOutput');
  const raw = typeof text === 'string' ? text : JSON.stringify(text, null, 2);
  lastOutput = raw;
  if (el) el.textContent = raw;
  setOutputMeta(provider, tool);
  // Scroll output into view on mobile
  const panel = $('aiOutputPanel');
  if (panel && window.innerWidth <= 1024) {
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

function loading(toolName) {
  const el = $('aiOutput');
  if (el) el.textContent = `Generating ${toolName || ''}... please wait.`;
  const meta = $('aiOutputMeta');
  if (meta) meta.textContent = 'Processing...';
}

function setButtonLoading(btnId, isLoading) {
  const btn = $(btnId);
  if (!btn) return;
  if (isLoading) {
    btn.classList.add('loading');
    btn.disabled = true;
  } else {
    btn.classList.remove('loading');
    btn.disabled = false;
  }
}

async function run(endpoint, payload, resultKey, btnId, toolName) {
  loading(toolName);
  if (btnId) setButtonLoading(btnId, true);
  try {
    const { item } = await api(endpoint, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    const text = resultKey ? item[resultKey] : item;
    output(typeof text === 'string' ? text : JSON.stringify(text, null, 2), item?.provider, toolName);
    success('Generated successfully');
  } catch (err) {
    output('Error: ' + err.message, '', toolName);
    error(err.message);
  } finally {
    if (btnId) setButtonLoading(btnId, false);
  }
}

// Category tab filtering
function initCategoryTabs() {
  document.querySelectorAll('.ai-cat-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.ai-cat-btn').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      const cat = btn.dataset.aiCat;
      document.querySelectorAll('.ai-tool-card').forEach((card) => {
        if (cat === 'all' || card.dataset.aiCat === cat) {
          card.style.display = '';
        } else {
          card.style.display = 'none';
        }
      });
    });
  });
}

export function refresh() {
  // Load provider status for the selector
  api('/api/ai/providers').then((status) => {
    const sel = $('aiProviderSelect');
    if (sel && status.active_provider) {
      const badge = $('providerBadge');
      if (badge) badge.textContent = `Provider: ${status.active_provider}`;
    }
  }).catch(() => {});

  // Load campaigns for the optimizer dropdown
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

  // ---- Content Creation Tools ----

  // Content Writer
  onClick('runContent', () => {
    run('/api/ai/content', {
      content_type: $('aiContentType')?.value || 'social_post',
      tone: $('aiTone')?.value || 'professional',
      platform: $('aiContentPlatform')?.value || 'facebook',
      topic: $('aiContentTopic')?.value || '',
      goal: $('aiContentGoal')?.value || '',
    }, 'content', 'runContent', 'Content Writer');
  });

  // Blog Post Generator
  onClick('runBlog', () => {
    run('/api/ai/blog-post', {
      title: $('aiBlogTitle')?.value || '',
      keywords: $('aiBlogKeywords')?.value || '',
      outline: $('aiBlogOutline')?.value || null,
    }, 'content', 'runBlog', 'Blog Post');
  });

  // Video Script
  onClick('runVideoScript', () => {
    run('/api/ai/video-script', {
      topic: $('aiVideoTopic')?.value || '',
      platform: $('aiVideoPlatform')?.value || 'tiktok',
      duration: parseInt($('aiVideoDuration')?.value || '60'),
    }, 'script', 'runVideoScript', 'Video Script');
  });

  // Caption Batch
  onClick('runCaptionBatch', () => {
    const checks = document.querySelectorAll('.caption-platform:checked');
    const platforms = [...checks].map((c) => c.value);
    run('/api/ai/caption-batch', {
      topic: $('aiCaptionTopic')?.value || '',
      platforms,
      count: 3,
    }, 'captions', 'runCaptionBatch', 'Caption Batch');
  });

  // Content Repurpose
  onClick('runRepurpose', () => {
    const checks = document.querySelectorAll('.repurpose-fmt:checked');
    const formats = [...checks].map((c) => c.value);
    run('/api/ai/repurpose', {
      content: $('aiRepurposeContent')?.value || '',
      formats,
    }, 'variations', 'runRepurpose', 'Content Repurpose');
  });

  // Ad Variations
  onClick('runAdVariations', () => {
    run('/api/ai/ad-variations', {
      base_ad: $('aiBaseAd')?.value || '',
      count: parseInt($('aiAdCount')?.value || '5'),
    }, 'variations', 'runAdVariations', 'Ad Variations');
  });

  // Email Subject Lines
  onClick('runSubjectLines', () => {
    run('/api/ai/subject-lines', {
      topic: $('aiSubjectTopic')?.value || '',
      count: parseInt($('aiSubjectCount')?.value || '10'),
    }, 'subjects', 'runSubjectLines', 'Email Subject Lines');
  });

  // ---- Research & Strategy Tools ----

  // Market Research
  onClick('runResearch', () => {
    run('/api/ai/research', {
      audience: $('aiAudience')?.value || '',
      goal: $('aiGoal')?.value || '',
    }, 'brief', 'runResearch', 'Market Research');
  });

  // Content Ideas
  onClick('runIdeas', () => {
    run('/api/ai/ideas', {
      topic: $('aiTopic')?.value || '',
      platform: $('aiIdeasPlatform')?.value || 'instagram',
    }, 'ideas', 'runIdeas', 'Content Ideas');
  });

  // Audience Persona
  onClick('runPersona', () => {
    run('/api/ai/persona', {
      demographics: $('aiPersonaDemographics')?.value || '',
      behaviors: $('aiPersonaBehaviors')?.value || '',
    }, 'persona', 'runPersona', 'Audience Persona');
  });

  // Competitor Analysis
  onClick('runCompAnalysis', () => {
    run('/api/ai/competitor-analysis', {
      name: $('aiCompName')?.value || '',
      notes: $('aiCompNotes')?.value || '',
    }, 'analysis', 'runCompAnalysis', 'Competitor Analysis');
  });

  // Social Strategy
  onClick('runSocialStrategy', () => {
    run('/api/ai/social-strategy', {
      goals: $('aiStrategyGoals')?.value || '',
      current_state: $('aiStrategyState')?.value || '',
    }, 'strategy', 'runSocialStrategy', 'Social Strategy');
  });

  // ---- Optimization Tools ----

  // SEO Keywords
  onClick('runSeoKeywords', () => {
    run('/api/ai/seo-keywords', {
      topic: $('aiSeoTopic')?.value || '',
      niche: $('aiSeoNiche')?.value || '',
    }, 'keywords', 'runSeoKeywords', 'SEO Keywords');
  });

  // Hashtag Research
  onClick('runHashtags', () => {
    run('/api/ai/hashtags', {
      topic: $('aiHashtagTopic')?.value || '',
      platform: $('aiHashtagPlatform')?.value || 'instagram',
    }, 'hashtags', 'runHashtags', 'Hashtag Research');
  });

  // Content Scorer
  onClick('runScore', () => {
    run('/api/ai/score', {
      content: $('aiScoreContent')?.value || '',
      platform: $('aiScorePlatform')?.value || 'instagram',
    }, 'score', 'runScore', 'Content Scorer');
  });

  // SEO Audit
  onClick('runSeoAudit', () => {
    run('/api/ai/seo-audit', {
      url: $('aiAuditUrl')?.value || '',
      description: $('aiAuditDescription')?.value || '',
    }, 'audit', 'runSeoAudit', 'SEO Audit');
  });

  // ---- Analytics & Reports ----

  // Posting Calendar
  onClick('runCalendar', () => {
    run('/api/ai/calendar', {
      objective: $('aiCalendarGoal')?.value || '',
    }, 'schedule', 'runCalendar', 'Posting Calendar');
  });

  // Weekly Report
  onClick('runWeeklyReport', () => {
    run('/api/ai/report', {}, 'report', 'runWeeklyReport', 'Weekly Report');
  });

  // ---- New Enhanced Tools ----

  // Content Brief
  onClick('runBrief', () => {
    run('/api/ai/brief', {
      topic: $('aiBriefTopic')?.value || '',
      content_type: $('aiBriefType')?.value || 'blog_post',
      goal: $('aiBriefGoal')?.value || '',
    }, 'brief', 'runBrief', 'Content Brief');
  });

  // Headline Optimizer
  onClick('runHeadlines', () => {
    run('/api/ai/headlines', {
      headline: $('aiHeadlineText')?.value || '',
      platform: $('aiHeadlinePlatform')?.value || 'blog',
    }, 'headlines', 'runHeadlines', 'Headline Optimizer');
  });

  // Monthly Content Calendar
  onClick('runCalendarMonth', () => {
    run('/api/ai/calendar-month', {
      month: $('aiCalMonthInput')?.value || '',
      goals: $('aiCalMonthGoals')?.value || '',
      channels: $('aiCalMonthChannels')?.value || 'instagram, twitter, linkedin, email',
    }, 'calendar', 'runCalendarMonth', 'Monthly Calendar');
  });

  // Smart Posting Times
  onClick('runSmartTimes', () => {
    run('/api/ai/smart-times', {
      platform: $('aiSmartTimePlatform')?.value || 'instagram',
      audience: $('aiSmartTimeAudience')?.value || '',
      content_type: $('aiSmartTimeType')?.value || 'social_post',
    }, 'schedule', 'runSmartTimes', 'Smart Posting Times');
  });

  // Tone Analyzer
  onClick('runToneAnalysis', () => {
    run('/api/ai/tone-analysis', {
      content: $('aiToneContent')?.value || '',
    }, 'analysis', 'runToneAnalysis', 'Tone Analyzer');
  });

  // Campaign Optimizer
  onClick('runCampaignOptimizer', () => {
    const campaignSelect = $('aiOptCampaignSelect');
    const payload = {
      campaign_data: $('aiOptCampaignData')?.value || '',
      goals: $('aiOptGoals')?.value || '',
    };
    if (campaignSelect?.value) {
      payload.campaign_id = parseInt(campaignSelect.value);
    }
    run('/api/ai/campaign-optimizer', payload, 'optimization', 'runCampaignOptimizer', 'Campaign Optimizer');
  });

  // ---- Output Actions ----

  // Copy output
  onClick('aiCopyOutput', () => {
    if (!lastOutput) { error('Nothing to copy'); return; }
    navigator.clipboard.writeText(lastOutput).then(() => success('Copied to clipboard')).catch(() => error('Copy failed'));
  });

  // Use in post - navigate to content studio with the output
  onClick('aiUseInPost', () => {
    if (!lastOutput) { error('Nothing to use'); return; }
    // Store in sessionStorage so the content page can pick it up
    sessionStorage.setItem('ai_generated_content', lastOutput);
    sessionStorage.setItem('ai_generated_tool', lastTool);
    navigate('content');
    // Switch to create tab
    setTimeout(() => {
      document.querySelector('[data-tab="content-create"]')?.click();
      const bodyField = document.querySelector('#postForm [name="body"]');
      if (bodyField) {
        bodyField.value = lastOutput;
        success('AI content loaded into post form');
      }
    }, 200);
  });

  // Clear output
  onClick('aiClearOutput', () => {
    const el = $('aiOutput');
    if (el) el.textContent = 'Select a tool and click generate to see AI output here.';
    const meta = $('aiOutputMeta');
    if (meta) meta.textContent = '';
    lastOutput = '';
  });
}
