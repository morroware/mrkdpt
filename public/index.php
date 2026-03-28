<?php

declare(strict_types=1);

// Auto-detect layout: src/ next to this file (flat) or one level up (nested/public)
$srcDir = is_dir(__DIR__ . '/src') ? __DIR__ . '/src' : __DIR__ . '/../src';

require $srcDir . '/bootstrap.php';
require $srcDir . '/Database.php';
require $srcDir . '/Repositories.php';
require $srcDir . '/Templates.php';
require $srcDir . '/Auth.php';
require $srcDir . '/Router.php';
require $srcDir . '/AiService.php';
require $srcDir . '/AiContentTools.php';
require $srcDir . '/AiAnalysisTools.php';
require $srcDir . '/AiStrategyTools.php';
require $srcDir . '/AiChatService.php';
require $srcDir . '/AiMemoryEngine.php';
require $srcDir . '/AiOrchestrator.php';
require $srcDir . '/MediaLibrary.php';
require $srcDir . '/Analytics.php';
require $srcDir . '/Webhooks.php';
require $srcDir . '/RssFetcher.php';
require $srcDir . '/Scheduler.php';

if (is_file($srcDir . '/SocialPublisher.php')) {
    require $srcDir . '/SocialPublisher.php';
}
if (is_file($srcDir . '/EmailService.php')) {
    require $srcDir . '/EmailService.php';
}

require $srcDir . '/UtmBuilder.php';
require $srcDir . '/LinkShortener.php';
require $srcDir . '/LandingPages.php';
require $srcDir . '/Contacts.php';
require $srcDir . '/FormBuilder.php';
require $srcDir . '/AbTesting.php';
require $srcDir . '/Funnels.php';
require $srcDir . '/Automations.php';
require $srcDir . '/Segments.php';
require $srcDir . '/SocialQueue.php';
require $srcDir . '/EmailTemplates.php';
require $srcDir . '/CampaignMetrics.php';
require $srcDir . '/AiAutopilot.php';

security_headers();

$forceHttps = in_array(strtolower((string)app_config('APP_FORCE_HTTPS', 'false')), ['1', 'true', 'yes', 'on'], true);
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = $_SERVER['HTTP_HOST'] ?? '';
// Validate host header to prevent host header injection
$host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $host);
$isLocalDevHost = in_array(strtolower($host), ['localhost', '127.0.0.1'], true) || str_starts_with(strtolower($host), 'localhost:') || str_starts_with($host, '127.0.0.1:');
if ($forceHttps && !$isHttps && !$isLocalDevHost) {
    // Prefer APP_URL if configured, otherwise use sanitized host
    $appUrl = app_config('APP_URL', '');
    $redirectHost = $appUrl ? (string)(parse_url($appUrl, PHP_URL_HOST) ?? $host) : $host;
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $redirectHost . $requestUri, true, 301);
    exit;
}

/* ---- Database & Repositories ---- */
$dataDir = APP_ROOT . '/data';
$db = new Database($dataDir . '/marketing.sqlite');
$pdo = $db->pdo();

// Initialize DB-backed settings
db_setting('_init', null, $pdo);

$campaigns      = new CampaignRepository($pdo);
$posts          = new PostRepository($pdo);
$competitors    = new CompetitorRepository($pdo);
$kpis           = new KpiRepository($pdo);
$aiLogs         = new AiLogRepository($pdo);
$templates      = new TemplateRepository($pdo);
$brandProfiles  = new BrandProfileRepository($pdo);
$socialAccounts = new SocialAccountRepository($pdo);
$emailLists     = new EmailListRepository($pdo);
$subscribers    = new SubscriberRepository($pdo);
$emailCampaigns = new EmailCampaignRepository($pdo);
$analytics      = new Analytics($pdo);
$webhooks       = new Webhooks($pdo);
$rssFetcher     = new RssFetcher($pdo);
$utmBuilder     = new UtmBuilder($pdo);
$linkShortener  = new LinkShortener($pdo);
$landingPages   = new LandingPageRepository($pdo);
$contactRepo    = new ContactRepository($pdo);
$formRepo       = new FormRepository($pdo);
$abTests        = new AbTestRepository($pdo);
$funnels        = new FunnelRepository($pdo);
$automations    = new AutomationRepository($pdo);
$segments       = new SegmentRepository($pdo);
$socialQueue    = new SocialQueue($pdo);
$emailTemplates = new EmailTemplateRepository($pdo);
$campaignMetrics = new CampaignMetricsRepository($pdo);

/* ---- Services ---- */
$auth      = new Auth($pdo);
$mediaLib  = new MediaLibrary($pdo, $dataDir);
$scheduler = new Scheduler($pdo, class_exists('SocialPublisher') ? new SocialPublisher($pdo) : null, $dataDir);
$scheduler->setAutomations($automations);
$scheduler->setQueue($socialQueue);

$ai = new AiService(
    app_config('AI_PROVIDER', 'openai'),
    app_config('BUSINESS_NAME', 'My Small Business'),
    app_config('BUSINESS_INDUSTRY', 'Local services'),
    app_config('TIMEZONE', 'America/New_York'),
    [
        'openai_api_key'    => app_config('OPENAI_API_KEY'),
        'openai_base_url'   => app_config('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'openai_model'      => app_config('OPENAI_MODEL') ?? env_value('AI_MODEL', 'gpt-4.1-mini'),
        'anthropic_api_key'  => app_config('ANTHROPIC_API_KEY'),
        'anthropic_model'   => app_config('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'gemini_api_key'    => app_config('GEMINI_API_KEY'),
        'gemini_model'      => app_config('GEMINI_MODEL', 'gemini-2.5-flash'),
        'deepseek_api_key'  => app_config('DEEPSEEK_API_KEY'),
        'deepseek_base_url' => app_config('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
        'deepseek_model'    => app_config('DEEPSEEK_MODEL', 'deepseek-chat'),
        'groq_api_key'      => app_config('GROQ_API_KEY'),
        'groq_base_url'     => app_config('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'groq_model'        => app_config('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'mistral_api_key'   => app_config('MISTRAL_API_KEY'),
        'mistral_base_url'  => app_config('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
        'mistral_model'     => app_config('MISTRAL_MODEL', 'mistral-large-latest'),
        'openrouter_api_key' => app_config('OPENROUTER_API_KEY'),
        'openrouter_base_url' => app_config('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'openrouter_model'  => app_config('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4'),
        'xai_api_key'       => app_config('XAI_API_KEY'),
        'xai_base_url'      => app_config('XAI_BASE_URL', 'https://api.x.ai/v1'),
        'xai_model'         => app_config('XAI_MODEL', 'grok-3-fast'),
        'together_api_key'  => app_config('TOGETHER_API_KEY'),
        'together_base_url' => app_config('TOGETHER_BASE_URL', 'https://api.together.xyz/v1'),
        'together_model'    => app_config('TOGETHER_MODEL', 'meta-llama/Llama-3.3-70B-Instruct-Turbo'),
        'banana_api_key'    => app_config('BANANA_API_KEY'),
        'banana_base_url'   => app_config('BANANA_BASE_URL', 'https://api.banana.dev'),
        'banana_model_id'   => app_config('BANANA_MODEL_ID', ''),
    ],
);

$contentTools  = new AiContentTools($ai);
$analysisTools = new AiAnalysisTools($ai);
$strategyTools = new AiStrategyTools($ai);
$chatService   = new AiChatService($ai, $pdo);

// AI Brain: Memory Engine + Orchestrator
$memoryEngine = new AiMemoryEngine($pdo, $ai);
$ai->setMemoryEngine($memoryEngine);
$chatService->setMemoryEngine($memoryEngine);
$orchestrator = new AiOrchestrator($pdo, $ai, $contentTools, $analysisTools, $strategyTools, $memoryEngine);

$activeBrand = $brandProfiles->getActive();
if ($activeBrand && method_exists($ai, 'setBrandVoice')) {
    $ai->setBrandVoice($activeBrand);
}

$autopilot = new AiAutopilot($pdo, $strategyTools, $contentTools, $aiLogs, $posts, $campaigns, $brandProfiles, $competitors);

// Load business profile and inject into AI system prompts
$businessProfile = $autopilot->getBusinessProfile();
if ($businessProfile) {
    $ai->setBusinessProfile($businessProfile);
}

$sharedMemoryRows = $pdo->query('SELECT memory_key, content, source, tags, updated_at FROM ai_shared_memory ORDER BY updated_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
if (!empty($sharedMemoryRows)) {
    $ai->setSharedMemory($sharedMemoryRows);
}

// Run memory maintenance on each request (lightweight)
$memoryEngine->maintenance();

$emailService = null;
if (class_exists('EmailService')) {
    $emailService = new EmailService($pdo, [
        'smtp_host'      => env_value('SMTP_HOST', ''),
        'smtp_port'      => (int)env_value('SMTP_PORT', '587'),
        'smtp_user'      => env_value('SMTP_USER', ''),
        'smtp_pass'      => env_value('SMTP_PASS', ''),
        'smtp_from'      => env_value('SMTP_FROM', ''),
        'smtp_from_name' => env_value('SMTP_FROM_NAME', env_value('BUSINESS_NAME', '')),
        'base_url'       => env_value('APP_URL', ''),
    ]);
}

$socialPublisher = class_exists('SocialPublisher') ? new SocialPublisher($pdo) : null;

/* ---- Request dispatch ---- */
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['REQUEST_URI'];
$path   = parse_url($uri, PHP_URL_PATH) ?: '/';

// Strip subdirectory prefix so route patterns work regardless of install location
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$basePath  = ($scriptDir !== '' && $scriptDir !== '.') ? $scriptDir : '';
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

// Serve uploaded files
if (str_starts_with($path, '/uploads/')) {
    serve_upload($path, $dataDir);
    return;
}

// Email tracking pixel
if ($path === '/api/track/open' && $method === 'GET') {
    $cid = (int)($_GET['c'] ?? 0);
    $sid = (int)($_GET['s'] ?? 0);
    if ($cid && $sid && $emailService) {
        $emailService->trackOpen($cid, $sid);
    }
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    return;
}

// Email click tracking
if ($path === '/api/track/click' && $method === 'GET') {
    $cid = (int)($_GET['c'] ?? 0);
    $sid = (int)($_GET['s'] ?? 0);
    $url = sanitize_redirect_url((string)($_GET['url'] ?? '/'), '/', true);
    if ($cid && $sid && $emailService) {
        $emailService->trackClick($cid, $sid, $url);
    }
    header('Location: ' . $url);
    http_response_code(302);
    return;
}

// Email unsubscribe
if ($path === '/api/unsubscribe' && $method === 'GET') {
    $sid = (int)($_GET['s'] ?? 0);
    $lid = (int)($_GET['l'] ?? 0);
    $unsubscribed = false;
    if ($sid && $lid) {
        $sub = $subscribers->find($sid);
        if ($sub && (int)$sub['list_id'] === $lid) {
            $pdo->prepare("UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = :u WHERE id = :id AND list_id = :lid")->execute([
                ':u' => gmdate(DATE_ATOM),
                ':id' => $sid,
                ':lid' => $lid,
            ]);
            $unsubscribed = true;
        }
    }
    header('Content-Type: text/html');
    if ($unsubscribed) {
        echo '<html><body style="font-family:sans-serif;text-align:center;padding:4rem"><h2>You have been unsubscribed.</h2><p>You will no longer receive emails from us.</p></body></html>';
    } else {
        echo '<html><body style="font-family:sans-serif;text-align:center;padding:4rem"><h2>Unsubscribe</h2><p>This unsubscribe link is invalid or has already been used.</p></body></html>';
    }
    return;
}

// Short link redirect (e.g. /s/abc123)
if (preg_match('#^/s/([a-zA-Z0-9]+)$#', $path, $m)) {
    $link = $linkShortener->findByCode($m[1]);
    if ($link) {
        $linkShortener->recordClick((int)$link['id'], 'short_link', [
            'ip_hash' => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
        ]);
        // If linked to a UTM link, increment that too
        if (!empty($link['utm_link_id'])) {
            $utmBuilder->incrementClicks((int)$link['utm_link_id']);
        }
        // Fire automation for link click
        $automations->fire('link.clicked', [
            'link_id' => (int)$link['id'],
            'short_code' => $m[1],
            'destination_url' => $link['destination_url'] ?? '',
        ]);
        $destinationUrl = sanitize_redirect_url((string)($link['destination_url'] ?? ''), '/');
        header('Location: ' . $destinationUrl);
        http_response_code(302);
    } else {
        http_response_code(404);
        echo 'Link not found';
    }
    return;
}

// Landing page rendering (e.g. /p/my-landing-page)
if (preg_match('#^/p/([a-zA-Z0-9\-]+)$#', $path, $m)) {
    $page = $landingPages->findBySlug($m[1]);
    if ($page) {
        $landingPages->incrementViews((int)$page['id']);
        $form = null;
        if (!empty($page['form_id'])) {
            $form = $formRepo->find((int)$page['form_id']);
        }
        header('Content-Type: text/html');
        echo $landingPages->render($page, $form);
    } else {
        http_response_code(404);
        echo 'Page not found';
    }
    return;
}

// Embeddable form rendering (e.g. /f/contact-us)
if (preg_match('#^/f/([a-zA-Z0-9\-]+)$#', $path, $m)) {
    $form = $formRepo->findBySlug($m[1]);
    if ($form) {
        header('Content-Type: text/html');
        echo $formRepo->renderStandalone($form);
    } else {
        http_response_code(404);
        echo 'Form not found';
    }
    return;
}

// API routes
if (str_starts_with($path, '/api/')) {
    $router = new Router();

    // Health check (always public)
    $router->get('/api/health', fn() => json_response(['ok' => true, 'service' => 'marketing-suite', 'version' => '3.0-beta']));

    // Load route modules
    require APP_ROOT . '/src/routes/auth.php';
    require APP_ROOT . '/src/routes/settings.php';
    require APP_ROOT . '/src/routes/dashboard.php';
    require APP_ROOT . '/src/routes/campaigns.php';
    require APP_ROOT . '/src/routes/posts.php';
    require APP_ROOT . '/src/routes/competitors.php';
    require APP_ROOT . '/src/routes/kpis.php';
    require APP_ROOT . '/src/routes/templates.php';
    require APP_ROOT . '/src/routes/media.php';
    require APP_ROOT . '/src/routes/social.php';
    require APP_ROOT . '/src/routes/email.php';
    require APP_ROOT . '/src/routes/analytics_routes.php';
    require APP_ROOT . '/src/routes/rss.php';
    require APP_ROOT . '/src/routes/webhooks_routes.php';
    require APP_ROOT . '/src/routes/cron.php';
    require APP_ROOT . '/src/routes/ai.php';
    require APP_ROOT . '/src/routes/utm.php';
    require APP_ROOT . '/src/routes/links.php';
    require APP_ROOT . '/src/routes/landing_pages.php';
    require APP_ROOT . '/src/routes/contacts.php';
    require APP_ROOT . '/src/routes/forms.php';
    require APP_ROOT . '/src/routes/ab_tests.php';
    require APP_ROOT . '/src/routes/funnels.php';
    require APP_ROOT . '/src/routes/automations.php';
    require APP_ROOT . '/src/routes/segments.php';
    require APP_ROOT . '/src/routes/social_queue.php';
    require APP_ROOT . '/src/routes/email_templates.php';
    require APP_ROOT . '/src/routes/campaign_metrics.php';
    require APP_ROOT . '/src/routes/wordpress_plugin.php';
    require APP_ROOT . '/src/routes/onboarding.php';
    require APP_ROOT . '/src/routes/autopilot.php';

    // Register public routes (before middleware)
    register_auth_routes($router, $auth);

    // Auth middleware
    $router->addMiddleware(function (string $method, string $path) use ($auth): ?bool {
        $public = ['/api/health', '/api/login', '/api/logout', '/api/setup-status'];
        if (in_array($path, $public, true)) {
            return null;
        }
        // Public form submissions
        if (preg_match('#^/api/forms/[^/]+/submit$#', $path)) {
            return null;
        }
        // When no users exist, only allow setup-related endpoints (not the entire API)
        if ($auth->userCount() === 0) {
            $setupPaths = ['/api/setup-status', '/api/login', '/api/health', '/api/settings'];
            if (in_array($path, $setupPaths, true) || str_starts_with($path, '/api/settings')) {
                return null;
            }
            // Block all other endpoints until a user is created
            json_response(['error' => 'Setup required - please create an admin account first'], 403);
            return false;
        }
        $user = $auth->currentUser();
        if (!$user) {
            json_response(['error' => 'Authentication required'], 401);
            return false;
        }
        return null;
    });

    // CSRF middleware
    $router->addMiddleware(function (string $method, string $path) use ($auth): ?bool {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        $public = ['/api/login', '/api/logout'];
        if (in_array($path, $public, true)) {
            return null;
        }
        // Allow public form submissions
        if (preg_match('#^/api/forms/[^/]+/submit$#', $path)) {
            return null;
        }
        // When no users exist, skip CSRF only for setup-related endpoints
        if ($auth->userCount() === 0) {
            if (str_starts_with($path, '/api/settings') || $path === '/api/login') {
                return null;
            }
        }
        if (!$auth->verifyCsrf()) {
            json_response(['error' => 'CSRF token invalid'], 403);
            return false;
        }
        return null;
    });

    // Register protected routes
    register_settings_routes($router, $ai, $scheduler, $dataDir, $pdo);
    register_dashboard_routes($router, $posts, $campaigns, $kpis, $aiLogs);
    register_campaign_routes($router, $campaigns, $webhooks);
    register_post_routes($router, $posts, $analytics, $webhooks, $pdo, $automations);
    register_competitor_routes($router, $competitors);
    register_kpi_routes($router, $kpis, $aiLogs);
    register_template_routes($router, $templates, $brandProfiles);
    register_media_routes($router, $mediaLib);
    register_social_routes($router, $socialAccounts, $socialPublisher);
    register_email_routes($router, $emailLists, $subscribers, $emailCampaigns, $emailService, $webhooks, $automations);
    register_analytics_routes($router, $analytics);
    register_rss_routes($router, $rssFetcher);
    register_webhook_routes($router, $webhooks);
    register_cron_routes($router, $scheduler);
    register_ai_routes($router, $ai, $contentTools, $analysisTools, $strategyTools, $chatService, $aiLogs, $analytics, $posts, $campaigns, $pdo, $memoryEngine, $orchestrator);
    register_utm_routes($router, $utmBuilder, $linkShortener);
    register_link_routes($router, $linkShortener);
    register_landing_page_routes($router, $landingPages);
    register_contact_routes($router, $contactRepo, $automations);
    register_form_routes($router, $formRepo, $contactRepo, $automations, $auth);
    register_ab_test_routes($router, $abTests);
    register_funnel_routes($router, $funnels);
    register_automation_routes($router, $automations);
    register_segment_routes($router, $segments);
    register_social_queue_routes($router, $socialQueue);
    register_email_template_routes($router, $emailTemplates);
    register_campaign_metric_routes($router, $campaignMetrics);
    register_wordpress_plugin_routes($router, $posts, $campaigns, $contactRepo, $pdo);
    register_onboarding_routes($router, $autopilot);
    register_autopilot_routes($router, $autopilot);

    // Dispatch using the base-path-stripped path
    $query = parse_url($uri, PHP_URL_QUERY);
    $dispatchUri = $path . ($query ? '?' . $query : '');
    if (!$router->dispatch($method, $dispatchUri)) {
        $allowedMethods = $router->allowedMethodsForPath($dispatchUri);
        if ($allowedMethods !== []) {
            header('Allow: ' . implode(', ', $allowedMethods));
            json_response(['error' => 'Method not allowed'], 405);
            return;
        }
        json_response(['error' => 'Not found'], 404);
    }
    return;
}

/* ---- Static files ---- */
$file = $path === '/' ? '/app.html' : $path;
// Prevent path traversal in static file serving
if (str_contains($file, '..')) {
    http_response_code(403);
    echo 'Forbidden';
    return;
}
$publicFile = __DIR__ . $file;
if (!is_file($publicFile)) {
    http_response_code(404);
    echo 'Not found';
    return;
}
// Verify resolved path is within public directory
$realPublicFile = realpath($publicFile);
if (!$realPublicFile || !str_starts_with($realPublicFile, __DIR__ . '/')) {
    http_response_code(403);
    echo 'Forbidden';
    return;
}

$ext = pathinfo($publicFile, PATHINFO_EXTENSION);
$types = [
    'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
    'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
];
if (isset($types[$ext])) {
    header('Content-Type: ' . $types[$ext]);
}
readfile($publicFile);
