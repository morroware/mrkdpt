<?php

declare(strict_types=1);

function register_segment_routes(Router $router, SegmentRepository $segments): void
{
    $router->get('/api/segments', fn() => json_response(['items' => $segments->all()]));

    $router->get('/api/segments/criteria-fields', fn() => json_response(SegmentRepository::criteriaFields()));

    $router->post('/api/segments', function () use ($segments) {
        $data = request_json();
        if (empty($data['name'])) {
            json_response(['error' => 'Missing: name'], 422);
            return;
        }
        json_response(['item' => $segments->create($data)], 201);
    });

    $router->get('/api/segments/{id}', function (array $params) use ($segments) {
        $seg = $segments->find((int)$params['id']);
        if (!$seg) {
            json_response(['error' => 'Not found'], 404);
            return;
        }
        json_response(['item' => $seg]);
    });

    $router->get('/api/segments/{id}/contacts', function (array $params) use ($segments) {
        json_response(['items' => $segments->contacts((int)$params['id'])]);
    });

    $router->put('/api/segments/{id}', function (array $params) use ($segments) {
        $item = $segments->update((int)$params['id'], request_json());
        $item ? json_response(['item' => $item]) : json_response(['error' => 'Not found'], 404);
    });

    $router->post('/api/segments/{id}/recompute', function (array $params) use ($segments) {
        $segments->recompute((int)$params['id']);
        json_response(['item' => $segments->find((int)$params['id'])]);
    });

    $router->delete('/api/segments/{id}', function (array $params) use ($segments) {
        $segments->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });
}
