# System Reference (Current State)

This document is the implementation-focused reference for the current codebase state.

## Stack & Runtime

- Backend: PHP 8.1+ (framework-free), SQLite, cURL, mbstring.
- Frontend: vanilla JS SPA (`public/app.html` + `public/assets/js/**`).
- Deployment: static PHP hosting with `public/` webroot.
- Data persistence: `data/marketing.sqlite` initialized and migrated by `src/Database.php`.

## Entry Points

- `public/index.php` — API bootstrap, dependency wiring, route registration, upload/static handlers.
- `public/install.php` — installer and first-user bootstrapping.
- `public/cron.php` — scheduler trigger endpoint.
- `public/app.html` — SPA shell and page containers.

## Core Subsystems

### 1) Auth & Security Controls

- Session + bearer-token auth in `src/Auth.php`.
- CSRF validation for mutating requests (except approved bearer-token flows).
- Login lockout/rate limiting configurable via settings/env.

### 2) Routing & API Surface

- Router implementation in `src/Router.php`.
- Route files under `src/routes/` by domain (AI, posts, campaigns, automations, etc.).
- Current route inventory: **336 method/path registrations across 31 route files** (derived from source scan).

### 3) Data Layer

- Database schema and safe migration helpers in `src/Database.php`.
- Repository layer in `src/Repositories.php` plus feature repositories (`CampaignMetrics`, `EmailTemplates`, etc.).

### 4) AI Platform

- Provider abstraction in `src/AiService.php`.
- Tool sets:
  - `src/AiContentTools.php`
  - `src/AiAnalysisTools.php`
  - `src/AiStrategyTools.php`
- Memory and self-learning:
  - `src/AiMemoryEngine.php`
  - `src/AiOrchestrator.php`
  - `src/AiSearchEngine.php`
  - `src/AiAgentSystem.php`

### 5) Marketing Operations Features

- Content, campaigns, contacts, social queue, templates, forms, landing pages, funnels, A/B tests, email, SMS, webhooks, RSS, media upload, and UTM/short links are implemented as first-party services in `src/` with API routes in `src/routes/`.

### 6) Async & Scheduled Work

- `src/JobQueue.php` provides async job dispatch/retry behavior.
- `src/Scheduler.php` runs cron tasks and queue processing.
- `public/cron.php` invokes scheduler with key-based auth.

## Frontend Mapping

- Shared runtime helpers: `public/assets/js/core/`.
- Page modules: `public/assets/js/pages/*.js` (one file per feature page).
- Boot registration: `public/assets/js/app.js`.

## WordPress Integration

- Plugin code under `wordpress-plugin/marketing-suite-connector/`.
- Connector endpoints in `src/routes/wordpress_plugin.php`.

## Operational Requirements

- Ensure `data/` is writable.
- Ensure cron is configured (typically every 5 minutes) for scheduled publishing, retries, and feed processing.
- AI and SMTP settings can be set in `.env` and overridden in app settings.

## Known Functional Boundaries (By Design)

- If setup is not completed, most app features are intentionally unavailable until installer flow is done.
- AI-dependent features gracefully degrade to fallback outputs when no provider credentials are configured.
- Scheduled/queued behavior requires cron; without cron, immediate UI actions still work but deferred jobs remain pending.
