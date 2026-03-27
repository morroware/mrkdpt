<?php

declare(strict_types=1);

function register_landing_page_routes(Router $router, LandingPageRepository $pages): void
{
    $router->get('/api/landing-pages', fn() => json_response(['items' => $pages->all()]));

    $router->post('/api/landing-pages', function () use ($pages) {
        $data = request_json();
        if (empty($data['title'])) {
            json_response(['error' => 'title is required'], 400);
            return;
        }
        json_response(['item' => $pages->create($data)], 201);
    });

    $router->get('/api/landing-pages/{id}', function (array $params) use ($pages) {
        $page = $pages->find((int)$params['id']);
        $page ? json_response(['item' => $page]) : json_response(['error' => 'Not found'], 404);
    });

    $router->patch('/api/landing-pages/{id}', function (array $params) use ($pages) {
        $data = request_json();
        $page = $pages->update((int)$params['id'], $data);
        $page ? json_response($page) : json_response(['error' => 'Not found'], 404);
    });

    $router->delete('/api/landing-pages/{id}', function (array $params) use ($pages) {
        $pages->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });
}
