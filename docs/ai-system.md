# AI System

The AI system provides 60+ endpoints across content creation, analysis, strategy, and conversational interfaces. It supports 9 providers through a unified API.

## Architecture

```
AiService.php              — Provider orchestration, HTTP transport, brand voice
AiContentTools.php         — Content creation, repurposing, refinement (18 methods)
AiAnalysisTools.php        — Scoring, tone analysis, SEO, pre-flight checks (7 methods)
AiStrategyTools.php        — Research, strategy, campaigns, reporting (14 methods)
AiChatService.php          — Conversational AI with database context (3 methods)
```

## Providers

### Configuration

Set `AI_PROVIDER` in `.env` to one of: `openai`, `anthropic`, `gemini`, `deepseek`, `groq`, `mistral`, `openrouter`, `xai`, `together`.

Multiple providers can be configured simultaneously. The primary provider handles default requests while others can be selected per-request or used for multi-provider comparison.

### Provider Details

| Provider | API Style | Auth Header | Default Model |
|----------|----------|-------------|---------------|
| OpenAI | OpenAI Chat Completions | `Authorization: Bearer` | gpt-4.1-mini |
| Anthropic | Anthropic Messages | `x-api-key` | claude-sonnet-4-20250514 |
| Gemini | Google GenerativeAI | API key in URL | gemini-2.5-flash |
| DeepSeek | OpenAI-compatible | `Authorization: Bearer` | deepseek-chat |
| Groq | OpenAI-compatible | `Authorization: Bearer` | llama-3.3-70b-versatile |
| Mistral | OpenAI-compatible | `Authorization: Bearer` | mistral-large-latest |
| OpenRouter | OpenAI-compatible | `Authorization: Bearer` | anthropic/claude-sonnet-4 |
| xAI | OpenAI-compatible | `Authorization: Bearer` | grok-3-fast |
| Together AI | OpenAI-compatible | `Authorization: Bearer` | Llama-3.3-70B-Instruct-Turbo |

### Multi-Provider Features

- **`POST /api/ai/multi`** — Run the same prompt on multiple providers simultaneously for comparison
- **`POST /api/ai/bulk`** — Execute multiple different AI specs in one request
- **`GET /api/ai/providers`** — Check which providers are configured and available

### Image Generation

- **`POST /api/ai/generate-image`** — Generate images via Banana/NanoBanana, DALL-E 3, or Gemini
- Provider priority: Banana > OpenAI DALL-E > Gemini
- DALL-E sizes: 1024x1024, 1024x1792, 1792x1024

## AI Tools Reference

### Content Creation

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/ai/content` | `generateContent()` | Main content writer for any platform/format |
| `/api/ai/blog-post` | `blogPostGenerator()` | 1200-1800 word SEO blog posts with meta tags and FAQ |
| `/api/ai/video-script` | `videoScript()` | Scene-by-scene scripts with hooks and overlays |
| `/api/ai/caption-batch` | `socialCaptionBatch()` | Multi-platform captions in one request |
| `/api/ai/repurpose` | `repurposeContent()` | Convert content across formats (tweet, LinkedIn, email, etc.) |
| `/api/ai/ad-variations` | `adVariations()` | 5+ ad angles with psychological triggers |
| `/api/ai/subject-lines` | `emailSubjectLines()` | 10 email subjects with predicted open rates |
| `/api/ai/brief` | `contentBrief()` | Full content brief with outline, SEO, distribution plan |
| `/api/ai/headlines` | `headlineOptimizer()` | 10 headline variations with CTR predictions |
| `/api/ai/refine` | `refineContent()` | 12 refinement actions (see below) |
| `/api/ai/workflow` | — | Multi-day content workflow planning |
| `/api/ai/drip-sequence` | — | Email drip sequence generation |
| `/api/ai/image-prompts` | — | AI image prompt generation |
| `/api/ai/localize` | — | Content localization for different languages |
| `/api/ai/rss-to-post` | — | Convert RSS article to social post |
| `/api/ai/build-brand-voice` | — | Extract brand voice from example content |

### Content Refinement Actions

The `/api/ai/refine` endpoint supports 12 actions:

| Action | Description |
|--------|-------------|
| `improve` | General quality improvement |
| `expand` | Expand content with more detail |
| `shorten` | Condense while keeping key points |
| `formal` | Shift to professional/formal tone |
| `casual` | Shift to conversational tone |
| `persuasive` | Add persuasive elements and CTAs |
| `storytelling` | Rewrite with narrative structure |
| `simplify` | Simplify language and structure |
| `add_hooks` | Add attention-grabbing hooks |
| `add_cta` | Add or strengthen calls-to-action |
| `emoji` | Add relevant emojis |
| `bullet_points` | Convert to bullet point format |

### Analysis & Optimization

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/ai/tone-analysis` | `toneAnalysis()` | Sentiment, readability, emotion map, brand alignment |
| `/api/ai/score` | `contentScore()` | 1-100 score across engagement, clarity, CTA, emotion, platform fit |
| `/api/ai/seo-keywords` | `seoKeywordResearch()` | 20 keywords with intent, difficulty, content type |
| `/api/ai/hashtags` | `hashtagResearch()` | 30 hashtags in 3 volume tiers |
| `/api/ai/seo-audit` | `seoAudit()` | 10-point page audit with scores and quick wins |
| `/api/ai/preflight` | `preFlightCheck()` | Pre-publish brand/compliance review |
| `/api/ai/ab-generate` | — | Generate A/B test variants |
| `/api/ai/ab-analyze` | — | Analyze A/B test results |

### Research & Strategy

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/ai/research` | `marketResearch()` | ICP, pain points, objections, 30-day plan |
| `/api/ai/ideas` | `contentIdeas()` | 8 platform-specific ideas with hooks and CTAs |
| `/api/ai/persona` | `audiencePersona()` | Detailed buyer persona with messaging dos/don'ts |
| `/api/ai/competitor-analysis` | `competitorAnalysis()` | Deep competitive analysis with counter-strategies |
| `/api/ai/social-strategy` | `socialStrategy()` | Full strategy with content pillars, schedule, KPIs |
| `/api/ai/calendar` | `scheduleSuggestion()` | 14-day schedule with times, channels, KPIs |
| `/api/ai/calendar-month` | `contentCalendarMonth()` | Full month content plan |
| `/api/ai/smart-times` | `smartPostingTime()` | Platform-specific optimal posting schedule |
| `/api/ai/campaign-optimizer` | `campaignOptimizer()` | Budget, channel mix, creative recommendations |
| `/api/ai/report` | `weeklyReport()` | AI-generated performance summary from your data |
| `/api/ai/insights` | `aiInsights()` | Proactive recommendations based on marketing data |
| `/api/ai/smart-segments` | — | Segment recommendations |
| `/api/ai/competitor-radar` | — | Competitive landscape analysis |
| `/api/ai/funnel-advisor` | — | Funnel optimization advice |
| `/api/ai/smart-utm` | — | Intelligent UTM link creation |
| `/api/ai/standup` | — | Daily marketing standup digest |
| `/api/ai/predict` | — | Content performance prediction |

### Conversational AI

| Endpoint | Description |
|----------|-------------|
| `POST /api/ai/chat` | Send message with conversation history |
| `GET /api/ai/conversations` | List all conversations |
| `GET /api/ai/conversations/{id}` | Get conversation with messages |
| `DELETE /api/ai/conversations/{id}` | Delete conversation |

The chat system gathers real database context (posts, campaigns, metrics) to provide informed marketing advice.

## Brand Voice

Brand voice profiles control AI-generated content tone and style.

### Configuration

Create brand profiles at **Content Library > Brand Voice**:

| Field | Description |
|-------|-------------|
| `voice_tone` | Overall tone (e.g., "Professional yet approachable") |
| `vocabulary` | Preferred words and phrases |
| `avoid_words` | Words and phrases to avoid |
| `example_content` | Sample content demonstrating the voice |
| `target_audience` | Description of the target audience |

Only one profile can be active at a time. The active profile is injected into all AI system prompts via `AiService::buildSystemPrompt()`.

## Integration Points

### AI Studio (`pages/ai.js`)
Full access to all 25+ AI tools organized in category tabs with a sticky output panel. Results can be copied or used directly.

### AI Writing Assistant (`pages/assistant.js`)
Floating side panel accessible from any page via the purple FAB button (bottom-right). Features:
- 12 quick refinement actions
- 4 tone changes
- Tone analysis, content score, headline ideas
- Custom instruction input
- One-click "Apply to Field" to replace active textarea content
- Auto-detects the last-focused textarea on the current page

### AI Inline Toolbar
Contextual AI action buttons rendered above textarea fields:
- **Content Studio** post body: Improve, Expand, Shorten, Persuasive, Emojis
- **Email Compose** HTML body: Improve, Expand, Shorten, Persuasive
- Wired globally in `app.js::initInlineAiToolbars()`

### Global Command Bar (`Ctrl+K`)
Quick access to 10 AI actions from any page, organized in 2 groups.

### Dashboard AI
- 6 AI quick action buttons
- AI Insights card with proactive recommendations
- Refresh on demand
