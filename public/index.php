<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Repositories.php';
require __DIR__ . '/../src/Templates.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Router.php';
require __DIR__ . '/../src/AiService.php';
require __DIR__ . '/../src/MediaLibrary.php';
require __DIR__ . '/../src/Analytics.php';
require __DIR__ . '/../src/Webhooks.php';
require __DIR__ . '/../src/RssFetcher.php';
require __DIR__ . '/../src/Scheduler.php';

if (is_file(__DIR__ . '/../src/SocialPublisher.php')) {
    require __DIR__ . '/../src/SocialPublisher.php';
}
if (is_file(__DIR__ . '/../src/EmailService.php')) {
    require __DIR__ . '/../src/EmailService.php';
}

require __DIR__ . '/../src/UtmBuilder.php';
require __DIR__ . '/../src/LinkShortener.php';
require __DIR__ . '/../src/LandingPages.php';
require __DIR__ . '/../src/Contacts.php';
require __DIR__ . '/../src/FormBuilder.php';
require __DIR__ . '/../src/AbTesting.php';
require __DIR__ . '/../src/Funnels.php';
require __DIR__ . '/../src/Automations.php';
require __DIR__ . '/../src/Segments.php';
require __DIR__ . '/../src/SocialQueue.php';
require __DIR__ . '/../src/EmailTemplates.php';
require __DIR__ . '/../src/CampaignMetrics.php';

security_headers();

/* ---- Database & Repositories ---- */
$dataDir = __DIR__ . '/../data';
$db = new Database($dataDir . '/marketing.sqlite');
$pdo = $db->pdo();

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

$ai = new AiService(
    env_value('AI_PROVIDER', 'openai') ?? 'openai',
    env_value('BUSINESS_NAME', 'My Small Business') ?? 'My Small Business',
    env_value('BUSINESS_INDUSTRY', 'Local services') ?? 'Local services',
    env_value('TIMEZONE', 'America/New_York') ?? 'America/New_York',
    [
        'openai_api_key'   => env_value('OPENAI_API_KEY'),
        'openai_base_url'  => env_value('OPENAI_BASE_URL', 'https://api.openai.com/v1') ?? 'https://api.openai.com/v1',
        'openai_model'     => env_value('AI_MODEL', 'gpt-4.1-mini') ?? 'gpt-4.1-mini',
        'anthropic_api_key' => env_value('ANTHROPIC_API_KEY'),
        'anthropic_model'  => env_value('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514') ?? 'claude-sonnet-4-20250514',
        'gemini_api_key'   => env_value('GEMINI_API_KEY'),
        'gemini_model'     => env_value('GEMINI_MODEL', 'gemini-2.5-flash') ?? 'gemini-2.5-flash',
    ],
);

$activeBrand = $brandProfiles->getActive();
if ($activeBrand && method_exists($ai, 'setBrandVoice')) {
    $ai->setBrandVoice($activeBrand);
}

$emailService = null;
if (class_exists('EmailService')) {
    $emailService = new EmailService($pdo, [
        'smtp_host'      => env_value('SMTP_HOST', ''),
        'smtp_port'      => (int)(env_value('SMTP_PORT', '587') ?? '587'),
        'smtp_user'      => env_value('SMTP_USER', ''),
        'smtp_pass'      => env_value('SMTP_PASS', ''),
        'smtp_from'      => env_value('SMTP_FROM', ''),
        'smtp_from_name' => env_value('SMTP_FROM_NAME', env_value('BUSINESS_NAME', '') ?? ''),
        'base_url'       => env_value('APP_URL', ''),
    ]);
}

$socialPublisher = class_exists('SocialPublisher') ? new SocialPublisher($pdo) : null;

/* ---- Request dispatch ---- */
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['REQUEST_URI'];
$path   = parse_url($uri, PHP_URL_PATH) ?: '/';

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
    $url = $_GET['url'] ?? '/';
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
    if ($sid && $lid) {
        $sub = $subscribers->find($sid);
        if ($sub) {
            $pdo->prepare("UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = :u WHERE id = :id")->execute([
                ':u' => gmdate(DATE_ATOM),
                ':id' => $sid,
            ]);
        }
    }
    header('Content-Type: text/html');
    echo '<html><body style="font-family:sans-serif;text-align:center;padding:4rem"><h2>You have been unsubscribed.</h2><p>You will no longer receive emails from us.</p></body></html>';
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
        header('Location: ' . $link['destination_url']);
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
    require __DIR__ . '/../src/routes/auth.php';
    require __DIR__ . '/../src/routes/settings.php';
    require __DIR__ . '/../src/routes/dashboard.php';
    require __DIR__ . '/../src/routes/campaigns.php';
    require __DIR__ . '/../src/routes/posts.php';
    require __DIR__ . '/../src/routes/competitors.php';
    require __DIR__ . '/../src/routes/kpis.php';
    require __DIR__ . '/../src/routes/templates.php';
    require __DIR__ . '/../src/routes/media.php';
    require __DIR__ . '/../src/routes/social.php';
    require __DIR__ . '/../src/routes/email.php';
    require __DIR__ . '/../src/routes/analytics_routes.php';
    require __DIR__ . '/../src/routes/rss.php';
    require __DIR__ . '/../src/routes/webhooks_routes.php';
    require __DIR__ . '/../src/routes/cron.php';
    require __DIR__ . '/../src/routes/ai.php';
    require __DIR__ . '/../src/routes/utm.php';
    require __DIR__ . '/../src/routes/links.php';
    require __DIR__ . '/../src/routes/landing_pages.php';
    require __DIR__ . '/../src/routes/contacts.php';
    require __DIR__ . '/../src/routes/forms.php';
    require __DIR__ . '/../src/routes/ab_tests.php';
    require __DIR__ . '/../src/routes/funnels.php';
    require __DIR__ . '/../src/routes/automations.php';
    require __DIR__ . '/../src/routes/segments.php';
    require __DIR__ . '/../src/routes/social_queue.php';
    require __DIR__ . '/../src/routes/email_templates.php';
    require __DIR__ . '/../src/routes/campaign_metrics.php';

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
        if ($auth->userCount() === 0) {
            return null;
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
        if ($auth->userCount() === 0) {
            return null;
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
    register_post_routes($router, $posts, $analytics, $webhooks);
    register_competitor_routes($router, $competitors);
    register_kpi_routes($router, $kpis, $aiLogs);
    register_template_routes($router, $templates, $brandProfiles);
    register_media_routes($router, $mediaLib);
    register_social_routes($router, $socialAccounts, $socialPublisher);
    register_email_routes($router, $emailLists, $subscribers, $emailCampaigns, $emailService, $webhooks);
    register_analytics_routes($router, $analytics);
    register_rss_routes($router, $rssFetcher);
    register_webhook_routes($router, $webhooks);
    register_cron_routes($router, $scheduler);
    register_ai_routes($router, $ai, $aiLogs, $analytics, $posts, $campaigns);
    register_utm_routes($router, $utmBuilder, $linkShortener);
    register_link_routes($router, $linkShortener);
    register_landing_page_routes($router, $landingPages);
    register_contact_routes($router, $contactRepo, $automations);
    register_form_routes($router, $formRepo, $contactRepo, $automations);
    register_ab_test_routes($router, $abTests);
    register_funnel_routes($router, $funnels);
    register_automation_routes($router, $automations);
    register_segment_routes($router, $segments);
    register_social_queue_routes($router, $socialQueue);
    register_email_template_routes($router, $emailTemplates);
    register_campaign_metric_routes($router, $campaignMetrics);

    // Dispatch
    if (!$router->dispatch($method, $uri)) {
        json_response(['error' => 'Not found'], 404);
    }
    return;
}

/* ---- Static files ---- */
$file = $path === '/' ? '/app.html' : $path;
$publicFile = __DIR__ . $file;
if (!is_file($publicFile)) {
    http_response_code(404);
    echo 'Not found';
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
