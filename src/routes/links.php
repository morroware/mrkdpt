<?php

declare(strict_types=1);

function register_link_routes(Router $router, LinkShortener $shortener): void
{
    $router->get('/api/links', fn() => json_response(['items' => $shortener->all()]));

    $router->post('/api/links', function () use ($shortener) {
        $data = request_json();
        if (empty($data['destination_url'])) {
            json_response(['error' => 'destination_url is required'], 400);
            return;
        }
        json_response(['item' => $shortener->create($data)], 201);
    });

    $router->get('/api/links/{id}', function (array $params) use ($shortener) {
        $link = $shortener->find((int)$params['id']);
        $link ? json_response(['item' => $link]) : json_response(['error' => 'Not found'], 404);
    });

    $router->get('/api/links/{id}/stats', function (array $params) use ($shortener) {
        json_response($shortener->clickStats((int)$params['id'], 'short_link'));
    });

    $router->delete('/api/links/{id}', function (array $params) use ($shortener) {
        $shortener->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });
}
