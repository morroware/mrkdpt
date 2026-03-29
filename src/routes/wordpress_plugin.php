<?php

declare(strict_types=1);

/**
 * API routes for the WordPress Plugin Connector.
 *
 * These endpoints are consumed by the "Marketing Suite Connector" WordPress plugin
 * to pull/push content, fetch dashboard metrics, proxy AI requests, and perform
 * bidirectional sync of posts, pages, categories, tags, and media.
 *
 * All endpoints require Bearer token authentication (no CSRF needed).
 */
function register_wordpress_plugin_routes(
    Router $router,
    PostRepository $posts,
    CampaignRepository $campaigns,
    ContactRepository $contactRepo,
    PDO $pdo,
    ?SocialPublisher $socialPublisher,
    SocialAccountRepository $socialAccounts
): void {

    // Helper: resolve WordPress social account by ID or find first WordPress account
    $resolveWpAccount = function () use ($socialAccounts, $pdo): ?array {
        $accountId = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

        if ($accountId > 0) {
            $acct = $socialAccounts->find($accountId);
            if ($acct && ($acct['platform'] ?? '') === 'wordpress') {
                return $acct;
            }
            return null;
        }

        // Find first WordPress account
        $stmt = $pdo->query("SELECT * FROM social_accounts WHERE platform = 'wordpress' ORDER BY id ASC LIMIT 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['meta_json'] = json_decode($row['meta_json'] ?? '{}', true) ?: [];
        return $row;
    };

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
            'version'   => '2.0.0',
            'posts'     => $postCount,
            'campaigns' => $campaignCount,
            'capabilities' => [
                'pull_posts', 'push_posts', 'sync_categories', 'sync_tags',
                'fetch_pages', 'publish_to_wp', 'webhooks', 'ai_content',
                'ai_refine', 'shared_memory', 'media_sync', 'bulk_operations',
            ],
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
        $wpSyncCount   = (int) $pdo->query('SELECT COUNT(*) FROM wp_sync_map')->fetchColumn();

        // Recent posts (last 10)
        $recentStmt = $pdo->query('SELECT id, title, status, platform, created_at FROM posts ORDER BY created_at DESC LIMIT 10');
        $recentPosts = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        // Active campaigns
        $campStmt = $pdo->query("SELECT id, name, status, channel, objective FROM campaigns ORDER BY created_at DESC LIMIT 5");
        $campaignsList = $campStmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent sync activity
        $syncStmt = $pdo->query('SELECT local_type, local_id, wp_id, wp_type, sync_direction, last_synced_at FROM wp_sync_map ORDER BY last_synced_at DESC LIMIT 5');
        $recentSyncs = $syncStmt->fetchAll(PDO::FETCH_ASSOC);

        json_response([
            'total_posts'         => $postMetrics['posts'],
            'published_posts'     => $postMetrics['published'],
            'scheduled_posts'     => $postMetrics['scheduled'],
            'draft_posts'         => $draftCount,
            'campaigns'           => $campaignCount,
            'contacts'            => $contactCount,
            'shared_memory_items' => $memoryCount,
            'synced_items'        => $wpSyncCount,
            'avg_ai_score'        => $postMetrics['avg_score'],
            'recent_posts'        => $recentPosts,
            'campaigns_list'      => $campaignsList,
            'recent_syncs'        => $recentSyncs,
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
    //  Delete post
    // -----------------------------------------------------------------
    $router->delete('/api/wordpress-plugin/posts/{id}', function ($params) use ($posts, $pdo) {
        $id = (int) $params['id'];
        $existing = $posts->find($id);
        if (!$existing) {
            json_response(['error' => 'Post not found'], 404);
            return;
        }

        $posts->delete($id);

        // Clean up sync mapping
        $stmt = $pdo->prepare('DELETE FROM wp_sync_map WHERE local_type = "post" AND local_id = :id');
        $stmt->execute([':id' => $id]);

        json_response(['ok' => true]);
    });

    // -----------------------------------------------------------------
    //  Bulk push posts to WordPress
    // -----------------------------------------------------------------
    $router->post('/api/wordpress-plugin/bulk-push', function () use ($posts, $pdo, $socialPublisher, $resolveWpAccount) {
        $p = request_json();
        $postIds = $p['post_ids'] ?? [];

        if (!is_array($postIds) || empty($postIds)) {
            json_response(['error' => 'post_ids array is required'], 422);
            return;
        }

        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $results = [];
        foreach ($postIds as $postId) {
            $post = $posts->find((int)$postId);
            if (!$post) {
                $results[] = ['post_id' => $postId, 'success' => false, 'error' => 'Not found'];
                continue;
            }

            $postData = [
                'title'     => $post['title'] ?? '',
                'body'      => $post['body'] ?? '',
                'wp_status' => $p['wp_status'] ?? 'draft',
                'excerpt'   => '',
            ];

            // Check if already synced
            $syncStmt = $pdo->prepare('SELECT wp_id FROM wp_sync_map WHERE social_account_id = :aid AND local_type = "post" AND local_id = :lid');
            $syncStmt->execute([':aid' => $account['id'], ':lid' => $postId]);
            $existingWpId = $syncStmt->fetchColumn();
            if ($existingWpId) {
                $postData['wp_post_id'] = (int)$existingWpId;
            }

            $result = $socialPublisher->publishStructuredToWordPress($account, $postData);

            if ($result['success'] && !empty($result['external_id'])) {
                $now = gmdate(DATE_ATOM);
                $syncHash = md5(($post['title'] ?? '') . ($post['body'] ?? ''));

                if ($existingWpId) {
                    $upd = $pdo->prepare('UPDATE wp_sync_map SET wp_id = :wpid, sync_hash = :hash, last_synced_at = :now WHERE social_account_id = :aid AND local_type = "post" AND local_id = :lid');
                    $upd->execute([':wpid' => (int)$result['external_id'], ':hash' => $syncHash, ':now' => $now, ':aid' => $account['id'], ':lid' => $postId]);
                } else {
                    $ins = $pdo->prepare('INSERT INTO wp_sync_map (social_account_id, local_type, local_id, wp_id, wp_type, sync_direction, sync_hash, last_synced_at, created_at) VALUES (:aid, "post", :lid, :wpid, "post", "push", :hash, :now, :now)');
                    $ins->execute([':aid' => $account['id'], ':lid' => $postId, ':wpid' => (int)$result['external_id'], ':hash' => $syncHash, ':now' => $now]);
                }
            }

            $results[] = ['post_id' => $postId, 'success' => $result['success'], 'wp_id' => $result['external_id'] ?? null, 'error' => $result['error'] ?? null];
        }

        json_response(['results' => $results]);
    });

    // -----------------------------------------------------------------
    //  Bulk import from WordPress
    // -----------------------------------------------------------------
    $router->post('/api/wordpress-plugin/bulk-import', function () use ($posts, $pdo, $socialPublisher, $resolveWpAccount) {
        $p = request_json();
        $wpPostIds = $p['wp_post_ids'] ?? [];

        if (!is_array($wpPostIds) || empty($wpPostIds)) {
            json_response(['error' => 'wp_post_ids array is required'], 422);
            return;
        }

        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $results = [];
        foreach ($wpPostIds as $wpPostId) {
            $wpPostId = (int)$wpPostId;

            // Check if already imported
            $syncStmt = $pdo->prepare('SELECT local_id FROM wp_sync_map WHERE social_account_id = :aid AND wp_type = "post" AND wp_id = :wpid');
            $syncStmt->execute([':aid' => $account['id'], ':wpid' => $wpPostId]);
            $existingLocalId = $syncStmt->fetchColumn();

            if ($existingLocalId) {
                $results[] = ['wp_id' => $wpPostId, 'success' => true, 'local_id' => (int)$existingLocalId, 'existing' => true, 'error' => null];
                continue;
            }

            $fetchResult = $socialPublisher->fetchWordPressPost($account, $wpPostId);
            if (!$fetchResult['success']) {
                $results[] = ['wp_id' => $wpPostId, 'success' => false, 'error' => $fetchResult['error']];
                continue;
            }

            $wpPost = $fetchResult['post'];
            $title   = $wpPost['title']['rendered'] ?? $wpPost['title'] ?? 'Imported Post';
            $body    = $wpPost['content']['rendered'] ?? $wpPost['content'] ?? '';
            $excerpt = $wpPost['excerpt']['rendered'] ?? '';
            $status  = ($wpPost['status'] ?? 'draft') === 'publish' ? 'published' : 'draft';

            // Extract tags from embedded data
            $tags = [];
            if (!empty($wpPost['_embedded']['wp:term'])) {
                foreach ($wpPost['_embedded']['wp:term'] as $termGroup) {
                    if (is_array($termGroup)) {
                        foreach ($termGroup as $term) {
                            $tags[] = $term['name'] ?? '';
                        }
                    }
                }
            }

            $localPost = $posts->create([
                'title'        => strip_tags($title),
                'body'         => $body,
                'platform'     => 'wordpress',
                'content_type' => 'blog_post',
                'status'       => $status,
                'tags'         => implode(',', array_filter($tags)),
            ]);

            $now = gmdate(DATE_ATOM);
            $syncHash = md5(strip_tags($title) . $body);
            $ins = $pdo->prepare('INSERT INTO wp_sync_map (social_account_id, local_type, local_id, wp_id, wp_type, sync_direction, sync_hash, last_synced_at, created_at) VALUES (:aid, "post", :lid, :wpid, "post", "pull", :hash, :now, :now)');
            $ins->execute([':aid' => $account['id'], ':lid' => $localPost['id'], ':wpid' => $wpPostId, ':hash' => $syncHash, ':now' => $now]);

            $results[] = ['wp_id' => $wpPostId, 'success' => true, 'local_id' => $localPost['id'], 'existing' => false, 'error' => null];
        }

        json_response(['results' => $results]);
    });

    // =================================================================
    //  WordPress Site Data Endpoints (pull from WP via social account)
    // =================================================================

    // -----------------------------------------------------------------
    //  Fetch posts from the connected WordPress site
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/wp-posts', function () use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $params = [];
        foreach (['per_page', 'page', 'status', 'search', 'categories', 'tags', 'after', 'before', 'orderby', 'order'] as $key) {
            if (!empty($_GET[$key])) {
                $params[$key] = $_GET[$key];
            }
        }

        $result = $socialPublisher->fetchWordPressPosts($account, $params);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response([
            'items'       => $result['posts'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ]);
    });

    // -----------------------------------------------------------------
    //  Fetch single WP post
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/wp-posts/{id}', function ($params) use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $result = $socialPublisher->fetchWordPressPost($account, (int)$params['id']);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response(['item' => $result['post']]);
    });

    // -----------------------------------------------------------------
    //  Fetch pages from the connected WordPress site
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/wp-pages', function () use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $params = [];
        foreach (['per_page', 'page', 'status', 'search', 'orderby', 'order'] as $key) {
            if (!empty($_GET[$key])) {
                $params[$key] = $_GET[$key];
            }
        }

        $result = $socialPublisher->fetchWordPressPages($account, $params);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response([
            'items'       => $result['pages'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ]);
    });

    // -----------------------------------------------------------------
    //  Fetch categories from the connected WordPress site
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/wp-categories', function () use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $result = $socialPublisher->fetchWordPressCategories($account, [
            'per_page' => (int)($_GET['per_page'] ?? 100),
            'search'   => $_GET['search'] ?? '',
        ]);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response(['items' => $result['items']]);
    });

    // -----------------------------------------------------------------
    //  Create category on the WordPress site
    // -----------------------------------------------------------------
    $router->post('/api/wordpress-plugin/wp-categories', function () use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $p = request_json();
        if (empty($p['name'])) {
            json_response(['error' => 'Category name is required'], 422);
            return;
        }

        $result = $socialPublisher->createWordPressCategory(
            $account,
            $p['name'],
            isset($p['parent']) ? (int)$p['parent'] : null,
            $p['description'] ?? ''
        );

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response(['item' => $result['item']], 201);
    });

    // -----------------------------------------------------------------
    //  Fetch tags from the connected WordPress site
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/wp-tags', function () use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $result = $socialPublisher->fetchWordPressTags($account, [
            'per_page' => (int)($_GET['per_page'] ?? 100),
            'search'   => $_GET['search'] ?? '',
        ]);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response(['items' => $result['items']]);
    });

    // -----------------------------------------------------------------
    //  Create tag on the WordPress site
    // -----------------------------------------------------------------
    $router->post('/api/wordpress-plugin/wp-tags', function () use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $p = request_json();
        if (empty($p['name'])) {
            json_response(['error' => 'Tag name is required'], 422);
            return;
        }

        $result = $socialPublisher->createWordPressTag($account, $p['name'], $p['description'] ?? '');

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response(['item' => $result['item']], 201);
    });

    // -----------------------------------------------------------------
    //  Fetch media from the connected WordPress site
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/wp-media', function () use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $result = $socialPublisher->fetchWordPressMedia($account, [
            'per_page' => (int)($_GET['per_page'] ?? 20),
            'page'     => (int)($_GET['page'] ?? 1),
        ]);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response(['items' => $result['items']]);
    });

    // -----------------------------------------------------------------
    //  Fetch WordPress site info
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/wp-site-info', function () use ($socialPublisher, $resolveWpAccount) {
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $result = $socialPublisher->fetchWordPressSiteInfo($account);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        unset($result['success'], $result['error']);
        json_response($result);
    });

    // -----------------------------------------------------------------
    //  Publish a local post to the connected WordPress site
    // -----------------------------------------------------------------
    $router->post('/api/wordpress-plugin/publish-to-wp', function () use ($posts, $pdo, $socialPublisher, $resolveWpAccount) {
        $p = request_json();
        $postId = (int)($p['post_id'] ?? 0);

        if ($postId <= 0) {
            json_response(['error' => 'post_id is required'], 422);
            return;
        }

        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $post = $posts->find($postId);
        if (!$post) {
            json_response(['error' => 'Post not found'], 404);
            return;
        }

        $postData = [
            'title'     => $post['title'] ?? '',
            'body'      => $post['body'] ?? '',
            'wp_status' => $p['wp_status'] ?? ($account['meta_json']['status'] ?? 'draft'),
            'excerpt'   => $post['excerpt'] ?? '',
        ];

        // Resolve categories and tags by name
        if (!empty($p['wp_categories']) && is_array($p['wp_categories'])) {
            $postData['categories'] = $p['wp_categories'];
        }
        if (!empty($p['wp_tags']) && is_array($p['wp_tags'])) {
            $postData['tags'] = $p['wp_tags'];
        }

        // Check if already synced
        $syncStmt = $pdo->prepare('SELECT wp_id FROM wp_sync_map WHERE social_account_id = :aid AND local_type = "post" AND local_id = :lid');
        $syncStmt->execute([':aid' => $account['id'], ':lid' => $postId]);
        $existingWpId = $syncStmt->fetchColumn();
        if ($existingWpId) {
            $postData['wp_post_id'] = (int)$existingWpId;
        }

        $result = $socialPublisher->publishStructuredToWordPress($account, $postData);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        // Record sync mapping
        $now = gmdate(DATE_ATOM);
        $syncHash = md5(($post['title'] ?? '') . ($post['body'] ?? ''));

        if ($existingWpId) {
            $upd = $pdo->prepare('UPDATE wp_sync_map SET wp_id = :wpid, sync_hash = :hash, last_synced_at = :now WHERE social_account_id = :aid AND local_type = "post" AND local_id = :lid');
            $upd->execute([':wpid' => (int)$result['external_id'], ':hash' => $syncHash, ':now' => $now, ':aid' => $account['id'], ':lid' => $postId]);
        } else {
            $ins = $pdo->prepare('INSERT INTO wp_sync_map (social_account_id, local_type, local_id, wp_id, wp_type, sync_direction, sync_hash, last_synced_at, created_at) VALUES (:aid, "post", :lid, :wpid, "post", "push", :hash, :now, :now)');
            $ins->execute([':aid' => $account['id'], ':lid' => $postId, ':wpid' => (int)$result['external_id'], ':hash' => $syncHash, ':now' => $now]);
        }

        json_response([
            'success'     => true,
            'wp_post_id'  => (int)$result['external_id'],
            'wp_post_url' => $result['post']['link'] ?? null,
            'message'     => $existingWpId ? 'Post updated on WordPress.' : 'Post published to WordPress.',
        ]);
    });

    // -----------------------------------------------------------------
    //  Update a post on the connected WordPress site
    // -----------------------------------------------------------------
    $router->put('/api/wordpress-plugin/wp-posts/{id}', function ($params) use ($socialPublisher, $resolveWpAccount) {
        $wpPostId = (int) $params['id'];
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $p = request_json();
        $fields = [];
        foreach (['title', 'content', 'status', 'excerpt', 'slug', 'categories', 'tags', 'featured_media', 'date'] as $key) {
            if (array_key_exists($key, $p)) {
                $fields[$key] = $p[$key];
            }
        }

        if (empty($fields)) {
            json_response(['error' => 'No fields to update'], 422);
            return;
        }

        $result = $socialPublisher->updateWordPressPost($account, $wpPostId, $fields);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        json_response(['success' => true, 'item' => $result['post']]);
    });

    // -----------------------------------------------------------------
    //  Delete a post on the connected WordPress site
    // -----------------------------------------------------------------
    $router->delete('/api/wordpress-plugin/wp-posts/{id}', function ($params) use ($pdo, $socialPublisher, $resolveWpAccount) {
        $wpPostId = (int) $params['id'];
        $account = $resolveWpAccount();
        if (!$account || !$socialPublisher) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $force = !empty($_GET['force']);
        $result = $socialPublisher->deleteWordPressPost($account, $wpPostId, $force);

        if (!$result['success']) {
            json_response(['error' => $result['error']], 502);
            return;
        }

        // Clean up sync mapping
        $stmt = $pdo->prepare('DELETE FROM wp_sync_map WHERE social_account_id = :aid AND wp_type = "post" AND wp_id = :wpid');
        $stmt->execute([':aid' => $account['id'], ':wpid' => $wpPostId]);

        json_response(['ok' => true]);
    });

    // =================================================================
    //  Sync Map Endpoints
    // =================================================================

    // -----------------------------------------------------------------
    //  Get sync status for local posts
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/sync-map', function () use ($pdo, $resolveWpAccount) {
        $account = $resolveWpAccount();
        $accountId = $account ? $account['id'] : 0;

        $localType = $_GET['local_type'] ?? 'post';
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));

        $stmt = $pdo->prepare('SELECT * FROM wp_sync_map WHERE social_account_id = :aid AND local_type = :lt ORDER BY last_synced_at DESC LIMIT :lim');
        $stmt->bindValue(':aid', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':lt', $localType, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    // -----------------------------------------------------------------
    //  Taxonomy mapping
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/taxonomy-map', function () use ($pdo, $resolveWpAccount) {
        $account = $resolveWpAccount();
        $accountId = $account ? $account['id'] : 0;
        $taxonomy = $_GET['taxonomy'] ?? 'category';

        $stmt = $pdo->prepare('SELECT * FROM wp_taxonomy_map WHERE social_account_id = :aid AND wp_taxonomy = :tax ORDER BY wp_term_name ASC');
        $stmt->execute([':aid' => $accountId, ':tax' => $taxonomy]);

        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    $router->post('/api/wordpress-plugin/taxonomy-map', function () use ($pdo, $resolveWpAccount) {
        $p = request_json();
        $account = $resolveWpAccount();
        if (!$account) {
            json_response(['error' => 'No WordPress account configured'], 400);
            return;
        }

        $localValue  = trim((string)($p['local_value'] ?? ''));
        $taxonomy    = trim((string)($p['taxonomy'] ?? 'category'));
        $wpTermId    = (int)($p['wp_term_id'] ?? 0);
        $wpTermName  = trim((string)($p['wp_term_name'] ?? ''));

        if ($localValue === '' || $wpTermId <= 0) {
            json_response(['error' => 'local_value and wp_term_id are required'], 422);
            return;
        }

        $now = gmdate(DATE_ATOM);
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO wp_taxonomy_map (social_account_id, local_value, wp_taxonomy, wp_term_id, wp_term_name, created_at) VALUES (:aid, :lv, :tax, :tid, :tn, :now)');
        $stmt->execute([
            ':aid' => $account['id'],
            ':lv'  => $localValue,
            ':tax' => $taxonomy,
            ':tid' => $wpTermId,
            ':tn'  => $wpTermName,
            ':now' => $now,
        ]);

        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    });

    $router->delete('/api/wordpress-plugin/taxonomy-map/{id}', function ($params) use ($pdo) {
        $id = (int)$params['id'];
        $stmt = $pdo->prepare('DELETE FROM wp_taxonomy_map WHERE id = :id');
        $stmt->execute([':id' => $id]);
        json_response(['ok' => true]);
    });

    // =================================================================
    //  Webhook Endpoint (receives events from WP plugin)
    // =================================================================

    $router->post('/api/wordpress-plugin/webhook', function () use ($posts, $pdo, $resolveWpAccount) {
        $p = request_json();
        $event    = trim((string)($p['event'] ?? ''));
        $wpPostId = (int)($p['wp_post_id'] ?? 0);

        if ($event === '') {
            json_response(['error' => 'event is required'], 422);
            return;
        }

        $account = $resolveWpAccount();
        $accountId = $account ? $account['id'] : null;

        // Log the webhook
        $now  = gmdate(DATE_ATOM);
        $stmt = $pdo->prepare('INSERT INTO wp_webhook_log (social_account_id, event, wp_post_id, payload_json, created_at) VALUES (:aid, :event, :wpid, :payload, :now)');
        $stmt->execute([
            ':aid'     => $accountId,
            ':event'   => $event,
            ':wpid'    => $wpPostId ?: null,
            ':payload' => json_encode($p, JSON_UNESCAPED_SLASHES),
            ':now'     => $now,
        ]);

        // Process known events
        $processed = false;

        if ($event === 'post_published' || $event === 'post_updated') {
            if ($wpPostId > 0 && $accountId) {
                // Auto-import or update the local copy
                $syncStmt = $pdo->prepare('SELECT local_id FROM wp_sync_map WHERE social_account_id = :aid AND wp_type = "post" AND wp_id = :wpid');
                $syncStmt->execute([':aid' => $accountId, ':wpid' => $wpPostId]);
                $localId = $syncStmt->fetchColumn();

                if ($localId) {
                    // Update existing local post with new data from webhook payload
                    $update = [];
                    if (!empty($p['title'])) $update['title'] = $p['title'];
                    if (!empty($p['content'])) $update['body'] = $p['content'];
                    if (!empty($p['status'])) {
                        $update['status'] = $p['status'] === 'publish' ? 'published' : ($p['status'] === 'draft' ? 'draft' : $p['status']);
                    }
                    if (!empty($update)) {
                        $posts->update((int)$localId, $update);
                    }
                    $processed = true;
                }
            }
        } elseif ($event === 'post_deleted' || $event === 'post_trashed') {
            if ($wpPostId > 0 && $accountId) {
                // Remove sync mapping (don't delete local post, just unlink)
                $delStmt = $pdo->prepare('DELETE FROM wp_sync_map WHERE social_account_id = :aid AND wp_type = "post" AND wp_id = :wpid');
                $delStmt->execute([':aid' => $accountId, ':wpid' => $wpPostId]);
                $processed = true;
            }
        }

        // Mark as processed
        if ($processed) {
            $logId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE wp_webhook_log SET processed = 1 WHERE id = :id')->execute([':id' => $logId]);
        }

        json_response(['ok' => true, 'processed' => $processed]);
    });

    // -----------------------------------------------------------------
    //  Webhook log
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/webhook-log', function () use ($pdo) {
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $stmt = $pdo->prepare('SELECT * FROM wp_webhook_log ORDER BY created_at DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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

    // -----------------------------------------------------------------
    //  List WordPress social accounts
    // -----------------------------------------------------------------
    $router->get('/api/wordpress-plugin/accounts', function () use ($pdo) {
        $stmt = $pdo->query("SELECT id, platform, account_name, meta_json, created_at FROM social_accounts WHERE platform = 'wordpress' ORDER BY id ASC");
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($accounts as &$acct) {
            $acct['meta_json'] = json_decode($acct['meta_json'] ?? '{}', true) ?: [];
            // Never expose credentials
            unset($acct['access_token'], $acct['refresh_token']);
        }
        json_response(['items' => $accounts]);
    });
}
