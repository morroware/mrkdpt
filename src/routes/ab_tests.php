<?php

declare(strict_types=1);

function register_ab_test_routes(Router $router, AbTestRepository $abTests): void
{
    $router->get('/api/ab-tests', fn() => json_response(['items' => $abTests->all()]));

    $router->post('/api/ab-tests', function () use ($abTests) {
        $data = request_json();
        if (empty($data['name'])) {
            json_response(['error' => 'name is required'], 400);
            return;
        }
        json_response(['item' => $abTests->create($data)], 201);
    });

    $router->get('/api/ab-tests/{id}', function (array $params) use ($abTests) {
        $test = $abTests->find((int)$params['id']);
        $test ? json_response(['item' => $test]) : json_response(['error' => 'Not found'], 404);
    });

    $router->patch('/api/ab-tests/{id}', function (array $params) use ($abTests) {
        $data = request_json();
        $test = $abTests->update((int)$params['id'], $data);
        $test ? json_response(['item' => $test]) : json_response(['error' => 'Not found'], 404);
    });

    $router->post('/api/ab-tests/{id}/variants', function (array $params) use ($abTests) {
        $data = request_json();
        $abTests->addVariant((int)$params['id'], $data);
        $test = $abTests->find((int)$params['id']);
        $test ? json_response(['item' => $test]) : json_response(['error' => 'Not found'], 404);
    });

    $router->patch('/api/ab-tests/variants/{id}', function (array $params) use ($abTests) {
        $data = request_json();
        $abTests->updateVariant((int)$params['id'], $data);
        json_response(['ok' => true]);
    });

    $router->post('/api/ab-tests/variants/{id}/impression', function (array $params) use ($abTests) {
        $abTests->recordImpression((int)$params['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/ab-tests/variants/{id}/conversion', function (array $params) use ($abTests) {
        $abTests->recordConversion((int)$params['id']);
        json_response(['ok' => true]);
    });

    $router->delete('/api/ab-tests/{id}', function (array $params) use ($abTests) {
        $abTests->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });
}
