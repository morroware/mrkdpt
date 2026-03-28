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
            'email_sent' => (int)($overview['email']['total_sent'] ?? 0),
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
}
