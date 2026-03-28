/**
 * Marketing Suite — main entry point.
 * Imports all page modules, registers them with the router, and boots the app.
 */

import { registerPage } from './core/router.js';
import { maybeStartWelcomeTour } from './core/guidedTour.js';
import { initHelpWidget } from './core/helpWidget.js';
import * as login from './pages/login.js';
import * as dashboard from './pages/dashboard.js';
import * as content from './pages/content.js';
import * as campaigns from './pages/campaigns.js';
import * as competitors from './pages/competitors.js';
import * as ai from './pages/ai.js';
import * as social from './pages/social.js';
import * as email from './pages/email.js';
import * as analytics from './pages/analytics.js';
import * as templates from './pages/templates.js';
import * as rss from './pages/rss.js';
import * as seo from './pages/seo.js';
import * as settings from './pages/settings.js';
import * as contacts from './pages/contacts.js';
import * as links from './pages/links.js';
import * as landing from './pages/landing.js';
import * as forms from './pages/forms.js';
import * as abtests from './pages/abtests.js';
import * as funnels from './pages/funnels.js';
import * as automations from './pages/automations.js';
import * as segments from './pages/segments.js';
import * as queue from './pages/queue.js';
import * as assistant from './pages/assistant.js';
import * as chat from './pages/chat.js';
import * as brain from './pages/brain.js';
import * as onboarding from './pages/onboarding.js';
import * as reviews from './pages/reviews.js';

// Register each page module with the SPA router
registerPage('dashboard', dashboard);
registerPage('content', content);
registerPage('campaigns', campaigns);
registerPage('competitors', competitors);
registerPage('ai', ai);
registerPage('social', social);
registerPage('email', email);
registerPage('analytics', analytics);
registerPage('templates', templates);
registerPage('rss', rss);
registerPage('seo', seo);
registerPage('settings', settings);
registerPage('contacts', contacts);
registerPage('links', links);
registerPage('landing', landing);
registerPage('forms', forms);
registerPage('abtests', abtests);
registerPage('funnels', funnels);
registerPage('automations', automations);
registerPage('segments', segments);
registerPage('queue', queue);
registerPage('chat', chat);
registerPage('brain', brain);
registerPage('onboarding', onboarding);
registerPage('reviews', reviews);

// Initialize all page modules (binds forms, click handlers, etc.)
function initAll() {
  dashboard.init();
  content.init();
  campaigns.init();
  competitors.init();
  ai.init();
  social.init();
  email.init();
  analytics.init();
  templates.init();
  rss.init();
  seo.init();
  settings.init();
  contacts.init();
  links.init();
  landing.init();
  forms.init();
  abtests.init();
  funnels.init();
  automations.init();
  segments.init();
  queue.init();
  chat.init();
  brain.init();
  onboarding.init();
  reviews.init();
  assistant.init();
  initInlineAiToolbars();

  // Initialize help widget and auto-start welcome tour for new users
  initHelpWidget();
  maybeStartWelcomeTour();
}

// Inline AI toolbar — attach refine actions to textareas
function initInlineAiToolbars() {
  document.querySelectorAll('.ai-inline-btn[data-inline-refine]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const action = btn.dataset.inlineRefine;
      // Find the sibling textarea
      const container = btn.closest('div').parentElement || btn.closest('.stack');
      const textarea = container?.querySelector('textarea');
      if (!textarea || !textarea.value.trim()) {
        const { error: showError } = await import('./core/toast.js');
        showError('Write some content first');
        return;
      }

      btn.classList.add('loading');
      btn.disabled = true;
      try {
        const { api: apiFn } = await import('./core/api.js');
        const { item } = await apiFn('/api/ai/refine', {
          method: 'POST',
          body: JSON.stringify({ content: textarea.value, action }),
        });
        if (item?.content) {
          textarea.value = item.content;
          textarea.dispatchEvent(new Event('input'));
          const { success: showSuccess } = await import('./core/toast.js');
          showSuccess(`Content ${action === 'improve' ? 'improved' : action === 'expand' ? 'expanded' : action === 'shorten' ? 'shortened' : 'refined'} with AI`);
        }
      } catch (err) {
        const { error: showError } = await import('./core/toast.js');
        showError(err.message);
      } finally {
        btn.classList.remove('loading');
        btn.disabled = false;
      }
    });
  });
}

// Boot sequence
async function boot() {
  // Register callback so initAll runs after login even if session didn't exist at boot
  login.setOnAuthenticated(initAll);
  login.init();
  const authenticated = await login.checkSession();
  if (authenticated) {
    initAll();
  }
}

boot();
