<?php

declare(strict_types=1);

/**
 * API routes for the WordPress Plugin Connector.
 *
 * These endpoints are consumed by the "Marketing Suite Connector" WordPress plugin
 * to pull/push content, fetch dashboard metrics, and proxy AI requests.
 *
 * All endpoints require Bearer token authentication (no CSRF needed).
 */
function register_wordpress_plugin_routes(
    Router $router,
    PostRepository $posts,
    CampaignRepository $campaigns,
    ContactRepository $contactRepo,
    PDO $pdo
): void {

    // -----------------------------------------------------------------
    //  Status / health check for the plugin
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/status', function () use ($pdo) {
        $postCount     = (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
        $campaignCount = (int) $pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();

        json_response([
            'ok'        => true,
            'service'   => 'marketing-suite',
            'plugin'    => 'wordpress-connector',
            'posts'     => $postCount,
            'campaigns' => $campaignCount,
        ]);
    });

    // -----------------------------------------------------------------
    //  Dashboard metrics for the WP dashboard widget / page
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/dashboard', function () use ($posts, $campaigns, $contactRepo, $pdo) {
        $postMetrics = $posts->metrics();

        $campaignCount = (int) $pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
        $contactCount  = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
        $draftCount    = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
        $memoryCount   = (int) $pdo->query('SELECT COUNT(*) FROM ai_shared_memory')->fetchColumn();

        // Recent posts (last 10)
        $recentStmt = $pdo->query('SELECT id, title, status, platform, created_at FROM posts ORDER BY created_at DESC LIMIT 10');
        $recentPosts = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        // Active campaigns
        $campStmt = $pdo->query("SELECT id, name, status, channel, objective FROM campaigns ORDER BY created_at DESC LIMIT 5");
        $campaignsList = $campStmt->fetchAll(PDO::FETCH_ASSOC);

        json_response([
            'total_posts'     => $postMetrics['posts'],
            'published_posts' => $postMetrics['published'],
            'scheduled_posts' => $postMetrics['scheduled'],
            'draft_posts'     => $draftCount,
            'campaigns'       => $campaignCount,
            'contacts'        => $contactCount,
            'shared_memory_items' => $memoryCount,
            'avg_ai_score'    => $postMetrics['avg_score'],
            'recent_posts'    => $recentPosts,
            'campaigns_list'  => $campaignsList,
        ]);
    });

    // -----------------------------------------------------------------
    //  List posts (for WP plugin "Pull" feature)
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/posts', function () use ($posts) {
        $status   = !empty($_GET['status']) ? $_GET['status'] : null;
        $platform = !empty($_GET['platform']) ? $_GET['platform'] : null;

        $items = $posts->all($status, $platform);

        json_response(['items' => $items]);
    });

    // -----------------------------------------------------------------
    //  Get single post
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/posts/{id}', function ($params) use ($posts) {
        $item = $posts->find((int) $params['id']);
        if (!$item) {
            json_response(['error' => 'Post not found'], 404);
            return;
        }
        json_response(['item' => $item]);
    });

    // -----------------------------------------------------------------
    //  Create post (WP plugin "Push" creates a new post)
    // -----------------------------------------------------------------
    $router->post('/api/wordpress-plugin/posts', function () use ($posts) {
        $p = request_json();

        if (empty($p['title'])) {
            json_response(['error' => 'Title is required'], 422);
            return;
        }

        $item = $posts->create([
            'title'        => $p['title'],
            'body'         => $p['body'] ?? '',
            'platform'     => $p['platform'] ?? 'wordpress',
            'content_type' => $p['content_type'] ?? 'blog_post',
            'status'       => $p['status'] ?? 'draft',
            'tags'         => is_array($p['tags'] ?? null) ? implode(',', $p['tags']) : ($p['tags'] ?? ''),
            'cta'          => $p['cta'] ?? '',
            'scheduled_for' => $p['scheduled_for'] ?? null,
        ]);

        json_response(['item' => $item], 201);
    });

    // -----------------------------------------------------------------
    //  Update post (WP plugin re-push)
    // -----------------------------------------------------------------
    $router->put('/api/wordpress-plugin/posts/{id}', function ($params) use ($posts) {
        $id = (int) $params['id'];
        $existing = $posts->find($id);
        if (!$existing) {
            json_response(['error' => 'Post not found'], 404);
            return;
        }

        $p = request_json();
        $update = [];

        foreach (['title', 'body', 'platform', 'content_type', 'status', 'cta', 'scheduled_for'] as $field) {
            if (array_key_exists($field, $p)) {
                $update[$field] = $p[$field];
            }
        }

        if (isset($p['tags'])) {
            $update['tags'] = is_array($p['tags']) ? implode(',', $p['tags']) : $p['tags'];
        }

        $item = $posts->update($id, $update);
        json_response(['item' => $item]);
    });

    // -----------------------------------------------------------------
    //  Shared Memory Sync (WordPress <-> Marketing Suite AI memory)
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/memory', function () use ($pdo) {
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $stmt = $pdo->prepare("SELECT id, memory_key, content, source, source_ref, tags, metadata_json, created_at, updated_at
                               FROM ai_shared_memory ORDER BY updated_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    $router->post('/api/wordpress-plugin/memory', function () use ($pdo) {
        $p = request_json();
        $entries = $p['items'] ?? null;

        if (is_array($entries)) {
            $created = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $content = trim((string)($entry['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                $now = gmdate(DATE_ATOM);
                $stmt = $pdo->prepare("INSERT INTO ai_shared_memory (memory_key, content, source, source_ref, tags, metadata_json, created_at, updated_at)
                                       VALUES (:memory_key, :content, :source, :source_ref, :tags, :metadata_json, :created_at, :updated_at)");
                $stmt->execute([
                    ':memory_key' => trim((string)($entry['memory_key'] ?? '')),
                    ':content' => $content,
                    ':source' => trim((string)($entry['source'] ?? 'wordpress')),
                    ':source_ref' => trim((string)($entry['source_ref'] ?? '')),
                    ':tags' => trim((string)($entry['tags'] ?? '')),
                    ':metadata_json' => json_encode($entry['metadata'] ?? new stdClass(), JSON_UNESCAPED_SLASHES),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $created[] = (int)$pdo->lastInsertId();
            }
            json_response(['ok' => true, 'created_ids' => $created], 201);
            return;
        }

        $content = trim((string)($p['content'] ?? ''));
        if ($content === '') {
            json_response(['error' => 'Missing: content (or items[])'], 422);
            return;
        }

        $now = gmdate(DATE_ATOM);
        $stmt = $pdo->prepare("INSERT INTO ai_shared_memory (memory_key, content, source, source_ref, tags, metadata_json, created_at, updated_at)
                               VALUES (:memory_key, :content, :source, :source_ref, :tags, :metadata_json, :created_at, :updated_at)");
        $stmt->execute([
            ':memory_key' => trim((string)($p['memory_key'] ?? '')),
            ':content' => $content,
            ':source' => trim((string)($p['source'] ?? 'wordpress')),
            ':source_ref' => trim((string)($p['source_ref'] ?? '')),
            ':tags' => trim((string)($p['tags'] ?? '')),
            ':metadata_json' => json_encode($p['metadata'] ?? new stdClass(), JSON_UNESCAPED_SLASHES),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        json_response(['item' => ['id' => (int)$pdo->lastInsertId()]], 201);
    });
}
