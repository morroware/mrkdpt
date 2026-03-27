<?php

declare(strict_types=1);

/**
 * AiStrategyTools — Research, competitors, campaigns, segments, funnels, UTM.
 *
 * Original: Market Research, Content Ideas, Audience Persona, Competitor Analysis,
 *           Social Strategy, Weekly Report, Campaign Optimizer, Calendar, Smart Times,
 *           AI Insights, Schedule Suggestion
 * Tier 2:  Smart Segmentation, Competitor Content Radar
 * Tier 3:  Funnel Advisor, Smart UTM & Attribution AI, Weekly Standup Digest
 */
final class AiStrategyTools
{
    public function __construct(private AiService $ai) {}

    /* ================================================================== */
    /*  ORIGINAL TOOLS                                                    */
    /* ================================================================== */

    public function marketResearch(string $audience, string $goal): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Create a practical market research brief for {$biz} in {$ind}. Audience: {$audience}. Goal: {$goal}. Include: ICP summary, top pain points, top objections, 3 competitor angles, messaging opportunities, and a 30-day execution plan.";
        return ['brief' => $this->ai->generate($prompt), 'generated_at' => gmdate(DATE_ATOM), 'provider' => $this->ai->getProvider()];
    }

    public function discoverBusinessFromWebsite(string $url, string $websiteSnapshot): array
    {
        $prompt = "You are a senior marketing strategist. Analyze this website snapshot and infer an onboarding profile.

Website URL: {$url}

Website Snapshot:
{$websiteSnapshot}

Return ONLY valid JSON with these keys:
{
  \"business_description\": \"...\",
  \"products_services\": \"...\",
  \"target_audience\": \"...\",
  \"competitors\": \"comma separated competitor names or categories\",
  \"marketing_goals\": \"comma separated goals\",
  \"active_platforms\": \"comma separated channels/platforms\",
  \"unique_selling_points\": \"...\",
  \"content_examples\": \"2-3 short brand voice sample lines\"
}

Rules:
- infer from available evidence only
- if unknown, provide a practical best-guess and keep it short
- be specific, actionable, and concise.";

        $raw = $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt, null, null, 2048);
        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $raw);
        if (preg_match('/\{[\s\S]*\}/', $cleaned, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) {
                return ['profile' => $parsed, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
            }
        }

        return ['profile' => null, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
    }

    public function contentIdeas(string $topic, string $platform): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $tz  = $this->ai->getTimezone();
        $prompt = "Generate 8 {$platform} content ideas for {$biz} ({$ind}) around: {$topic}. Each idea must include: Hook, Value, CTA, and best posting time in {$tz}.";
        return ['ideas' => $this->ai->generate($prompt), 'platform' => $platform, 'topic' => $topic, 'provider' => $this->ai->getProvider()];
    }

    public function audiencePersona(string $demographics, string $behaviors): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Create a detailed buyer persona for {$biz} ({$ind}).\n\nDemographics: {$demographics}\nBehaviors: {$behaviors}\n\nInclude: persona name/description, age, job, company size, income, top 3 goals, top 3 pain points, objections, preferred content types, social platforms, buying triggers, messaging do's and don'ts.\n\nBe specific and actionable.";

        return ['persona' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function competitorAnalysis(string $competitorName, string $notes): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Perform a deep competitive analysis of {$competitorName} as a competitor to {$biz} in {$ind}.\n\nContext: {$notes}\n\nAnalyze: positioning/UVP, audience overlap, content strategy, key strengths, key weaknesses, differentiation opportunities, counter-strategies.\n\nBe specific and actionable.";

        return ['analysis' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function socialStrategy(string $goals, string $currentState): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $tz  = $this->ai->getTimezone();
        $prompt = "Create a comprehensive social media strategy for {$biz} ({$ind}).\n\nCurrent state: {$currentState}\nGoals: {$goals}\n\nProvide: platform prioritization, content pillars, content mix ratio, posting frequency, optimal schedule for {$tz}, format recommendations, engagement strategy, KPIs, 30-day action plan, tools needed.\n\nBe specific to {$ind} and practical for a small team.";

        return ['strategy' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function weeklyReport(array $stats): array
    {
        $statsFormatted = $this->formatStats($stats);
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Generate a weekly marketing performance summary for {$biz} ({$ind}).\n\nThis week's stats:\n{$statsFormatted}\nProvide: executive summary, key wins, areas needing improvement, insights/trends, recommended actions for next week, one creative experiment to try.\n\nKeep it concise and action-oriented.";

        return ['report' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function scheduleSuggestion(string $objective): array
    {
        $biz = $this->ai->getBusinessName();
        $tz  = $this->ai->getTimezone();
        $prompt = "Produce a 14-day marketing schedule for {$biz}. Objective: {$objective}. Include date, weekday, channel, content type, posting time in {$tz}, and primary KPI.";
        return ['schedule' => $this->ai->generate($prompt), 'provider' => $this->ai->getProvider()];
    }

    public function campaignOptimizer(string $campaignData, string $goals): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Analyze this campaign data and provide optimization recommendations for {$biz} ({$ind}).\n\nCampaign Data:\n{$campaignData}\n\nGoals: {$goals}\n\nProvide: performance assessment, budget optimization, channel mix, audience targeting, creative recommendations, timing optimization, 3 quick wins, 30-day action plan.\n\nBe specific with numbers and percentages.";

        return ['optimization' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'provider' => $this->ai->getProvider()];
    }

    public function contentCalendarMonth(string $month, string $goals, string $channels): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $tz  = $this->ai->getTimezone();
        $prompt = "Create a complete content calendar for {$biz} ({$ind}) for {$month}.\n\nGoals: {$goals}\nChannels: {$channels}\nTimezone: {$tz}\n\nFor each day: content type, channel, topic/theme, caption/hook, posting time, hashtags, content pillar.\n\nAlso: weekly themes, key dates/holidays, content mix summary, engagement tactics.";

        return ['calendar' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt, null, null, 4096), 'provider' => $this->ai->getProvider()];
    }

    public function smartPostingTime(string $platform, string $audience, string $contentType): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $tz  = $this->ai->getTimezone();
        $prompt = "Recommend the optimal posting schedule for {$biz} ({$ind}) on {$platform}.\n\nTarget Audience: {$audience}\nContent Type: {$contentType}\nTimezone: {$tz}\n\nProvide: best days ranked, top 5 time slots with engagement multiplier, worst times, frequency, content-specific timing, seasonal adjustments, algorithm tips.";

        return ['schedule' => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt), 'platform' => $platform, 'provider' => $this->ai->getProvider()];
    }

    public function aiInsights(array $stats): array
    {
        $statsFormatted = $this->formatStats($stats);
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();
        $prompt = "Based on the following marketing metrics for {$biz} ({$ind}), provide 5 actionable insights as a JSON array. Each: title (under 60 chars), description (1-2 sentences), priority (high/medium/low), category (content/engagement/growth/optimization), action (specific next step).\n\nMetrics:\n{$statsFormatted}\n\nReturn ONLY a valid JSON array:\n[{\"title\":\"...\",\"description\":\"...\",\"priority\":\"...\",\"category\":\"...\",\"action\":\"...\"}]";

        $raw = $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt);

        if (preg_match('/\[[\s\S]*\]/', $raw, $jsonMatch)) {
            $parsed = json_decode($jsonMatch[0], true);
            if (is_array($parsed)) {
                return ['insights' => $parsed, 'provider' => $this->ai->getProvider()];
            }
        }

        return ['insights' => [['title' => 'AI Insights Available', 'description' => $raw, 'priority' => 'medium', 'category' => 'content', 'action' => 'Review the full analysis']], 'provider' => $this->ai->getProvider()];
    }

    /* ================================================================== */
    /*  TIER 2: AI Smart Segmentation                                    */
    /* ================================================================== */

    /**
     * Analyze contacts and suggest high-value segments not yet created.
     */
    public function smartSegmentation(array $contactStats, array $existingSegments): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();

        $segList = '';
        foreach ($existingSegments as $s) {
            $segList .= "- {$s['name']}: {$s['description']} ({$s['contact_count']} contacts)\n";
        }

        $statsFormatted = $this->formatStats($contactStats);

        $prompt = "You are a CRM and audience segmentation expert for {$biz} ({$ind}).

Analyze our contact database stats and suggest high-value segments we haven't created yet.

Contact Database Stats:
{$statsFormatted}

Existing Segments:
{$segList}

Suggest 5-7 NEW segments as a JSON array. Each segment should have:
{
  \"name\": \"<descriptive segment name>\",
  \"description\": \"<why this segment is valuable>\",
  \"criteria\": {
    \"stage\": \"<optional: lead|mql|sql|opportunity|customer>\",
    \"min_score\": <optional: number>,
    \"max_score\": <optional: number>,
    \"tags\": \"<optional: comma-separated>\",
    \"source\": \"<optional>\",
    \"has_activity_since\": \"<optional: YYYY-MM-DD>\",
    \"no_activity_since\": \"<optional: YYYY-MM-DD>\"
  },
  \"estimated_size\": \"<small/medium/large>\",
  \"recommended_action\": \"<what to do with this segment>\",
  \"priority\": \"<high/medium/low>\"
}

Return ONLY a valid JSON array. Focus on actionable segments that can drive revenue.";

        $raw = $this->ai->generateAdvanced(
            $this->ai->buildSystemPrompt('You are a CRM expert. Return valid JSON.'),
            $prompt
        );

        if (preg_match('/\[[\s\S]*\]/', $raw, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) {
                return ['segments' => $parsed, 'provider' => $this->ai->getProvider()];
            }
        }

        return ['segments' => [], 'raw' => $raw, 'provider' => $this->ai->getProvider()];
    }

    /* ================================================================== */
    /*  TIER 2: AI Competitor Content Radar                              */
    /* ================================================================== */

    /**
     * For each tracked competitor, generate weekly counter-content suggestions.
     */
    public function competitorRadar(array $competitors): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();

        $compList = '';
        foreach ($competitors as $c) {
            $compList .= "- {$c['name']} ({$c['channel']}): Recent activity: {$c['recent_activity']}. Positioning: {$c['positioning']}. Opportunities: {$c['opportunity']}\n";
        }

        $prompt = "You are a competitive intelligence analyst for {$biz} ({$ind}).

Here are our tracked competitors and their recent activity:
{$compList}

For EACH competitor, provide:
1. **Content Alert**: What notable content they've published or topics they're pushing
2. **Counter-Content Suggestion**: A specific piece of content we should create to compete — with a stronger angle
3. **Differentiation Opportunity**: Where we can uniquely stand out
4. **Urgency**: How time-sensitive is this response (act now / this week / this month)
5. **Platform**: Where to publish our response for maximum impact

Also provide:
- Overall competitive landscape summary (2-3 sentences)
- Top 3 content gaps our competitors aren't covering that we should own
- One bold move to leapfrog all competitors this week";

        return [
            'radar'    => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt, null, null, 4096),
            'provider' => $this->ai->getProvider(),
        ];
    }

    /* ================================================================== */
    /*  TIER 3: AI Funnel Advisor                                        */
    /* ================================================================== */

    /**
     * Analyze funnel stages and suggest fixes for drop-off points.
     */
    public function funnelAdvisor(string $funnelName, array $stages): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();

        $stageData = '';
        $prevActual = 0;
        foreach ($stages as $i => $s) {
            $dropoff = ($i > 0 && $prevActual > 0) ? round((1 - ($s['actual_count'] / $prevActual)) * 100, 1) . '% drop-off' : 'entry point';
            $stageData .= "- Stage {$s['stage_order']}: {$s['name']} — Target: {$s['target_count']}, Actual: {$s['actual_count']}, Conversion: {$s['conversion_rate']}% ({$dropoff})\n";
            $prevActual = (int)$s['actual_count'];
        }

        $prompt = "You are a conversion rate optimization expert for {$biz} ({$ind}).

Analyze this sales funnel and provide specific recommendations to fix drop-off points.

Funnel: {$funnelName}
Stages:
{$stageData}

For EACH stage with significant drop-off (>20%), provide:
1. **Root Cause Analysis**: Why people are likely dropping off
2. **Content Fix**: Specific content/messaging to create for this stage
3. **Offer Suggestion**: What offer or incentive could help
4. **Automation**: What automated action to trigger (email, retarget, etc.)
5. **Expected Impact**: Estimated improvement if implemented

Also provide:
- Overall funnel health score (1-100)
- The single biggest bottleneck
- Quick-win optimization (can implement today)
- Ideal conversion rate benchmarks for each stage in {$ind}";

        return [
            'advice'  => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt),
            'provider' => $this->ai->getProvider(),
        ];
    }

    /* ================================================================== */
    /*  TIER 3: Smart UTM & Attribution AI                               */
    /* ================================================================== */

    /**
     * Auto-suggest UTM parameters based on campaign context.
     */
    public function smartUtm(string $campaignName, string $url, string $channel, string $contentDescription): array
    {
        $biz = $this->ai->getBusinessName();

        $prompt = "You are a marketing attribution specialist for {$biz}.

Generate optimized UTM parameters for this link:
- Campaign: {$campaignName}
- Destination URL: {$url}
- Channel: {$channel}
- Content: {$contentDescription}

Return as JSON:
{
  \"utm_source\": \"<source>\",
  \"utm_medium\": \"<medium>\",
  \"utm_campaign\": \"<campaign>\",
  \"utm_term\": \"<term or empty>\",
  \"utm_content\": \"<content variant identifier>\",
  \"naming_rationale\": \"<why you chose these parameters>\",
  \"tracking_tips\": [\"<3 tips for better attribution>\"],
  \"suggested_variants\": [
    {\"utm_content\": \"<variant A>\", \"description\": \"...\"},
    {\"utm_content\": \"<variant B>\", \"description\": \"...\"}
  ]
}

Follow UTM best practices: lowercase, hyphens not spaces, consistent naming.";

        $raw = $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt);

        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $raw);
        if (preg_match('/\{[\s\S]*\}/s', $cleaned, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['utm_source'])) {
                return ['utm' => $parsed, 'provider' => $this->ai->getProvider()];
            }
        }

        return ['utm' => null, 'raw' => $raw, 'provider' => $this->ai->getProvider()];
    }

    /* ================================================================== */
    /*  TIER 3: AI Weekly Standup Digest                                 */
    /* ================================================================== */

    /**
     * Monday morning briefing: what happened last week, what's scheduled
     * this week, what needs attention, and 3 priority actions.
     */
    public function weeklyStandup(array $lastWeekStats, array $thisWeekSchedule, array $pendingItems): array
    {
        $biz = $this->ai->getBusinessName();
        $ind = $this->ai->getIndustry();

        $lastWeek = $this->formatStats($lastWeekStats);

        $scheduleNote = '';
        foreach ($thisWeekSchedule as $item) {
            $scheduleNote .= "- {$item['title']} ({$item['platform']}) — {$item['scheduled_for']}\n";
        }
        if ($scheduleNote === '') $scheduleNote = "No content scheduled yet.\n";

        $pendingNote = '';
        foreach ($pendingItems as $item) {
            $pendingNote .= "- {$item['type']}: {$item['description']}\n";
        }
        if ($pendingNote === '') $pendingNote = "No pending items.\n";

        $prompt = "Generate a Monday morning marketing standup digest for {$biz} ({$ind}).

LAST WEEK'S RESULTS:
{$lastWeek}

THIS WEEK'S SCHEDULE:
{$scheduleNote}

PENDING / NEEDS ATTENTION:
{$pendingNote}

Format as a concise briefing with:
1. **Last Week Summary** (3-4 bullet points of key outcomes)
2. **This Week Preview** (what's coming up)
3. **Needs Attention** (what's overdue, failing, or at risk)
4. **Top 3 Priority Actions** (most impactful things to do today)
5. **Quick Win** (one thing that takes <30 min but will move the needle)
6. **Motivation** (one encouraging data point or insight)

Keep it under 400 words. Make it scannable.";

        return [
            'digest'   => $this->ai->generateAdvanced($this->ai->buildSystemPrompt(), $prompt),
            'provider' => $this->ai->getProvider(),
        ];
    }

    /* ================================================================== */
    /*  Helpers                                                           */
    /* ================================================================== */

    private function formatStats(array $stats): string
    {
        $out = '';
        foreach ($stats as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            if (is_array($value)) {
                $out .= "- {$label}: " . json_encode($value) . "\n";
            } else {
                $out .= "- {$label}: {$value}\n";
            }
        }
        return $out;
    }
}
