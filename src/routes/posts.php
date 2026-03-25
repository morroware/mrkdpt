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
