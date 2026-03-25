<?php

declare(strict_types=1);

function register_campaign_routes(Router $router, CampaignRepository $campaigns, Webhooks $webhooks): void
{
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
}
