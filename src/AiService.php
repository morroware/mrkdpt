<?php

declare(strict_types=1);

final class AiService
{
    private ?array $brandVoice = null;

    public function __construct(
        private string $provider,
        private string $businessName,
        private string $industry,
        private string $timezone,
        private array $config,
    ) {
    }

    public function setBrandVoice(?array $profile): void
    {
        $this->brandVoice = $profile;
    }

    public function providerStatus(): array
    {
        return [
            'active_provider' => $this->provider,
            'supports' => ['openai', 'anthropic', 'gemini'],
            'has_openai_key' => !empty($this->config['openai_api_key']),
            'has_anthropic_key' => !empty($this->config['anthropic_api_key']),
            'has_gemini_key' => !empty($this->config['gemini_api_key']),
        ];
    }

    public function marketResearch(string $audience, string $goal): array
    {
        $prompt = "Create a practical market research brief for {$this->businessName} in {$this->industry}. Audience: {$audience}. Business goal: {$goal}. Include: ICP summary, top pain points, top objections, 3 competitor angles, messaging opportunities, and a 30-day execution plan.";
        return ['brief' => $this->generate($prompt), 'generated_at' => gmdate(DATE_ATOM), 'provider' => $this->provider];
    }

    public function contentIdeas(string $topic, string $platform): array
    {
        $prompt = "Generate 8 {$platform} content ideas for {$this->businessName} ({$this->industry}) around: {$topic}. Each idea must include: Hook, Value, CTA, and best posting time in {$this->timezone}.";
        return ['ideas' => $this->generate($prompt), 'platform' => $platform, 'topic' => $topic, 'provider' => $this->provider];
    }

    public function generateContent(array $input): array
    {
        $contentType = $input['content_type'] ?? 'social_post';
        $tone = $input['tone'] ?? 'professional';
        $platform = $input['platform'] ?? 'instagram';
        $topic = $input['topic'] ?? 'seasonal promotion';
        $goal = $input['goal'] ?? 'drive qualified leads';
        $length = $input['length'] ?? 'medium';

        $prompt = "Write a {$contentType} for {$platform} for {$this->businessName}. Topic: {$topic}. Tone: {$tone}. Goal: {$goal}. Length: {$length}. Include strong CTA, hashtags, and a short A/B variant.";
        return ['content' => $this->generate($prompt), 'provider' => $this->provider];
    }

    public function scheduleSuggestion(string $objective): array
    {
        $prompt = "Produce a 14-day marketing schedule for {$this->businessName}. Objective: {$objective}. Include date, weekday, channel, content type, posting time in {$this->timezone}, and primary KPI.";
        return ['schedule' => $this->generate($prompt), 'provider' => $this->provider];
    }

    public function repurposeContent(string $sourceContent, array $targetFormats): array
    {
        $formatList = implode(', ', $targetFormats);
        $prompt = "Repurpose the following content for {$this->businessName} ({$this->industry}) into each of these formats: {$formatList}.\n\nFor each format (tweet, linkedin_post, email, instagram_caption, blog_intro, facebook_post or whichever are requested), produce ready-to-publish copy that fits the platform's best practices, character limits, and audience expectations.\n\nReturn each result clearly labeled by format.\n\nSource content:\n{$sourceContent}";

        $raw = $this->generateAdvanced($this->buildSystemPrompt(), $prompt);

        $results = [];
        foreach ($targetFormats as $format) {
            $results[] = ['format' => $format, 'content' => ''];
        }

        // Parse raw response — best-effort split by format labels
        foreach ($targetFormats as $i => $format) {
            $label = strtoupper(str_replace('_', ' ', $format));
            $pattern = '/#{0,3}\s*\**' . preg_quote($label, '/') . '\**:?\s*/i';
            $parts = preg_split($pattern, $raw);
            if (isset($parts[1])) {
                // grab text until the next format heading or end
                $nextLabels = array_slice($targetFormats, $i + 1);
                $chunk = $parts[1];
                foreach ($nextLabels as $nl) {
                    $nl2 = strtoupper(str_replace('_', ' ', $nl));
                    $pos = stripos($chunk, $nl2);
                    if ($pos !== false) {
                        $chunk = substr($chunk, 0, $pos);
                        break;
                    }
                }
                $results[$i]['content'] = trim($chunk);
            }
        }

        // If parsing failed, put full response in first result
        if (array_sum(array_map(fn($r) => strlen($r['content']), $results)) === 0) {
            $results[0]['content'] = $raw;
        }

        return ['results' => $results, 'provider' => $this->provider];
    }

    public function seoKeywordResearch(string $topic, string $niche): array
    {
        $prompt = "Generate 20 SEO keyword suggestions for {$this->businessName} in the {$niche} niche around the topic: {$topic}.\n\nFor each keyword provide:\n1. The keyword phrase\n2. Search intent (informational, transactional, or navigational)\n3. Estimated difficulty (low, medium, or high)\n4. Suggested content type (blog post, landing page, product page, FAQ, video, etc.)\n\nOrganize from easiest to hardest to rank for.";

        return ['keywords' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function blogPostGenerator(string $title, string $keywords, ?string $outline = null): array
    {
        $outlineSection = $outline !== null ? "\n\nUse this outline as a guide:\n{$outline}" : '';
        $prompt = "Write a full SEO-optimized blog post (1200-1800 words) for {$this->businessName} ({$this->industry}).\n\nTitle: {$title}\nTarget keywords: {$keywords}{$outlineSection}\n\nInclude all of the following:\n- Meta title (under 60 characters)\n- Meta description (under 155 characters)\n- H1 heading\n- At least 4 H2 subheadings\n- Internal linking suggestions (describe where links should go)\n- A clear call-to-action (CTA)\n- A FAQ section with at least 3 questions and answers\n\nWrite in a natural, engaging style. Integrate keywords naturally without stuffing.";

        return ['post' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function hashtagResearch(string $topic, string $platform): array
    {
        $prompt = "Generate 30 hashtags for {$this->businessName} ({$this->industry}) about the topic: {$topic}, optimized for {$platform}.\n\nOrganize them into three groups:\n1. 10 high-volume popular hashtags (broad reach)\n2. 10 medium-volume niche hashtags (targeted community)\n3. 10 low-competition targeted hashtags (easy to rank in)\n\nAlso include:\n- The optimal number of hashtags to use per post on {$platform}\n- Recommended placement strategy (in caption, first comment, etc.)\n- Any platform-specific hashtag tips";

        return ['hashtags' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function audiencePersona(string $demographics, string $behaviors): array
    {
        $prompt = "Create a detailed buyer persona for {$this->businessName} ({$this->industry}).\n\nDemographic info: {$demographics}\nBehavioral info: {$behaviors}\n\nInclude all of the following:\n- Persona name and photo description\n- Age, job title, company size, income range\n- Top 3 goals\n- Top 3 pain points\n- Common objections to purchasing\n- Preferred content types (video, blog, podcast, etc.)\n- Most-used social platforms and when they use them\n- Buying triggers (what pushes them to act)\n- Messaging do's (what resonates)\n- Messaging don'ts (what turns them off)\n\nMake it specific and actionable, not generic.";

        return ['persona' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function contentScore(string $content, string $platform): array
    {
        $prompt = "Score the following content for {$platform} on a scale of 1-100 for each category:\n\n1. Engagement potential (hooks, questions, shareability)\n2. Clarity (message is clear and easy to understand)\n3. CTA strength (clear next step for the reader)\n4. Emotional appeal (evokes feeling or connection)\n5. Platform fit (matches {$platform} best practices and format)\n\nProvide an overall weighted score and specific improvement suggestions for each category that scored below 80.\n\nBusiness: {$this->businessName} ({$this->industry})\n\nContent to score:\n{$content}";

        return ['score' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function emailSubjectLines(string $topic, int $count = 10): array
    {
        $prompt = "Generate {$count} email subject line variations for {$this->businessName} ({$this->industry}) about: {$topic}.\n\nFor each subject line include:\n1. The subject line text (under 60 characters preferred)\n2. Predicted open-rate category: high, medium, or low\n3. The psychological trigger used (curiosity, urgency, social proof, personalization, fear of missing out, exclusivity, storytelling, controversy, benefit-driven, or question-based)\n\nOrder from highest predicted open rate to lowest. Include a mix of different psychological triggers.";

        return ['subjects' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function adVariations(string $baseAd, int $count = 5): array
    {
        $prompt = "Create {$count} ad copy variations for {$this->businessName} ({$this->industry}) based on this original ad:\n\n{$baseAd}\n\nEach variation must use a different angle:\n1. Pain-point focused (highlight the problem)\n2. Benefit-driven (highlight the transformation)\n3. Social-proof based (leverage testimonials/numbers)\n4. Urgency/scarcity (time or quantity limited)\n5. Storytelling (mini narrative)\n" . ($count > 5 ? "Create additional variations using creative combinations of the above angles.\n" : '') . "\nFor each variation provide:\n- Headline (under 40 characters)\n- Body copy (2-3 sentences)\n- CTA (clear action phrase)\n- Recommended platform for this angle";

        return ['variations' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function competitorAnalysis(string $competitorName, string $notes): array
    {
        $prompt = "Perform a deep competitive analysis of {$competitorName} as a competitor to {$this->businessName} in the {$this->industry} industry.\n\nAdditional context/notes: {$notes}\n\nAnalyze the following:\n1. Likely positioning and unique value proposition\n2. Target audience overlap with {$this->businessName}\n3. Content strategy assessment (platforms, frequency, content types)\n4. Key strengths (what they do well)\n5. Key weaknesses (gaps and vulnerabilities)\n6. Opportunities for {$this->businessName} to differentiate\n7. Recommended counter-strategies (specific actions to take)\n\nBe specific and actionable. Focus on what {$this->businessName} can actually do differently.";

        return ['analysis' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function videoScript(string $topic, string $platform, int $durationSeconds = 60): array
    {
        $prompt = "Write a video script for {$this->businessName} ({$this->industry}) for {$platform}.\n\nTopic: {$topic}\nTarget duration: {$durationSeconds} seconds\n\nInclude all of the following:\n1. Hook (first 3 seconds — must stop the scroll)\n2. Scene-by-scene breakdown with:\n   - Timestamp range\n   - Visual direction (what's on screen)\n   - Voiceover/dialogue\n   - Text overlay suggestions\n3. Call-to-action (final 5 seconds)\n4. Music/sound suggestions\n5. Thumbnail text suggestion\n6. 5 caption options for the post\n7. Recommended hashtags\n\nKeep it punchy and native to {$platform}.";

        return ['script' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function socialCaptionBatch(string $topic, array $platforms, int $count = 3): array
    {
        $platformList = implode(', ', $platforms);
        $prompt = "Generate {$count} ready-to-post social media captions for each of these platforms: {$platformList}.\n\nBusiness: {$this->businessName} ({$this->industry})\nTopic: {$topic}\n\nFor each caption include:\n- The platform name\n- Caption text (platform-appropriate length and style)\n- Hashtags (platform-appropriate count)\n- Best posting time suggestion\n- Engagement hook type (question, controversy, story, stat, etc.)\n\nMake each caption unique — don't just rephrase the same thing. Vary the angles, hooks, and CTAs.";

        return ['captions' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function seoAudit(string $url, string $pageDescription): array
    {
        $prompt = "Perform a comprehensive SEO audit assessment for {$this->businessName} ({$this->industry}) based on this page description:\n\nURL: {$url}\nPage Description: {$pageDescription}\n\nAnalyze and provide recommendations for:\n1. Title tag optimization (under 60 chars)\n2. Meta description (under 155 chars)\n3. Header structure (H1, H2, H3 hierarchy)\n4. Keyword density and placement\n5. Internal linking opportunities\n6. Image optimization (alt tags, file names)\n7. Page speed factors (what to optimize)\n8. Mobile-friendliness checks\n9. Schema markup suggestions\n10. Content length and quality assessment\n\nFor each area, provide:\n- Current assessment (pass/warning/fail)\n- Specific recommendation\n- Priority (high/medium/low)\n\nEnd with a summary score out of 100 and top 3 quick wins.";

        return ['audit' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function socialStrategy(string $goals, string $currentState): array
    {
        $prompt = "Create a comprehensive social media strategy for {$this->businessName} ({$this->industry}).\n\nCurrent state: {$currentState}\nGoals: {$goals}\n\nProvide:\n1. Platform prioritization (which platforms and why)\n2. Content pillars (3-5 recurring themes)\n3. Content mix ratio (educational/entertaining/promotional/community)\n4. Posting frequency per platform\n5. Optimal posting schedule for {$this->timezone}\n6. Content format recommendations per platform\n7. Engagement strategy (how to grow community)\n8. KPIs to track per platform\n9. 30-day action plan with weekly milestones\n10. Tools and resources needed\n\nBe specific to {$this->industry} and practical for a small team.";

        return ['strategy' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function weeklyReport(array $stats): array
    {
        $statsFormatted = '';
        foreach ($stats as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            $statsFormatted .= "- {$label}: {$value}\n";
        }

        $prompt = "Generate a weekly marketing performance summary for {$this->businessName} ({$this->industry}).\n\nThis week's stats:\n{$statsFormatted}\nProvide:\n1. Executive summary (2-3 sentences)\n2. Key wins this week\n3. Areas that need improvement\n4. Insights and trends spotted\n5. Recommended actions for next week (prioritized list)\n6. One creative experiment to try\n\nKeep it concise and action-oriented. Use the data to back up every recommendation.";

        return ['report' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function refineContent(string $content, string $action, ?string $context = null): array
    {
        $actionPrompts = [
            'improve' => "Improve the following content. Make it more engaging, clear, and compelling while keeping the same meaning and length. Fix any grammar issues.",
            'expand' => "Expand the following content to be 2-3x longer. Add more detail, examples, and supporting points while maintaining the same tone and message.",
            'shorten' => "Condense the following content to be 50% shorter. Keep the core message and most impactful phrases. Remove filler and redundancy.",
            'formal' => "Rewrite the following content in a formal, professional tone. Keep the same meaning but make it suitable for corporate communication.",
            'casual' => "Rewrite the following content in a casual, conversational tone. Make it feel friendly and approachable, like talking to a friend.",
            'persuasive' => "Rewrite the following content to be more persuasive. Add urgency, social proof language, and stronger calls-to-action.",
            'storytelling' => "Rewrite the following content using storytelling techniques. Add narrative elements, emotional hooks, and a compelling arc.",
            'simplify' => "Simplify the following content. Use shorter sentences, simpler words, and clearer structure. Target a 6th-grade reading level.",
            'add_hooks' => "Add 3 attention-grabbing hooks/opening lines for the following content. Then rewrite the content with the best hook integrated.",
            'add_cta' => "Add 3 different call-to-action options at the end of the following content. Make each CTA use a different psychological trigger (urgency, curiosity, benefit).",
            'emoji' => "Add relevant emojis to the following content to make it more engaging for social media. Don't overdo it — use 1-2 per paragraph or key point.",
            'bullet_points' => "Restructure the following content into clear bullet points or a numbered list format. Add a brief intro and conclusion.",
        ];

        $actionText = $actionPrompts[$action] ?? "Improve the following content: ";
        $contextNote = $context ? "\n\nAdditional context: {$context}" : '';

        $prompt = "{$actionText}{$contextNote}\n\nContent:\n{$content}";
        return ['content' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'action' => $action, 'provider' => $this->provider];
    }

    public function toneAnalysis(string $content): array
    {
        $prompt = "Analyze the tone and sentiment of the following content for {$this->businessName} ({$this->industry}). Provide:\n\n1. **Primary Tone**: (e.g., professional, casual, urgent, inspirational)\n2. **Sentiment**: Positive / Neutral / Negative with confidence %\n3. **Readability**: Score 1-100 and grade level\n4. **Emotion Map**: Top 3 emotions detected with intensity (low/medium/high)\n5. **Brand Alignment**: How well it matches our brand voice (if set) — score 1-10\n6. **Audience Fit**: Which audience segments would respond best\n7. **Improvement Tips**: 3 specific suggestions to better align tone with marketing goals\n\nContent:\n{$content}";

        return ['analysis' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function contentBrief(string $topic, string $contentType, string $goal): array
    {
        $prompt = "Create a detailed content brief for {$this->businessName} ({$this->industry}).\n\nTopic: {$topic}\nContent Type: {$contentType}\nGoal: {$goal}\n\nThe brief must include:\n1. **Working Title** (3 options)\n2. **Target Audience** (specific persona)\n3. **Key Message** (one sentence)\n4. **Content Outline** (structured sections with bullet points)\n5. **SEO Keywords** (5-8 target keywords)\n6. **Tone & Voice Direction** (specific guidance)\n7. **Reference/Inspiration** (3 examples of similar successful content)\n8. **Distribution Plan** (which channels, when to post, repurpose strategy)\n9. **Success Metrics** (KPIs to track)\n10. **Call-to-Action** (primary and secondary)\n\nMake it actionable — a writer should be able to create the content directly from this brief.";

        return ['brief' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function headlineOptimizer(string $headline, string $platform): array
    {
        $prompt = "Optimize this headline for {$platform} for {$this->businessName} ({$this->industry}).\n\nOriginal: {$headline}\n\nGenerate 10 headline variations using different techniques:\n1. Question format\n2. Number/listicle format\n3. How-to format\n4. Urgency/FOMO\n5. Curiosity gap\n6. Benefit-driven\n7. Controversy/bold claim\n8. Social proof\n9. Emotional trigger\n10. Power words\n\nFor each, rate predicted CTR impact (low/medium/high) and explain the psychology behind it.";

        return ['headlines' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'platform' => $platform, 'provider' => $this->provider];
    }

    public function campaignOptimizer(string $campaignData, string $goals): array
    {
        $prompt = "Analyze this campaign data and provide optimization recommendations for {$this->businessName} ({$this->industry}).\n\nCampaign Data:\n{$campaignData}\n\nGoals: {$goals}\n\nProvide:\n1. **Performance Assessment**: How is the campaign performing against goals?\n2. **Budget Optimization**: Where to reallocate spend for better ROI\n3. **Channel Mix**: Which channels to increase/decrease/add\n4. **Audience Targeting**: Refinement suggestions\n5. **Creative Recommendations**: What content/messaging changes to make\n6. **Timing Optimization**: Best days/times to increase activity\n7. **Quick Wins**: 3 things to change immediately for better results\n8. **30-Day Action Plan**: Week-by-week optimization roadmap\n\nBe specific with numbers and percentages where possible.";

        return ['optimization' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function contentCalendarMonth(string $month, string $goals, string $channels): array
    {
        $prompt = "Create a complete content calendar for {$this->businessName} ({$this->industry}) for {$month}.\n\nGoals: {$goals}\nChannels: {$channels}\nTimezone: {$this->timezone}\n\nFor each day of the month, provide:\n- **Day & Date**\n- **Content Type** (post, story, reel, blog, email, etc.)\n- **Channel/Platform**\n- **Topic/Theme**\n- **Caption/Hook** (first 2 lines)\n- **Best Posting Time**\n- **Hashtags** (if applicable)\n- **Content Pillar** (educational/entertaining/promotional/community)\n\nAlso include:\n- Weekly themes\n- Key dates/holidays to leverage\n- Content mix ratio summary\n- Engagement tactics for each week";

        return ['calendar' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'provider' => $this->provider];
    }

    public function smartPostingTime(string $platform, string $audience, string $contentType): array
    {
        $prompt = "Recommend the optimal posting schedule for {$this->businessName} ({$this->industry}) on {$platform}.\n\nTarget Audience: {$audience}\nContent Type: {$contentType}\nTimezone: {$this->timezone}\n\nProvide:\n1. **Best Days**: Ranked from best to worst with reasoning\n2. **Best Times**: Top 5 time slots with expected engagement multiplier\n3. **Worst Times**: When to avoid posting\n4. **Frequency**: Recommended posts per day/week\n5. **Content-Specific Timing**: Different times for different content types\n6. **Seasonal Adjustments**: How to adjust for time of year\n7. **Algorithm Tips**: Platform-specific algorithm optimization\n\nBase recommendations on current {$platform} best practices and the specific audience described.";

        return ['schedule' => $this->generateAdvanced($this->buildSystemPrompt(), $prompt), 'platform' => $platform, 'provider' => $this->provider];
    }

    public function aiInsights(array $stats): array
    {
        $statsFormatted = '';
        foreach ($stats as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            if (is_array($value)) {
                $statsFormatted .= "- {$label}: " . json_encode($value) . "\n";
            } else {
                $statsFormatted .= "- {$label}: {$value}\n";
            }
        }

        $prompt = "Based on the following marketing metrics for {$this->businessName} ({$this->industry}), provide 5 actionable insights as a JSON array. Each insight should have: title (short, under 60 chars), description (1-2 sentences), priority (high/medium/low), category (content/engagement/growth/optimization), and action (specific next step).\n\nMetrics:\n{$statsFormatted}\n\nReturn ONLY a valid JSON array like:\n[{\"title\":\"...\",\"description\":\"...\",\"priority\":\"...\",\"category\":\"...\",\"action\":\"...\"}]\n\nFocus on actionable, specific recommendations — not generic advice.";

        $raw = $this->generateAdvanced($this->buildSystemPrompt(), $prompt);

        // Try to parse JSON from response
        $jsonMatch = [];
        if (preg_match('/\[[\s\S]*\]/', $raw, $jsonMatch)) {
            $parsed = json_decode($jsonMatch[0], true);
            if (is_array($parsed)) {
                return ['insights' => $parsed, 'provider' => $this->provider];
            }
        }

        return ['insights' => [['title' => 'AI Insights Available', 'description' => $raw, 'priority' => 'medium', 'category' => 'content', 'action' => 'Review the full analysis']], 'provider' => $this->provider];
    }

    private function generate(string $prompt): string
    {
        return match ($this->provider) {
            'anthropic' => $this->callAnthropic($prompt),
            'gemini' => $this->callGemini($prompt),
            default => $this->callOpenAiCompatible($prompt),
        };
    }

    private function callOpenAiCompatible(string $prompt): string
    {
        if (empty($this->config['openai_api_key'])) {
            return $this->fallback($prompt);
        }

        $url = rtrim((string)$this->config['openai_base_url'], '/') . '/chat/completions';
        $payload = [
            'model' => $this->config['openai_model'] ?? 'gpt-4.1-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a practical SMB marketing strategist. Be concise but specific.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
        ];

        $data = $this->postJson($url, [
            'Authorization: Bearer ' . $this->config['openai_api_key'],
        ], $payload);

        $content = $data['choices'][0]['message']['content'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    private function callAnthropic(string $prompt): string
    {
        if (empty($this->config['anthropic_api_key'])) {
            return $this->fallback($prompt);
        }

        $payload = [
            'model' => $this->config['anthropic_model'] ?? 'claude-sonnet-4-20250514',
            'max_tokens' => 1200,
            'system' => 'You are a practical SMB marketing strategist. Be concise but specific.',
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        $data = $this->postJson('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $this->config['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ], $payload);

        $content = $data['content'][0]['text'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    private function callGemini(string $prompt): string
    {
        if (empty($this->config['gemini_api_key'])) {
            return $this->fallback($prompt);
        }

        $model = $this->config['gemini_model'] ?? 'gemini-2.5-flash';
        $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', $model, urlencode((string)$this->config['gemini_api_key']));

        $payload = [
            'contents' => [[
                'parts' => [[
                    'text' => 'You are a practical SMB marketing strategist. Be concise but specific. ' . $prompt,
                ]],
            ]],
        ];

        $data = $this->postJson($url, [], $payload);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    private function postJson(string $url, array $headers, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 45,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildSystemPrompt(): string
    {
        $base = 'You are a practical SMB marketing strategist. Be concise but specific.';

        if ($this->brandVoice !== null) {
            $tone = $this->brandVoice['voice_tone'] ?? 'professional';
            $audience = $this->brandVoice['target_audience'] ?? 'general';
            $vocabulary = $this->brandVoice['vocabulary'] ?? '';
            $avoid = $this->brandVoice['avoid_words'] ?? '';
            $example = $this->brandVoice['example_content'] ?? '';

            $base .= "\n\nBrand Voice Guidelines:"
                . "\n- Tone: {$tone}"
                . "\n- Target Audience: {$audience}"
                . "\n- Vocabulary to use: {$vocabulary}"
                . "\n- Words/phrases to avoid: {$avoid}"
                . "\n- Example of our voice: {$example}";
        }

        return $base;
    }

    private function generateAdvanced(string $system, string $prompt): string
    {
        return match ($this->provider) {
            'anthropic' => $this->callAnthropicAdv($system, $prompt),
            'gemini' => $this->callGeminiAdv($system, $prompt),
            default => $this->callOpenAiAdv($system, $prompt),
        };
    }

    private function callOpenAiAdv(string $system, string $prompt): string
    {
        if (empty($this->config['openai_api_key'])) {
            return $this->fallback($prompt);
        }

        $url = rtrim((string)$this->config['openai_base_url'], '/') . '/chat/completions';
        $payload = [
            'model' => $this->config['openai_model'] ?? 'gpt-4.1-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
        ];

        $data = $this->postJson($url, [
            'Authorization: Bearer ' . $this->config['openai_api_key'],
        ], $payload);

        $content = $data['choices'][0]['message']['content'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    private function callAnthropicAdv(string $system, string $prompt): string
    {
        if (empty($this->config['anthropic_api_key'])) {
            return $this->fallback($prompt);
        }

        $payload = [
            'model' => $this->config['anthropic_model'] ?? 'claude-sonnet-4-20250514',
            'max_tokens' => 4096,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        $data = $this->postJson('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $this->config['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ], $payload);

        $content = $data['content'][0]['text'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    private function callGeminiAdv(string $system, string $prompt): string
    {
        if (empty($this->config['gemini_api_key'])) {
            return $this->fallback($prompt);
        }

        $model = $this->config['gemini_model'] ?? 'gemini-2.5-flash';
        $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', $model, urlencode((string)$this->config['gemini_api_key']));

        $payload = [
            'contents' => [[
                'parts' => [[
                    'text' => $system . "\n\n" . $prompt,
                ]],
            ]],
        ];

        $data = $this->postJson($url, [], $payload);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    private function fallback(string $prompt): string
    {
        return "[Fallback mode: configure AI provider keys in .env]\n\n" .
            "- Core strategy: 40% educational, 30% social proof, 20% offer, 10% behind-the-scenes.\n" .
            "- Recommended cadence: 5 posts/week + 2 stories/day + 1 email/week.\n" .
            "- Highest-conversion windows: Tue 11:30 AM, Wed 6:30 PM, Thu 12:15 PM ({$this->timezone}).\n" .
            "- CTA suggestions: 'Comment START', 'Book your spot', 'Send us DM with keyword'.\n\n" .
            "Prompt used:\n{$prompt}";
    }
}
