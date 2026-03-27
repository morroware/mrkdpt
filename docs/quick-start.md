# Quick Start Guide

This guide gets you from zero to a working **AI Marketing Department** in ~10 minutes.

## 1) Start the app

```bash
git clone <repo-url> marketing
cd marketing
php -S localhost:8080 -t public
```

Open: `http://localhost:8080/install.php`

---

## 2) Complete installer

In the installer, set:

1. Business info (name, industry, timezone)
2. Admin account
3. Primary AI provider
4. Optional provider keys
5. SMTP (optional)

After setup, sign in at the app URL.

---

## 3) Configure AI providers from the UI (no reinstall needed)

Go to **Settings** and update:

- Primary provider + model
- API keys for all supported providers
- Per-provider base URLs (if proxying/self-hosting)
- Banana/NanoBanana image settings

> Tip: Leave key fields blank to keep current saved values.

---

## 4) Bootstrap onboarding with website auto-research

Go to **Onboarding**:

1. Add your website URL
2. Click **Auto-Research from Website**
3. Review generated business profile/audience/goals/channels
4. Click **Launch AI Autopilot**

Autopilot launches asynchronously and continues in background.

---

## 5) Generate modern multi-model creative assets

Go to **AI Studio**:

- **Content Writer**: choose audience + quality mode (fast draft vs enhanced review)
- **Multi-Source Creative Pipeline**: pick one provider for copy and another for image prompting, plus image provider
- **AI Image Studio**: prompt + generate visuals

---

## 6) Use AI Chat as your marketing copilot

Go to **AI Chat**:

- Ask for analysis grounded in your real app data
- Use content brief controls (type/platform/tone/audience/goal) for production-ready outputs
- Save shared memory so every AI tool stays aligned with business context

---

## 7) Enable ongoing operations

Set cron:

```bash
*/5 * * * * curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY" > /dev/null 2>&1
```

This handles scheduled publishing, recurring content, and feed processing.

---

## 8) Recommended first-week workflow

Day 1:
- Complete onboarding + website auto-research
- Run autopilot
- Configure provider keys/models in Settings

Day 2:
- Generate 1 week of content workflow in AI Studio
- Produce 3-5 image assets via multi-source pipeline

Day 3:
- Start publishing queue and email campaign
- Use AI Chat for weekly priorities and optimization

Day 4-7:
- Review analytics insights
- Iterate copy with enhanced quality mode
- Add shared memory notes from what performed best
