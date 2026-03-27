# Security

## Authentication

### Session-Based (Browser)

- PHP sessions with 7-day cookie lifetime
- `httponly`, `samesite=Lax`, and `secure` (when HTTPS) cookie flags
- Session ID regenerated on login (`session_regenerate_id(true)`)
- Sessions stored server-side

### Bearer Token (API)

- 64-character hex tokens (32 random bytes via `random_bytes()`)
- Sent via `Authorization: Bearer {token}` header
- Stateless — no session required
- Tokens can be regenerated via `POST /api/regenerate-token`

### Authentication Flow

1. Browser request → Check `Authorization` header for bearer token
2. If no bearer token → Check PHP session for user ID
3. If neither → Return 401 Unauthorized

## CSRF Protection

- CSRF tokens generated per session
- Required in `X-CSRF-Token` header for all mutating requests
- Bearer token requests skip CSRF (stateless API calls)
- Token returned on login and via `GET /api/me`

## Rate Limiting

- IP-based rate limiting via `rate_limits` database table
- Default: 30 requests per 60-second window
- Login rate limiting uses both client IP and username keying (e.g., `login:alice`)
- Optional proxy-aware IP detection via `TRUST_PROXY_HEADERS=true` (reads validated `X-Forwarded-For`)
- Applied to sensitive endpoints (login, form submissions)
- Old entries cleaned on each check

## Password Security

- Passwords hashed with bcrypt (`password_hash()`)
- Verified with `password_verify()`
- Minimum 10 characters with uppercase, lowercase, number, and symbol
- Stored as `password_hash` column in `users` table

## SQL Injection Prevention

- **Prepared statements** used throughout all database queries
- PDO parameter binding for all user input
- No raw string concatenation in SQL queries

## XSS Prevention

- HTML escaping via `htmlspecialchars()` with `ENT_QUOTES`
- `escapeHtml()` utility in frontend JavaScript
- Content-Security-Policy headers (when configured)
- **Custom CSS sanitization** in `LandingPages.php` — strips `javascript:`, `expression()`, `@import`, `-moz-binding`, `behavior`, and `</style>` tag breakout sequences from user-provided landing page CSS
- Email template previews use sandboxed iframes (`sandbox=""`) to prevent script execution

## Security Headers

Applied via `security_headers()` in `bootstrap.php`:

| Header | Value | Purpose |
|--------|-------|---------|
| `X-Content-Type-Options` | `nosniff` | Prevent MIME sniffing |
| `X-Frame-Options` | `SAMEORIGIN` | Prevent clickjacking |
| `X-XSS-Protection` | `1; mode=block` | XSS filter |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Control referer header |

## File Upload Security

- MIME type validation using `finfo_file()` or `mime_content_type()`
- Allowed types: JPEG, PNG, GIF, WebP, MP4, PDF
- File size limits (configurable via `MAX_UPLOAD_MB`)
- `is_uploaded_file()` check prevents path manipulation
- Files stored with unique names (not user-provided names)
- Upload directory outside web root path validation via `realpath()`

## Path Protection

### Apache (.htaccess)

```
# Block access to sensitive files
<FilesMatch "\.(env|sqlite|db)$">
    Require all denied
</FilesMatch>
```

### Nginx

```nginx
location ~ /\.(env|git|sqlite) {
    deny all;
}
location ^~ /data/ {
    deny all;
}
location ^~ /src/ {
    deny all;
}
```

## Webhook Security

- **HMAC-SHA256 signing** — Each webhook has a unique secret
- Signature sent in `X-Webhook-Signature` header
- Auto-generated 48-character hex secrets

### Verification

```php
$expectedSignature = hash_hmac('sha256', $requestBody, $webhookSecret);
if (hash_equals($expectedSignature, $receivedSignature)) {
    // Webhook is authentic
}
```

## Email Security

- **HMAC-signed unsubscribe URLs** — Prevents unauthorized unsubscribes
- STARTTLS encryption for SMTP connections
- Click tracking validates URL scheme (http/https only)

## Automation SSRF Protection

The automation webhook action includes SSRF protection:
- Validates webhook URLs before sending
- Prevents requests to internal/private IP ranges

## Cron Security

- Cron endpoint requires a secret key (`CRON_KEY` in `.env`)
- Key validated via GET parameter or CLI execution
- CLI detection via `php_sapi_name() === 'cli'`

## Short Link Code Generation

- Iterative generation with uniqueness check (max 20 attempts)
- Auto-increases code length after 10 collision attempts
- Throws `RuntimeException` if unable to generate a unique code after exhausting all attempts
- Prevents stack overflow from recursive generation under high load

## Accessibility

- `:focus-visible` outlines on all interactive elements for keyboard navigation
- Modal overlays include `role="dialog"` and `aria-labelledby` attributes
- Icon-only buttons (close, menu toggle, theme toggle) include `aria-label`
- Disabled button states with `pointer-events: none` prevent interaction

## Static File Serving

- `realpath()` validation prevents directory traversal
- Only files within the `public/` directory are served
- Sensitive directories (`data/`, `src/`) are blocked
