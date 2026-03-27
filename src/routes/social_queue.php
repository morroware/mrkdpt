<?php

declare(strict_types=1);

function register_social_queue_routes(Router $router, SocialQueue $socialQueue): void
{
    $router->get('/api/social-queue', function () use ($socialQueue) {
        $status = $_GET['status'] ?? null;
        json_response(['items' => $socialQueue->all($status)]);
    });

    $router->get('/api/social-queue/metrics', fn() => json_response($socialQueue->metrics()));

    $router->get('/api/social-queue/best-times', function () use ($socialQueue) {
        $platform = $_GET['platform'] ?? '';
        json_response(['items' => $socialQueue->bestTimes($platform)]);
    });

    $router->post('/api/social-queue', function () use ($socialQueue) {
        $data = request_json();
        if (empty($data['post_id']) || empty($data['social_account_id'])) {
            json_response(['error' => 'Missing: post_id, social_account_id'], 422);
            return;
        }
        json_response(['item' => $socialQueue->enqueue($data)], 201);
    });

    $router->patch('/api/social-queue/{id}', function (array $params) use ($socialQueue) {
        $data = request_json();
        if (isset($data['priority'])) {
            $socialQueue->reorder((int)$params['id'], (int)$data['priority']);
        }
        if (!empty($data['status'])) {
            $socialQueue->updateStatus((int)$params['id'], $data['status'], $data['error_message'] ?? '');
        }
        json_response(['item' => $socialQueue->find((int)$params['id'])]);
    });

    $router->delete('/api/social-queue/{id}', function (array $params) use ($socialQueue) {
        $socialQueue->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });
}
