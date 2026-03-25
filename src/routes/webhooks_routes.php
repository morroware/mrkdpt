<?php

declare(strict_types=1);

function register_webhook_routes(Router $router, Webhooks $webhooks): void
{
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
}
