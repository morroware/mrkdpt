# Email Marketing

## Overview

Built-in email marketing with zero-dependency SMTP client, list management, campaign composer, template system, and open/click tracking.

## Architecture

```
EmailService.php         — SMTP client, sending, tracking
EmailTemplates.php       — Template management (6 built-in)
routes/email.php         — API endpoints for lists, subscribers, campaigns
routes/email_templates.php — Template CRUD endpoints
```

## Email Lists

Lists organize subscribers for targeted campaigns.

```
POST /api/email-lists
{ "name": "Newsletter Subscribers", "description": "Weekly newsletter" }
```

Each list tracks subscriber count automatically.

## Subscribers

### Adding Subscribers

```
POST /api/subscribers
{
    "email": "user@example.com",
    "list_id": 1,
    "first_name": "John",
    "last_name": "Doe",
    "status": "active"
}
```

Duplicate email/list combinations return an error.

### CSV Import

```
POST /api/subscribers/import
{
    "list_id": 1,
    "csv": "email,first_name,last_name\njohn@example.com,John,Doe\njane@example.com,Jane,Smith"
}
```

Returns `{ imported: 2, skipped: 0 }`.

### Subscriber Statuses
- `active` — Receives emails
- `unsubscribed` — Opted out

## Email Campaigns

### Creating a Campaign

```
POST /api/email-campaigns
{
    "name": "June Newsletter",
    "subject": "Your June Update",
    "body_html": "<h1>Hello {{name}}</h1>...",
    "body_text": "Hello {{name}}...",
    "list_id": 1,
    "status": "draft"
}
```

### Merge Tags

Available in both HTML and text bodies:

| Tag | Description |
|-----|-------------|
| `{{name}}` / `{{firstname}}` | Subscriber's first name |
| `{{email}}` | Subscriber's email address |
| `{{unsubscribe_url}}` | HMAC-signed unsubscribe link |
| `{{tracking_pixel}}` | 1x1 invisible tracking GIF |
| `{{date}}` | Current date |

### Sending

```
# Send to all active subscribers in the list
POST /api/email-campaigns/{id}/send

# Send test email
POST /api/email-campaigns/{id}/test
{ "to": "test@example.com" }
```

Send returns: `{ sent: 150, failed: 2, skipped: 5 }`

### Campaign Statistics

```
GET /api/email-campaigns/{id}/stats
```

Returns:
- Total sent
- Opens (total and unique)
- Clicks (total and unique)
- Open rate, click rate

## Email Templates

### Built-in Templates (6)

| Template | Category | Description |
|----------|----------|-------------|
| Welcome Email | Onboarding | New subscriber/customer welcome |
| Newsletter | Content | Regular newsletter layout |
| Promotional Offer | Sales | Discount/promotion announcement |
| Event Invitation | Events | Event details with RSVP |
| Follow-Up | Sales | Post-interaction follow-up |
| Product Announcement | Product | New feature/product launch |

Built-in templates cannot be deleted.

### Template Variables

Each template defines its own variable set. Variables use `{{name}}` syntax and are replaced during rendering:

```
POST /api/email-templates/{id}/render
{
    "variables": {
        "subscriber_name": "John",
        "company_name": "Acme Corp",
        "cta_url": "https://example.com"
    }
}
```

### Custom Templates

Create custom templates with full HTML/text content:

```
POST /api/email-templates
{
    "name": "My Template",
    "html_template": "<html>...</html>",
    "text_template": "Plain text version...",
    "category": "marketing"
}
```

## Tracking

### Open Tracking

A 1x1 transparent GIF pixel is embedded in HTML emails:

```
GET /api/track/open?c={campaign_id}&s={subscriber_id}
```

Logs the open event with timestamp.

### Click Tracking

Links in emails are wrapped with tracking redirects:

```
GET /api/track/click?c={campaign_id}&s={subscriber_id}&url={destination_url}
```

Logs the click event and redirects to the destination URL.

### Unsubscribe

HMAC-signed unsubscribe links ensure only the intended subscriber can unsubscribe:

```
GET /api/unsubscribe?s={subscriber_id}&l={list_id}
```

Displays an unsubscribe confirmation page and updates the subscriber status to `unsubscribed`.

## SMTP Configuration

The SMTP client connects directly via socket (no PHPMailer or external libraries).

| Setting | Description |
|---------|-------------|
| `SMTP_HOST` | Server hostname |
| `SMTP_PORT` | Port (25, 465, 587) |
| `SMTP_USER` | Authentication username |
| `SMTP_PASS` | Authentication password |
| `SMTP_FROM` | Sender email address |
| `SMTP_FROM_NAME` | Sender display name |

Features:
- TLS/SSL via STARTTLS
- PLAIN and LOGIN authentication
- MIME multipart (HTML + text)
- 30-second socket timeout

## AI Integration

### AI Write Email
Generate complete email content from a topic and goal.

### AI Subject Lines
Generate 10 subject line variations with predicted open rates and psychological triggers:

```
POST /api/ai/subject-lines
{ "topic": "Summer sale announcement", "count": 10 }
```

### Inline AI Toolbar
On the email campaign composer, the HTML body textarea has inline AI buttons:
- Improve, Expand, Shorten, Persuasive

### AI Drip Sequence
Generate a complete email drip sequence:

```
POST /api/ai/drip-sequence
{ "goal": "onboard new users", "audience": "SaaS trial users", "count": 5 }
```
