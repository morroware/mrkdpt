# Known Issues

A catalog of identified issues, limitations, and improvement recommendations organized by severity.

## Critical Issues

### 1. Open Redirect in Email Click Tracking

**Location:** `public/index.php` — email click tracking redirect

**Description:** The `/api/track/click` endpoint accepts any HTTPS URL as the redirect destination. An attacker could craft tracking links that redirect to phishing pages.

**Current behavior:** Only validates that the URL starts with `http://` or `https://`.

**Recommendation:** Whitelist the application's own domain or maintain an allowlist of permitted redirect domains.

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

**Description:** The cron key is passed as a URL query parameter over potentially unencrypted connections. No automatic HTTPS redirect exists.

**Recommendation:** Move the cron key to a request header. Add HTTPS redirect in `index.php` for production environments.

---

### 6. Weak Password Policy

**Location:** `public/install.php`

**Description:** Only an 8-character minimum length is enforced. No requirements for uppercase, numbers, or special characters.

**Recommendation:** Add password complexity requirements or use a password strength estimator.

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

**Description:** Uses a filesystem lock which doesn't protect against distributed execution. Running cron on multiple servers could publish posts multiple times.

**Recommendation:** Implement database-level locking or use an `is_processing` flag with timestamps.

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

**Description:** When a route exists but the HTTP method doesn't match, the router returns 404 instead of 405 Method Not Allowed.

**Recommendation:** Implement proper 405 responses with `Allow` header listing valid methods.

---

### 19. No Bounce Handling for Email

**Location:** `src/EmailService.php`

**Description:** The SMTP client doesn't handle bounce notifications. Undeliverable emails are not tracked, which can affect sender reputation.

**Recommendation:** Implement bounce processing via a return-path address and IMAP monitoring.

---

### 20. Missing .env.example

**Location:** Project root

**Description:** No `.env.example` template file is provided. Users must rely on the web installer or documentation for configuration reference.

**Recommendation:** Create a `.env.example` with all configuration options and comments.

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

## Architectural Limitations

### Single-Server Design

The application is designed for single-server deployment. The SQLite database, filesystem locks, and local file storage don't scale to multiple servers without modification.

### No Background Workers

All operations (email sending, social publishing, AI calls) are synchronous within the request lifecycle. Long-running operations block the response.

### No Versioning

No API versioning, database versioning, or content versioning. Schema changes rely on the auto-migration system.

### No Internationalization

The application UI is English-only. No i18n framework or translatable strings.
