# Forms & Landing Pages

## Form Builder

### Creating Forms

```
POST /api/forms
{
    "name": "Contact Us",
    "fields": [
        { "name": "email", "type": "email", "label": "Email", "required": true },
        { "name": "name", "type": "text", "label": "Full Name", "required": true },
        { "name": "message", "type": "textarea", "label": "Message", "required": false }
    ],
    "submit_label": "Send",
    "success_message": "Thank you!",
    "redirect_url": "",
    "notification_email": "admin@example.com",
    "list_id": 1,
    "tag_on_submit": "contact-form"
}
```

### Field Types

| Type | Description |
|------|-------------|
| `text` | Single-line text input |
| `email` | Email address input |
| `textarea` | Multi-line text area |
| `select` | Dropdown selection |
| `checkbox` | Checkbox input |
| `number` | Numeric input |
| `tel` | Phone number input |
| `url` | URL input |

### Form Submission (Public)

Forms can be submitted without authentication:

```
POST /api/forms/{slug}/submit
{
    "email": "user@example.com",
    "name": "John Doe",
    "message": "Hello!"
}
```

On submission:
1. Data is validated against field definitions
2. Submission is recorded in `form_submissions`
3. Form submission counter is incremented
4. If email field exists and `ContactRepository` is available:
   - Contact is created or updated
   - `tag_on_submit` is applied if configured
5. Automation rules fire for `form.submitted` event
6. Returns `{ contact_id, submission_id }` or redirects

### Embedding Forms

```
GET /api/forms/{id}/embed
```

Returns an iframe embed code for use on external sites:

```html
<iframe src="https://yourdomain.com/f/contact-us"
        width="100%" height="400" frameborder="0"></iframe>
```

The form at `/f/{slug}` is a self-contained HTML page with built-in JavaScript for AJAX submission.

### Viewing Submissions

```
GET /api/forms/{id}/submissions
```

Returns submissions with contact data (if linked) and timestamp.

## Landing Pages

### Creating Landing Pages

```
POST /api/landing-pages
{
    "title": "Summer Sale",
    "slug": "summer-sale",
    "template": "startup",
    "status": "published",
    "hero_headline": "50% Off Everything",
    "hero_subheadline": "Limited time offer",
    "hero_cta_text": "Shop Now",
    "hero_cta_url": "https://shop.example.com",
    "hero_image_url": "https://example.com/hero.jpg",
    "body_html": "<p>Additional content...</p>",
    "custom_css": ".hero { background: #000; }",
    "form_id": 1,
    "campaign_id": 1,
    "meta_description": "Summer sale - 50% off all products"
}
```

### Templates

| Template | Color Scheme | Description |
|----------|-------------|-------------|
| `startup` | Purple gradients | Modern startup/SaaS style |
| `minimal` | Light, clean | Minimal with ample whitespace |
| `bold` | Red/dark | Bold, high-contrast design |
| `nature` | Green tones | Organic, nature-inspired |
| `blank` | Neutral | Minimal styling for custom CSS |

### Public Rendering

Published landing pages are accessible at:

```
GET /p/{slug}
```

Features:
- Full HTML page with chosen template styles
- Hero section with headline, subheadline, CTA button, and optional image
- Body HTML content
- Integrated form (if `form_id` is set)
- Custom CSS injection
- Responsive design
- View tracking (auto-increments views counter)

### Form Integration

When a landing page has a `form_id`, the form is rendered inline with AJAX submission handling. On successful submission, the landing page's conversion counter is incremented.

### Tracking

| Metric | Description |
|--------|-------------|
| `views` | Incremented on each page load |
| `conversions` | Incremented on form submission |
| Conversion rate | `conversions / views * 100` |

### AI Integration

```
POST /api/ai/content
{
    "content_type": "landing_page",
    "topic": "Summer sale promotion",
    "platform": "web"
}
```

The AI generates landing page copy including headlines, body text, and CTAs that can be applied directly to the page editor.

## Links & UTM

### UTM Link Builder

```
POST /api/utm
{
    "base_url": "https://example.com/page",
    "utm_source": "newsletter",
    "utm_medium": "email",
    "utm_campaign": "summer-sale",
    "utm_content": "hero-button",
    "utm_term": "",
    "create_short_link": true
}
```

Automatically generates the full URL with UTM parameters and optionally creates a short link.

### Short Links

```
POST /api/links
{
    "destination_url": "https://example.com/very-long-url",
    "title": "Summer Sale Link",
    "custom_code": "summer"
}
```

Short links redirect at `/s/{code}` (e.g., `/s/summer`).

### Click Analytics

```
GET /api/links/{id}/stats
```

Returns 30-day click timeline with daily counts. Each click logs:
- IP hash (privacy-preserving)
- User agent
- Referer
- Timestamp
