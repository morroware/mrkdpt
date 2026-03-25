/**
 * AI Studio — all 12 AI generation cards.
 */

import { api } from '../core/api.js';
import { $, onClick } from '../core/utils.js';
import { success, error } from '../core/toast.js';

function output(text) {
  const el = $('aiOutput');
  if (el) el.textContent = typeof text === 'string' ? text : JSON.stringify(text, null, 2);
}

function loading() {
  output('Generating... please wait.');
}

async function run(endpoint, payload, resultKey) {
  loading();
  try {
    const { item } = await api(endpoint, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    const text = resultKey ? item[resultKey] : item;
    output(typeof text === 'string' ? text : JSON.stringify(text, null, 2));
    success('Generated successfully');
  } catch (err) {
    output('Error: ' + err.message);
    error(err.message);
  }
}

export function refresh() {
  // Nothing to load on page show
}

export function init() {
  // Market Research
  onClick('runResearch', () => {
    run('/api/ai/research', {
      audience: $('aiAudience')?.value || '',
      goal: $('aiGoal')?.value || '',
    }, 'brief');
  });

  // Content Ideas
  onClick('runIdeas', () => {
    run('/api/ai/ideas', {
      topic: $('aiTopic')?.value || '',
      platform: $('aiIdeasPlatform')?.value || 'instagram',
    }, 'ideas');
  });

  // Content Writer
  onClick('runContent', () => {
    run('/api/ai/content', {
      content_type: $('aiContentType')?.value || 'social_post',
      tone: $('aiTone')?.value || 'professional',
      platform: $('aiContentPlatform')?.value || 'facebook',
      topic: $('aiContentTopic')?.value || '',
      goal: $('aiContentGoal')?.value || '',
    }, 'content');
  });

  // Blog Post Generator
  onClick('runBlog', () => {
    run('/api/ai/blog-post', {
      title: $('aiBlogTitle')?.value || '',
      keywords: $('aiBlogKeywords')?.value || '',
      outline: $('aiBlogOutline')?.value || null,
    }, 'content');
  });

  // SEO Keywords
  onClick('runSeoKeywords', () => {
    run('/api/ai/seo-keywords', {
      topic: $('aiSeoTopic')?.value || '',
      niche: $('aiSeoNiche')?.value || '',
    }, 'keywords');
  });

  // Hashtag Research
  onClick('runHashtags', () => {
    run('/api/ai/hashtags', {
      topic: $('aiHashtagTopic')?.value || '',
      platform: $('aiHashtagPlatform')?.value || 'instagram',
    }, 'hashtags');
  });

  // Content Repurpose
  onClick('runRepurpose', () => {
    const checks = document.querySelectorAll('.repurpose-fmt:checked');
    const formats = [...checks].map((c) => c.value);
    run('/api/ai/repurpose', {
      content: $('aiRepurposeContent')?.value || '',
      formats,
    }, 'variations');
  });

  // Ad Variations
  onClick('runAdVariations', () => {
    run('/api/ai/ad-variations', {
      base_ad: $('aiBaseAd')?.value || '',
      count: parseInt($('aiAdCount')?.value || '5'),
    }, 'variations');
  });

  // Email Subject Lines
  onClick('runSubjectLines', () => {
    run('/api/ai/subject-lines', {
      topic: $('aiSubjectTopic')?.value || '',
      count: parseInt($('aiSubjectCount')?.value || '10'),
    }, 'subjects');
  });

  // Audience Persona
  onClick('runPersona', () => {
    run('/api/ai/persona', {
      demographics: $('aiPersonaDemographics')?.value || '',
      behaviors: $('aiPersonaBehaviors')?.value || '',
    }, 'persona');
  });

  // Content Scorer
  onClick('runScore', () => {
    run('/api/ai/score', {
      content: $('aiScoreContent')?.value || '',
      platform: $('aiScorePlatform')?.value || 'instagram',
    }, 'score');
  });

  // Posting Calendar
  onClick('runCalendar', () => {
    run('/api/ai/calendar', {
      objective: $('aiCalendarGoal')?.value || '',
    }, 'schedule');
  });
}
