/**
 * Marketing Suite — main entry point.
 * Imports all page modules, registers them with the router, and boots the app.
 */

import { registerPage } from './core/router.js';
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
