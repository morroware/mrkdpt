# Known Issues

A catalog of identified issues, limitations, and improvement recommendations organized by severity.

## Critical Issues

### 1. Open Redirect in Email Click Tracking

**Location:** `public/index.php` — email click tracking redirect

**Description:** The `/api/track/click` endpoint accepts any HTTPS URL as the redirect destination. An attacker could craft tracking links that redirect to phishing pages.

**Status (March 26, 2026):** Resolved in code. Redirects are now sanitized and restricted to the `APP_URL` host for click-tracking links.

**Remaining recommendation:** Expand to a multi-domain allowlist for multi-brand tenants if needed.

---

### 2. OAuth Token Refresh Not Implemented

**Location:** `src/SocialPublisher.php`

**Description:** The social publisher has a TODO comment indicating that per-platform OAuth token refresh logic is not implemented. When access tokens expire, publishing will fail silently.

**Affected platforms:** Twitter, Facebook, Instagram, LinkedIn, Threads, Pinterest, TikTok, Reddit (all OAuth-based platforms).

**Recommendation:** Implement token refresh for each OAuth provider before the access token expires. Store and check `token_expires_at` field.

---

### 3. API Keys Stored in Plaintext

**Location:** `social_accounts` table (`access_token`, `refresh_token` fields), `.env` file

**Description:** API keys and OAuth tokens are stored as plaintext in the database and configuration file. If the database is compromised, all connected platform credentials are exposed.

**Recommendation:** Implement encryption at rest for sensitive fields using a key derivation function.

---

## High Priority Issues

### 4. Rate Limiting Gaps

**Location:** `src/Auth.php`

**Description:**
- Login endpoint rate limiting uses `$_SERVER['REMOTE_ADDR']` which can be spoofed behind proxies
- Not all public endpoints have rate limiting (e.g., short link redirects)
- No per-user rate limiting (only per-IP)

**Recommendation:** Use `X-Forwarded-For` with validation when behind a reverse proxy. Add rate limiting to all public endpoints.

---

### 5. No HTTPS Enforcement

**Location:** `public/cron.php`, `public/index.php`

**Description:** The cron key is passed as a URL query parameter over potentially unencrypted connections.

**Status (March 26, 2026):** Partially resolved — optional `APP_FORCE_HTTPS=true` now enforces HTTPS redirects in `index.php`.

**Recommendation:** Move the cron key to a request header to fully remove URL-based secret exposure.

---

### 6. Weak Password Policy

**Location:** `public/install.php`

**Status (March 26, 2026):** Resolved in installer and auth layer. Passwords now require 10+ characters with uppercase, lowercase, number, and symbol.

---

### 7. No Account Lockout

**Location:** `src/Auth.php`

**Description:** There is no account lockout after repeated failed login attempts. The rate limiter slows down attempts but doesn't block them.

**Recommendation:** Implement account lockout after N failed attempts with a cooldown period.

---

## Medium Priority Issues

### 8. N+1 Query Patterns

**Location:** `src/Scheduler.php`, various repositories

**Description:** The `publishDuePosts()` method queries social accounts in a loop for each post. Similar patterns exist in other repository methods.

**Recommendation:** Use JOINs or batch queries to reduce database round trips.

---

### 9. Synchronous Email Sending

**Location:** `src/EmailService.php`

**Description:** SMTP operations are synchronous and blocking. Sending a campaign to a large list blocks the request thread. A single failed email can affect the send queue.

**Recommendation:** Implement asynchronous email sending with a queue and background worker.

---

### 10. Fragile AI Output Parsing

**Location:** `src/AiContentTools.php` — `repurposeContent()`

**Description:** Content repurposing uses regex to split AI output by format labels. If the AI doesn't follow the expected format exactly, data can be lost or misattributed.

**Recommendation:** Use structured output (JSON mode or function calling) for AI responses that need parsing.

---

### 11. No Request Caching for AI

**Location:** `src/AiService.php`

**Description:** No deduplication or caching of AI requests. Identical prompts generate new API calls every time.

**Recommendation:** Implement prompt-level caching with a configurable TTL.

---

### 12. No Token Counting

**Location:** `src/AiService.php`

**Description:** The AI service doesn't count tokens before sending requests. Prompts that exceed the model's context window will fail.

**Recommendation:** Implement token estimation and prompt truncation for large inputs.

---

### 13. Missing Error Context

**Location:** Various services

**Description:** Many error responses are generic strings without debugging context. Hard to diagnose issues in production.

**Recommendation:** Implement structured logging with request IDs, timestamps, and stack traces.

---

### 14. No CSRF on Public Forms

**Location:** `src/FormBuilder.php`, `public/index.php`

**Description:** Public form submissions at `/api/forms/{slug}/submit` don't require CSRF tokens. While rate-limited, they could be targets for cross-site request forgery.

**Recommendation:** Add a CSRF or honeypot mechanism to public forms.

---

### 15. Scheduler Race Condition

**Location:** `src/Scheduler.php`

**Description:** Uses a filesystem lock via `flock(LOCK_EX | LOCK_NB)` which is atomic on single-server deployments but doesn't protect against distributed execution. Running cron on multiple servers could publish posts multiple times.

**Status:** Partially resolved — `flock()` provides atomic locking on single servers. Null guard added for `account_ids` from `GROUP_CONCAT`. Stale lock recovery with PID checking implemented.

**Remaining:** For multi-server deployments, consider database-level locking.

---

## Low Priority Issues

### 16. No Soft Deletes

**Location:** All repositories

**Description:** All delete operations are permanent. No recycle bin or undo capability.

**Recommendation:** Add `deleted_at` column for soft deletes on critical entities (posts, contacts, campaigns).

---

### 17. No Audit Logging

**Location:** System-wide

**Description:** No audit trail for user actions. Can't track who made what changes and when.

**Recommendation:** Implement an audit log table tracking user, action, entity, timestamp, and changes.

---

### 18. Router Returns 404 for Wrong Method

**Location:** `src/Router.php`

**Status (March 26, 2026):** Resolved. Router now returns 405 with an `Allow` header when a path exists for other methods.

---

### 19. No Bounce Handling for Email

**Location:** `src/EmailService.php`

**Description:** The SMTP client doesn't handle bounce notifications. Undeliverable emails are not tracked, which can affect sender reputation.

**Recommendation:** Implement bounce processing via a return-path address and IMAP monitoring.

---

### 20. Missing .env.example

**Location:** Project root

**Status (March 26, 2026):** Resolved — `.env.example` added at project root.

---

### 21. No Content-Length Validation for Platforms

**Location:** `src/SocialPublisher.php`

**Description:** Some platforms have hard character limits (e.g., Twitter/X: 280 chars) that are not enforced before publishing. Posts exceeding limits will fail at the API level.

**Recommendation:** Validate content length against platform limits before attempting to publish.

---

### 22. RSS Feed Fetch Efficiency

**Location:** `src/RssFetcher.php`

**Description:** RSS feeds are fetched entirely on each cron run with no conditional GET (ETag/If-Modified-Since) support.

**Recommendation:** Store ETag and Last-Modified headers to enable conditional requests.

---

### 23. SMTP Authentication Limited

**Location:** `src/EmailService.php`

**Description:** Only PLAIN and LOGIN SMTP authentication mechanisms are supported. No CRAM-MD5, XOAUTH2, or other methods.

**Recommendation:** Add XOAUTH2 support for modern email providers (Gmail, Outlook).

---

### 24. No Service Container / Dependency Injection

**Location:** `public/index.php`

**Description:** All 20+ services are manually instantiated in `index.php`. Adding or modifying dependencies requires changing the bootstrap code.

**Recommendation:** Implement a lightweight service container or factory pattern.

---

## Frontend Issues

### 25. ~~Potential XSS in Email Template Preview~~ (RESOLVED)

**Location:** `public/assets/js/pages/email.js`

**Status:** Fixed — Email template preview now uses DOM property assignment (`iframe.srcdoc = html`) instead of HTML attribute interpolation, and the iframe uses `sandbox=""` attribute which prevents all script execution.

---

### 26. Duplicated `esc()` Utility Function

**Location:** `contacts.js`, `forms.js`, `landing.js`, `abtests.js`, `funnels.js`, `links.js`, `automations.js`, `rss.js`

**Description:** An `esc()` HTML escaping function is defined locally in 8+ page modules despite `escapeHtml()` already existing in `core/utils.js`.

**Recommendation:** Remove duplicate definitions and import `escapeHtml` from `utils.js`.

---

### 27. Silent Error Handling in Multiple Modules

**Location:** `contacts.js`, `analytics.js`, `funnels.js`, `automations.js`, `rss.js`

**Description:** Several page modules have empty or silent `catch` blocks. When API calls fail, users receive no feedback.

**Recommendation:** Add `error(err.message)` toast notifications in all catch blocks.

---

### 28. Global Window Function Exposure

**Location:** `contacts.js` (`window._deleteContact`, `window._viewContact`, etc.)

**Description:** Several modules expose internal functions on `window` for use in inline `onclick` handlers. This pollutes the global namespace and could cause naming conflicts.

**Recommendation:** Use event delegation instead of inline onclick handlers with global functions.

---

### 29. No Client-Side Pagination

**Location:** All list/table views across page modules

**Description:** All data is loaded at once with no pagination. Large datasets (thousands of posts, contacts, etc.) will cause performance degradation and slow page rendering.

**Recommendation:** Implement server-side pagination with `limit` and `offset` parameters.

---

### 30. Alert/Confirm Dialogs for Complex Output

**Location:** `content.js` (pre-flight, predict), `abtests.js` (AI analyze), `funnels.js`

**Description:** Several features use `alert()` and `confirm()` to display detailed AI analysis output. These are blocking, unstyled, and poor UX on mobile.

**Recommendation:** Replace with modal dialogs for rich content display.

---

### 31. Hardcoded Platform Lists in Frontend

**Location:** `content.js`, `landing.js`, `email.js`, `social.js`

**Description:** Platform options (Twitter, LinkedIn, Instagram, etc.) are hardcoded in multiple JS modules and HTML. Adding a new platform requires changes in multiple files.

**Recommendation:** Serve platform configuration from the backend and populate dropdowns dynamically.

---

## Architectural Limitations

### Single-Server Design

The application is designed for single-server deployment. The SQLite database, filesystem locks, and local file storage don't scale to multiple servers without modification.

### No Background Workers

All operations (email sending, social publishing, AI calls) are synchronous within the request lifecycle. Long-running operations block the response.

### No Versioning

No API versioning, database versioning, or content versioning. Schema changes rely on the auto-migration system.

### No Internationalization

The application UI is English-only. No i18n framework or translatable strings.

---

## Recently Resolved Issues

The following issues were identified and fixed during a comprehensive code audit:

| Issue | Fix | File |
|-------|-----|------|
| SQL concatenation in Competitor/KPI repositories | Replaced with prepared statements | `src/Repositories.php` |
| CSS injection in landing page custom CSS | Added `sanitizeCss()` to strip dangerous patterns | `src/LandingPages.php` |
| Scheduler null pointer on missing account_ids | Added null coalescing guard | `src/Scheduler.php` |
| LinkShortener recursive code generation | Replaced with iterative loop (max 20 attempts) | `src/LinkShortener.php` |
| AI-generated content not applied from sessionStorage | Content now populates post form on Content Studio | `public/assets/js/pages/content.js` |
| Email template preview escaped HTML | Uses DOM srcdoc property instead of escapeHtml | `public/assets/js/pages/email.js` |
| Missing `.btn-ghost` CSS class (7 uses) | Added class definition | `public/assets/styles.css` |
| Missing `.flex-end` CSS utility | Added class definition | `public/assets/styles.css` |
| No disabled button states | Added `.btn:disabled` styles | `public/assets/styles.css` |
| No keyboard focus indicators | Added `:focus-visible` styles | `public/assets/styles.css` |
| Modal overlays with no transition | Added opacity/visibility/transform transitions | `public/assets/styles.css` |
| Missing ARIA attributes on modals | Added `role="dialog"` and `aria-labelledby` | `public/app.html` |
| Missing aria-label on icon buttons | Added `aria-label` to close/menu/theme buttons | `public/app.html` |
