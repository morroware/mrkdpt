<?php

declare(strict_types=1);

/**
 * AiContentTools — Content creation, repurposing, workflow engine, and image generation.
 *
 * Tier 1: Content Workflow Engine, Brand Voice Auto-Builder, RSS-to-Post Pipeline
 * Tier 2: Email Drip Sequence Generator, Multi-Language Localization
 * Tier 3: AI Image Prompt Generator + Image Generation
 */
final class AiContentTools
{
    public function __construct(private AiService $ai) {}

    /* ================================================================== */
    /*  ORIGINAL TOOLS (migrated from monolithic AiService)               */
    /* ================================================================== */

    public function generateContent(array $input): array
    {
        $contentType = $input['content_type'] ?? 'social_post';
        $tone     = $input['tone'] ?? 'professional';
        $platform = $input['platform'] ?? 'instagram';
        $topic    = $input['topic'] ?? 'seasonal promotion';
        $goal     = $input['goal'] ?? 'drive qualified leads';
        $length   = $input['length'] ?? 'medium';
        $audience = $input['audience'] ?? 'our target audience';
        $qualityMode = strtolower(trim((string)($input['quality_mode'] ?? 'enhanced')));

        $prompt = "Write a {$contentType} for {$platform} for {$this->ai->getBusinessName()}.
Topic: {$topic}
Tone: {$tone}
Audience: {$audience}
Goal: {$goal}
Length: {$length}

Include strong CTA, relevant hashtags, and a short A/B variant.";

        $provider = $input['provider'] ?? null;
        $model    = $input['model'] ?? null;
        $system = $this->ai->buildSystemPrompt($this->contentRoleDirective($contentType, $platform, $tone, $goal, $audience));
        $draft = $this->ai->generateAdvanced($system, $prompt, $provider, $model);

        if ($qualityMode !== 'enhanced') {
            return [
                'content' => $draft,
                'provider' => $provider ?? $this->ai->getProvider(),
                'quality_mode' => 'standard',
            ];
        }

        $primaryProvider = $provider ?? $this->ai->getProvider();
        $reviewer = $this->pickReviewerProvider($primaryProvider);
        if ($reviewer === null) {
            return [
                'content' => $draft,
                'provider' => $primaryProvider,
                'quality_mode' => 'enhanced',
                'reviewer_provider' => null,
            ];
        }

        $reviewSystem = $this->ai->buildSystemPrompt(
            "You are a senior conversion editor. Improve copy quality while preserving brand voice and factual correctness."
        );
        $reviewPrompt = "Improve this draft for clarity, persuasion, and platform fit.
Content type: {$contentType}
Platform: {$platform}
Tone: {$tone}
Audience: {$audience}
Goal: {$goal}

Original draft:
{$draft}

Return:
1) Final improved version
2) 3 short bullet edits explaining what improved.";
        $improved = $this->ai->generateAdvanced($reviewSystem, $reviewPrompt, $reviewer, null, 4096);

        return [
            'content' => $improved,
            'provider' => $primaryProvider,
            'quality_mode' => 'enhanced',
            'reviewer_provider' => $reviewer,
        ];
    }

    public function blogPostGenerator(string $title, string $keywords, ?string $outline = null, ?string $provider = null, ?string $model = null): array
    {
        $outlineSection = $outline !== null ? "\n\nUse this outline as a guide:\n{$outline}" : '';
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Write a full SEO-optimized blog post (1200-1800 words) for {$biz} ({$ind}).\n\nTitle: {$title}\nTarget keywords: {$keywords}{$outlineSection}\n\nInclude:\n- Meta title (under 60 characters)\n- Meta description (under 155 characters)\n- H1 heading\n- At least 4 H2 subheadings\n- Internal linking suggestions\n- A clear call-to-action\n- A FAQ section with at least 3 questions\n\nWrite naturally. Integrate keywords without stuffing.";

        return ['post' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt, $provider, $model), 'provider' => $provider ?? $this->ai->getProvider()];
    }

    public function multiSourceContentSuite(array $input): array
    {
        $topic = trim((string)($input['topic'] ?? ''));
        if ($topic === '') {
            return ['error' => 'Missing topic'];
        }

        $contentType = trim((string)($input['content_type'] ?? 'social_post'));
        $platform = trim((string)($input['platform'] ?? 'instagram'));
        $tone = trim((string)($input['tone'] ?? 'professional'));
        $goal = trim((string)($input['goal'] ?? 'drive engagement'));
        $audience = trim((string)($input['audience'] ?? 'target audience'));
        $size = trim((string)($input['image_size'] ?? '1024x1024'));

        $copyProvider = $input['copy_provider'] ?? null;
        $copyModel = $input['copy_model'] ?? null;
        $imagePromptProvider = $input['image_prompt_provider'] ?? null;
        $imagePromptModel = $input['image_prompt_model'] ?? null;
        $imageProvider = trim((string)($input['image_provider'] ?? 'auto'));

        $copySystem = $this->ai->buildSystemPrompt($this->contentRoleDirective($contentType, $platform, $tone, $goal, $audience));
        $copyPrompt = "Create publish-ready {$contentType} content for {$platform}.
Topic: {$topic}
Tone: {$tone}
Audience: {$audience}
Goal: {$goal}

Return:
1) Final copy
2) A/B variant
3) CTA options
4) Hashtag set (if relevant).";

        $copy = $this->ai->generateAdvanced($copySystem, $copyPrompt, $copyProvider, $copyModel, 4096);

        $imagePromptSystem = $this->ai->buildSystemPrompt(
            "You are a senior visual director. Create production-grade prompts for image generation models."
        );
        $imagePromptTask = "Build one high-quality image prompt to match this content.
Platform: {$platform}
Style direction: {$tone}
Goal: {$goal}
Topic: {$topic}

Content to visualize:
{$copy}

Return only:
- prompt: <single detailed prompt, 90-140 words>
- negative_prompt: <short list>
- size: <best size for platform>";

        $imagePromptText = $this->ai->generateAdvanced($imagePromptSystem, $imagePromptTask, $imagePromptProvider, $imagePromptModel, 1024);
        $imagePrompt = $this->extractImagePrompt($imagePromptText);
        $imageResult = $this->ai->generateImage($imagePrompt, $imageProvider === '' ? 'auto' : $imageProvider, $size);

        return [
            'copy' => $copy,
            'image_prompt' => $imagePrompt,
            'image_prompt_raw' => $imagePromptText,
            'image' => $imageResult,
            'providers' => [
                'copy' => $copyProvider ?? $this->ai->getProvider(),
                'image_prompt' => $imagePromptProvider ?? $this->ai->getProvider(),
                'image' => $imageResult['provider'] ?? $imageProvider,
            ],
        ];
    }

    public function videoScript(string $topic, string $platform, int $durationSeconds = 60): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Write a video script for {$biz} ({$ind}) for {$platform}.\n\nTopic: {$topic}\nTarget duration: {$durationSeconds} seconds\n\nInclude:\n1. Hook (first 3 seconds)\n2. Scene-by-scene breakdown with timestamps, visuals, voiceover, text overlays\n3. CTA (final 5 seconds)\n4. Music/sound suggestions\n5. Thumbnail text suggestion\n6. 5 caption options\n7. Recommended hashtags";

        return ['script' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function socialCaptionBatch(string $topic, array $platforms, int $count = 3): array
    {
        $platformList = implode(', ', $platforms);
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Generate {$count} ready-to-post social media captions for each platform: {$platformList}.\n\nBusiness: {$biz} ({$ind})\nTopic: {$topic}\n\nFor each: platform, caption text, hashtags, best posting time, engagement hook type.\n\nMake each caption unique with varied angles, hooks, and CTAs.";

        return ['captions' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function repurposeContent(string $sourceContent, array $targetFormats): array
    {
        $formatList = implode(', ', $targetFormats);
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Repurpose the following content for {$biz} ({$ind}) into each of these formats: {$formatList}.\n\nFor each format, produce ready-to-publish copy that fits the platform's best practices, character limits, and audience expectations.\n\nReturn each result clearly labeled by format.\n\nSource content:\n{$sourceContent}";

        $raw = $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt);

        $results = [];
        foreach ($targetFormats as $format) {
            $results[] = ['format' => $format, 'content' => ''];
        }

        foreach ($targetFormats as $i => $format) {
            $label   = strtoupper(str_replace('_', ' ', $format));
            $pattern = '/#{0,3}\s*\**' . preg_quote($label, '/') . '\**:?\s*/i';
            $parts   = preg_split($pattern, $raw);
            if (isset($parts[1])) {
                $nextLabels = array_slice($targetFormats, $i + 1);
                $chunk = $parts[1];
                foreach ($nextLabels as $nl) {
                    $nl2 = strtoupper(str_replace('_', ' ', $nl));
                    $pos = stripos($chunk, $nl2);
                    if ($pos !== false) { $chunk = substr($chunk, 0, $pos); break; }
                }
                $results[$i]['content'] = trim($chunk);
            }
        }

        if (array_sum(array_map(fn($r) => strlen($r['content']), $results)) === 0) {
            $results[0]['content'] = $raw;
        }

        return ['results' => $results, 'provider' => $this->ai->getProvider()];
    }

    public function adVariations(string $baseAd, int $count = 5): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Create {$count} ad copy variations for {$biz} ({$ind}) based on this ad:\n\n{$baseAd}\n\nAngles: pain-point, benefit-driven, social-proof, urgency/scarcity, storytelling.\n\nFor each: headline (under 40 chars), body (2-3 sentences), CTA, recommended platform.";

        return ['variations' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function emailSubjectLines(string $topic, int $count = 10): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Generate {$count} email subject line variations for {$biz} ({$ind}) about: {$topic}.\n\nFor each: subject line text (under 60 chars), predicted open-rate (high/medium/low), psychological trigger used.\n\nOrder from highest predicted open rate to lowest. Mix different triggers.";

        return ['subjects' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function contentBrief(string $topic, string $contentType, string $goal): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Create a detailed content brief for {$biz} ({$ind}).\n\nTopic: {$topic}\nContent Type: {$contentType}\nGoal: {$goal}\n\nInclude: Working Title (3 options), Target Audience, Key Message, Content Outline, SEO Keywords (5-8), Tone & Voice Direction, Reference/Inspiration (3), Distribution Plan, Success Metrics, Call-to-Action (primary + secondary).\n\nMake it actionable — a writer should create content directly from this brief.";

        return ['brief' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function headlineOptimizer(string $headline, string $platform): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Optimize this headline for {$platform} for {$biz} ({$ind}).\n\nOriginal: {$headline}\n\nGenerate 10 variations using: question, number/listicle, how-to, urgency/FOMO, curiosity gap, benefit-driven, controversy/bold, social proof, emotional trigger, power words.\n\nFor each: rate predicted CTR impact (low/medium/high) and explain the psychology.";

        return ['headlines' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'platform' => $platform, 'provider' => $this->ai->getProvider()];
    }

    public function refineContent(string $content, string $action, ?string $context = null): array
    {
        $actionPrompts = [
            'improve'       => "Improve the following content. Make it more engaging, clear, and compelling while keeping the same meaning and length. Fix any grammar issues.",
            'expand'        => "Expand the following content to be 2-3x longer. Add more detail, examples, and supporting points while maintaining the same tone.",
            'shorten'       => "Condense the following content to be 50% shorter. Keep the core message and most impactful phrases.",
            'formal'        => "Rewrite in a formal, professional tone. Keep the same meaning but make it suitable for corporate communication.",
            'casual'        => "Rewrite in a casual, conversational tone. Make it feel friendly and approachable.",
            'persuasive'    => "Rewrite to be more persuasive. Add urgency, social proof language, and stronger calls-to-action.",
            'storytelling'  => "Rewrite using storytelling techniques. Add narrative elements, emotional hooks, and a compelling arc.",
            'simplify'      => "Simplify. Use shorter sentences, simpler words, and clearer structure. Target a 6th-grade reading level.",
            'add_hooks'     => "Add 3 attention-grabbing hooks/opening lines. Then rewrite with the best hook integrated.",
            'add_cta'       => "Add 3 different call-to-action options using different psychological triggers (urgency, curiosity, benefit).",
            'emoji'         => "Add relevant emojis to make it more engaging for social media. Don't overdo it — 1-2 per paragraph.",
            'bullet_points' => "Restructure into clear bullet points or numbered list format. Add a brief intro and conclusion.",
        ];

        $actionText  = $actionPrompts[$action] ?? "Improve the following content: ";
        $contextNote = $context ? "\n\nAdditional context: {$context}" : '';
        $prompt      = "{$actionText}{$contextNote}\n\nContent:\n{$content}";

        return ['content' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'action' => $action, 'provider' => $this->ai->getProvider()];
    }

    /* ================================================================== */
    /*  TIER 1: Content Workflow Engine                                   */
    /* ================================================================== */

    /**
     * Input one topic → get a full week of coordinated content across all
     * platforms (blog + social posts + email + ad copy) in one click.
     */
    public function contentWorkflow(string $topic, string $goal, array $platforms, int $days = 7): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $tz  = $this->ai->getTimezone();
        $platformList = implode(', ', $platforms);

        $prompt = "Create a complete {$days}-day coordinated content plan for {$biz} ({$ind}).

Topic/Theme: {$topic}
Goal: {$goal}
Platforms: {$platformList}
Timezone: {$tz}

For EACH day, generate ALL of the following as ready-to-publish content:

1. **Blog Post** (title + 3-paragraph summary + CTA + SEO keywords)
2. **Social Posts** — one per platform listed above (full caption, hashtags, posting time)
3. **Email** (subject line + body copy, 150-200 words, with CTA)
4. **Ad Copy** (headline + body + CTA for paid promotion of that day's content)

Also include:
- A unifying theme/narrative arc across the {$days} days
- Cross-promotion strategy (how each piece links to others)
- Optimal posting schedule with specific times in {$tz}
- KPIs to track for each content type

Return as a structured day-by-day plan with clear headings for each content type.";

        $sys = $this->ai->buildSystemPrompt('You are creating a multi-channel content workflow. Be thorough and produce ready-to-publish content for each piece.');

        return [
            'workflow' => $this->ai->generateAdvanced($sys, $prompt, null, null, 8192),
            'topic'    => $topic,
            'days'     => $days,
            'provider' => $this->ai->getProvider(),
        ];
    }

    /* ================================================================== */
    /*  TIER 1: Brand Voice Auto-Builder                                 */
    /* ================================================================== */

    /**
     * Paste 3-5 content examples → AI analyzes and generates a complete
     * brand voice profile (tone, vocabulary, avoid words, target audience).
     *
     * Returns structured data ready to insert into brand_profiles table.
     */
    public function buildBrandVoice(string $examples): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();

        $prompt = "Analyze the following content examples from {$biz} ({$ind}) and extract a complete brand voice profile.

Content Examples:
{$examples}

Return ONLY a valid JSON object with these exact keys:
{
  \"voice_tone\": \"<primary tone description, 2-3 sentences>\",
  \"vocabulary\": \"<comma-separated list of 15-20 words/phrases this brand uses frequently>\",
  \"avoid_words\": \"<comma-separated list of 10-15 words/phrases this brand should avoid>\",
  \"target_audience\": \"<detailed description of who this content speaks to>\",
  \"example_content\": \"<a short example paragraph written perfectly in this brand's voice>\",
  \"summary\": \"<2-3 sentence summary of the overall brand personality>\"
}

Be specific and detailed. The voice_tone should capture nuance — not just 'professional' but the specific flavor of professional. The vocabulary should include actual signature phrases found in the examples.";

        $raw = $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt);

        // Parse JSON from response
        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $raw);
        if (preg_match('/\{[\s\S]*\}/s', $cleaned, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['voice_tone'])) {
                return ['profile' => $parsed, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
            }
        }

        return ['profile' => null, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
    }

    /* ================================================================== */
    /*  TIER 1: RSS-to-Post Pipeline                                     */
    /* ================================================================== */

    /**
     * Turn an RSS item into a ready-to-publish social post.
     */
    public function rssToPost(string $title, string $summary, string $url, string $platform): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();

        $prompt = "You are the social media manager for {$biz} ({$ind}).

An interesting article just appeared in our industry feed:
Title: {$title}
Summary: {$summary}
URL: {$url}

Create a ready-to-publish {$platform} post that:
1. Opens with an original hot take or insight (NOT just a summary)
2. Adds our unique perspective or expertise
3. References the source naturally
4. Includes a clear CTA (comment, share, read more)
5. Includes relevant hashtags (optimized for {$platform})
6. Stays under {$platform} character limits

Also provide:
- A suggested posting time
- An alternative version with a different angle
- 3 reply/engagement prompts to boost comments";

        return [
            'post'     => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt),
            'platform' => $platform,
            'source'   => $url,
            'provider' => $this->ai->getProvider(),
        ];
    }

    /* ================================================================== */
    /*  TIER 2: Email Drip Sequence Generator                            */
    /* ================================================================== */

    /**
     * Generate a full 5-7 email drip sequence with subject lines, body,
     * timing, and conditional branching.
     */
    public function emailDripSequence(string $goal, string $audience, int $emailCount = 5): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();

        $prompt = "Create a complete {$emailCount}-email drip sequence for {$biz} ({$ind}).

Goal: {$goal}
Target Audience: {$audience}

For EACH email in the sequence, provide:
1. **Email Number & Name** (e.g., Email 1: Welcome)
2. **Send Timing** (e.g., immediately, +2 days, +4 days)
3. **Subject Line** (with A/B variant)
4. **Preview Text** (40-90 characters)
5. **Body Copy** (full HTML-ready email, 150-250 words)
6. **CTA Button Text & URL placeholder**
7. **Conditional Branch** — what to do if they:
   - Opened but didn't click → [action]
   - Clicked but didn't convert → [action]
   - Didn't open → [action]

Also include:
- Overall sequence strategy and arc
- Key metrics to monitor at each stage
- When to remove non-responders
- Re-engagement trigger suggestions";

        $sys = $this->ai->buildSystemPrompt('You are an email marketing expert. Create complete, ready-to-use email sequences.');

        return [
            'sequence' => $this->ai->generateAdvanced($sys, $prompt, null, null, 4096),
            'goal'     => $goal,
            'count'    => $emailCount,
            'provider' => $this->ai->getProvider(),
        ];
    }

    /* ================================================================== */
    /*  TIER 2: Multi-Language Content Localization                       */
    /* ================================================================== */

    /**
     * Translate and culturally adapt content for a target language/locale.
     */
    public function localizeContent(string $content, string $targetLanguage, string $platform): array
    {
        $biz = $this->ai->getBusinessName();

        $prompt = "Localize the following content for a {$targetLanguage}-speaking audience for {$biz}.

Platform: {$platform}

IMPORTANT: This is NOT just translation. You must:
1. Adapt cultural references and idioms
2. Adjust humor and tone for the target culture
3. Modify CTAs to match local buying behaviors
4. Adapt hashtags to locally trending equivalents
5. Adjust content length for local platform norms
6. Note any images/visuals that might need changing

Provide:
- **Localized Content**: The fully adapted version
- **Cultural Notes**: What you changed and why
- **Local Hashtags**: Region-appropriate hashtags
- **Timing Note**: Best posting times for this region

Original content:
{$content}";

        return [
            'localized' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt),
            'language'  => $targetLanguage,
            'provider'  => $this->ai->getProvider(),
        ];
    }

    /* ================================================================== */
    /*  TIER 3: AI Image Prompt Generator                                */
    /* ================================================================== */

    /**
     * For every piece of content, auto-generate a detailed image prompt
     * that matches the content's tone and message. Optionally generate
     * the image via Banana/DALL-E.
     */
    public function imagePromptGenerator(string $content, string $platform, string $style = 'modern'): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();

        $prompt = "You are a visual creative director for {$biz} ({$ind}).

Analyze this content and create detailed image generation prompts for it:

Content: {$content}
Platform: {$platform}
Visual Style: {$style}

Generate 3 image prompt options:

For EACH option provide:
1. **Concept**: One-line description of the visual idea
2. **Detailed Prompt**: A complete image generation prompt (70-120 words) optimized for AI image generators (Flux/DALL-E/Midjourney). Include: subject, composition, lighting, color palette, mood, style, and technical specs.
3. **Negative Prompt**: What to avoid in the image
4. **Recommended Size**: Optimal dimensions for {$platform}
5. **Alt Text**: Accessibility-friendly image description

Also provide:
- Color palette suggestion (3-5 hex codes) that matches the content mood
- Typography recommendation for any text overlay
- Which option best matches the content's emotional tone";

        $result = $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt);

        return [
            'prompts'  => $result,
            'platform' => $platform,
            'style'    => $style,
            'provider' => $this->ai->getProvider(),
        ];
    }

    /**
     * Generate an image from a prompt using configured image provider.
     */
    public function generateImage(string $prompt, string $imageProvider = 'auto', string $size = '1024x1024'): array
    {
        return $this->ai->generateImage($prompt, $imageProvider, $size);
    }

    private function contentRoleDirective(string $contentType, string $platform, string $tone, string $goal, string $audience): string
    {
        $role = match ($contentType) {
            'ad_copy' => 'You are a direct-response copywriter focused on conversions.',
            'email' => 'You are an email lifecycle marketer focused on opens, clicks, and clarity.',
            'blog_post' => 'You are an SEO content strategist and editorial writer.',
            'video_script' => 'You are a short-form video creative director and script writer.',
            default => 'You are a social media strategist and conversion copywriter.',
        };

        return "{$role}
- Primary channel: {$platform}
- Desired tone: {$tone}
- Business objective: {$goal}
- Target audience: {$audience}
- Always produce polished, publish-ready output with a clear structure and CTA.";
    }

    private function extractImagePrompt(string $text): string
    {
        if (preg_match('/prompt:\s*(.+)/i', $text, $m)) {
            return trim($m[1]);
        }
        return trim($text);
    }

    private function pickReviewerProvider(string $primary): ?string
    {
        $status = $this->ai->providerStatus();
        $providers = $status['providers'] ?? [];
        foreach ($providers as $name => $info) {
            if ($name === $primary) {
                continue;
            }
            if (!empty($info['configured'])) {
                return (string)$name;
            }
        }
        return null;
    }
}
