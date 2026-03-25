<?php

declare(strict_types=1);

function register_post_routes(Router $router, PostRepository $posts, Analytics $analytics, Webhooks $webhooks): void
{
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

    // Calendar view: posts grouped by date for a given month
    $router->get('/api/posts/calendar', function () use ($posts) {
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('m'));
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $all = $posts->all();
        $grouped = [];
        foreach ($all as $p) {
            $d = $p['scheduled_for'] ?: $p['created_at'];
            if (!$d) continue;
            $pDate = substr($d, 0, 10);
            if ($pDate >= $start && $pDate <= $end) {
                $grouped[$pDate][] = $p;
            }
        }
        json_response(['month' => $start, 'days' => $grouped]);
    });

    // Content approval workflow
    $router->post('/api/posts/{id}/approve', function ($p) use ($posts, $webhooks) {
        $data = request_json();
        $item = $posts->update((int)$p['id'], [
            'approval_status' => 'approved',
            'approved_by' => $data['approved_by'] ?? 'admin',
            'approved_at' => gmdate(DATE_ATOM),
            'review_notes' => $data['notes'] ?? '',
        ]);
        json_response(['item' => $item]);
    });

    $router->post('/api/posts/{id}/reject', function ($p) use ($posts) {
        $data = request_json();
        $item = $posts->update((int)$p['id'], [
            'approval_status' => 'rejected',
            'review_notes' => $data['notes'] ?? 'Content needs revision',
        ]);
        json_response(['item' => $item]);
    });

    $router->post('/api/posts/{id}/request-review', function ($p) use ($posts) {
        $item = $posts->update((int)$p['id'], [
            'approval_status' => 'pending',
        ]);
        json_response(['item' => $item]);
    });

    // Content notes/comments
    $router->get('/api/posts/{id}/notes', function ($p) use ($posts) {
        global $pdo;
        $stmt = $pdo->prepare('SELECT * FROM content_notes WHERE post_id = :pid ORDER BY id DESC');
        $stmt->execute([':pid' => (int)$p['id']]);
        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    $router->post('/api/posts/{id}/notes', function ($p) use ($posts) {
        global $pdo;
        $data = request_json();
        $pdo->prepare('INSERT INTO content_notes(post_id, author, note, created_at) VALUES(:pid,:a,:n,:c)')->execute([
            ':pid' => (int)$p['id'],
            ':a' => $data['author'] ?? 'admin',
            ':n' => $data['note'] ?? '',
            ':c' => gmdate(DATE_ATOM),
        ]);
        json_response(['ok' => true], 201);
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
}
