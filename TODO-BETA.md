# Beta Readiness TODO (Canonical)

Last updated: March 28, 2026
Owner: Engineering + Product + QA

This is the single source of truth for getting Marketing Suite from PoC to beta-ready.

## Exit Criteria

- [ ] All **P0** and **P1** items complete.
- [ ] Security checklist pass (auth, redirects, secret handling, headers, CSRF/rate limits).
- [ ] End-to-end smoke test pass on fresh install and upgraded install.
- [ ] Cron jobs verified in production-like environment.
- [ ] Operational runbook published (backup/restore, incident response, rollbacks).

---

## P0 — Must Fix Before Beta (Blockers)

### Security & Trust
- [ ] Implement encryption-at-rest for sensitive tokens/keys in DB (`social_accounts.access_token`, `refresh_token`, webhook secrets where applicable).
  - **Acceptance:** DB compromise simulation cannot expose plaintext provider credentials.
- [ ] Add token refresh lifecycle for OAuth social accounts (Twitter/X, Facebook, Instagram, LinkedIn, Threads, Pinterest, TikTok, Reddit).
  - **Acceptance:** Expired token auto-refresh path is covered by integration tests and retries safely.
- [ ] Expand rate limiting to public endpoints (`/s/{code}`, form submission, tracking endpoints, webhook ingress).
  - **Acceptance:** Burst abuse attempts are throttled with deterministic 429 responses.
- [ ] Move cron authentication secret from query parameter to header-based auth (keep backward compatibility for one release with deprecation warning).
  - **Acceptance:** `cron.php` works with header auth; URL secret path can be disabled by config.

### Reliability
- [ ] Add robust error logging with request IDs and structured context across API routes/services.
  - **Acceptance:** Every 5xx response includes a correlatable server-side log entry.
- [ ] Add DB-level or distributed-safe lock strategy for scheduler when running multiple instances.
  - **Acceptance:** No duplicate publish on concurrent cron runs across >=2 workers.

### Compliance / User Safety
- [ ] Enforce signed unsubscribe links and verify this behavior in regression tests.
  - **Acceptance:** Unsigned/tampered links cannot unsubscribe users.
- [ ] Document data retention policy for contacts, tracking events, and uploads.
  - **Acceptance:** Policy appears in docs and configurable retention jobs exist.

---

## P1 — High Priority for Beta Quality

### Performance
- [ ] Remove N+1 query hotspots in scheduler/repositories with batching or joins.
  - **Acceptance:** Query count and latency reduced on batch publish benchmark.
- [ ] Implement async email send queue + worker; avoid request-thread blocking.
  - **Acceptance:** Large list campaign send starts quickly and processes in background.
- [ ] Add conditional GET support (ETag / Last-Modified) to RSS fetcher.
  - **Acceptance:** Repeated cron pulls reduce bandwidth when feeds unchanged.

### AI Robustness
- [ ] Replace fragile regex parsing for AI repurpose flows with structured JSON outputs + validation.
  - **Acceptance:** Malformed model output is rejected/retried without silent data loss.
- [ ] Add token estimation and truncation safeguards before provider calls.
  - **Acceptance:** Oversized prompts are trimmed or rejected with actionable errors.
- [ ] Add prompt-result cache for repeated AI requests (configurable TTL).
  - **Acceptance:** Duplicate prompts within TTL hit cache and skip provider call.

### Platform Hardening
- [ ] Complete social-platform content validation parity (weighted lengths, media rules, per-platform constraints).
  - **Acceptance:** Invalid payloads are blocked before publish, with clear per-platform reasons.
- [ ] Add bounce handling + suppression list flow for email.
  - **Acceptance:** Hard bounces mark subscriber/email state and prevent repeated sends.

---

## P2 — Important, Can Follow Immediately After Beta Start

### Product Safety / Governance
- [ ] Add audit log table and UI for critical actions (login, publish, delete, settings changes).
- [ ] Add soft-delete strategy for key entities (posts, contacts, campaigns).
- [ ] Add admin lockout event visibility and unlock workflow.

### Architecture / Maintainability
- [ ] Introduce lightweight service container/factory for app bootstrap dependencies.
- [ ] Add `.env` key validation utility + startup diagnostics endpoint.
- [ ] Add typed DTOs/validators for major API payloads.

### DX / Quality
- [ ] Add CI pipeline (syntax, static checks, smoke tests).
- [ ] Add seed fixtures for local QA scenarios.
- [ ] Add API contract smoke tests for top 20 endpoints.

---

## Smoke Test Matrix (Required for Beta Sign-off)

### Install / Auth
- [ ] Fresh install via `install.php`, admin creation, login/logout, CSRF flow.
- [ ] Upgrade path validation with pre-existing DB.
- [ ] Password policy and lockout behavior validated.

### Core Workflow
- [ ] Create campaign, post, and schedule publish.
- [ ] Connect social account and queue publish job.
- [ ] Build landing page + form and verify public submission.
- [ ] Create email list/campaign, send test email, verify open/click/unsubscribe tracking.

### Integrations
- [ ] RSS ingestion + auto-post path.
- [ ] Webhook signature verification and retry behavior.
- [ ] WordPress plugin connect/sync basic flow.

### Operations
- [ ] Cron runs: scheduled publish, queue dispatch, RSS fetch.
- [ ] Backup/restore dry run for `data/marketing.sqlite` + uploads.
- [ ] APP_FORCE_HTTPS and proxy header behavior in staging.

---

## Ownership & Tracking

- Engineering Lead: P0/P1 implementation and architecture calls.
- QA Lead: Smoke matrix execution and bug triage.
- Product Manager: Scope lock, release notes, beta cohort communication.
- Security Owner: Threat review sign-off for auth, redirects, token handling.

## Weekly Cadence

- Daily 15-minute blocker standup.
- Mid-week go/no-go checkpoint.
- Beta release candidate cut after P0 completion + smoke pass.
