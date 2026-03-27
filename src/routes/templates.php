<?php

declare(strict_types=1);

function register_template_routes(Router $router, TemplateRepository $templates, BrandProfileRepository $brandProfiles): void
{
    /* ---- Templates ---- */
    $router->get('/api/templates', fn() => json_response(['items' => $templates->all()]));

    $router->post('/api/templates', function () use ($templates) {
        $p = request_json();
        if (empty($p['name'])) { json_response(['error' => 'Missing: name'], 422); return; }
        json_response(['item' => $templates->create($p)], 201);
    });

    $router->get('/api/templates/{id}', function ($p) use ($templates) {
        $item = $templates->find((int)$p['id']);
        $item ? json_response(['item' => $item]) : json_response(['error' => 'Not found'], 404);
    });

    $router->put('/api/templates/{id}', fn($p) => json_response(['item' => $templates->update((int)$p['id'], request_json())]));

    $router->delete('/api/templates/{id}', function ($p) use ($templates) {
        $templates->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/templates/{id}/clone', function ($p) use ($templates) {
        $item = $templates->duplicate((int)$p['id']);
        if ($item) {
            json_response(['item' => $item], 201);
        } else {
            json_response(['error' => 'Template not found'], 404);
        }
    });

    $router->post('/api/templates/{id}/render', function ($p) use ($templates) {
        $data = request_json();
        $output = $templates->render((int)$p['id'], $data['values'] ?? []);
        json_response(['rendered' => $output]);
    });

    /* ---- Brand Profiles ---- */
    $router->get('/api/brand-profiles', fn() => json_response(['items' => $brandProfiles->all()]));

    $router->post('/api/brand-profiles', function () use ($brandProfiles) {
        $p = request_json();
        if (empty($p['name'])) { json_response(['error' => 'Missing: name'], 422); return; }
        json_response(['item' => $brandProfiles->create($p)], 201);
    });

    $router->get('/api/brand-profiles/{id}', function ($p) use ($brandProfiles) {
        $item = $brandProfiles->find((int)$p['id']);
        $item ? json_response(['item' => $item]) : json_response(['error' => 'Not found'], 404);
    });

    $router->put('/api/brand-profiles/{id}', fn($p) => json_response(['item' => $brandProfiles->update((int)$p['id'], request_json())]));

    $router->delete('/api/brand-profiles/{id}', function ($p) use ($brandProfiles) {
        $brandProfiles->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/brand-profiles/{id}/activate', function ($p) use ($brandProfiles) {
        $brandProfiles->setActive((int)$p['id']);
        json_response(['ok' => true]);
    });
}
