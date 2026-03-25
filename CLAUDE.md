# Marketing Suite - Project Reference

## Overview

All-in-one marketing operations platform with deep AI integration. Zero-dependency PHP 8.1+ backend with vanilla JS SPA frontend. SQLite database. No build step, no Composer, no npm.

## Architecture

**Backend:** Pure PHP (procedural + classes), no framework
**Frontend:** Vanilla HTML/CSS/JS SPA with hash-based routing
**Database:** SQLite with WAL mode, auto-migration in `Database.php`
**Deployment:** Any PHP 8.1+ server with `pdo_sqlite`, `curl`, `mbstring`

## Key Entry Points

- `public/index.php` - Main router, API dispatcher, includes all route files
- `public/app.html` - SPA shell (all 22 pages defined inline in HTML)
- `public/install.php` - Web-based setup wizard
- `public/cron.php` - External cron trigger

## Directory Structure

```
public/
  app.html                    # SPA shell - ALL page HTML lives here
  assets/
    styles.css                # Single CSS file (dark/light themes)
    js/
      app.js                  # Boot sequence, page registration
      core/
        api.js                # Fetch wrapper, CSRF token management
        router.js             # SPA hash router, tab switching, sidebar toggles, AI command bar
        utils.js              # DOM helpers ($, $$, escapeHtml, formatDateTime, etc.)
        toast.js              # Notification system
      pages/                  # One module per page (exports init() + refresh())
        ai.js                 # AI Studio - 18 tools with categories
        content.js            # Content Studio - calendar, list, create with AI buttons
        dashboard.js          # Dashboard - metrics, recent items, AI quick actions
        email.js              # Email Marketing - lists, subscribers, campaigns, AI compose
        campaigns.js          # Campaigns - CRUD with ROI, AI strategy
        competitors.js        # Competitors - CRUD with AI deep dive
        landing.js            # Landing Pages - builder with AI copy generation
        abtests.js            # A/B Tests - with AI variant generation
        contacts.js           # CRM - pipeline, scoring, import/export
        analytics.js          # Analytics - charts, CSV export
        social.js             # Social accounts management
        queue.js              # Publish queue with best-time optimization
        templates.js          # Content Library - templates, brand voice, media
        forms.js              # Form builder
        funnels.js            # Sales funnels
        automations.js        # Trigger-action workflows
        segments.js           # Dynamic audience segments
        links.js              # UTM builder, short links
        seo.js                # SEO tools (keywords, blog generator)
        rss.js                # RSS feed reader
        settings.js           # App config, health, backups
        login.js              # Auth flow

src/
  bootstrap.php               # Helpers: env_value(), json_response(), security_headers()
  Database.php                # SQLite schema (35+ tables), auto-migration
  Auth.php                    # Session + bearer token, CSRF, rate limiting
  Router.php                  # Lightweight router with middleware
  AiService.php               # Multi-provider AI (OpenAI, Anthropic, Gemini)
  Repositories.php            # Data access layer (Post, Campaign, Competitor, etc.)
  SocialPublisher.php         # Multi-platform publishing (Twitter, Bluesky, Mastodon, Facebook, Instagram)
  SocialQueue.php             # Queue with best-time optimization
  EmailService.php            # SMTP client with tracking, merge tags
  EmailTemplates.php          # Built-in email templates
  Scheduler.php               # Cron task runner
  Analytics.php               # Event tracking, dashboard metrics
  Contacts.php                # Mini CRM
  FormBuilder.php             # Dynamic forms + submissions
  LandingPages.php            # Landing page editor (5 templates)
  LinkShortener.php           # Short links + click analytics
  UtmBuilder.php              # UTM link generator
  Automations.php             # Trigger-action engine
  Segments.php                # Dynamic contact segmentation
  Templates.php               # Reusable templates + brand profiles
  Funnels.php                 # Sales funnel stages
  AbTesting.php               # A/B test variants + conversion tracking
  CampaignMetrics.php         # ROI tracking
  MediaLibrary.php            # File uploads + thumbnails
  RssFetcher.php              # RSS/Atom parser
  Webhooks.php                # Event dispatch + HMAC signing
  routes/                     # 28 route files (one per API domain)
    ai.php                    # 18 AI tool endpoints + /api/ai/multi + /api/ai/providers + /api/ai/bulk
    posts.php                 # Full CRUD, calendar, bulk ops, approval workflows
    auth.php                  # Login, logout, setup status
    campaigns.php, contacts.php, email.php, etc.
```

## AI System

### Providers (configured in .env)
- **OpenAI** (`AI_PROVIDER=openai`) - Chat completions API
- **Anthropic** (`AI_PROVIDER=anthropic`) - Messages API
- **Google Gemini** (`AI_PROVIDER=gemini`) - GenerativeAI API

### AiService.php Methods
Each maps to an `/api/ai/*` endpoint:
- `marketResearch()` -> `/api/ai/research`
- `contentIdeas()` -> `/api/ai/ideas`
- `generateContent()` -> `/api/ai/content` (main content writer)
- `blogPostGenerator()` -> `/api/ai/blog-post`
- `seoKeywordResearch()` -> `/api/ai/seo-keywords`
- `hashtagResearch()` -> `/api/ai/hashtags`
- `repurposeContent()` -> `/api/ai/repurpose`
- `adVariations()` -> `/api/ai/ad-variations`
- `emailSubjectLines()` -> `/api/ai/subject-lines`
- `audiencePersona()` -> `/api/ai/persona`
- `contentScore()` -> `/api/ai/score`
- `scheduleSuggestion()` -> `/api/ai/calendar`
- `videoScript()` -> `/api/ai/video-script`
- `socialCaptionBatch()` -> `/api/ai/caption-batch`
- `seoAudit()` -> `/api/ai/seo-audit`
- `socialStrategy()` -> `/api/ai/social-strategy`
- `competitorAnalysis()` -> `/api/ai/competitor-analysis`
- `weeklyReport()` -> `/api/ai/report`

### AI Integration Points (throughout the app)
- **AI Studio** (`pages/ai.js`): All 18 tools with category tabs, sticky output panel, copy/use actions
- **Content Studio** (`pages/content.js`): AI Write Content, AI Title, AI Hashtags, AI Score
- **Email Marketing** (`pages/email.js`): AI Write Email, AI Subject Lines
- **Campaigns** (`pages/campaigns.js`): AI Strategy
- **Landing Pages** (`pages/landing.js`): AI Generate Copy
- **Competitors** (`pages/competitors.js`): AI Deep Dive
- **A/B Tests** (`pages/abtests.js`): AI Generate Variants
- **Dashboard** (`pages/dashboard.js`): 6 AI quick action buttons
- **Global Command Bar** (`core/router.js`): Ctrl+K from any page
- **SEO Tools** (`pages/seo.js`): AI keyword research, blog generation

### Brand Voice
`BrandProfileRepository` in `Repositories.php` manages brand voice profiles. Active profile fields (`voice_tone`, `vocabulary`, `avoid_words`, `example_content`, `target_audience`) are injected into AI system prompts via `AiService::buildSystemPrompt()`.

## UI/UX Architecture

### Navigation
- Sidebar with 5 collapsible grouped sections + Dashboard and Settings as top-level
- Sections: AI & Content, Marketing, Intelligence, Audience, Tools
- Collapse state persists in localStorage (`nav_<section>` keys)
- Active page's section auto-expands on navigation

### SPA Routing
- Hash-based: `#page-name` (e.g. `#ai`, `#content`, `#dashboard`)
- `router.js`: `registerPage(name, module)`, `navigate(page)`, `showPage(page)`
- Each page module exports `init()` (bind events) and `refresh()` (load data)
- `app.js` registers all 22 pages and calls `initAll()` after authentication

### Tab System
- `.tab-btn[data-tab]` toggles `.tab-panel` visibility within a page section
- Handled globally in `router.js`

### AI Button Pattern
All AI buttons use the `.btn-ai` class (purple gradient). Loading state pattern:
```js
btn.classList.add('loading'); btn.disabled = true;
try { /* api call */ } catch (err) { error(err.message); }
finally { btn.classList.remove('loading'); btn.disabled = false; }
```

### CSS Variables
Theme is controlled by CSS variables on `:root` (dark) and `body.light` (light). Key vars: `--bg`, `--panel`, `--text`, `--accent`, `--line`, `--input-bg`, `--radius`.

AI-specific styling uses purple gradient: `linear-gradient(135deg, #6366f1, #8b5cf6, #a855f7)`.

## Database (SQLite)

35+ tables defined in `Database.php`. Key tables:
- `posts` - Content with platform, status, AI score, recurrence
- `campaigns` - Budget, channel, objective, ROI tracking
- `social_accounts` - Connected platforms with tokens
- `publish_log` - Post-to-platform history
- `social_queue` - Publishing queue with priority
- `email_lists`, `subscribers`, `email_campaigns` - Email marketing
- `contacts`, `contact_activities` - CRM
- `analytics_events` - Event tracking
- `content_ideas`, `research_briefs` - AI output storage
- `landing_pages`, `forms`, `form_submissions`
- `utm_links`, `short_links` - Link tracking
- `ab_tests`, `ab_test_variants` - A/B testing
- `funnels`, `funnel_stages` - Sales funnels
- `automations` - Trigger-action workflows
- `segments` - Dynamic contact segmentation
- `templates`, `brand_profiles` - Content library
- `media` - File uploads
- `webhooks`, `cron_log`, `rss_feeds`, `rss_items`

## Authentication

- Session-based for browser, bearer token for API
- CSRF token required in `X-CSRF-Token` header
- Rate limiting on sensitive endpoints
- Public endpoints (no auth): `/p/{slug}`, `/f/{slug}`, `/s/{code}`, tracking pixels

## Social Publishing

`SocialPublisher.php` supports: Twitter (API v2), Bluesky (AT Protocol), Mastodon (ActivityPub), Facebook (Graph API), Instagram (Graph API). Unified `publish(post, account)` interface.

## Configuration (.env)

```
BUSINESS_NAME, BUSINESS_INDUSTRY, TIMEZONE
AI_PROVIDER=openai|anthropic|gemini
OPENAI_API_KEY, OPENAI_BASE_URL, OPENAI_MODEL
ANTHROPIC_API_KEY, ANTHROPIC_MODEL
GEMINI_API_KEY, GEMINI_MODEL
APP_URL, MAX_UPLOAD_MB, CRON_KEY
SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM
```

## Development Notes

- No build step required - edit files directly and refresh
- All frontend JS uses ES modules (`import`/`export`)
- Backend uses `request_json()` helper to parse POST bodies
- API responses follow `{ item: {...} }` or `{ items: [...] }` pattern
- The `formData(e)` utility converts FormData to a plain object
- `statusBadge(status)` returns colored badge HTML
- Toast notifications via `success()`, `error()` from `toast.js`
- The `api()` wrapper in `api.js` handles CSRF tokens automatically
- When adding new AI tools: add method to `AiService.php`, register route in `routes/ai.php`, add card in `app.html`, wire button in `pages/ai.js`
- When adding new pages: create page module in `pages/`, register in `app.js`, add HTML section in `app.html`, add nav link in sidebar
