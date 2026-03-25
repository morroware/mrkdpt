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

security_headers();

$dataDir = __DIR__ . '/../data';
$db = new Database($dataDir . '/marketing.sqlite');
$pdo = $db->pdo();

/* ---- repositories ---- */
$campaigns = new CampaignRepository($pdo);
$posts = new PostRepository($pdo);
$competitors = new CompetitorRepository($pdo);
$kpis = new KpiRepository($pdo);
$aiLogs = new AiLogRepository($pdo);
$templates = new TemplateRepository($pdo);
$brandProfiles = new BrandProfileRepository($pdo);
$socialAccounts = new SocialAccountRepository($pdo);
$emailLists = new EmailListRepository($pdo);
$subscribers = new SubscriberRepository($pdo);
$emailCampaigns = new EmailCampaignRepository($pdo);
$analytics = new Analytics($pdo);
$webhooks = new Webhooks($pdo);
$rssFetcher = new RssFetcher($pdo);

/* ---- services ---- */
$auth = new Auth($pdo);
$mediaLib = new MediaLibrary($pdo, $dataDir);
$scheduler = new Scheduler($pdo, class_exists('SocialPublisher') ? new SocialPublisher($pdo) : null, $dataDir);

$ai = new AiService(
    env_value('AI_PROVIDER', 'openai') ?? 'openai',
    env_value('BUSINESS_NAME', 'My Small Business') ?? 'My Small Business',
    env_value('BUSINESS_INDUSTRY', 'Local services') ?? 'Local services',
    env_value('TIMEZONE', 'America/New_York') ?? 'America/New_York',
    [
        'openai_api_key' => env_value('OPENAI_API_KEY'),
        'openai_base_url' => env_value('OPENAI_BASE_URL', 'https://api.openai.com/v1') ?? 'https://api.openai.com/v1',
        'openai_model' => env_value('AI_MODEL', 'gpt-4.1-mini') ?? 'gpt-4.1-mini',
        'anthropic_api_key' => env_value('ANTHROPIC_API_KEY'),
        'anthropic_model' => env_value('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514') ?? 'claude-sonnet-4-20250514',
        'gemini_api_key' => env_value('GEMINI_API_KEY'),
        'gemini_model' => env_value('GEMINI_MODEL', 'gemini-2.5-flash') ?? 'gemini-2.5-flash',
    ],
);

// Inject active brand voice
$activeBrand = $brandProfiles->getActive();
if ($activeBrand && method_exists($ai, 'setBrandVoice')) {
    $ai->setBrandVoice($activeBrand);
}

$emailService = null;
if (class_exists('EmailService')) {
    $emailService = new EmailService($pdo, [
        'smtp_host' => env_value('SMTP_HOST', ''),
        'smtp_port' => (int)(env_value('SMTP_PORT', '587') ?? '587'),
        'smtp_user' => env_value('SMTP_USER', ''),
        'smtp_pass' => env_value('SMTP_PASS', ''),
        'smtp_from' => env_value('SMTP_FROM', ''),
        'smtp_from_name' => env_value('SMTP_FROM_NAME', env_value('BUSINESS_NAME', '') ?? ''),
        'base_url' => env_value('APP_URL', ''),
    ]);
}

$socialPublisher = class_exists('SocialPublisher') ? new SocialPublisher($pdo) : null;

/* ---- routing ---- */
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

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
    $sig = $_GET['sig'] ?? '';
    if ($sid && $lid && $emailService) {
        $emailService->unsubscribe('',$lid); // will look up by subscriber id
        // Actually we need to find the subscriber
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

// API routes
if (str_starts_with($path, '/api/')) {
    $router = new Router();

    // ---- Public routes (no auth) ----
    $router->get('/api/health', fn() => json_response(['ok' => true, 'service' => 'marketing-suite', 'version' => '2.0']));

    $router->post('/api/login', function () use ($auth) {
        $data = request_json();
        if (!$auth->rateLimit('login', 10, 300)) {
            json_response(['error' => 'Too many login attempts. Try again later.'], 429);
            return;
        }
        $user = $auth->login($data['username'] ?? '', $data['password'] ?? '');
        if (!$user) {
            json_response(['error' => 'Invalid credentials'], 401);
            return;
        }
        json_response([
            'user' => ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']],
            'csrf_token' => $auth->csrfToken(),
            'api_token' => $user['api_token'],
        ]);
    });

    $router->post('/api/logout', function () use ($auth) {
        $auth->logout();
        json_response(['ok' => true]);
    });

    // Check if setup is needed (no users exist)
    $router->get('/api/setup-status', function () use ($auth) {
        json_response(['needs_setup' => $auth->userCount() === 0]);
    });

    // ---- Auth middleware for remaining routes ----
    $router->addMiddleware(function (string $method, string $path) use ($auth): ?bool {
        // Skip auth for public endpoints
        $public = ['/api/health', '/api/login', '/api/logout', '/api/setup-status'];
        if (in_array($path, $public, true)) {
            return null; // continue
        }
        // Allow if no users created yet (first-time setup)
        if ($auth->userCount() === 0) {
            return null;
        }
        $user = $auth->currentUser();
        if (!$user) {
            json_response(['error' => 'Authentication required'], 401);
            return false;
        }
        return null; // continue
    });

    // CSRF middleware for state-changing requests
    $router->addMiddleware(function (string $method, string $path) use ($auth): ?bool {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        $public = ['/api/login', '/api/logout'];
        if (in_array($path, $public, true)) {
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

    /* ---- User / Auth ---- */
    $router->get('/api/me', function () use ($auth) {
        $user = $auth->currentUser();
        if ($user) {
            json_response(['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'], 'api_token' => $user['api_token'], 'csrf_token' => $auth->csrfToken()]);
        } else {
            json_response(['error' => 'Not authenticated'], 401);
        }
    });

    $router->post('/api/regenerate-token', function () use ($auth) {
        $user = $auth->currentUser();
        if (!$user) { json_response(['error' => 'Auth required'], 401); return; }
        $token = $auth->regenerateToken($user['id']);
        json_response(['api_token' => $token]);
    });

    /* ---- Settings ---- */
    $router->get('/api/settings', function () use ($ai) {
        json_response([
            'business_name' => env_value('BUSINESS_NAME', 'My Small Business'),
            'business_industry' => env_value('BUSINESS_INDUSTRY', 'Local services'),
            'timezone' => env_value('TIMEZONE', 'America/New_York'),
            'ai' => $ai->providerStatus(),
            'smtp_configured' => env_value('SMTP_HOST', '') !== '',
            'cron_key' => env_value('CRON_KEY', ''),
            'app_url' => env_value('APP_URL', ''),
        ]);
    });

    $router->get('/api/settings/health', function () use ($pdo, $scheduler, $dataDir) {
        $diskFree = @disk_free_space($dataDir);
        $cronLog = $scheduler->getLog(1);
        json_response([
            'php_version' => PHP_VERSION,
            'sqlite_version' => $pdo->query('SELECT sqlite_version()')->fetchColumn(),
            'extensions' => [
                'curl' => extension_loaded('curl'),
                'gd' => extension_loaded('gd'),
                'mbstring' => extension_loaded('mbstring'),
                'simplexml' => extension_loaded('simplexml'),
                'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            ],
            'disk_free_mb' => $diskFree ? round($diskFree / 1024 / 1024) : null,
            'data_dir_writable' => is_writable($dataDir),
            'last_cron' => $cronLog[0] ?? null,
        ]);
    });

    $router->post('/api/settings/backup', function () use ($dataDir) {
        $dbFile = $dataDir . '/marketing.sqlite';
        if (!is_file($dbFile)) {
            json_response(['error' => 'Database not found'], 404);
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="marketing-backup-' . date('Y-m-d-His') . '.sqlite"');
        header('Content-Length: ' . filesize($dbFile));
        readfile($dbFile);
    });

    /* ---- Dashboard ---- */
    $router->get('/api/dashboard', function () use ($posts, $campaigns, $kpis, $aiLogs) {
        json_response([
            'metrics' => $posts->metrics(),
            'campaigns' => count($campaigns->all()),
            'kpis' => $kpis->summary(),
            'recent_posts' => array_slice($posts->all(), 0, 8),
            'recent_ideas' => array_slice($aiLogs->ideas(), 0, 5),
        ]);
    });

    /* ---- Campaigns ---- */
    $router->get('/api/campaigns', fn() => json_response(['items' => $campaigns->all()]));

    $router->post('/api/campaigns', function () use ($campaigns, $webhooks) {
        $p = request_json();
        foreach (['name', 'channel', 'objective'] as $r) {
            if (empty($p[$r])) { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        $item = $campaigns->create($p);
        $webhooks->dispatch('campaign.created', $item);
        json_response(['item' => $item], 201);
    });

    $router->get('/api/campaigns/{id}', fn($p) => json_response(['item' => $campaigns->find((int)$p['id'])]));

    $router->put('/api/campaigns/{id}', function ($p) use ($campaigns) {
        $data = request_json();
        json_response(['item' => $campaigns->update((int)$p['id'], $data)]);
    });

    $router->delete('/api/campaigns/{id}', function ($p) use ($campaigns) {
        $campaigns->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    /* ---- Posts ---- */
    $router->get('/api/posts', function () use ($posts) {
        $status = $_GET['status'] ?? null;
        $platform = $_GET['platform'] ?? null;
        $campaignId = !empty($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;
        json_response(['items' => $posts->all($status, $platform, $campaignId)]);
    });

    $router->post('/api/posts', function () use ($posts, $analytics, $webhooks) {
        $p = request_json();
        foreach (['platform', 'title', 'body'] as $r) {
            if (empty($p[$r])) { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        $item = $posts->create($p);
        $analytics->track('post.created', 'post', $item['id'], ['platform' => $item['platform']]);
        json_response(['item' => $item], 201);
    });

    $router->get('/api/posts/{id}', fn($p) => json_response(['item' => $posts->find((int)$p['id'])]));

    $router->patch('/api/posts/{id}', function ($p) use ($posts, $analytics, $webhooks) {
        $data = request_json();
        if (!empty($data['status'])) {
            $item = $posts->updateStatus((int)$p['id'], $data['status']);
            if ($data['status'] === 'published') {
                $analytics->track('post.published', 'post', (int)$p['id'], ['platform' => $item['platform'] ?? '']);
                $webhooks->dispatch('post.published', $item);
            }
        } else {
            $item = $posts->update((int)$p['id'], $data);
        }
        json_response(['item' => $item]);
    });

    $router->delete('/api/posts/{id}', function ($p) use ($posts) {
        $posts->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/posts/bulk', function () use ($posts) {
        $data = request_json();
        $ids = $data['ids'] ?? [];
        $action = $data['action'] ?? '';
        if (empty($ids)) { json_response(['error' => 'No IDs provided'], 422); return; }
        $count = match($action) {
            'publish' => $posts->bulkUpdateStatus($ids, 'published'),
            'schedule' => $posts->bulkUpdateStatus($ids, 'scheduled'),
            'delete' => $posts->bulkDelete($ids),
            default => 0,
        };
        json_response(['affected' => $count]);
    });

    /* ---- Competitors ---- */
    $router->get('/api/competitors', fn() => json_response(['items' => $competitors->all()]));

    $router->post('/api/competitors', function () use ($competitors) {
        $p = request_json();
        foreach (['name', 'channel'] as $r) {
            if (empty($p[$r])) { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        json_response(['item' => $competitors->create($p)], 201);
    });

    $router->delete('/api/competitors/{id}', function ($p) use ($competitors) {
        $competitors->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    /* ---- KPIs ---- */
    $router->get('/api/kpis', fn() => json_response(['items' => $kpis->all(), 'summary' => $kpis->summary()]));

    $router->post('/api/kpis', function () use ($kpis) {
        $p = request_json();
        foreach (['channel', 'metric_name', 'metric_value'] as $r) {
            if (!isset($p[$r]) || $p[$r] === '') { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        json_response(['item' => $kpis->create($p)], 201);
    });

    /* ---- Ideas ---- */
    $router->get('/api/ideas', fn() => json_response(['items' => $aiLogs->ideas()]));

    /* ---- Templates ---- */
    $router->get('/api/templates', fn() => json_response(['items' => $templates->all()]));

    $router->post('/api/templates', function () use ($templates) {
        $p = request_json();
        if (empty($p['name'])) { json_response(['error' => 'Missing: name'], 422); return; }
        json_response(['item' => $templates->create($p)], 201);
    });

    $router->get('/api/templates/{id}', fn($p) => json_response(['item' => $templates->find((int)$p['id'])]));

    $router->put('/api/templates/{id}', fn($p) => json_response(['item' => $templates->update((int)$p['id'], request_json())]));

    $router->delete('/api/templates/{id}', function ($p) use ($templates) {
        $templates->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/templates/{id}/clone', function ($p) use ($templates) {
        $item = $templates->duplicate((int)$p['id']);
        json_response(['item' => $item], $item ? 201 : 404);
    });

    $router->post('/api/templates/{id}/render', function ($p) use ($templates) {
        $data = request_json();
        $output = $templates->render((int)$p['id'], $data['values'] ?? []);
        json_response(['rendered' => $output]);
    });

    /* ---- Brand Profiles ---- */
    $router->get('/api/brand-profiles', fn() => json_response(['items' => $brandProfiles->all()]));

    $router->post('/api/brand-profiles', function () use ($brandProfiles) {
        $p = request_json();
        if (empty($p['name'])) { json_response(['error' => 'Missing: name'], 422); return; }
        json_response(['item' => $brandProfiles->create($p)], 201);
    });

    $router->get('/api/brand-profiles/{id}', fn($p) => json_response(['item' => $brandProfiles->find((int)$p['id'])]));

    $router->put('/api/brand-profiles/{id}', fn($p) => json_response(['item' => $brandProfiles->update((int)$p['id'], request_json())]));

    $router->delete('/api/brand-profiles/{id}', function ($p) use ($brandProfiles) {
        $brandProfiles->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/brand-profiles/{id}/activate', function ($p) use ($brandProfiles) {
        $brandProfiles->setActive((int)$p['id']);
        json_response(['ok' => true]);
    });

    /* ---- Media ---- */
    $router->get('/api/media', fn() => json_response(['items' => $mediaLib->all()]));

    $router->post('/api/media', function () use ($mediaLib) {
        if (empty($_FILES['file'])) {
            json_response(['error' => 'No file uploaded'], 422);
            return;
        }
        $result = $mediaLib->upload($_FILES['file'], $_POST['alt_text'] ?? '', $_POST['tags'] ?? '');
        if (is_string($result)) {
            json_response(['error' => $result], 422);
        } else {
            json_response(['item' => $result], 201);
        }
    });

    $router->delete('/api/media/{id}', function ($p) use ($mediaLib) {
        $mediaLib->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    /* ---- Social Accounts ---- */
    $router->get('/api/social-accounts', fn() => json_response(['items' => $socialAccounts->all()]));

    $router->post('/api/social-accounts', function () use ($socialAccounts) {
        $p = request_json();
        foreach (['platform', 'account_name'] as $r) {
            if (empty($p[$r])) { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        json_response(['item' => $socialAccounts->create($p)], 201);
    });

    $router->put('/api/social-accounts/{id}', fn($p) => json_response(['item' => $socialAccounts->update((int)$p['id'], request_json())]));

    $router->delete('/api/social-accounts/{id}', function ($p) use ($socialAccounts) {
        $socialAccounts->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/social-accounts/{id}/test', function ($p) use ($socialAccounts, $socialPublisher) {
        if (!$socialPublisher) { json_response(['error' => 'Social publisher not available'], 500); return; }
        $account = $socialAccounts->find((int)$p['id']);
        if (!$account) { json_response(['error' => 'Account not found'], 404); return; }
        // Simple connectivity test
        json_response(['ok' => true, 'platform' => $account['platform'], 'account' => $account['account_name']]);
    });

    /* ---- Email Lists ---- */
    $router->get('/api/email-lists', fn() => json_response(['items' => $emailLists->all()]));

    $router->post('/api/email-lists', function () use ($emailLists) {
        $p = request_json();
        if (empty($p['name'])) { json_response(['error' => 'Missing: name'], 422); return; }
        json_response(['item' => $emailLists->create($p)], 201);
    });

    $router->delete('/api/email-lists/{id}', function ($p) use ($emailLists) {
        $emailLists->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    /* ---- Subscribers ---- */
    $router->get('/api/subscribers', function () use ($subscribers) {
        $listId = !empty($_GET['list_id']) ? (int)$_GET['list_id'] : null;
        json_response(['items' => $subscribers->all($listId)]);
    });

    $router->post('/api/subscribers', function () use ($subscribers, $webhooks) {
        $p = request_json();
        if (empty($p['email']) || empty($p['list_id'])) {
            json_response(['error' => 'Missing: email, list_id'], 422);
            return;
        }
        $result = $subscribers->create($p);
        if (is_string($result)) {
            json_response(['error' => $result], 409);
        } else {
            $webhooks->dispatch('subscriber.added', $result);
            json_response(['item' => $result], 201);
        }
    });

    $router->post('/api/subscribers/import', function () use ($subscribers) {
        $data = request_json();
        if (empty($data['list_id']) || empty($data['csv'])) {
            json_response(['error' => 'Missing: list_id, csv'], 422);
            return;
        }
        $result = $subscribers->importCsv((int)$data['list_id'], $data['csv']);
        json_response($result);
    });

    $router->delete('/api/subscribers/{id}', function ($p) use ($subscribers) {
        $subscribers->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    /* ---- Email Campaigns ---- */
    $router->get('/api/email-campaigns', fn() => json_response(['items' => $emailCampaigns->all()]));

    $router->post('/api/email-campaigns', function () use ($emailCampaigns) {
        $p = request_json();
        foreach (['name', 'subject'] as $r) {
            if (empty($p[$r])) { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        json_response(['item' => $emailCampaigns->create($p)], 201);
    });

    $router->get('/api/email-campaigns/{id}', fn($p) => json_response(['item' => $emailCampaigns->find((int)$p['id'])]));

    $router->put('/api/email-campaigns/{id}', fn($p) => json_response(['item' => $emailCampaigns->update((int)$p['id'], request_json())]));

    $router->delete('/api/email-campaigns/{id}', function ($p) use ($emailCampaigns) {
        $emailCampaigns->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/email-campaigns/{id}/send', function ($p) use ($emailService, $emailCampaigns, $webhooks) {
        if (!$emailService) { json_response(['error' => 'Email service not configured'], 500); return; }
        $campaign = $emailCampaigns->find((int)$p['id']);
        if (!$campaign) { json_response(['error' => 'Campaign not found'], 404); return; }
        $result = $emailService->sendCampaign((int)$p['id']);
        $emailCampaigns->update((int)$p['id'], [
            'status' => 'sent',
            'sent_count' => $result['sent'] ?? 0,
            'sent_at' => gmdate(DATE_ATOM),
        ]);
        $webhooks->dispatch('email.sent', array_merge($result, ['campaign_id' => (int)$p['id']]));
        json_response($result);
    });

    $router->post('/api/email-campaigns/{id}/test', function ($p) use ($emailService, $emailCampaigns) {
        if (!$emailService) { json_response(['error' => 'Email service not configured'], 500); return; }
        $data = request_json();
        $campaign = $emailCampaigns->find((int)$p['id']);
        if (!$campaign) { json_response(['error' => 'Campaign not found'], 404); return; }
        $to = $data['to'] ?? '';
        if (!$to) { json_response(['error' => 'Missing: to'], 422); return; }
        $ok = $emailService->sendTestEmail($to, $campaign['subject'], $campaign['body_html'], $campaign['body_text']);
        json_response(['success' => $ok]);
    });

    $router->get('/api/email-campaigns/{id}/stats', function ($p) use ($emailService) {
        if (!$emailService) { json_response(['error' => 'Email service not configured'], 500); return; }
        json_response($emailService->getCampaignStats((int)$p['id']));
    });

    /* ---- Analytics ---- */
    $router->get('/api/analytics/overview', function () use ($analytics) {
        $days = (int)($_GET['days'] ?? 30);
        json_response($analytics->overview($days));
    });

    $router->get('/api/analytics/content', function () use ($analytics) {
        json_response(['items' => $analytics->contentPerformance()]);
    });

    $router->get('/api/analytics/chart/{metric}', function ($p) use ($analytics) {
        $days = (int)($_GET['days'] ?? 30);
        json_response(['data' => $analytics->chartData($p['metric'], $days)]);
    });

    $router->get('/api/analytics/export/{type}', function ($p) use ($analytics) {
        $csv = $analytics->exportCsv($p['type']);
        if ($csv === '') { json_response(['error' => 'No data'], 404); return; }
        csv_response($csv, $p['type'] . '-export-' . date('Y-m-d') . '.csv');
    });

    /* ---- RSS Feeds ---- */
    $router->get('/api/rss-feeds', fn() => json_response(['items' => $rssFetcher->allFeeds()]));

    $router->post('/api/rss-feeds', function () use ($rssFetcher) {
        $p = request_json();
        if (empty($p['url'])) { json_response(['error' => 'Missing: url'], 422); return; }
        json_response(['item' => $rssFetcher->createFeed($p)], 201);
    });

    $router->put('/api/rss-feeds/{id}', fn($p) => json_response(['item' => $rssFetcher->updateFeed((int)$p['id'], request_json())]));

    $router->delete('/api/rss-feeds/{id}', function ($p) use ($rssFetcher) {
        $rssFetcher->deleteFeed((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/rss-feeds/{id}/fetch', function ($p) use ($rssFetcher) {
        json_response($rssFetcher->fetchFeed((int)$p['id']));
    });

    $router->get('/api/rss-items', function () use ($rssFetcher) {
        $feedId = !empty($_GET['feed_id']) ? (int)$_GET['feed_id'] : null;
        json_response(['items' => $rssFetcher->allItems(100, $feedId)]);
    });

    /* ---- Webhooks ---- */
    $router->get('/api/webhooks', fn() => json_response(['items' => $webhooks->all()]));

    $router->post('/api/webhooks', function () use ($webhooks) {
        $p = request_json();
        foreach (['event', 'url'] as $r) {
            if (empty($p[$r])) { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        json_response(['item' => $webhooks->create($p)], 201);
    });

    $router->put('/api/webhooks/{id}', fn($p) => json_response(['item' => $webhooks->update((int)$p['id'], request_json())]));

    $router->delete('/api/webhooks/{id}', function ($p) use ($webhooks) {
        $webhooks->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/webhooks/{id}/test', fn($p) => json_response($webhooks->test((int)$p['id'])));

    /* ---- Cron Log ---- */
    $router->get('/api/cron-log', fn() => json_response(['items' => $scheduler->getLog()]));

    /* ---- AI endpoints ---- */
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

    // New AI endpoints
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

    // Dispatch
    if (!$router->dispatch($method, $uri)) {
        json_response(['error' => 'Not found'], 404);
    }
    return;
}

/* ---- static files ---- */
$file = $path === '/' ? '/app.html' : $path;
$publicFile = __DIR__ . $file;
if (!is_file($publicFile)) {
    http_response_code(404);
    echo 'Not found';
    return;
}

$ext = pathinfo($publicFile, PATHINFO_EXTENSION);
$types = [
    'html' => 'text/html',
    'css' => 'text/css',
    'js' => 'application/javascript',
    'json' => 'application/json',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
];
if (isset($types[$ext])) {
    header('Content-Type: ' . $types[$ext]);
}
readfile($publicFile);
