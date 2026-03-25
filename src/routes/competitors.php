<?php

declare(strict_types=1);

function register_competitor_routes(Router $router, CompetitorRepository $competitors): void
{
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
}
