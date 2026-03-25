<?php

declare(strict_types=1);

function register_media_routes(Router $router, MediaLibrary $mediaLib): void
{
    $router->get('/api/media', fn() => json_response(['items' => $mediaLib->all()]));

    $router->post('/api/media', function () use ($mediaLib) {
        if (empty($_FILES['file'])) {
            json_response(['error' => 'No file uploaded'], 422);
            return;
        }
        $result = $mediaLib->upload($_FILES['file'], $_POST['alt_text'] ?? '', $_POST['tags'] ?? '');
        if (is_string($result)) {
            json_response(['error' => $result], 422);
        } else {
            json_response(['item' => $result], 201);
        }
    });

    $router->delete('/api/media/{id}', function ($p) use ($mediaLib) {
        $mediaLib->delete((int)$p['id']);
        json_response(['ok' => true]);
    });
}
