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
- `public/app.html` - SPA shell (all 24 pages defined inline in HTML)
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
        ai.js                 # AI Studio - 25+ tools with categories
        assistant.js          # AI Writing Assistant - floating refinement panel
        chat.js               # AI Marketing Chat - conversational AI with history
        brain.js              # AI Brain - self-awareness dashboard, learnings, pipelines, feedback
        onboarding.js         # Onboarding wizard - business profile + AI autopilot
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
  AiService.php               # Multi-provider AI (9 providers) - core generate/chat methods
  AiContentTools.php          # Content creation AI tools (generate, blog, video, captions, refine, etc.)
  AiAnalysisTools.php         # Analysis AI tools (tone, score, SEO, hashtags, A/B variants)
  AiStrategyTools.php         # Strategy AI tools (research, personas, competitor, calendar, insights)
  AiChatService.php           # AI Marketing Chat with conversation history, auto-insight extraction
  AiMemoryEngine.php          # AI Brain: activity logging, auto-learning, situational awareness, performance feedback
  AiOrchestrator.php          # AI Pipelines: tool chaining, templates, next-action suggestions
  AiAutopilot.php             # AI Autopilot for onboarding content bootstrapping
  Repositories.php            # Data access layer (Post, Campaign, Competitor, etc.)
  SocialPublisher.php         # Multi-platform publishing (Twitter, Bluesky, Mastodon, Facebook, Instagram)
  SocialQueue.php             # Queue with best-time optimization
  EmailService.php            # SMTP client with tracking, merge tags
  EmailTemplates.php          # Built-in email templates
  Scheduler.php               # Cron task runner
  Analytics.php               # Event tracking, dashboard metrics
  Contacts.php                # Mini CRM
  FormBuilder.php             # Dynamic forms + submissions
  LandingPages.php            # Landing page editor (5 templates) + CSS sanitization
  LinkShortener.php           # Short links + click analytics (iterative code gen)
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
  routes/                     # 31 route files (one per API domain)
    ai.php                    # 26 AI tool endpoints + /api/ai/multi + /api/ai/providers + /api/ai/bulk
    posts.php                 # Full CRUD, calendar, bulk ops, approval workflows
    auth.php                  # Login, logout, setup status
    campaigns.php, contacts.php, email.php, etc.
```

## AI System

### Providers (configured in .env)
- **OpenAI** (`AI_PROVIDER=openai`) - Chat completions API
- **Anthropic** (`AI_PROVIDER=anthropic`) - Messages API
- **Google Gemini** (`AI_PROVIDER=gemini`) - GenerativeAI API
- **DeepSeek** (`AI_PROVIDER=deepseek`) - OpenAI-compatible, best cost/quality ratio
- **Groq** (`AI_PROVIDER=groq`) - Ultra-fast inference, OpenAI-compatible
- **Mistral** (`AI_PROVIDER=mistral`) - OpenAI-compatible, strong multilingual
- **OpenRouter** (`AI_PROVIDER=openrouter`) - Meta-provider, 100+ models via one key
- **xAI** (`AI_PROVIDER=xai`) - Grok models, OpenAI-compatible
- **Together AI** (`AI_PROVIDER=together`) - Open models (Llama, Mixtral, Qwen), OpenAI-compatible

### AI Tool Methods
Methods are split across tool classes, each mapping to `/api/ai/*` endpoints:

**AiContentTools.php (Content Creation):**
- `generateContent()` -> `/api/ai/content` (main content writer)
- `blogPostGenerator()` -> `/api/ai/blog-post`
- `videoScript()` -> `/api/ai/video-script`
- `socialCaptionBatch()` -> `/api/ai/caption-batch`
- `repurposeContent()` -> `/api/ai/repurpose`
- `adVariations()` -> `/api/ai/ad-variations`
- `emailSubjectLines()` -> `/api/ai/subject-lines`
- `refineContent()` -> `/api/ai/refine` (12 actions: improve, expand, shorten, formal, casual, persuasive, storytelling, simplify, add_hooks, add_cta, emoji, bullet_points)
- `contentBrief()` -> `/api/ai/brief` (full content brief with outline, SEO, distribution plan)
- `headlineOptimizer()` -> `/api/ai/headlines` (10 variations with psychological triggers)
- `contentWorkflow()` -> `/api/ai/content-workflow`
- `buildBrandVoice()` -> `/api/ai/brand-voice`
- `rssToPost()` -> `/api/ai/rss-to-post`
- `emailDripSequence()` -> `/api/ai/drip-sequence`
- `localizeContent()` -> `/api/ai/localize`
- `imagePromptGenerator()` -> `/api/ai/image-prompt`
- `generateImage()` -> `/api/ai/image`

**AiAnalysisTools.php (Analysis):**
- `toneAnalysis()` -> `/api/ai/tone-analysis` (sentiment, readability, emotion map, brand alignment)
- `contentScore()` -> `/api/ai/score`
- `seoKeywordResearch()` -> `/api/ai/seo-keywords`
- `hashtagResearch()` -> `/api/ai/hashtags`
- `seoAudit()` -> `/api/ai/seo-audit`
- `preFlightCheck()` -> `/api/ai/pre-flight`
- `predictPerformance()` -> `/api/ai/predict`
- `generateAbVariants()` -> `/api/ai/ab-variants`
- `analyzeAbResults()` -> `/api/ai/ab-analysis`

**AiStrategyTools.php (Strategy):**
- `marketResearch()` -> `/api/ai/research`
- `contentIdeas()` -> `/api/ai/ideas`
- `audiencePersona()` -> `/api/ai/persona`
- `competitorAnalysis()` -> `/api/ai/competitor-analysis`
- `socialStrategy()` -> `/api/ai/social-strategy`
- `weeklyReport()` -> `/api/ai/report`
- `scheduleSuggestion()` -> `/api/ai/calendar`
- `campaignOptimizer()` -> `/api/ai/campaign-optimizer` (budget, channel mix, creative recommendations)
- `contentCalendarMonth()` -> `/api/ai/calendar-month` (full month content plan)
- `smartPostingTime()` -> `/api/ai/smart-times` (platform-specific optimal schedule)
- `aiInsights()` -> `/api/ai/insights` (proactive recommendations from marketing data)
- `smartSegmentation()` -> `/api/ai/smart-segments`
- `competitorRadar()` -> `/api/ai/competitor-radar`
- `funnelAdvisor()` -> `/api/ai/funnel-advisor`
- `smartUtm()` -> `/api/ai/smart-utm`
- `weeklyStandup()` -> `/api/ai/standup`

### AI Integration Points (throughout the app)
- **AI Studio** (`pages/ai.js`): All 25+ tools with category tabs, sticky output panel, copy/use actions
- **AI Marketing Chat** (`pages/chat.js`): Conversational AI with marketing data context, conversation history, multi-provider model selection
- **AI Writing Assistant** (`pages/assistant.js`): Floating panel with 12 refinement actions, 4 tone changes, analysis tools, accessible from any page via FAB button
- **Content Studio** (`pages/content.js`): AI Write Content, AI Title, AI Hashtags, AI Score, inline AI toolbar (improve/expand/shorten/persuasive/emoji), one-click repurpose per post; auto-populates from AI Studio "Use in Post" via sessionStorage
- **Email Marketing** (`pages/email.js`): AI Write Email, AI Subject Lines, inline AI toolbar on body field; template preview via sandboxed iframe
- **Campaigns** (`pages/campaigns.js`): AI Strategy, AI Campaign Optimizer
- **Landing Pages** (`pages/landing.js`): AI Generate Copy
- **Competitors** (`pages/competitors.js`): AI Deep Dive
- **A/B Tests** (`pages/abtests.js`): AI Generate Variants
- **Dashboard** (`pages/dashboard.js`): 6 AI quick action buttons, AI Insights card with proactive recommendations
- **Global Command Bar** (`core/router.js`): Ctrl+K from any page, 10 quick actions in 2 groups
- **SEO Tools** (`pages/seo.js`): AI keyword research, blog generation
- **Publish Queue** (`pages/queue.js`): AI Smart Posting Times with platform-specific recommendations
- **Onboarding** (`pages/onboarding.js`): 5-step wizard that collects business profile and launches AI Autopilot to bootstrap content

### AI Writing Assistant (`pages/assistant.js`)
Floating side panel accessible from any page via the purple FAB button (bottom-right corner).
- **Quick Actions** (12): Improve, Expand, Shorten, Add Hooks, Add CTA, Bullet Points, Add Emojis, Simplify
- **Tone Changes** (4): Formal, Casual, Persuasive, Storytelling
- **Analysis**: Tone Analysis, Content Score, Headline Ideas
- **Custom Instructions**: Free-form AI refinement input
- **Apply to Field**: One-click to replace the active textarea content with AI output
- Automatically detects the last-focused textarea on the current page

### AI Inline Toolbar
Contextual AI action buttons rendered above textarea fields. Currently on:
- Content Studio post body textarea
- Email Compose HTML body textarea
Uses `.ai-inline-btn[data-inline-refine]` elements, wired globally in `app.js::initInlineAiToolbars()`.

### Brand Voice
`BrandProfileRepository` in `Repositories.php` manages brand voice profiles. Active profile fields (`voice_tone`, `vocabulary`, `avoid_words`, `example_content`, `target_audience`) are injected into AI system prompts via `AiService::buildSystemPrompt()`.

### AI Brain System (`AiMemoryEngine.php` + `AiOrchestrator.php`)
The AI Brain makes the system self-aware — it learns from its own outputs, tracks all activity, and feeds context back into every AI call.

**Memory Engine (`AiMemoryEngine.php`):**
- **Activity Logging**: Every AI tool invocation is logged to `ai_activity_log` with input/output summaries
- **Auto-Learning**: After each tool run, the AI extracts key insights and saves them to `ai_learnings` with confidence scores
- **Situational Awareness**: `buildBrainContext()` injects current date/time, active campaigns, upcoming deadlines, recent AI activity, and learned insights into every system prompt
- **Performance Feedback**: Connects published content performance back to AI context via `ai_performance_feedback`
- **Memory Decay**: Unreinforced learnings decay in confidence over time; duplicate insights reinforce existing ones
- **Self-Reflection**: `selfReflect()` returns knowledge coverage, gaps, and strongest learnings

**Orchestrator (`AiOrchestrator.php`):**
- **Pipeline Templates**: 5 built-in multi-step pipelines (content_creation, campaign_launch, competitor_intel, content_repurpose, seo_content)
- **Tool Chaining**: Steps pass context via `{{prev_summary}}` and `{{prev.content}}` variables
- **Next-Action Suggestions**: After any tool runs, suggests the best follow-up tools via `NEXT_ACTION_MAP`
- **Tool Registry**: Maps all 28+ tool names to endpoints, categories, and output fields

**Database Tables:**
- `ai_activity_log` — Every AI tool call (tool_name, category, input/output summaries, provider, duration)
- `ai_learnings` — Auto-extracted insights (category, insight, confidence, reinforcement count, expiry)
- `ai_performance_feedback` — Content performance metrics linked back to AI activity
- `ai_pipelines` — Saved pipeline definitions
- `ai_pipeline_runs` — Pipeline execution history with per-step results

**Context Injection Flow:**
```
buildSystemPrompt() → buildBrainContext() injects:
  1. Situational awareness (date, active campaigns, upcoming posts, draft count)
  2. Recent AI activity digest (last 8 tool runs with summaries)
  3. Learned insights grouped by category (ranked by confidence × reinforcement)
  4. Performance feedback (what content worked/didn't)
```

**API Endpoints:**
- `/api/ai/brain/status` — Self-reflection (knowledge coverage, gaps, stats)
- `/api/ai/brain/activity` — Activity log with category filter
- `/api/ai/brain/stats` — Aggregated activity statistics
- `/api/ai/brain/learnings` — CRUD for auto-extracted learnings
- `/api/ai/brain/feedback` — Performance feedback CRUD
- `/api/ai/brain/capture-performance` — Auto-capture post performance data
- `/api/ai/pipelines/templates` — List pipeline templates
- `/api/ai/pipelines/run` — Execute a pipeline
- `/api/ai/pipelines/next-actions` — Get suggested next tools
- `/api/ai/pipelines/runs` — Pipeline run history
- `/api/ai/pipelines/tools` — Tool registry for frontend

## UI/UX Architecture

### Navigation
- Sidebar with 5 collapsible grouped sections + Dashboard and Settings as top-level
- Sections: AI & Content, Marketing, Intelligence, Audience, Tools
- Collapse state persists in localStorage (`nav_<section>` keys)
- Active page's section auto-expands on navigation

### SPA Routing
- Hash-based: `#page-name` (e.g. `#ai`, `#content`, `#dashboard`, `#chat`, `#onboarding`)
- `router.js`: `registerPage(name, module)`, `navigate(page)`, `showPage(page)`
- Each page module exports `init()` (bind events) and optionally `refresh()` (load data on visit)
- `app.js` registers all 24 pages and calls `initAll()` after authentication
- Router safely checks `typeof mod.refresh === 'function'` before calling — pages without it simply skip data refresh

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

### CSS Utility Classes
Key utility classes: `.hidden`, `.flex`, `.flex-between`, `.flex-end`, `.mt-1`, `.mb-1`, `.mb-2`, `.text-muted`, `.text-secondary`, `.text-small`, `.text-success`, `.text-danger`, `.w-full`, `.gap-1`, `.flex-wrap`.

Button variants: `.btn`, `.btn-ai` (AI purple gradient), `.btn-ghost` (transparent), `.btn-outline`, `.btn-success`, `.btn-danger`, `.btn-warning`, `.btn-sm`, `.btn-lg`.

### Accessibility
- `:focus-visible` outlines on all interactive elements for keyboard navigation
- Disabled button states (`.btn:disabled`) with reduced opacity
- Modal overlays use `role="dialog"` and `aria-labelledby`
- Icon-only buttons include `aria-label` attributes
- Modal open/close animations use `opacity` + `visibility` + `transform` transitions

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

`SocialPublisher.php` supports 15 platforms with unified `publish(post, account)` interface:
- **Twitter/X** (API v2) - OAuth 2.0 Bearer token
- **Bluesky** (AT Protocol) - identifier + password auth
- **Mastodon** (ActivityPub) - instance URL + OAuth token
- **Facebook Pages** (Graph API v19.0) - Page Access Token
- **Instagram** (Graph API) - requires public image URL
- **LinkedIn** (REST API v2) - OAuth 2.0, URN-based author
- **Threads** (Meta Graph API) - two-step container publish
- **Pinterest** (REST API v5) - board_id + image URL required
- **TikTok** (Content Posting API) - video/photo upload
- **Reddit** (REST API) - subreddit targeting, self/link posts
- **Telegram** (Bot API) - bot token + chat_id
- **Discord** (Webhooks) - webhook URL, no OAuth needed
- **Slack** (Incoming Webhooks) - webhook URL, no OAuth needed
- **WordPress** (WP REST API) - Application Passwords auth
- **Medium** (REST API) - Integration token

## Configuration (.env)

```
BUSINESS_NAME, BUSINESS_INDUSTRY, TIMEZONE
AI_PROVIDER=openai|anthropic|gemini|deepseek|groq|mistral|openrouter|xai|together
OPENAI_API_KEY, OPENAI_BASE_URL, OPENAI_MODEL
ANTHROPIC_API_KEY, ANTHROPIC_MODEL
GEMINI_API_KEY, GEMINI_MODEL
DEEPSEEK_API_KEY, DEEPSEEK_MODEL
GROQ_API_KEY, GROQ_MODEL
MISTRAL_API_KEY, MISTRAL_MODEL
OPENROUTER_API_KEY, OPENROUTER_MODEL
XAI_API_KEY, XAI_MODEL
TOGETHER_API_KEY, TOGETHER_MODEL
APP_URL, MAX_UPLOAD_MB, CRON_KEY
SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM
```

## Development Notes

- No build step required - edit files directly and refresh
- All frontend JS uses ES modules (`import`/`export`)
- Backend uses `request_json()` helper to parse POST bodies
- API responses follow `{ item: {...} }` or `{ items: [...] }` pattern
- The `api()` wrapper in `api.js` handles CSRF tokens automatically and returns raw `Response` for non-JSON content types (CSV exports, etc.)
- The `formData(e)` utility converts FormData to a plain object
- `statusBadge(status)` returns colored badge HTML
- Toast notifications via `success()`, `error()` from `toast.js`
- When adding new AI tools: add method to the appropriate tool class (`AiContentTools.php`, `AiAnalysisTools.php`, or `AiStrategyTools.php`), register route in `routes/ai.php`, add card in `app.html`, wire button in `pages/ai.js`
- When adding new pages: create page module in `pages/`, register in `app.js`, add HTML section in `app.html`, add nav link in sidebar
- All SQL queries use prepared statements with parameter binding (no raw concatenation)
- Landing page custom CSS is sanitized via `LandingPages::sanitizeCss()` to prevent style tag injection
- Short link codes are generated iteratively (max 20 attempts, auto-lengthens on collision)
- Scheduler uses `flock()` with `LOCK_EX | LOCK_NB` for atomic cron lock + stale lock recovery
- Webhook dispatch validates URLs against SSRF (blocks private/reserved IP ranges)
- AI Studio provider select sends `provider` param to API for per-request provider override
- All API list endpoints return `{ items: [...] }` and single-item endpoints return `{ item: {...} }`; frontend uses defensive `data.items || data` unwrapping
- `install.php` requires minimum 10-character passwords (matches server-side validation in Auth)
- `login.js` guards against duplicate `initRouter()` calls on re-authentication
