# Campaigns & Analytics

## Campaign Management

### Campaign Model

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Primary key |
| `name` | string | Campaign name |
| `channel` | string | Marketing channel |
| `objective` | string | Campaign goal |
| `budget` | float | Total budget |
| `spend_to_date` | float | Auto-calculated total spend |
| `revenue` | float | Auto-calculated total revenue |
| `status` | string | active, paused, completed, draft |
| `start_date` | date | Campaign start |
| `end_date` | date | Campaign end |
| `notes` | text | Campaign notes |
| `kpi_target` | string | Target KPI description |
| `kpi_current` | string | Current KPI value |

### Campaign Metrics

Log daily performance metrics:

```
POST /api/campaigns/{id}/metrics
{
    "metric_date": "2025-06-15",
    "impressions": 50000,
    "clicks": 2500,
    "conversions": 125,
    "spend": 500.00,
    "revenue": 2500.00
}
```

### Calculated KPIs

```
GET /api/campaigns/{id}/summary
```

| Metric | Formula | Description |
|--------|---------|-------------|
| ROI | `((revenue - spend) / spend) * 100` | Return on investment % |
| CTR | `(clicks / impressions) * 100` | Click-through rate % |
| CVR | `(conversions / clicks) * 100` | Conversion rate % |
| CPA | `spend / conversions` | Cost per acquisition |
| ROAS | `revenue / spend` | Return on ad spend |

All calculations handle division-by-zero gracefully (return 0).

### Campaign Comparison

Compare multiple campaigns side-by-side:

```
POST /api/campaigns/compare
{ "campaign_ids": [1, 2, 3] }
```

Returns each campaign's summary metrics and daily data for comparison.

### AI Campaign Optimizer

```
POST /api/ai/campaign-optimizer
{
    "campaign_data": { /* campaign + metrics */ },
    "goals": "Increase conversions while reducing CPA"
}
```

Returns budget allocation, channel mix, creative, and targeting recommendations.

## Analytics Dashboard

### Overview Metrics

```
GET /api/analytics/overview?days=30
```

Returns:
- Total posts, by status, by platform
- Active campaigns count
- AI usage (generation count)
- Email stats (sent, opens, clicks)
- Social publishing stats (total, success, failed)

### Content Performance

```
GET /api/analytics/content
```

Returns top-performing content with publish counts and engagement data.

### Chart Data

```
GET /api/analytics/chart/{metric}?days=30
```

| Metric | Description |
|--------|-------------|
| `posts_by_day` | Daily post creation count |
| `posts_by_platform` | Posts grouped by platform |
| `kpi_trend` | KPI values over time |
| `publish_success` | Social publishing success/failure rate |

### CSV Exports

```
GET /api/analytics/export/{type}
```

| Type | Contents |
|------|----------|
| `posts` | All posts with metadata |
| `campaigns` | All campaigns with budgets |
| `kpis` | KPI log entries |
| `competitors` | Competitor data |
| `subscribers` | Email subscribers |
| `publish_log` | Social publish history |

### KPI Tracking

Manual KPI logging for any channel/metric:

```
POST /api/kpis
{
    "channel": "instagram",
    "metric_name": "followers",
    "metric_value": 5000,
    "date": "2025-06-15"
}
```

KPI summary aggregates the latest values per channel/metric.

## Dashboard

The main dashboard (`GET /api/dashboard`) provides:

- **Metrics overview** — Posts, campaigns, recent activity
- **Active campaigns** — With budget utilization
- **KPI summary** — Latest values per channel
- **Recent posts** — Last 10 created posts
- **Recent AI ideas** — Latest AI-generated content ideas

### AI Dashboard Features

- **6 Quick Action Buttons** — One-click access to common AI tools
- **AI Insights Card** — Proactive recommendations based on your marketing data
  - Priority-coded badges (high/medium/low)
  - Category icons (content, engagement, growth, optimization)
  - Specific action items
  - Refresh on demand

## Webhooks for Events

Campaign and content events can trigger webhooks:

| Event | Fired When |
|-------|-----------|
| `post.published` | A post is published |
| `post.scheduled` | A post is scheduled |
| `campaign.created` | A new campaign is created |
| `subscriber.added` | A new email subscriber joins |
| `email.sent` | An email campaign is sent |
| `cron.completed` | Cron job finishes |

See [API Reference](api-reference.md) for webhook configuration.
