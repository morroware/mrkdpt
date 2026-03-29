<?php

declare(strict_types=1);

function register_ai_routes(
    Router $router,
    AiService $ai,
    AiContentTools $contentTools,
    AiAnalysisTools $analysisTools,
    AiStrategyTools $strategyTools,
    AiChatService $chatService,
    AiLogRepository $aiLogs,
    Analytics $analytics,
    PostRepository $posts,
    CampaignRepository $campaigns,
    PDO $pdo,
    ?AiMemoryEngine $memoryEngine = null,
    ?AiOrchestrator $orchestrator = null,
    ?AiSearchEngine $searchEngine = null,
    ?AiAgentSystem $agentSystem = null,
): void {

    // Helper: log activity + auto-extract learnings
    $logAi = function (string $tool, string $category, string $inputSummary, $result, string $provider = '') use ($memoryEngine): void {
        if ($memoryEngine === null) return;
        $outputSummary = is_array($result) ? mb_substr(json_encode($result, JSON_UNESCAPED_SLASHES), 0, 800) : mb_substr((string)$result, 0, 800);
        $activityId = $memoryEngine->logActivity($tool, $category, $inputSummary, $outputSummary, $provider);
        // Auto-extract learnings in background (non-blocking for simple tools)
        $memoryEngine->extractAndSaveLearnings($tool, $inputSummary, $outputSummary, $activityId);
    };

    /* ================================================================== */
    /*  CONTENT CREATION                                                  */
    /* ================================================================== */

    $router->post('/api/ai/content', function () use ($contentTools, $analytics, $logAi) {
        $p = request_json();
        $result = $contentTools->generateContent($p);
        $analytics->track('ai.content', 'ai', 0, [
            'type' => $p['content_type'] ?? 'social_post',
            'quality_mode' => $p['quality_mode'] ?? 'enhanced',
            'reviewer_provider' => $result['reviewer_provider'] ?? '',
        ]);
        $logAi('content', 'content', 'topic=' . ($p['topic'] ?? '') . ' platform=' . ($p['platform'] ?? '') . ' type=' . ($p['content_type'] ?? 'social_post'), $result, $result['provider'] ?? '');
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/blog-post', function () use ($contentTools, $analytics, $logAi) {
        $p = request_json();
        $result = $contentTools->blogPostGenerator($p['title'] ?? '', $p['keywords'] ?? '', $p['outline'] ?? null, $p['provider'] ?? null, $p['model'] ?? null);
        $analytics->track('ai.blog_post', 'ai', 0, ['provider' => $result['provider'] ?? '']);
        $logAi('blog-post', 'content', 'title=' . ($p['title'] ?? '') . ' keywords=' . ($p['keywords'] ?? ''), $result, $result['provider'] ?? '');
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/video-script', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['topic'])) { json_response(['error' => 'Missing: topic'], 422); return; }
        $result = $contentTools->videoScript($p['topic'], $p['platform'] ?? 'tiktok', (int)($p['duration'] ?? 60));
        $analytics->track('ai.video_script', 'ai', 0, ['platform' => $p['platform'] ?? 'tiktok']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/caption-batch', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['topic'])) { json_response(['error' => 'Missing: topic'], 422); return; }
        $platforms = $p['platforms'] ?? ['instagram', 'twitter', 'linkedin'];
        $result = $contentTools->socialCaptionBatch($p['topic'], $platforms, (int)($p['count'] ?? 3));
        $analytics->track('ai.caption_batch', 'ai', 0, ['count' => (int)($p['count'] ?? 3)]);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/repurpose', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $formats = $p['formats'] ?? ['tweet', 'linkedin_post', 'email', 'instagram_caption'];
        $result = $contentTools->repurposeContent($p['content'], $formats);
        $analytics->track('ai.repurpose', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/ad-variations', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['base_ad'])) { json_response(['error' => 'Missing: base_ad'], 422); return; }
        $result = $contentTools->adVariations($p['base_ad'], (int)($p['count'] ?? 5));
        $analytics->track('ai.ad_variations', 'ai', 0, ['count' => (int)($p['count'] ?? 5)]);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/subject-lines', function () use ($contentTools, $analytics) {
        $p = request_json();
        $result = $contentTools->emailSubjectLines($p['topic'] ?? '', (int)($p['count'] ?? 10));
        $analytics->track('ai.subject_lines', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/brief', function () use ($contentTools, $analytics) {
        $p = request_json();
        $result = $contentTools->contentBrief($p['topic'] ?? '', $p['content_type'] ?? 'blog_post', $p['goal'] ?? 'drive engagement');
        $analytics->track('ai.brief', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/headlines', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['headline'])) { json_response(['error' => 'Missing: headline'], 422); return; }
        $result = $contentTools->headlineOptimizer($p['headline'], $p['platform'] ?? 'general');
        $analytics->track('ai.headlines', 'ai', 0, ['platform' => $p['platform'] ?? 'general']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/refine', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $contentTools->refineContent($p['content'], $p['action'] ?? 'improve', $p['context'] ?? null);
        $analytics->track('ai.refine', 'ai', 0, ['action' => $p['action'] ?? 'improve']);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  RESEARCH & STRATEGY                                               */
    /* ================================================================== */

    $router->post('/api/ai/research', function () use ($strategyTools, $aiLogs, $analytics, $logAi) {
        $p = request_json();
        $audience = $p['audience'] ?? 'local customers';
        $goal = $p['goal'] ?? 'grow inbound leads';
        $result = $strategyTools->marketResearch($audience, $goal);
        $aiLogs->saveResearch("audience={$audience};goal={$goal}", $result['brief']);
        $analytics->track('ai.research', 'ai', 0, ['provider' => $result['provider']]);
        $logAi('research', 'strategy', "audience={$audience} goal={$goal}", $result, $result['provider']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/ideas', function () use ($strategyTools, $aiLogs, $analytics, $logAi) {
        $p = request_json();
        $topic = $p['topic'] ?? 'seasonal offer';
        $platform = $p['platform'] ?? 'instagram';
        $result = $strategyTools->contentIdeas($topic, $platform);
        $aiLogs->saveIdea($topic, $platform, $result['ideas']);
        $analytics->track('ai.ideas', 'ai', 0, ['platform' => $platform]);
        $logAi('ideas', 'strategy', "topic={$topic} platform={$platform}", $result);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/persona', function () use ($strategyTools, $analytics) {
        $p = request_json();
        $result = $strategyTools->audiencePersona($p['demographics'] ?? '', $p['behaviors'] ?? '');
        $analytics->track('ai.persona', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/competitor-analysis', function () use ($strategyTools, $analytics, $logAi) {
        $p = request_json();
        $result = $strategyTools->competitorAnalysis($p['name'] ?? '', $p['notes'] ?? '');
        $analytics->track('ai.competitor_analysis', 'ai', 0, []);
        $logAi('competitor-analysis', 'strategy', 'competitor=' . ($p['name'] ?? ''), $result);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/social-strategy', function () use ($strategyTools, $analytics, $logAi) {
        $p = request_json();
        $result = $strategyTools->socialStrategy($p['goals'] ?? '', $p['current_state'] ?? '');
        $analytics->track('ai.social_strategy', 'ai', 0, []);
        $logAi('social-strategy', 'strategy', 'goals=' . ($p['goals'] ?? ''), $result);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/calendar', function () use ($strategyTools, $analytics) {
        $p = request_json();
        $result = $strategyTools->scheduleSuggestion($p['objective'] ?? 'increase qualified leads');
        $analytics->track('ai.calendar', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/calendar-month', function () use ($strategyTools, $analytics) {
        $p = request_json();
        $result = $strategyTools->contentCalendarMonth(
            $p['month'] ?? date('F Y'),
            $p['goals'] ?? 'grow audience and engagement',
            $p['channels'] ?? 'instagram, twitter, linkedin, email'
        );
        $analytics->track('ai.calendar_month', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/smart-times', function () use ($strategyTools, $analytics) {
        $p = request_json();
        $result = $strategyTools->smartPostingTime(
            $p['platform'] ?? 'instagram',
            $p['audience'] ?? 'general business audience',
            $p['content_type'] ?? 'social_post'
        );
        $analytics->track('ai.smart_times', 'ai', 0, ['platform' => $p['platform'] ?? 'instagram']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/campaign-optimizer', function () use ($strategyTools, $campaigns, $analytics) {
        $p = request_json();
        $campaignData = $p['campaign_data'] ?? '';
        if (!empty($p['campaign_id'])) {
            $c = $campaigns->find((int)$p['campaign_id']);
            if ($c) {
                $campaignData = "Name: {$c['name']}\nChannel: {$c['channel']}\nObjective: {$c['objective']}\nBudget: \${$c['budget']}\nSpent: \${$c['spend_to_date']}\nRevenue: \${$c['revenue']}\nStart: {$c['start_date']}\nEnd: {$c['end_date']}\nStatus: {$c['status']}\nNotes: {$c['notes']}";
            }
        }
        $result = $strategyTools->campaignOptimizer($campaignData, $p['goals'] ?? 'maximize ROI');
        $analytics->track('ai.campaign_optimizer', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/report', function () use ($strategyTools, $posts, $campaigns, $analytics) {
        $overview = $analytics->overview(7);
        $stats = [
            'posts_created' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'campaigns_active' => count($campaigns->all()),
            'top_platforms' => $overview['by_platform'] ?? [],
            'ai_research_count' => (int)($overview['ai_usage']['research_count'] ?? 0),
            'ai_ideas_count' => (int)($overview['ai_usage']['ideas_count'] ?? 0),
        ];
        json_response(['item' => $strategyTools->weeklyReport($stats)]);
    });

    $router->post('/api/ai/insights', function () use ($strategyTools, $posts, $campaigns, $analytics) {
        $overview = $analytics->overview(30);
        $stats = [
            'posts_total' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'posts_scheduled' => (int)($overview['posts']['scheduled'] ?? 0),
            'posts_draft' => (int)($overview['posts']['drafts'] ?? 0),
            'avg_ai_score' => (int)($overview['posts']['avg_score'] ?? 0),
            'campaigns_count' => count($campaigns->all()),
            'top_platforms' => $overview['by_platform'] ?? [],
            'content_types' => $overview['by_content_type'] ?? [],
            'ai_research_count' => (int)($overview['ai_usage']['research_count'] ?? 0),
            'ai_ideas_count' => (int)($overview['ai_usage']['ideas_count'] ?? 0),
            'email_campaigns' => (int)($overview['email']['campaigns'] ?? 0),
            'email_sent' => (int)($overview['email']['sent'] ?? 0),
            'social_published' => (int)array_sum(array_column($overview['social_publishing'] ?? [], 'success')),
        ];
        json_response(['item' => $strategyTools->aiInsights($stats)]);
    });

    /* ================================================================== */
    /*  ANALYSIS & OPTIMIZATION                                           */
    /* ================================================================== */

    $router->post('/api/ai/tone-analysis', function () use ($analysisTools, $analytics, $logAi) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $analysisTools->toneAnalysis($p['content']);
        $analytics->track('ai.tone_analysis', 'ai', 0, []);
        $logAi('tone-analysis', 'analysis', mb_substr($p['content'], 0, 100), $result);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/score', function () use ($analysisTools, $analytics, $logAi) {
        $p = request_json();
        $result = $analysisTools->contentScore($p['content'] ?? '', $p['platform'] ?? 'instagram');
        $analytics->track('ai.score', 'ai', 0, ['platform' => $p['platform'] ?? 'instagram']);
        $logAi('score', 'analysis', 'platform=' . ($p['platform'] ?? 'instagram') . ' ' . mb_substr($p['content'] ?? '', 0, 80), $result);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/seo-keywords', function () use ($analysisTools, $analytics) {
        $p = request_json();
        $result = $analysisTools->seoKeywordResearch($p['topic'] ?? '', $p['niche'] ?? '');
        $analytics->track('ai.seo_keywords', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/hashtags', function () use ($analysisTools, $analytics) {
        $p = request_json();
        $result = $analysisTools->hashtagResearch($p['topic'] ?? '', $p['platform'] ?? 'instagram');
        $analytics->track('ai.hashtags', 'ai', 0, ['platform' => $p['platform'] ?? 'instagram']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/seo-audit', function () use ($analysisTools, $analytics) {
        $p = request_json();
        $result = $analysisTools->seoAudit($p['url'] ?? '', $p['description'] ?? '');
        $analytics->track('ai.seo_audit', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  NEW TIER 1 FEATURES                                               */
    /* ================================================================== */

    // Content Workflow Engine — full week of coordinated content
    $router->post('/api/ai/workflow', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['topic'])) { json_response(['error' => 'Missing: topic'], 422); return; }
        $platforms = $p['platforms'] ?? ['instagram', 'twitter', 'linkedin', 'email'];
        $result = $contentTools->contentWorkflow($p['topic'], $p['goal'] ?? 'drive engagement', $platforms, (int)($p['days'] ?? 7));
        $analytics->track('ai.workflow', 'ai', 0, ['days' => $p['days'] ?? 7]);
        json_response(['item' => $result]);
    });

    // Brand Voice Auto-Builder
    $router->post('/api/ai/build-brand-voice', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['examples'])) { json_response(['error' => 'Missing: examples (paste 3-5 content samples)'], 422); return; }
        $result = $contentTools->buildBrandVoice($p['examples']);
        $analytics->track('ai.brand_voice', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Content Approval Reviewer — Pre-Flight Check
    $router->post('/api/ai/preflight', function () use ($analysisTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $analysisTools->preFlightCheck($p['content'], $p['platform'] ?? 'general', $p['context'] ?? null);
        $analytics->track('ai.preflight', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // RSS-to-Post Pipeline
    $router->post('/api/ai/rss-to-post', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['title'])) { json_response(['error' => 'Missing: title'], 422); return; }
        $result = $contentTools->rssToPost(
            $p['title'],
            $p['summary'] ?? '',
            $p['url'] ?? '',
            $p['platform'] ?? 'twitter'
        );
        $analytics->track('ai.rss_to_post', 'ai', 0, ['platform' => $p['platform'] ?? 'twitter']);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  NEW TIER 2 FEATURES                                               */
    /* ================================================================== */

    // Email Drip Sequence Generator
    $router->post('/api/ai/drip-sequence', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['goal'])) { json_response(['error' => 'Missing: goal'], 422); return; }
        $result = $contentTools->emailDripSequence($p['goal'], $p['audience'] ?? 'general', (int)($p['count'] ?? 5));
        $analytics->track('ai.drip_sequence', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Content Performance Predictor
    $router->post('/api/ai/predict', function () use ($analysisTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $analysisTools->predictPerformance(
            $p['content'],
            $p['platform'] ?? 'instagram',
            $p['scheduled_time'] ?? null,
            $p['historical_stats'] ?? null
        );
        $analytics->track('ai.predict', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Smart Segmentation
    $router->post('/api/ai/smart-segments', function () use ($strategyTools, $pdo, $analytics) {
        $segments = $pdo->query("SELECT name, description, contact_count FROM audience_segments ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

        $contactStats = [];
        $contactStats['total_contacts'] = (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        $stages = $pdo->query("SELECT stage, COUNT(*) as c FROM contacts GROUP BY stage")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stages as $s) $contactStats['stage_' . $s['stage']] = (int)$s['c'];
        $contactStats['avg_score'] = (int)$pdo->query("SELECT AVG(score) FROM contacts WHERE score > 0")->fetchColumn();
        $contactStats['active_subscribers'] = (int)$pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();

        $result = $strategyTools->smartSegmentation($contactStats, $segments);
        $analytics->track('ai.smart_segments', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Competitor Content Radar
    $router->post('/api/ai/competitor-radar', function () use ($strategyTools, $pdo, $analytics) {
        $competitors = $pdo->query("SELECT name, channel, positioning, recent_activity, opportunity FROM competitors ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($competitors)) { json_response(['error' => 'No competitors tracked. Add competitors first.'], 422); return; }
        $result = $strategyTools->competitorRadar($competitors);
        $analytics->track('ai.competitor_radar', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Multi-Language Localization
    $router->post('/api/ai/localize', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['content']) || empty($p['language'])) { json_response(['error' => 'Missing: content and language'], 422); return; }
        $result = $contentTools->localizeContent($p['content'], $p['language'], $p['platform'] ?? 'general');
        $analytics->track('ai.localize', 'ai', 0, ['language' => $p['language']]);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  NEW TIER 3 FEATURES                                               */
    /* ================================================================== */

    // AI Image Prompt Generator
    $router->post('/api/ai/image-prompts', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $contentTools->imagePromptGenerator($p['content'], $p['platform'] ?? 'instagram', $p['style'] ?? 'modern');
        $analytics->track('ai.image_prompts', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Image Generation (Banana / DALL-E)
    $router->post('/api/ai/generate-image', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['prompt'])) { json_response(['error' => 'Missing: prompt'], 422); return; }
        $result = $contentTools->generateImage($p['prompt'], $p['provider'] ?? 'auto', $p['size'] ?? '1024x1024');
        $analytics->track('ai.generate_image', 'ai', 0, ['provider' => $result['provider'] ?? 'unknown']);
        json_response(['item' => $result]);
    });

    // Multi-source content pipeline (copy + image prompt + image generation)
    $router->post('/api/ai/multi-source-content', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['topic'])) { json_response(['error' => 'Missing: topic'], 422); return; }
        $result = $contentTools->multiSourceContentSuite($p);
        if (!empty($result['error'])) {
            json_response(['error' => $result['error']], 422);
            return;
        }
        $analytics->track('ai.multi_source_content', 'ai', 0, [
            'content_type' => $p['content_type'] ?? 'social_post',
            'platform' => $p['platform'] ?? 'instagram',
        ]);
        json_response(['item' => $result]);
    });

    // Funnel Advisor
    $router->post('/api/ai/funnel-advisor', function () use ($strategyTools, $pdo, $analytics) {
        $p = request_json();
        $funnelId = (int)($p['funnel_id'] ?? 0);
        if (!$funnelId) { json_response(['error' => 'Missing: funnel_id'], 422); return; }

        $stmt = $pdo->prepare("SELECT name FROM funnels WHERE id = :id");
        $stmt->execute([':id' => $funnelId]);
        $funnel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$funnel) { json_response(['error' => 'Funnel not found'], 404); return; }

        $stmt = $pdo->prepare("SELECT * FROM funnel_stages WHERE funnel_id = :id ORDER BY stage_order ASC");
        $stmt->execute([':id' => $funnelId]);
        $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = $strategyTools->funnelAdvisor($funnel['name'], $stages);
        $analytics->track('ai.funnel_advisor', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // A/B Test Auto-Optimizer
    $router->post('/api/ai/ab-generate', function () use ($analysisTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $analysisTools->generateAbVariants($p['content'], $p['test_type'] ?? 'content', (int)($p['variants'] ?? 3));
        $analytics->track('ai.ab_generate', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/ab-analyze', function () use ($analysisTools, $pdo, $analytics) {
        $p = request_json();
        $testId = (int)($p['test_id'] ?? 0);
        if (!$testId) { json_response(['error' => 'Missing: test_id'], 422); return; }
        $stmt = $pdo->prepare("SELECT variant_name, impressions, conversions FROM ab_variants WHERE test_id = :id");
        $stmt->execute([':id' => $testId]);
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($variants)) { json_response(['error' => 'No variants found'], 404); return; }
        $result = $analysisTools->analyzeAbResults($variants);
        $analytics->track('ai.ab_analyze', 'ai', 0, ['test_id' => $testId]);
        json_response(['item' => $result]);
    });

    // Smart UTM & Attribution AI
    $router->post('/api/ai/smart-utm', function () use ($strategyTools, $analytics) {
        $p = request_json();
        if (empty($p['campaign_name']) || empty($p['url'])) { json_response(['error' => 'Missing: campaign_name and url'], 422); return; }
        $result = $strategyTools->smartUtm($p['campaign_name'], $p['url'], $p['channel'] ?? '', $p['description'] ?? '');
        $analytics->track('ai.smart_utm', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Weekly Standup Digest
    $router->post('/api/ai/standup', function () use ($strategyTools, $posts, $campaigns, $analytics, $pdo) {
        $overview = $analytics->overview(7);
        $lastWeekStats = [
            'posts_created' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'campaigns_active' => count($campaigns->all()),
            'platforms' => $overview['by_platform'] ?? [],
            'email_sent' => (int)($overview['email']['sent'] ?? 0),
        ];

        $scheduled = $pdo->query("SELECT title, platform, scheduled_for FROM posts WHERE status = 'scheduled' AND scheduled_for IS NOT NULL ORDER BY scheduled_for ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        $pending = [];
        $draftCount = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
        if ($draftCount > 0) $pending[] = ['type' => 'drafts', 'description' => "{$draftCount} posts still in draft"];
        $queuedCount = (int)$pdo->query("SELECT COUNT(*) FROM social_queue WHERE status = 'queued'")->fetchColumn();
        if ($queuedCount > 0) $pending[] = ['type' => 'queue', 'description' => "{$queuedCount} items in publish queue"];

        $result = $strategyTools->weeklyStandup($lastWeekStats, $scheduled, $pending);
        $analytics->track('ai.standup', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  CONVERSATIONAL AI CHAT                                            */
    /* ================================================================== */

    $router->post('/api/ai/chat', function () use ($chatService, $pdo, $analytics) {
        $p = request_json();
        $message = $p['message'] ?? '';
        if ($message === '') { json_response(['error' => 'Missing: message'], 422); return; }

        $conversationId = (int)($p['conversation_id'] ?? 0);
        $history = [];

        // Load conversation history
        if ($conversationId > 0) {
            $stmt = $pdo->prepare("SELECT role, content FROM ai_chat_messages WHERE conversation_id = :id ORDER BY id ASC");
            $stmt->execute([':id' => $conversationId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Create new conversation
            $stmt = $pdo->prepare("INSERT INTO ai_chat_conversations (title, created_at, updated_at) VALUES (:t, :c, :u)");
            $now = gmdate(DATE_ATOM);
            $title = mb_substr($message, 0, 80);
            $stmt->execute([':t' => $title, ':c' => $now, ':u' => $now]);
            $conversationId = (int)$pdo->lastInsertId();
        }

        // Get reply
        $contentBrief = is_array($p['content_brief'] ?? null) ? $p['content_brief'] : null;
        $result = $chatService->chat($message, $history, $p['provider'] ?? null, $p['model'] ?? null, $contentBrief);

        // Save messages
        $now = gmdate(DATE_ATOM);
        $stmt = $pdo->prepare("INSERT INTO ai_chat_messages (conversation_id, role, content, provider, created_at) VALUES (:cid, :role, :content, :provider, :ca)");
        $stmt->execute([':cid' => $conversationId, ':role' => 'user', ':content' => $message, ':provider' => '', ':ca' => $now]);
        $stmt->execute([':cid' => $conversationId, ':role' => 'assistant', ':content' => $result['reply'], ':provider' => $result['provider'], ':ca' => $now]);

        // Update conversation
        $pdo->prepare("UPDATE ai_chat_conversations SET message_count = message_count + 2, updated_at = :u, provider = :p WHERE id = :id")
            ->execute([':u' => $now, ':p' => $result['provider'], ':id' => $conversationId]);

        $analytics->track('ai.chat', 'ai', 0, ['provider' => $result['provider']]);

        json_response(['item' => [
            'reply'           => $result['reply'],
            'conversation_id' => $conversationId,
            'provider'        => $result['provider'],
            'context_used'    => $result['context_used'],
        ]]);
    });

    // List conversations
    $router->get('/api/ai/conversations', function () use ($pdo) {
        $rows = $pdo->query("SELECT id, title, provider, message_count, created_at, updated_at FROM ai_chat_conversations ORDER BY updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        json_response(['items' => $rows]);
    });

    // Get conversation messages
    $router->get('/api/ai/conversations/{id}', function (array $params) use ($pdo) {
        $id = (int)($params['id'] ?? 0);
        $conv = $pdo->prepare("SELECT * FROM ai_chat_conversations WHERE id = :id");
        $conv->execute([':id' => $id]);
        $conversation = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conversation) { json_response(['error' => 'Not found'], 404); return; }

        $msgs = $pdo->prepare("SELECT role, content, provider, created_at FROM ai_chat_messages WHERE conversation_id = :id ORDER BY id ASC");
        $msgs->execute([':id' => $id]);

        json_response(['item' => $conversation, 'messages' => $msgs->fetchAll(PDO::FETCH_ASSOC)]);
    });

    // Rename conversation
    $router->put('/api/ai/conversations/{id}', function (array $params) use ($pdo) {
        $id = (int)($params['id'] ?? 0);
        $data = request_json();
        $title = trim($data['title'] ?? '');
        if ($title === '') { json_response(['error' => 'Title required'], 422); return; }
        $pdo->prepare("UPDATE ai_chat_conversations SET title = :t, updated_at = :u WHERE id = :id")->execute([
            ':t' => mb_substr($title, 0, 200),
            ':u' => gmdate(DATE_ATOM),
            ':id' => $id,
        ]);
        json_response(['ok' => true, 'title' => $title]);
    });

    // Delete conversation
    $router->delete('/api/ai/conversations/{id}', function (array $params) use ($pdo) {
        $id = (int)($params['id'] ?? 0);
        $pdo->prepare("DELETE FROM ai_chat_messages WHERE conversation_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM ai_chat_conversations WHERE id = :id")->execute([':id' => $id]);
        json_response(['ok' => true]);
    });

    /* ================================================================== */
    /*  SHARED MEMORY (GLOBAL AI CONTEXT)                                 */
    /* ================================================================== */

    $router->get('/api/ai/shared-memory', function () use ($pdo) {
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $stmt = $pdo->prepare("SELECT id, memory_key, content, source, source_ref, tags, metadata_json, created_at, updated_at FROM ai_shared_memory ORDER BY updated_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    $router->post('/api/ai/shared-memory', function () use ($pdo, $ai) {
        $p = request_json();
        $content = trim((string)($p['content'] ?? ''));
        if ($content === '') { json_response(['error' => 'Missing: content'], 422); return; }

        $now = gmdate(DATE_ATOM);
        $stmt = $pdo->prepare("INSERT INTO ai_shared_memory (memory_key, content, source, source_ref, tags, metadata_json, created_at, updated_at)
                               VALUES (:memory_key, :content, :source, :source_ref, :tags, :metadata_json, :created_at, :updated_at)");
        $stmt->execute([
            ':memory_key' => trim((string)($p['memory_key'] ?? '')),
            ':content' => $content,
            ':source' => trim((string)($p['source'] ?? 'manual')),
            ':source_ref' => trim((string)($p['source_ref'] ?? '')),
            ':tags' => trim((string)($p['tags'] ?? '')),
            ':metadata_json' => json_encode($p['metadata'] ?? new stdClass(), JSON_UNESCAPED_SLASHES),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $items = $pdo->query("SELECT memory_key, content, source, tags, updated_at FROM ai_shared_memory ORDER BY updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $ai->setSharedMemory($items);

        $id = (int)$pdo->lastInsertId();
        $row = $pdo->prepare("SELECT id, memory_key, content, source, source_ref, tags, metadata_json, created_at, updated_at FROM ai_shared_memory WHERE id = :id");
        $row->execute([':id' => $id]);
        json_response(['item' => $row->fetch(PDO::FETCH_ASSOC)], 201);
    });

    $router->put('/api/ai/shared-memory/{id}', function (array $params) use ($pdo, $ai) {
        $id = (int)($params['id'] ?? 0);
        $existing = $pdo->prepare("SELECT * FROM ai_shared_memory WHERE id = :id");
        $existing->execute([':id' => $id]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$row) { json_response(['error' => 'Not found'], 404); return; }

        $p = request_json();
        $content = array_key_exists('content', $p) ? trim((string)$p['content']) : trim((string)$row['content']);
        if ($content === '') { json_response(['error' => 'content cannot be empty'], 422); return; }

        $stmt = $pdo->prepare("UPDATE ai_shared_memory
                               SET memory_key = :memory_key, content = :content, source = :source, source_ref = :source_ref, tags = :tags,
                                   metadata_json = :metadata_json, updated_at = :updated_at
                               WHERE id = :id");
        $stmt->execute([
            ':id' => $id,
            ':memory_key' => array_key_exists('memory_key', $p) ? trim((string)$p['memory_key']) : $row['memory_key'],
            ':content' => $content,
            ':source' => array_key_exists('source', $p) ? trim((string)$p['source']) : $row['source'],
            ':source_ref' => array_key_exists('source_ref', $p) ? trim((string)$p['source_ref']) : $row['source_ref'],
            ':tags' => array_key_exists('tags', $p) ? trim((string)$p['tags']) : $row['tags'],
            ':metadata_json' => array_key_exists('metadata', $p) ? json_encode($p['metadata'] ?? new stdClass(), JSON_UNESCAPED_SLASHES) : $row['metadata_json'],
            ':updated_at' => gmdate(DATE_ATOM),
        ]);

        $items = $pdo->query("SELECT memory_key, content, source, tags, updated_at FROM ai_shared_memory ORDER BY updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $ai->setSharedMemory($items);

        $fresh = $pdo->prepare("SELECT id, memory_key, content, source, source_ref, tags, metadata_json, created_at, updated_at FROM ai_shared_memory WHERE id = :id");
        $fresh->execute([':id' => $id]);
        json_response(['item' => $fresh->fetch(PDO::FETCH_ASSOC)]);
    });

    $router->delete('/api/ai/shared-memory/{id}', function (array $params) use ($pdo, $ai) {
        $id = (int)($params['id'] ?? 0);
        $pdo->prepare("DELETE FROM ai_shared_memory WHERE id = :id")->execute([':id' => $id]);
        $items = $pdo->query("SELECT memory_key, content, source, tags, updated_at FROM ai_shared_memory ORDER BY updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $ai->setSharedMemory($items);
        json_response(['ok' => true]);
    });

    /* ================================================================== */
    /*  BULK / MULTI / PROVIDER STATUS                                    */
    /* ================================================================== */

    $router->post('/api/ai/bulk', function () use ($contentTools) {
        $data = request_json();
        $specs = $data['specs'] ?? [];
        if (empty($specs) || !is_array($specs)) { json_response(['error' => 'Missing: specs array'], 422); return; }
        $results = [];
        foreach (array_slice($specs, 0, 10) as $spec) {
            $results[] = $contentTools->generateContent($spec);
        }
        json_response(['items' => $results]);
    });

    $router->post('/api/ai/multi', function () use ($ai) {
        $data = request_json();
        $prompt = $data['prompt'] ?? '';
        $providers = $data['providers'] ?? [];
        if (empty($prompt) || empty($providers)) { json_response(['error' => 'Missing: prompt and providers'], 422); return; }
        $results = $ai->generateMulti($ai->buildSystemPrompt(), $prompt, $providers);
        json_response(['item' => ['results' => $results]]);
    });

    $router->post('/api/ai/collaborate', function () use ($ai) {
        $data = request_json();
        $goal = trim((string)($data['goal'] ?? ''));
        if ($goal === '') { json_response(['error' => 'Missing: goal'], 422); return; }
        $providers = is_array($data['providers'] ?? null) ? $data['providers'] : [];
        $context = (string)($data['context'] ?? '');
        $item = $ai->collaboratePlan($goal, $providers, $context);
        json_response(['item' => $item]);
    });

    $router->get('/api/ai/providers', function () use ($ai) {
        json_response($ai->providerStatus());
    });

    /* ================================================================== */
    /*  AI BRAIN — Self-awareness, Activity, Learnings, Pipelines          */
    /* ================================================================== */

    // AI Brain status — self-reflection endpoint
    $router->get('/api/ai/brain/status', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        json_response(['item' => $memoryEngine->selfReflect()]);
    });

    // Activity log
    $router->get('/api/ai/brain/activity', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['items' => []]); return; }
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $category = !empty($_GET['category']) ? $_GET['category'] : null;
        json_response(['items' => $memoryEngine->getRecentActivity($limit, $category)]);
    });

    // Activity stats
    $router->get('/api/ai/brain/stats', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['item' => []]); return; }
        $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
        json_response(['item' => $memoryEngine->getActivityStats($days)]);
    });

    // Learnings CRUD
    $router->get('/api/ai/brain/learnings', function () use ($pdo) {
        $category = !empty($_GET['category']) ? $_GET['category'] : null;
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $sql = "SELECT id, category, insight, confidence, source_tool, times_reinforced, last_used_at, created_at, updated_at FROM ai_learnings";
        $params = [];
        if ($category) {
            $sql .= " WHERE category = :cat";
            $params[':cat'] = $category;
        }
        $sql .= " ORDER BY (confidence * (1 + times_reinforced)) DESC, updated_at DESC LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    $router->delete('/api/ai/brain/learnings/{id}', function (array $params) use ($pdo) {
        $id = (int)($params['id'] ?? 0);
        $pdo->prepare("DELETE FROM ai_learnings WHERE id = :id")->execute([':id' => $id]);
        json_response(['ok' => true]);
    });

    // Performance feedback
    $router->post('/api/ai/brain/feedback', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        $p = request_json();
        if (empty($p['entity_type']) || empty($p['entity_id']) || empty($p['metric_name'])) {
            json_response(['error' => 'Missing: entity_type, entity_id, metric_name'], 422); return;
        }
        $memoryEngine->recordPerformanceFeedback(
            $p['entity_type'],
            (int)$p['entity_id'],
            $p['metric_name'],
            (float)($p['metric_value'] ?? 0),
            $p['feedback_note'] ?? '',
            isset($p['activity_id']) ? (int)$p['activity_id'] : null,
        );
        json_response(['ok' => true], 201);
    });

    $router->get('/api/ai/brain/feedback', function () use ($pdo) {
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $stmt = $pdo->prepare(
            "SELECT f.*, a.tool_name, a.input_summary FROM ai_performance_feedback f
             LEFT JOIN ai_activity_log a ON f.activity_id = a.id
             ORDER BY f.created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    // Capture post performance (manual trigger)
    $router->post('/api/ai/brain/capture-performance', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        $captured = $memoryEngine->capturePostPerformance();
        json_response(['item' => ['captured' => $captured]]);
    });

    // Initialize brain from onboarding profile
    $router->post('/api/ai/brain/initialize', function () use ($memoryEngine, $pdo) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        $profile = $pdo->query("SELECT * FROM business_profile ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$profile) { json_response(['error' => 'No business profile found. Complete onboarding first.'], 404); return; }
        $result = $memoryEngine->initializeFromOnboarding($profile);
        json_response(['item' => $result]);
    });

    // Daily briefing — smart morning briefing
    $router->get('/api/ai/brain/briefing', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        $briefing = $memoryEngine->generateDailyBriefing();
        json_response(['item' => $briefing]);
    });

    // Proactive recommendations
    $router->get('/api/ai/brain/recommendations', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        $recs = $memoryEngine->generateProactiveRecommendations();
        json_response(['items' => $recs]);
    });

    // Knowledge base
    $router->get('/api/ai/brain/knowledge', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        $kb = $memoryEngine->getKnowledgeBase();
        json_response(['item' => $kb]);
    });

    // Add manual learning
    $router->post('/api/ai/brain/learnings', function () use ($memoryEngine) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        $p = request_json();
        if (empty($p['category']) || empty($p['insight'])) {
            json_response(['error' => 'Missing: category, insight'], 422); return;
        }
        $id = $memoryEngine->addManualLearning(
            $p['category'],
            $p['insight'],
            (float)($p['confidence'] ?? 0.85),
        );
        json_response(['item' => ['id' => $id]], 201);
    });

    // Execute a tool by name (for brain/agent programmatic access)
    $router->post('/api/ai/brain/execute-tool', function () use ($memoryEngine, $contentTools, $analysisTools, $strategyTools) {
        if (!$memoryEngine) { json_response(['error' => 'Memory engine not available'], 500); return; }
        $p = request_json();
        $toolName = $p['tool'] ?? '';
        $input = $p['input'] ?? [];
        if ($toolName === '') { json_response(['error' => 'Missing: tool'], 422); return; }
        $result = $memoryEngine->executeToolByName($toolName, $input, $contentTools, $analysisTools, $strategyTools);
        if ($result === null) { json_response(['error' => "Tool '{$toolName}' not found or failed"], 404); return; }
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  AI PIPELINES — Tool chaining & orchestration                       */
    /* ================================================================== */

    // List pipeline templates
    $router->get('/api/ai/pipelines/templates', function () use ($orchestrator) {
        if (!$orchestrator) { json_response(['items' => []]); return; }
        $templates = $orchestrator->getTemplates();
        $items = [];
        foreach ($templates as $id => $tpl) {
            $items[] = [
                'id'          => $id,
                'name'        => $tpl['name'],
                'description' => $tpl['description'],
                'step_count'  => count($tpl['steps']),
                'steps'       => array_map(fn($s) => ['tool' => $s['tool'], 'label' => $s['label']], $tpl['steps']),
            ];
        }
        json_response(['items' => $items]);
    });

    // Run a pipeline
    $router->post('/api/ai/pipelines/run', function () use ($orchestrator, $analytics) {
        if (!$orchestrator) { json_response(['error' => 'Orchestrator not available'], 500); return; }
        $p = request_json();
        $templateId = $p['template_id'] ?? '';
        $variables = $p['variables'] ?? [];
        $customSteps = $p['steps'] ?? [];

        if ($templateId === '' && empty($customSteps)) {
            json_response(['error' => 'Missing: template_id or steps'], 422); return;
        }

        $result = $orchestrator->runPipeline($templateId, $variables, $customSteps);
        $analytics->track('ai.pipeline', 'ai', 0, ['template' => $templateId, 'status' => $result['status'] ?? '']);
        json_response(['item' => $result]);
    });

    // Get next action suggestions
    $router->get('/api/ai/pipelines/next-actions', function () use ($orchestrator) {
        if (!$orchestrator) { json_response(['items' => []]); return; }
        $tool = $_GET['tool'] ?? '';
        if ($tool === '') { json_response(['items' => []]); return; }
        json_response(['items' => $orchestrator->suggestNextActions($tool)]);
    });

    // Pipeline run history
    $router->get('/api/ai/pipelines/runs', function () use ($orchestrator) {
        if (!$orchestrator) { json_response(['items' => []]); return; }
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
        json_response(['items' => $orchestrator->getRecentRuns($limit)]);
    });

    // Pipeline run details
    $router->get('/api/ai/pipelines/runs/{id}', function (array $params) use ($orchestrator) {
        if (!$orchestrator) { json_response(['error' => 'Not available'], 500); return; }
        $id = (int)($params['id'] ?? 0);
        $run = $orchestrator->getRunDetails($id);
        if (!$run) { json_response(['error' => 'Not found'], 404); return; }
        json_response(['item' => $run]);
    });

    // Tool registry (for frontend pipeline builder)
    $router->get('/api/ai/pipelines/tools', function () use ($orchestrator) {
        if (!$orchestrator) { json_response(['items' => []]); return; }
        json_response(['items' => $orchestrator->getToolRegistry()]);
    });

    /* ================================================================== */
    /*  AI SEARCH — Unified search across data, web, and websites          */
    /* ================================================================== */

    $router->post('/api/ai/search', function () use ($searchEngine, $memoryEngine, $pdo, $logAi) {
        if (!$searchEngine) { json_response(['error' => 'Search engine not available'], 500); return; }
        $p = request_json();
        $query = trim((string)($p['query'] ?? ''));
        if ($query === '') { json_response(['error' => 'Missing: query'], 422); return; }

        $sources = $p['sources'] ?? ['internal'];
        $url = trim((string)($p['url'] ?? ''));

        $results = $searchEngine->search($query, $sources, $url);

        // Save search to history
        try {
            $stmt = $pdo->prepare("INSERT INTO ai_search_history (query, sources, url, results_count, summary, created_at) VALUES (:q, :s, :u, :c, :sum, :now)");
            $stmt->execute([
                ':q' => $query, ':s' => implode(',', $sources), ':u' => $url,
                ':c' => $results['total_results'], ':sum' => mb_substr($results['summary'] ?? '', 0, 500),
                ':now' => gmdate(DATE_ATOM),
            ]);
        } catch (\PDOException $e) { /* ignore */ }

        $logAi('search', 'research', "query={$query} sources=" . implode(',', $sources), $results['summary'] ?? '');
        json_response(['item' => $results]);
    });

    $router->get('/api/ai/search/history', function () use ($pdo) {
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        $stmt = $pdo->prepare("SELECT id, query, sources, url, results_count, summary, created_at FROM ai_search_history ORDER BY created_at DESC LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    /* ================================================================== */
    /*  AI AGENTS — Multi-agent task system                                */
    /* ================================================================== */

    // Get agent types
    $router->get('/api/ai/agents/types', function () use ($agentSystem) {
        if (!$agentSystem) { json_response(['items' => []]); return; }
        $types = $agentSystem->getAgentTypes();
        $items = [];
        foreach ($types as $id => $type) {
            $items[] = array_merge(['id' => $id], $type);
        }
        json_response(['items' => $items]);
    });

    // Create a new agent task
    $router->post('/api/ai/agents/tasks', function () use ($agentSystem, $analytics) {
        if (!$agentSystem) { json_response(['error' => 'Agent system not available'], 500); return; }
        $p = request_json();
        $goal = trim((string)($p['goal'] ?? ''));
        if ($goal === '') { json_response(['error' => 'Missing: goal'], 422); return; }

        $context = trim((string)($p['context'] ?? ''));
        $modelConfig = $p['model_config'] ?? [];
        $autoApprove = (bool)($p['auto_approve'] ?? false);

        $result = $agentSystem->createTask($goal, $context, $modelConfig, $autoApprove);
        $analytics->track('ai.agent_task', 'ai', 0, ['steps' => $result['steps_total'] ?? 0]);
        json_response(['item' => $result], 201);
    });

    // List recent agent tasks
    $router->get('/api/ai/agents/tasks', function () use ($agentSystem) {
        if (!$agentSystem) { json_response(['items' => []]); return; }
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        json_response(['items' => $agentSystem->getRecentTasks($limit)]);
    });

    // Get task details
    $router->get('/api/ai/agents/tasks/{id}', function (array $params) use ($agentSystem) {
        if (!$agentSystem) { json_response(['error' => 'Not available'], 500); return; }
        $id = (int)($params['id'] ?? 0);
        $task = $agentSystem->getTaskDetails($id);
        if (!$task) { json_response(['error' => 'Not found'], 404); return; }
        json_response(['item' => $task]);
    });

    // Execute next step
    $router->post('/api/ai/agents/tasks/{id}/execute', function (array $params) use ($agentSystem) {
        if (!$agentSystem) { json_response(['error' => 'Not available'], 500); return; }
        $id = (int)($params['id'] ?? 0);
        $result = $agentSystem->executeNextStep($id);
        json_response(['item' => $result]);
    });

    // Execute all remaining steps
    $router->post('/api/ai/agents/tasks/{id}/execute-all', function (array $params) use ($agentSystem) {
        if (!$agentSystem) { json_response(['error' => 'Not available'], 500); return; }
        $id = (int)($params['id'] ?? 0);
        $result = $agentSystem->executeAll($id);
        json_response(['item' => $result]);
    });

    // Approve step
    $router->post('/api/ai/agents/tasks/{id}/approve', function (array $params) use ($agentSystem) {
        if (!$agentSystem) { json_response(['error' => 'Not available'], 500); return; }
        $id = (int)($params['id'] ?? 0);
        $p = request_json();
        $result = $agentSystem->approveStep($id, trim((string)($p['feedback'] ?? '')));
        json_response(['item' => $result]);
    });

    // Reject/revise step
    $router->post('/api/ai/agents/tasks/{id}/reject', function (array $params) use ($agentSystem) {
        if (!$agentSystem) { json_response(['error' => 'Not available'], 500); return; }
        $id = (int)($params['id'] ?? 0);
        $p = request_json();
        $result = $agentSystem->rejectStep($id, trim((string)($p['reason'] ?? '')));
        json_response(['item' => $result]);
    });

    // Cancel task
    $router->post('/api/ai/agents/tasks/{id}/cancel', function (array $params) use ($agentSystem) {
        if (!$agentSystem) { json_response(['error' => 'Not available'], 500); return; }
        $id = (int)($params['id'] ?? 0);
        $result = $agentSystem->cancelTask($id);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  MODEL ROUTING — Task-type-specific provider/model configuration    */
    /* ================================================================== */

    $router->get('/api/ai/model-routing', function () use ($pdo, $ai) {
        $routing = AiService::getModelRouting($pdo);
        $taskTypes = AiService::MODEL_TASK_TYPES;
        json_response(['item' => [
            'routing' => $routing,
            'task_types' => $taskTypes,
            'providers' => $ai->providerStatus(),
        ]]);
    });

    $router->post('/api/ai/model-routing', function () use ($pdo) {
        $p = request_json();
        $taskType = trim((string)($p['task_type'] ?? ''));
        $provider = trim((string)($p['provider'] ?? ''));
        $model = trim((string)($p['model'] ?? ''));
        if ($taskType === '' || $provider === '') {
            json_response(['error' => 'Missing: task_type and provider'], 422); return;
        }
        AiService::saveModelRouting($pdo, $taskType, $provider, $model, $p['label'] ?? '');
        json_response(['ok' => true, 'item' => AiService::getModelRouting($pdo)]);
    });

    $router->delete('/api/ai/model-routing/{taskType}', function (array $params) use ($pdo) {
        $taskType = $params['taskType'] ?? '';
        AiService::deleteModelRouting($pdo, $taskType);
        json_response(['ok' => true]);
    });

    /* ================================================================== */
    /*  EXTENDED AI ROUTES                                                */
    /* ================================================================== */

    // Daily Action Queue
    $router->post('/api/ai/daily-actions', function () use ($ai, $pdo, $memoryEngine, $logAi) {
        $brainCtx = $memoryEngine ? $memoryEngine->buildBrainContext() : '';

        $drafts = $pdo->query("SELECT id, title, platform FROM posts WHERE status = 'draft' ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $scheduled = $pdo->query("SELECT id, title, platform, scheduled_for FROM posts WHERE status = 'scheduled' AND scheduled_for <= datetime('now', '+48 hours') ORDER BY scheduled_for LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $campaigns_data = $pdo->query("SELECT id, name, status FROM campaigns WHERE status = 'active' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $subCount = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();
        $emailCampaigns = $pdo->query("SELECT id, name, status FROM email_campaigns WHERE status = 'draft' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

        $ctx = "TODAY: " . date('l, F j, Y') . "\n\nDRAFT POSTS:\n";
        foreach ($drafts as $d) $ctx .= "- #{$d['id']} \"{$d['title']}\" ({$d['platform']})\n";
        $ctx .= "\nUPCOMING SCHEDULED (next 48h):\n";
        foreach ($scheduled as $s) $ctx .= "- #{$s['id']} \"{$s['title']}\" scheduled {$s['scheduled_for']}\n";
        $ctx .= "\nACTIVE CAMPAIGNS:\n";
        foreach ($campaigns_data as $c) $ctx .= "- #{$c['id']} \"{$c['name']}\"\n";
        $ctx .= "\nSUBSCRIBERS: {$subCount} active\n";
        $ctx .= "DRAFT EMAILS:\n";
        foreach ($emailCampaigns as $e) $ctx .= "- #{$e['id']} \"{$e['name']}\"\n";

        $prompt = "Based on this marketing data, generate exactly 5 prioritized daily actions as a JSON array. Each object must have: id (1-5), priority (high/medium/low), title (short), description (1 sentence), action_type (publish_draft|review_scheduled|create_content|send_email|analyze_performance|engage_audience), entity_id (number or null), entity_type (post|campaign|email|null).\n\n{$ctx}\n\nReturn ONLY valid JSON array.";
        $system = "You are an AI marketing assistant that creates actionable daily to-do lists. {$brainCtx}\nRespond with ONLY a valid JSON array, no other text.";

        $result = $ai->generateAdvanced($system, $prompt);
        $actions = json_decode($result, true);
        if (!$actions && preg_match('/\[[\s\S]*\]/m', $result, $m)) {
            $actions = json_decode($m[0], true);
        }
        $logAi('daily-actions', 'strategy', 'Generated daily action queue', $result);
        json_response(['item' => ['actions' => $actions ?: [], 'raw' => $result]]);
    });

    // One-Click Repurpose Chain
    $router->post('/api/ai/repurpose-chain', function () use ($ai, $memoryEngine, $logAi) {
        $p = request_json();
        $content = trim((string)($p['content'] ?? ''));
        $title = trim((string)($p['title'] ?? ''));
        $platforms = $p['platforms'] ?? ['twitter', 'instagram', 'linkedin', 'facebook', 'email'];
        if ($content === '') { json_response(['error' => 'content is required'], 422); return; }

        $brainCtx = $memoryEngine ? $memoryEngine->buildBrainContext() : '';
        $platformList = implode(', ', $platforms);

        $prompt = "Repurpose this content for these platforms: {$platformList}.\n\nOriginal content:\n{$content}\n\nFor each platform, create an adapted version respecting platform constraints:\n- Twitter: max 280 chars, concise, hashtags\n- Instagram: engaging caption, emojis, 5-10 hashtags\n- LinkedIn: professional tone, thought leadership\n- Facebook: conversational, question-driven\n- Email: subject line + body snippet\n\nReturn a JSON array where each object has: platform, content, hashtags (string of hashtags or empty).";
        $system = "You are a content repurposing expert. {$brainCtx}\nReturn ONLY a valid JSON array.";

        $result = $ai->generateAdvanced($system, $prompt);
        $variants = json_decode($result, true);
        if (!$variants && preg_match('/\[[\s\S]*\]/m', $result, $m)) {
            $variants = json_decode($m[0], true);
        }
        $logAi('repurpose-chain', 'content', "Repurposed content for {$platformList}", $result);
        json_response(['item' => ['variants' => $variants ?: [], 'raw' => $result]]);
    });

    // Calendar Auto-Fill
    $router->post('/api/ai/calendar-autofill', function () use ($ai, $pdo, $memoryEngine, $logAi) {
        $p = request_json();
        $period = ($p['period'] ?? 'week') === 'month' ? 'month' : 'week';
        $startDate = $p['start_date'] ?? date('Y-m-d');
        $brainCtx = $memoryEngine ? $memoryEngine->buildBrainContext() : '';

        $bizName = app_config('BUSINESS_NAME', '');
        $industry = app_config('BUSINESS_INDUSTRY', '');

        $days = $period === 'month' ? 28 : 7;
        $prompt = "Create a {$period}ly content calendar starting {$startDate} for a {$industry} business called \"{$bizName}\". Generate {$days} social media posts (1 per day) with variety: mix of promotional, educational, engagement, and storytelling content across platforms (instagram, twitter, linkedin, facebook).\n\nReturn a JSON array of objects with: title, body (the post content), platform, scheduled_for (YYYY-MM-DD HH:MM:SS format, spread across the {$days} days at optimal times), content_type (social_post).\n\n{$brainCtx}\nReturn ONLY valid JSON array.";
        $system = "You are a content calendar expert. Create diverse, engaging content. Return ONLY a valid JSON array.";

        $result = $ai->generateAdvanced($system, $prompt);
        $posts = json_decode($result, true);
        if (!$posts && preg_match('/\[[\s\S]*\]/m', $result, $m)) {
            $posts = json_decode($m[0], true);
        }

        $created = 0;
        if (is_array($posts)) {
            $stmt = $pdo->prepare("INSERT INTO posts (title, body, platform, status, content_type, scheduled_for, created_at) VALUES (?, ?, ?, 'draft', ?, ?, datetime('now'))");
            foreach ($posts as $post) {
                if (!isset($post['title'], $post['body'])) continue;
                $stmt->execute([
                    mb_substr($post['title'], 0, 200),
                    mb_substr($post['body'], 0, 5000),
                    $post['platform'] ?? 'instagram',
                    $post['content_type'] ?? 'social_post',
                    $post['scheduled_for'] ?? null,
                ]);
                $created++;
            }
        }
        $logAi('calendar-autofill', 'content', "Auto-filled {$period} calendar: {$created} posts", $result);
        json_response(['item' => ['posts_created' => $created, 'period' => $period]]);
    });

    // Chat Execute (Slash Commands)
    $router->post('/api/ai/chat-execute', function () use ($ai, $pdo, $memoryEngine, $logAi) {
        $p = request_json();
        $command = strtolower(trim((string)($p['command'] ?? '')));
        $args = trim((string)($p['args'] ?? ''));
        $brainCtx = $memoryEngine ? $memoryEngine->buildBrainContext() : '';
        $createdIds = [];
        $message = '';
        $data = null;

        switch ($command) {
            case 'create-post':
                $prompt = "Generate a social media post based on this request: {$args}\nReturn JSON: {title, body, platform (instagram|twitter|linkedin|facebook), tags}";
                $system = "You are a content creator. {$brainCtx}\nReturn ONLY valid JSON object.";
                $result = $ai->generateAdvanced($system, $prompt);
                $post = json_decode($result, true);
                if (!$post && preg_match('/\{[\s\S]*\}/m', $result, $m)) $post = json_decode($m[0], true);
                if ($post && isset($post['body'])) {
                    $stmt = $pdo->prepare("INSERT INTO posts (title, body, platform, status, tags, created_at) VALUES (?, ?, ?, 'draft', ?, datetime('now'))");
                    $stmt->execute([$post['title'] ?? 'AI Post', $post['body'], $post['platform'] ?? 'instagram', $post['tags'] ?? '']);
                    $id = (int)$pdo->lastInsertId();
                    $createdIds[] = $id;
                    $message = "Created draft post #{$id}: \"{$post['title']}\" for {$post['platform']}.\n\n**Content:**\n{$post['body']}";
                } else {
                    $message = "I generated this content but couldn't auto-save:\n\n{$result}";
                }
                break;

            case 'schedule-posts':
                $prompt = "Generate 5 social media posts based on this: {$args}\nReturn JSON array of objects with: title, body, platform, tags, scheduled_for (YYYY-MM-DD HH:MM:SS over the next 7 days at optimal times).";
                $system = "You are a content scheduler. {$brainCtx}\nReturn ONLY valid JSON array.";
                $result = $ai->generateAdvanced($system, $prompt);
                $posts = json_decode($result, true);
                if (!$posts && preg_match('/\[[\s\S]*\]/m', $result, $m)) $posts = json_decode($m[0], true);
                if (is_array($posts)) {
                    $stmt = $pdo->prepare("INSERT INTO posts (title, body, platform, status, tags, scheduled_for, created_at) VALUES (?, ?, ?, 'scheduled', ?, ?, datetime('now'))");
                    foreach ($posts as $post) {
                        if (!isset($post['body'])) continue;
                        $stmt->execute([$post['title'] ?? 'Scheduled Post', $post['body'], $post['platform'] ?? 'instagram', $post['tags'] ?? '', $post['scheduled_for'] ?? null]);
                        $createdIds[] = (int)$pdo->lastInsertId();
                    }
                    $message = "Scheduled " . count($createdIds) . " posts for the next week.";
                } else {
                    $message = "Here's my suggestion:\n\n{$result}";
                }
                break;

            case 'check-analytics':
                $totalPosts = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
                $published = $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
                $scheduled = $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'scheduled'")->fetchColumn();
                $totalCampaigns = $pdo->query("SELECT COUNT(*) FROM campaigns")->fetchColumn();
                $platforms = $pdo->query("SELECT platform, COUNT(*) as count FROM posts WHERE status = 'published' GROUP BY platform ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

                $analyticsCtx = "Total posts: {$totalPosts}, Published: {$published}, Scheduled: {$scheduled}, Campaigns: {$totalCampaigns}\n";
                foreach ($platforms as $pl) $analyticsCtx .= "- {$pl['platform']}: {$pl['count']} published\n";

                $prompt = "Analyze these marketing analytics and provide insights: {$args}\n\n{$analyticsCtx}\n\nProvide a concise analysis with recommendations.";
                $system = "You are a marketing analyst. {$brainCtx}";
                $message = $ai->generateAdvanced($system, $prompt);
                break;

            case 'optimize-campaign':
                $activeCampaigns = $pdo->query("SELECT * FROM campaigns WHERE status = 'active' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
                $ctx = '';
                foreach ($activeCampaigns as $c) $ctx .= "Campaign: {$c['name']}, Channel: {$c['channel']}, Budget: {$c['budget']}\n";
                $prompt = "Optimize these campaigns: {$args}\n\n{$ctx}\nProvide specific, actionable optimization recommendations.";
                $system = "You are a campaign optimizer. {$brainCtx}";
                $message = $ai->generateAdvanced($system, $prompt);
                break;

            default:
                $message = "Unknown command: /{$command}. Available: /create-post, /schedule-posts, /check-analytics, /optimize-campaign";
        }

        $logAi('chat-execute', 'strategy', "Executed /{$command}: {$args}", $message);
        json_response(['item' => ['message' => $message, 'created_ids' => $createdIds, 'data' => $data]]);
    });

    // Performance Patterns
    $router->post('/api/ai/performance-patterns', function () use ($ai, $pdo, $memoryEngine, $logAi) {
        $brainCtx = $memoryEngine ? $memoryEngine->buildBrainContext() : '';
        $posts = $pdo->query("SELECT title, platform, content_type, ai_score, status, created_at FROM posts WHERE status = 'published' ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $feedback = $pdo->query("SELECT * FROM ai_performance_feedback ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

        $ctx = "PUBLISHED POSTS:\n";
        foreach ($posts as $p) $ctx .= "- \"{$p['title']}\" ({$p['platform']}, {$p['content_type']}, score:{$p['ai_score']}) on {$p['created_at']}\n";
        $ctx .= "\nPERFORMANCE FEEDBACK:\n";
        foreach ($feedback as $f) $ctx .= "- {$f['metric_name']}: {$f['metric_value']} ({$f['feedback_note']})\n";

        $prompt = "Analyze these content performance patterns and identify:\n1. What content types perform best\n2. Best platforms and posting times\n3. Content topics that resonate\n4. Specific recommendations for improvement\n\n{$ctx}";
        $system = "You are a marketing performance analyst. {$brainCtx}";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('performance-patterns', 'performance', 'Analyzed content performance patterns', $result);
        json_response(['item' => ['patterns' => $result]]);
    });

    // Monthly Review
    $router->post('/api/ai/monthly-review', function () use ($ai, $pdo, $memoryEngine, $logAi) {
        $p = request_json();
        $days = max(7, min(90, (int)($p['days'] ?? 30)));
        $brainCtx = $memoryEngine ? $memoryEngine->buildBrainContext() : '';

        $published = $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published' AND created_at >= datetime('now', '-{$days} days')")->fetchColumn();
        $scheduled = $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'scheduled' AND created_at >= datetime('now', '-{$days} days')")->fetchColumn();
        $campaigns = $pdo->query("SELECT COUNT(*) FROM campaigns WHERE created_at >= datetime('now', '-{$days} days')")->fetchColumn();
        $emailsSent = $pdo->query("SELECT COALESCE(SUM(sent_count), 0) FROM email_campaigns WHERE sent_at >= datetime('now', '-{$days} days')")->fetchColumn();
        $platforms = $pdo->query("SELECT platform, COUNT(*) as count FROM posts WHERE status = 'published' AND created_at >= datetime('now', '-{$days} days') GROUP BY platform")->fetchAll(PDO::FETCH_ASSOC);
        $aiCalls = $pdo->query("SELECT COUNT(*) FROM ai_activity_log WHERE created_at >= datetime('now', '-{$days} days')")->fetchColumn();

        $ctx = "PERIOD: Last {$days} days\nPublished: {$published}, Scheduled: {$scheduled}, Campaigns: {$campaigns}\nEmails sent: {$emailsSent}, AI tool uses: {$aiCalls}\nBy platform:\n";
        foreach ($platforms as $pl) $ctx .= "- {$pl['platform']}: {$pl['count']} posts\n";

        $prompt = "Generate a comprehensive marketing performance review for the last {$days} days:\n{$ctx}\n\nInclude: Executive summary, key wins, areas for improvement, content performance by platform, and 5 specific recommendations for next month.";
        $system = "You are a CMO reviewing marketing performance. Be specific and data-driven. {$brainCtx}";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('monthly-review', 'performance', "Monthly review ({$days} days)", $result);
        json_response(['item' => ['review' => $result, 'days' => $days]]);
    });

    // Describe Audience (Natural Language → Segment Criteria)
    $router->post('/api/ai/describe-audience', function () use ($ai, $logAi) {
        $p = request_json();
        $desc = trim((string)($p['description'] ?? ''));
        if ($desc === '') { json_response(['error' => 'description is required'], 422); return; }

        $prompt = "Convert this audience description into segment filter criteria:\n\"{$desc}\"\n\nReturn JSON object with:\n- segment_name (string, short name for this segment)\n- criteria (object with optional fields: stage (array of strings from: lead/prospect/customer/churned), min_score (int 0-100), max_score (int 0-100), tags (comma-separated string), source (string), company (string), has_activity_since (YYYY-MM-DD), no_activity_since (YYYY-MM-DD))\n- explanation (1 sentence explaining the criteria)\n- estimated_size (string like 'Small (~10-50)' or 'Medium (~50-200)')\n\nOnly include criteria fields that are relevant to the description.";
        $system = "You are a marketing segmentation expert. Return ONLY valid JSON.";
        $result = $ai->generateAdvanced($system, $prompt);
        $parsed = json_decode($result, true);
        if (!$parsed && preg_match('/\{[\s\S]*\}/m', $result, $m)) $parsed = json_decode($m[0], true);
        $logAi('describe-audience', 'audience', "Audience: {$desc}", $result);
        json_response(['item' => $parsed ?: ['raw' => $result]]);
    });

    // Email Intelligence
    $router->post('/api/ai/email-intelligence', function () use ($ai, $pdo, $memoryEngine, $logAi) {
        $brainCtx = $memoryEngine ? $memoryEngine->buildBrainContext() : '';
        $campaigns = $pdo->query("
            SELECT ec.id, ec.name, ec.subject, ec.sent_count, ec.sent_at,
                   COALESCE(SUM(CASE WHEN et.event_type = 'open' THEN 1 ELSE 0 END), 0) AS open_count,
                   COALESCE(SUM(CASE WHEN et.event_type = 'click' THEN 1 ELSE 0 END), 0) AS click_count
            FROM email_campaigns ec
            LEFT JOIN email_tracking et ON et.campaign_id = ec.id
            WHERE ec.sent_at IS NOT NULL
            GROUP BY ec.id
            ORDER BY ec.sent_at DESC LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
        $subCount = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();
        $listCount = $pdo->query("SELECT COUNT(*) FROM email_lists")->fetchColumn();

        $ctx = "EMAIL DATA:\n- {$subCount} active subscribers across {$listCount} lists\n- Campaign history:\n";
        foreach ($campaigns as $c) {
            $openRate = ($c['sent_count'] > 0) ? round(((int)$c['open_count'] / (int)$c['sent_count']) * 100, 1) : 0;
            $clickRate = ($c['sent_count'] > 0) ? round(((int)$c['click_count'] / (int)$c['sent_count']) * 100, 1) : 0;
            $ctx .= "  - \"{$c['name']}\" (Subject: \"{$c['subject']}\") sent:{$c['sent_count']} opens:{$openRate}% clicks:{$clickRate}% on {$c['sent_at']}\n";
        }

        $prompt = "Analyze this email marketing data and provide:\n1. Overall email health score\n2. Best performing subject line patterns\n3. Open rate and click rate trends\n4. Best send day/time patterns\n5. Specific recommendations to improve email performance\n6. Subscriber growth suggestions\n\n{$ctx}";
        $system = "You are an email marketing expert. Be specific and actionable. {$brainCtx}";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('email-intelligence', 'channel', 'Email intelligence analysis', $result);
        json_response(['item' => ['analysis' => $result]]);
    });

    // Deliverability Check
    $router->post('/api/ai/deliverability-check', function () use ($ai, $logAi) {
        $smtpHost = env_value('SMTP_HOST', '');
        $smtpPort = env_value('SMTP_PORT', '');
        $smtpFrom = env_value('SMTP_FROM', '');
        $hasSmtp = $smtpHost !== '';
        $fromDomain = $smtpFrom ? substr($smtpFrom, strpos($smtpFrom, '@') + 1) : '';

        $ctx = "SMTP configured: " . ($hasSmtp ? 'Yes' : 'No') . "\n";
        $ctx .= "SMTP host: {$smtpHost}\n";
        $ctx .= "SMTP port: {$smtpPort}\n";
        $ctx .= "From domain: {$fromDomain}\n";

        $prompt = "Based on this email configuration, provide a deliverability assessment:\n{$ctx}\n\nCheck for:\n1. SMTP configuration completeness\n2. SPF/DKIM/DMARC recommendations for the domain\n3. Common deliverability issues\n4. Spam score risk factors\n5. Actionable steps to improve deliverability\n\nRate overall deliverability readiness as: Good / Needs Work / Critical Issues.";
        $system = "You are an email deliverability expert.";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('deliverability-check', 'channel', 'Deliverability check', $result);
        json_response(['item' => ['analysis' => $result]]);
    });

    // AI Review Response
    $router->post('/api/ai/review-response', function () use ($ai, $logAi) {
        $p = request_json();
        $reviewText = trim((string)($p['review_text'] ?? ''));
        $rating = (int)($p['rating'] ?? 3);
        $reviewer = trim((string)($p['reviewer_name'] ?? 'Customer'));
        if ($reviewText === '') { json_response(['error' => 'review_text is required'], 422); return; }

        $bizName = env_value('BUSINESS_NAME', 'our business');
        $tone = $rating >= 4 ? 'warm and grateful' : ($rating >= 3 ? 'appreciative and helpful' : 'empathetic and solution-oriented');

        $prompt = "Write a professional business response to this {$rating}-star review from {$reviewer}:\n\n\"{$reviewText}\"\n\nTone: {$tone}\nBusiness: {$bizName}\n\nKeep it concise (2-4 sentences). " . ($rating < 3 ? "Acknowledge the issue, apologize, and offer to make it right. Include an invitation to discuss offline." : "Thank them genuinely and invite them back.");
        $system = "You are a reputation manager for {$bizName}. Write natural, non-template responses.";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('review-response', 'brand', "Response for {$rating}-star review", $result);
        json_response(['item' => ['response' => $result]]);
    });

    // Calendar Intelligence
    $router->post('/api/ai/calendar-intelligence', function () use ($ai, $pdo, $memoryEngine, $logAi) {
        $brainCtx = $memoryEngine ? $memoryEngine->buildBrainContext() : '';
        $posts = $pdo->query("SELECT platform, content_type, status, scheduled_for, created_at FROM posts WHERE created_at >= datetime('now', '-30 days') ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $byPlatform = [];
        $byType = [];
        foreach ($posts as $p) {
            $byPlatform[$p['platform']] = ($byPlatform[$p['platform']] ?? 0) + 1;
            $byType[$p['content_type']] = ($byType[$p['content_type']] ?? 0) + 1;
        }

        $ctx = "LAST 30 DAYS:\n- Total posts: " . count($posts) . "\n- By platform: " . json_encode($byPlatform) . "\n- By type: " . json_encode($byType) . "\n";

        $prompt = "Analyze this content calendar and provide:\n1. Content mix assessment (is it balanced?)\n2. Platform coverage gaps\n3. Seasonal opportunities for the next 2 weeks\n4. Content frequency recommendations\n5. Topics that are missing\n\n{$ctx}\nToday is " . date('F j, Y');
        $system = "You are a content strategist. Identify gaps and opportunities. {$brainCtx}";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('calendar-intelligence', 'strategy', 'Calendar intelligence analysis', $result);
        json_response(['item' => ['analysis' => $result]]);
    });

    // SEO Opportunities
    $router->post('/api/ai/seo-opportunities', function () use ($ai, $pdo, $logAi) {
        $p = request_json();
        $topic = trim((string)($p['topic'] ?? ''));
        $industry = app_config('BUSINESS_INDUSTRY', '');
        $existingPosts = $pdo->query("SELECT title FROM posts WHERE status = 'published' ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);

        $ctx = "Industry: {$industry}\nTopic focus: {$topic}\nExisting content:\n";
        foreach ($existingPosts as $t) $ctx .= "- {$t}\n";

        $prompt = "Find SEO content opportunities:\n{$ctx}\n\n1. Identify 5 low-competition keywords this business could rank for\n2. For each keyword, suggest a content piece (title, type, target word count)\n3. Identify content gaps compared to typical competitors\n4. Suggest internal linking opportunities\n5. Quick wins for improving existing content SEO";
        $system = "You are an SEO strategist. Focus on achievable, high-impact opportunities for small businesses.";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('seo-opportunities', 'strategy', "SEO opportunities: {$topic}", $result);
        json_response(['item' => ['opportunities' => $result]]);
    });

    // Content Freshness
    $router->post('/api/ai/content-freshness', function () use ($ai, $pdo, $logAi) {
        $oldPosts = $pdo->query("SELECT id, title, platform, created_at FROM posts WHERE status = 'published' AND created_at <= datetime('now', '-60 days') ORDER BY created_at ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

        $ctx = "OLD PUBLISHED CONTENT (60+ days):\n";
        foreach ($oldPosts as $p) $ctx .= "- #{$p['id']} \"{$p['title']}\" ({$p['platform']}) published {$p['created_at']}\n";

        if (empty($oldPosts)) {
            json_response(['item' => ['analysis' => 'All content is fresh (published within the last 60 days). No updates needed.']]);
            return;
        }

        $prompt = "Review this old content and recommend:\n{$ctx}\n\n1. Which pieces should be updated and why\n2. What updates are needed (new data, refreshed examples, updated statistics)\n3. Which pieces could be repurposed into new formats\n4. Priority ranking (update first → later)";
        $system = "You are a content freshness auditor. Be specific about what needs updating.";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('content-freshness', 'content', 'Content freshness check', $result);
        json_response(['item' => ['analysis' => $result]]);
    });

    // Compliance Check
    $router->post('/api/ai/compliance-check', function () use ($ai, $pdo, $logAi) {
        $recentPosts = $pdo->query("SELECT title, body, platform FROM posts WHERE status IN ('draft', 'scheduled') ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $hasEmails = $pdo->query("SELECT COUNT(*) FROM email_campaigns")->fetchColumn() > 0;
        $hasForms = $pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn() > 0;
        $hasLanding = $pdo->query("SELECT COUNT(*) FROM landing_pages")->fetchColumn() > 0;

        $ctx = "CONTENT TO CHECK:\n";
        foreach ($recentPosts as $p) $ctx .= "- [{$p['platform']}] \"{$p['title']}\": {$p['body']}\n";
        $ctx .= "\nFEATURES IN USE: Email campaigns: " . ($hasEmails ? 'Yes' : 'No') . ", Forms: " . ($hasForms ? 'Yes' : 'No') . ", Landing pages: " . ($hasLanding ? 'Yes' : 'No');

        $prompt = "Perform a compliance audit:\n{$ctx}\n\nCheck for:\n1. GDPR compliance (consent, data handling, privacy notices)\n2. FTC guidelines (sponsored content disclosures, truthful claims)\n3. CAN-SPAM compliance (email opt-out, physical address)\n4. Platform-specific rules (character limits, prohibited content)\n5. Accessibility concerns\n6. Cookie consent requirements for landing pages\n\nRate overall compliance as: Compliant / Needs Attention / Non-Compliant, with specific action items.";
        $system = "You are a marketing compliance auditor. Be thorough but practical.";
        $result = $ai->generateAdvanced($system, $prompt);
        $logAi('compliance-check', 'general', 'Compliance audit', $result);
        json_response(['item' => ['analysis' => $result]]);
    });
}
