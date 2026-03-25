<?php

declare(strict_types=1);

function register_campaign_metric_routes(Router $router, CampaignMetricsRepository $campaignMetrics): void
{
    $router->get('/api/campaigns/{id}/metrics', function (array $params) use ($campaignMetrics) {
        json_response(['items' => $campaignMetrics->forCampaign((int)$params['id'])]);
    });

    $router->get('/api/campaigns/{id}/summary', function (array $params) use ($campaignMetrics) {
        json_response($campaignMetrics->summary((int)$params['id']));
    });

    $router->post('/api/campaigns/{id}/metrics', function (array $params) use ($campaignMetrics) {
        $data = request_json();
        json_response(['item' => $campaignMetrics->add((int)$params['id'], $data)], 201);
    });

    $router->delete('/api/campaign-metrics/{id}', function (array $params) use ($campaignMetrics) {
        $campaignMetrics->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });

    $router->post('/api/campaigns/compare', function () use ($campaignMetrics) {
        $data = request_json();
        $ids = $data['campaign_ids'] ?? [];
        if (empty($ids)) {
            json_response(['error' => 'Missing: campaign_ids'], 422);
            return;
        }
        json_response(['comparisons' => $campaignMetrics->compare($ids)]);
    });
}
