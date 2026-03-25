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
- Applied to sensitive endpoints (login, form submissions)
- Old entries cleaned on each check

## Password Security

- Passwords hashed with bcrypt (`password_hash()`)
- Verified with `password_verify()`
- Minimum 8 characters enforced at installation
- Stored as `password_hash` column in `users` table

## SQL Injection Prevention

- **Prepared statements** used throughout all database queries
- PDO parameter binding for all user input
- No raw string concatenation in SQL queries

## XSS Prevention

- HTML escaping via `htmlspecialchars()` with `ENT_QUOTES`
- `escapeHtml()` utility in frontend JavaScript
- Content-Security-Policy headers (when configured)

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

## Static File Serving

- `realpath()` validation prevents directory traversal
- Only files within the `public/` directory are served
- Sensitive directories (`data/`, `src/`) are blocked
