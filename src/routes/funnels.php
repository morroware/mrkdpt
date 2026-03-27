<?php

declare(strict_types=1);

function register_funnel_routes(Router $router, FunnelRepository $funnels): void
{
    $router->get('/api/funnels', fn() => json_response(['items' => $funnels->all()]));

    $router->post('/api/funnels', function () use ($funnels) {
        $data = request_json();
        if (empty($data['name'])) {
            json_response(['error' => 'name is required'], 400);
            return;
        }
        json_response(['item' => $funnels->create($data)], 201);
    });

    $router->get('/api/funnels/{id}', function (array $params) use ($funnels) {
        $funnel = $funnels->find((int)$params['id']);
        $funnel ? json_response($funnel) : json_response(['error' => 'Not found'], 404);
    });

    $router->patch('/api/funnels/{id}', function (array $params) use ($funnels) {
        $data = request_json();
        $funnel = $funnels->update((int)$params['id'], $data);
        $funnel ? json_response($funnel) : json_response(['error' => 'Not found'], 404);
    });

    $router->post('/api/funnels/{id}/stages', function (array $params) use ($funnels) {
        $data = request_json();
        if (empty($data['name'])) {
            json_response(['error' => 'name is required'], 400);
            return;
        }
        $funnels->addStage((int)$params['id'], $data, (int)($data['stage_order'] ?? 0));
        json_response($funnels->find((int)$params['id']));
    });

    $router->patch('/api/funnels/stages/{id}', function (array $params) use ($funnels) {
        $data = request_json();
        $funnels->updateStage((int)$params['id'], $data);
        json_response(['ok' => true]);
    });

    $router->delete('/api/funnels/stages/{id}', function (array $params) use ($funnels) {
        $funnels->deleteStage((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });

    $router->delete('/api/funnels/{id}', function (array $params) use ($funnels) {
        $funnels->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });
}
