<?php

declare(strict_types=1);

function register_ai_routes(
    Router $router,
    AiService $ai,
    AiLogRepository $aiLogs,
    Analytics $analytics,
    PostRepository $posts,
    CampaignRepository $campaigns
): void {
    $router->post('/api/ai/research', function () use ($ai, $aiLogs, $analytics) {
        $p = request_json();
        $focus = sprintf('audience=%s;goal=%s', $p['audience'] ?? 'local customers', $p['goal'] ?? 'grow inbound leads');
        $result = $ai->marketResearch($p['audience'] ?? 'local customers', $p['goal'] ?? 'grow inbound leads');
        $aiLogs->saveResearch($focus, $result['brief']);
        $analytics->track('ai.research', 'ai', 0, ['provider' => $result['provider']]);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/content', function () use ($ai, $analytics) {
        $p = request_json();
        $result = $ai->generateContent($p);
        $analytics->track('ai.content', 'ai', 0, ['type' => $p['content_type'] ?? 'social_post']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/ideas', function () use ($ai, $aiLogs, $analytics) {
        $p = request_json();
        $topic = $p['topic'] ?? 'seasonal offer';
        $platform = $p['platform'] ?? 'instagram';
        $result = $ai->contentIdeas($topic, $platform);
        $aiLogs->saveIdea($topic, $platform, $result['ideas']);
        $analytics->track('ai.ideas', 'ai', 0, ['platform' => $platform]);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/calendar', function () use ($ai) {
        $p = request_json();
        json_response(['item' => $ai->scheduleSuggestion($p['objective'] ?? 'increase qualified leads')]);
    });

    $router->post('/api/ai/repurpose', function () use ($ai) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $formats = $p['formats'] ?? ['tweet', 'linkedin_post', 'email', 'instagram_caption'];
        if (method_exists($ai, 'repurposeContent')) {
            json_response(['item' => $ai->repurposeContent($p['content'], $formats)]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/seo-keywords', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'seoKeywordResearch')) {
            json_response(['item' => $ai->seoKeywordResearch($p['topic'] ?? '', $p['niche'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/blog-post', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'blogPostGenerator')) {
            json_response(['item' => $ai->blogPostGenerator($p['title'] ?? '', $p['keywords'] ?? '', $p['outline'] ?? null)]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/hashtags', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'hashtagResearch')) {
            json_response(['item' => $ai->hashtagResearch($p['topic'] ?? '', $p['platform'] ?? 'instagram')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/persona', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'audiencePersona')) {
            json_response(['item' => $ai->audiencePersona($p['demographics'] ?? '', $p['behaviors'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/score', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'contentScore')) {
            json_response(['item' => $ai->contentScore($p['content'] ?? '', $p['platform'] ?? 'instagram')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/subject-lines', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'emailSubjectLines')) {
            json_response(['item' => $ai->emailSubjectLines($p['topic'] ?? '', (int)($p['count'] ?? 10))]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/ad-variations', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'adVariations')) {
            json_response(['item' => $ai->adVariations($p['base_ad'] ?? '', (int)($p['count'] ?? 5))]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/competitor-analysis', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'competitorAnalysis')) {
            json_response(['item' => $ai->competitorAnalysis($p['name'] ?? '', $p['notes'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/report', function () use ($ai, $posts, $campaigns, $analytics) {
        $overview = $analytics->overview(7);
        $stats = [
            'posts_created' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'campaigns_active' => count($campaigns->all()),
            'top_platforms' => $overview['by_platform'] ?? [],
            'ai_research_count' => (int)($overview['ai_usage']['research_count'] ?? 0),
            'ai_ideas_count' => (int)($overview['ai_usage']['ideas_count'] ?? 0),
        ];
        if (method_exists($ai, 'weeklyReport')) {
            json_response(['item' => $ai->weeklyReport($stats)]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/video-script', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'videoScript')) {
            json_response(['item' => $ai->videoScript($p['topic'] ?? '', $p['platform'] ?? 'tiktok', (int)($p['duration'] ?? 60))]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/caption-batch', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'socialCaptionBatch')) {
            $platforms = $p['platforms'] ?? ['instagram', 'twitter', 'linkedin'];
            json_response(['item' => $ai->socialCaptionBatch($p['topic'] ?? '', $platforms, (int)($p['count'] ?? 3))]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/seo-audit', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'seoAudit')) {
            json_response(['item' => $ai->seoAudit($p['url'] ?? '', $p['description'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/social-strategy', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'socialStrategy')) {
            json_response(['item' => $ai->socialStrategy($p['goals'] ?? '', $p['current_state'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    // --- New enhanced AI endpoints ---

    $router->post('/api/ai/refine', function () use ($ai, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $ai->refineContent($p['content'], $p['action'] ?? 'improve', $p['context'] ?? null);
        $analytics->track('ai.refine', 'ai', 0, ['action' => $p['action'] ?? 'improve']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/tone-analysis', function () use ($ai, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $ai->toneAnalysis($p['content']);
        $analytics->track('ai.tone_analysis', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/brief', function () use ($ai, $analytics) {
        $p = request_json();
        $result = $ai->contentBrief($p['topic'] ?? '', $p['content_type'] ?? 'blog_post', $p['goal'] ?? 'drive engagement');
        $analytics->track('ai.brief', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/headlines', function () use ($ai) {
        $p = request_json();
        if (empty($p['headline'])) { json_response(['error' => 'Missing: headline'], 422); return; }
        json_response(['item' => $ai->headlineOptimizer($p['headline'], $p['platform'] ?? 'general')]);
    });

    $router->post('/api/ai/campaign-optimizer', function () use ($ai, $campaigns) {
        $p = request_json();
        $campaignData = $p['campaign_data'] ?? '';
        // If campaign_id provided, auto-gather data
        if (!empty($p['campaign_id'])) {
            $c = $campaigns->find((int)$p['campaign_id']);
            if ($c) {
                $campaignData = "Name: {$c['name']}\nChannel: {$c['channel']}\nObjective: {$c['objective']}\nBudget: \${$c['budget']}\nSpent: \${$c['spend_to_date']}\nRevenue: \${$c['revenue']}\nStart: {$c['start_date']}\nEnd: {$c['end_date']}\nStatus: {$c['status']}\nNotes: {$c['notes']}";
            }
        }
        json_response(['item' => $ai->campaignOptimizer($campaignData, $p['goals'] ?? 'maximize ROI')]);
    });

    $router->post('/api/ai/calendar-month', function () use ($ai) {
        $p = request_json();
        json_response(['item' => $ai->contentCalendarMonth(
            $p['month'] ?? date('F Y'),
            $p['goals'] ?? 'grow audience and engagement',
            $p['channels'] ?? 'instagram, twitter, linkedin, email'
        )]);
    });

    $router->post('/api/ai/smart-times', function () use ($ai) {
        $p = request_json();
        json_response(['item' => $ai->smartPostingTime(
            $p['platform'] ?? 'instagram',
            $p['audience'] ?? 'general business audience',
            $p['content_type'] ?? 'social_post'
        )]);
    });

    $router->post('/api/ai/insights', function () use ($ai, $posts, $campaigns, $analytics) {
        $overview = $analytics->overview(30);
        $stats = [
            'posts_total' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'posts_scheduled' => (int)($overview['posts']['scheduled'] ?? 0),
            'posts_draft' => (int)($overview['posts']['draft'] ?? 0),
            'avg_ai_score' => (int)($overview['posts']['avg_score'] ?? 0),
            'campaigns_count' => count($campaigns->all()),
            'top_platforms' => $overview['by_platform'] ?? [],
            'content_types' => $overview['by_type'] ?? [],
            'ai_research_count' => (int)($overview['ai_usage']['research_count'] ?? 0),
            'ai_ideas_count' => (int)($overview['ai_usage']['ideas_count'] ?? 0),
            'email_campaigns' => (int)($overview['email']['campaigns'] ?? 0),
            'email_sent' => (int)($overview['email']['total_sent'] ?? 0),
            'social_published' => (int)($overview['social']['total_published'] ?? 0),
        ];
        json_response(['item' => $ai->aiInsights($stats)]);
    });

    $router->post('/api/ai/bulk', function () use ($ai) {
        $data = request_json();
        $specs = $data['specs'] ?? [];
        if (empty($specs) || !is_array($specs)) {
            json_response(['error' => 'Missing: specs array'], 422);
            return;
        }
        $results = [];
        foreach (array_slice($specs, 0, 10) as $spec) {
            $results[] = $ai->generateContent($spec);
        }
        json_response(['items' => $results]);
    });

    // Multi-provider endpoint: run same prompt through multiple AI providers
    $router->post('/api/ai/multi', function () use ($ai) {
        $data = request_json();
        $tool = $data['tool'] ?? '';
        $params = $data['params'] ?? [];
        $providers = $data['providers'] ?? [];

        if (empty($tool)) {
            json_response(['error' => 'Missing: tool'], 422);
            return;
        }

        // Map tool names to AiService methods
        $methodMap = [
            'content' => 'generateContent',
            'research' => 'marketResearch',
            'ideas' => 'contentIdeas',
            'blog-post' => 'blogPostGenerator',
            'seo-keywords' => 'seoKeywordResearch',
            'hashtags' => 'hashtagResearch',
            'repurpose' => 'repurposeContent',
            'ad-variations' => 'adVariations',
            'subject-lines' => 'emailSubjectLines',
            'persona' => 'audiencePersona',
            'score' => 'contentScore',
            'calendar' => 'scheduleSuggestion',
            'video-script' => 'videoScript',
            'caption-batch' => 'socialCaptionBatch',
            'seo-audit' => 'seoAudit',
            'social-strategy' => 'socialStrategy',
            'competitor-analysis' => 'competitorAnalysis',
        ];

        if (!isset($methodMap[$tool])) {
            json_response(['error' => 'Unknown tool: ' . $tool], 422);
            return;
        }

        json_response(['item' => ['note' => 'Multi-provider comparison: use the individual endpoints with provider override for each provider.', 'tool' => $tool, 'provider' => $ai->providerStatus()['active_provider']]]);
    });

    // Provider status for frontend
    $router->get('/api/ai/providers', function () use ($ai) {
        json_response($ai->providerStatus());
    });
}
