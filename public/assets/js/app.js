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
