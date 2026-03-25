<?php

declare(strict_types=1);

/**
 * AiAnalysisTools — Content analysis, scoring, approval, and prediction.
 *
 * Tier 1: Content Approval Reviewer (Pre-Flight Check)
 * Tier 2: Content Performance Predictor
 * Original: Tone Analysis, Content Score, SEO Audit, Hashtag Research, SEO Keywords
 */
final class AiAnalysisTools
{
    public function __construct(private AiService $ai) {}

    /* ================================================================== */
    /*  ORIGINAL TOOLS                                                    */
    /* ================================================================== */

    public function toneAnalysis(string $content): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Analyze the tone and sentiment of the following content for {$biz} ({$ind}). Provide:\n\n1. **Primary Tone**: (e.g., professional, casual, urgent, inspirational)\n2. **Sentiment**: Positive / Neutral / Negative with confidence %\n3. **Readability**: Score 1-100 and grade level\n4. **Emotion Map**: Top 3 emotions detected with intensity (low/medium/high)\n5. **Brand Alignment**: How well it matches our brand voice (if set) — score 1-10\n6. **Audience Fit**: Which audience segments would respond best\n7. **Improvement Tips**: 3 specific suggestions to better align tone with marketing goals\n\nContent:\n{$content}";

        return ['analysis' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function contentScore(string $content, string $platform): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Score the following content for {$platform} on a scale of 1-100 for each category:\n\n1. Engagement potential (hooks, questions, shareability)\n2. Clarity (message is clear and easy to understand)\n3. CTA strength (clear next step for the reader)\n4. Emotional appeal (evokes feeling or connection)\n5. Platform fit (matches {$platform} best practices and format)\n\nProvide an overall weighted score and specific improvement suggestions for each category that scored below 80.\n\nBusiness: {$biz} ({$ind})\n\nContent to score:\n{$content}";

        return ['score' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function seoKeywordResearch(string $topic, string $niche): array
    {
        $biz = $this->ai->getBusinessName();
        $prompt = "Generate 20 SEO keyword suggestions for {$biz} in the {$niche} niche around: {$topic}.\n\nFor each: keyword phrase, search intent, estimated difficulty, suggested content type.\n\nOrganize from easiest to hardest to rank for.";

        return ['keywords' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function hashtagResearch(string $topic, string $platform): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Generate 30 hashtags for {$biz} ({$ind}) about: {$topic}, optimized for {$platform}.\n\nOrganize into: 10 high-volume popular, 10 medium-volume niche, 10 low-competition targeted.\n\nAlso: optimal hashtag count per post on {$platform}, placement strategy, platform-specific tips.";

        return ['hashtags' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function seoAudit(string $url, string $pageDescription): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Perform a comprehensive SEO audit for {$biz} ({$ind}).\n\nURL: {$url}\nPage Description: {$pageDescription}\n\nAnalyze: title tag, meta description, header structure, keyword density, internal linking, image optimization, page speed, mobile-friendliness, schema markup, content quality.\n\nFor each: assessment (pass/warning/fail), specific recommendation, priority.\n\nEnd with summary score /100 and top 3 quick wins.";

        return ['audit' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    /* ================================================================== */
    /*  TIER 1: Content Approval Reviewer (Pre-Flight Check)             */
    /* ================================================================== */

    /**
     * Before publishing, AI checks for brand consistency, tone drift,
     * grammar, potential PR risks, and compliance issues.
     */
    public function preFlightCheck(string $content, string $platform, ?string $context = null): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $contextNote = $context ? "\nAdditional context: {$context}" : '';

        $prompt = "You are a senior marketing editor and brand guardian for {$biz} ({$ind}).

Review the following content before it gets published on {$platform}. Perform a thorough pre-flight check.{$contextNote}

Content to review:
{$content}

Check for and report on ALL of the following:

1. **Brand Consistency** (1-10): Does it match our brand voice? Specific mismatches?
2. **Tone Drift** (1-10): Is the tone consistent throughout? Any jarring shifts?
3. **Grammar & Spelling** (1-10): Any errors? List them with corrections.
4. **PR Risk Assessment** (low/medium/high): Could this offend, be misinterpreted, or go viral negatively? Explain.
5. **Compliance Check**: Any potential legal/regulatory issues? (claims, disclosures, copyright)
6. **Platform Fit** (1-10): Does it follow {$platform} best practices? Length, format, hashtags?
7. **CTA Effectiveness** (1-10): Is the call-to-action clear and compelling?
8. **Inclusivity Check**: Any language that could alienate audience segments?
9. **Factual Claims**: Any claims that need sourcing or disclaimers?
10. **Competitor Mentions**: Any direct/indirect competitor references that could backfire?

Return as JSON with this structure:
{
  \"overall_score\": <1-100>,
  \"status\": \"approved|needs_revision|rejected\",
  \"checks\": [
    {\"name\": \"...\", \"score\": <1-10>, \"status\": \"pass|warn|fail\", \"details\": \"...\", \"fix\": \"...\"}
  ],
  \"summary\": \"<2-3 sentence overall assessment>\",
  \"revised_content\": \"<only if needs_revision: suggested improved version>\"
}";

        $raw = $this->ai->generateAdvanced(
            $this->ai->buildSystemPrompt('You are a meticulous editor. Return valid JSON.'),
            $prompt
        );

        // Parse JSON
        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $raw);
        if (preg_match('/\{[\s\S]*\}/s', $cleaned, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['overall_score'])) {
                return ['review' => $parsed, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
            }
        }

        return ['review' => null, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
    }

    /* ================================================================== */
    /*  TIER 2: Content Performance Predictor                            */
    /* ================================================================== */

    /**
     * Before publishing, predict engagement score (1-100) based on content
     * type, platform, posting time, historical patterns, and quality.
     */
    public function predictPerformance(string $content, string $platform, ?string $scheduledTime = null, ?array $historicalStats = null): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $tz  = $this->ai->getTimezone();

        $timeNote = $scheduledTime
            ? "Scheduled posting time: {$scheduledTime} ({$tz})"
            : "No specific posting time set.";

        $statsNote = '';
        if ($historicalStats) {
            $statsNote = "\n\nHistorical performance data:\n";
            foreach ($historicalStats as $k => $v) {
                $label = ucwords(str_replace('_', ' ', $k));
                $statsNote .= "- {$label}: " . (is_array($v) ? json_encode($v) : $v) . "\n";
            }
        }

        $prompt = "You are a marketing analytics expert for {$biz} ({$ind}).

Predict the performance of this content before it's published.

Platform: {$platform}
{$timeNote}{$statsNote}

Content:
{$content}

Provide a detailed prediction as JSON:
{
  \"confidence_score\": <1-100, overall predicted engagement>,
  \"predicted_metrics\": {
    \"engagement_rate\": \"<low/medium/high with estimated %>\",
    \"reach_potential\": \"<low/medium/high>\",
    \"share_probability\": \"<low/medium/high>\",
    \"save_probability\": \"<low/medium/high>\",
    \"comment_likelihood\": \"<low/medium/high>\"
  },
  \"timing_score\": <1-100, how good is the posting time>,
  \"timing_suggestion\": \"<better time if score < 70>\",
  \"strengths\": [\"<list 3 things that will drive engagement>\"],
  \"weaknesses\": [\"<list 3 things that could hurt performance>\"],
  \"optimization_tips\": [\"<list 3 specific changes to boost performance>\"],
  \"comparable_benchmark\": \"<what similar content typically achieves on this platform>\"
}";

        $raw = $this->ai->generateAdvanced(
            $this->ai->buildSystemPrompt('You are a data-driven marketing analyst. Return valid JSON.'),
            $prompt
        );

        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $raw);
        if (preg_match('/\{[\s\S]*\}/s', $cleaned, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['confidence_score'])) {
                return ['prediction' => $parsed, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
            }
        }

        return ['prediction' => null, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
    }

    /* ================================================================== */
    /*  TIER 3: AI A/B Auto-Optimizer                                    */
    /* ================================================================== */

    /**
     * Auto-generate A/B test variants for content with predicted winners.
     */
    public function generateAbVariants(string $originalContent, string $testType, int $variantCount = 3): array
    {
        $biz = $this->ai->getBusinessName();

        $prompt = "Create {$variantCount} A/B test variants for {$biz}.

Test Type: {$testType}
Original Content (Control):
{$originalContent}

For EACH variant:
1. **Variant Name**: Descriptive name (e.g., 'Urgency-driven CTA')
2. **Content**: The full variant content
3. **Hypothesis**: What you're testing and why this might outperform
4. **Predicted Winner Probability**: Estimated % chance this beats the control
5. **Key Metric to Watch**: What to measure for this variant

Also provide:
- Recommended sample size for statistical significance
- How long to run the test
- When to declare a winner (confidence threshold)
- What to do after the test concludes";

        return [
            'variants' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt),
            'provider' => $this->ai->getProvider(),
        ];
    }

    /**
     * Analyze A/B test results and declare a winner.
     */
    public function analyzeAbResults(array $variants): array
    {
        $variantData = '';
        foreach ($variants as $v) {
            $rate = ($v['impressions'] ?? 0) > 0
                ? round(($v['conversions'] ?? 0) / $v['impressions'] * 100, 2) . '%'
                : 'N/A';
            $variantData .= "- {$v['variant_name']}: {$v['impressions']} impressions, {$v['conversions']} conversions ({$rate})\n";
        }

        $prompt = "Analyze these A/B test results and determine the winner.

Variants:
{$variantData}

Provide:
1. **Winner**: Which variant won and by how much
2. **Statistical Significance**: Is the result reliable? (estimate confidence level)
3. **Insights**: Why the winner likely performed better
4. **Recommendation**: Should we adopt the winner or run more tests?
5. **Next Test**: What to test next based on these learnings";

        return [
            'analysis' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt),
            'provider' => $this->ai->getProvider(),
        ];
    }
}
