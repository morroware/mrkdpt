<?php

declare(strict_types=1);

function register_utm_routes(Router $router, UtmBuilder $utm, LinkShortener $shortener): void
{
    $router->get('/api/utm', fn() => json_response(['items' => $utm->all()]));

    $router->post('/api/utm', function () use ($utm, $shortener) {
        $data = request_json();
        if (empty($data['base_url']) || empty($data['utm_source']) || empty($data['utm_medium']) || empty($data['utm_campaign'])) {
            json_response(['error' => 'base_url, utm_source, utm_medium, utm_campaign are required'], 400);
            return;
        }
        $link = $utm->build($data);

        // Auto-create short link if requested
        if (!empty($data['create_short_link'])) {
            $short = $shortener->create([
                'destination_url' => $link['full_url'],
                'title' => $link['campaign_name'],
                'utm_link_id' => $link['id'],
            ]);
            $link['short_link'] = $short;
        }

        json_response(['item' => $link], 201);
    });

    $router->delete('/api/utm/{id}', function (array $params) use ($utm) {
        $utm->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });
}
