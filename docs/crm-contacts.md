# CRM & Contacts

## Overview

A built-in mini-CRM for managing contacts through a sales pipeline with scoring, activity tracking, segmentation, and import/export.

## Contact Model

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Primary key |
| `email` | string | Unique email address |
| `first_name` | string | First name |
| `last_name` | string | Last name |
| `company` | string | Company name |
| `phone` | string | Phone number |
| `stage` | string | Pipeline stage |
| `score` | int | Lead score (default 0) |
| `tags` | string | Comma-separated tags |
| `source` | string | How the contact was acquired |
| `source_detail` | string | Additional source info |
| `notes` | text | Free-form notes |
| `custom_fields` | JSON | Flexible custom data |
| `last_activity` | datetime | Last activity timestamp |
| `created_at` | datetime | Creation timestamp |

## Pipeline Stages

```
lead → mql → sql → opportunity → customer
```

| Stage | Description |
|-------|-------------|
| `lead` | New contact, not yet qualified |
| `mql` | Marketing Qualified Lead |
| `sql` | Sales Qualified Lead |
| `opportunity` | Active sales opportunity |
| `customer` | Converted customer |

## Contact Scoring

Scores range from 0 to any positive integer. Scores can be:
- Set manually when creating/updating a contact
- Incremented via automation rules (e.g., +10 points on form submission)
- Bulk-updated via the bulk operations endpoint

## Activity Timeline

Activities are logged automatically and manually:

```
POST /api/contacts/{id}/activity
{
    "type": "email_sent",
    "description": "Sent welcome email",
    "data": { "campaign_id": 5 }
}
```

Activity types (examples):
- `created` — Contact created
- `updated` — Contact updated
- `email_sent` — Email sent to contact
- `form_submitted` — Submitted a form
- `stage_changed` — Pipeline stage changed
- `note_added` — Manual note
- `tagged` — Tag added via automation

The timeline shows the last 50 activities per contact.

## Contact Sources

| Source | Description |
|--------|-------------|
| `manual` | Manually created in the UI |
| `csv_import` | Imported via CSV |
| `form` | Created from form submission |
| `email` | From email subscription |
| `api` | Created via API |

## CSV Import/Export

### Import

```
POST /api/contacts/import
{
    "csv": "email,first_name,last_name,company\njohn@example.com,John,Doe,Acme",
    "source": "csv_import"
}
```

- Existing contacts (matched by email) are updated
- New contacts are created with `lead` stage
- Returns `{ imported: count, skipped: count }`

### Export

```
GET /api/contacts/export
```

Downloads all contacts as CSV with columns: email, first_name, last_name, company, phone, stage, score, tags, source, created_at.

## Bulk Operations

```
POST /api/contacts/bulk
{
    "ids": [1, 2, 3],
    "action": "update_stage",
    "stage": "mql"
}
```

| Action | Additional Params | Description |
|--------|------------------|-------------|
| `delete` | — | Delete contacts |
| `update_stage` | `stage` | Change pipeline stage |
| `add_tag` | `tag` | Add tag to contacts |
| `add_score` | `points` | Add score points |

## Metrics

```
GET /api/contacts/metrics
```

Returns:
```json
{
    "total": 500,
    "lead": 200,
    "mql": 150,
    "sql": 80,
    "opportunity": 50,
    "customer": 20,
    "avg_score": 42
}
```

## Audience Segments

Dynamic segments filter contacts based on criteria rules. See [Automations & Workflows](automations-workflows.md) for segment details.

### Criteria Fields

| Field | Type | Description |
|-------|------|-------------|
| `stage` | multi-select | Match contacts in specific stages |
| `min_score` | number | Minimum score threshold |
| `max_score` | number | Maximum score threshold |
| `tags` | string | Tag contains match |
| `source` | string | Source equals match |
| `company` | string | Company contains match |
| `created_after` | date | Created after date |
| `created_before` | date | Created before date |
| `has_activity_since` | date | Has activity since date |
| `no_activity_since` | date | No activity since date |

### Segment Operations

```
# Create segment
POST /api/segments
{
    "name": "High-value leads",
    "criteria": [
        { "field": "stage", "value": ["mql", "sql"] },
        { "field": "min_score", "value": 50 }
    ]
}

# View matching contacts
GET /api/segments/{id}/contacts

# Recompute count
POST /api/segments/{id}/recompute
```

## Auto-Creation from Forms

When a form submission includes an email field and the form has a `ContactRepository` available:
1. If no contact exists with that email, a new one is created
2. If a contact exists, it's updated with the new data
3. If the form has `tag_on_submit`, the tag is applied
4. An activity is logged: `form_submitted`
5. Automation rules fire for `form.submitted` and `contact.created` events
