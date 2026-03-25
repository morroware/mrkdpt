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
