<?php

declare(strict_types=1);

function register_kpi_routes(Router $router, KpiRepository $kpis, AiLogRepository $aiLogs): void
{
    $router->get('/api/kpis', fn() => json_response(['items' => $kpis->all(), 'summary' => $kpis->summary()]));

    $router->post('/api/kpis', function () use ($kpis) {
        $p = request_json();
        foreach (['channel', 'metric_name', 'metric_value'] as $r) {
            if (!isset($p[$r]) || $p[$r] === '') { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        json_response(['item' => $kpis->create($p)], 201);
    });

    $router->get('/api/ideas', fn() => json_response(['items' => $aiLogs->ideas()]));
}
