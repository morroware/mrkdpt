<?php

declare(strict_types=1);

function register_rss_routes(Router $router, RssFetcher $rssFetcher): void
{
    $router->get('/api/rss-feeds', fn() => json_response(['items' => $rssFetcher->allFeeds()]));

    $router->post('/api/rss-feeds', function () use ($rssFetcher) {
        $p = request_json();
        if (empty($p['url'])) { json_response(['error' => 'Missing: url'], 422); return; }
        json_response(['item' => $rssFetcher->createFeed($p)], 201);
    });

    $router->put('/api/rss-feeds/{id}', fn($p) => json_response(['item' => $rssFetcher->updateFeed((int)$p['id'], request_json())]));

    $router->delete('/api/rss-feeds/{id}', function ($p) use ($rssFetcher) {
        $rssFetcher->deleteFeed((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/rss-feeds/{id}/fetch', function ($p) use ($rssFetcher) {
        json_response($rssFetcher->fetchFeed((int)$p['id']));
    });

    $router->get('/api/rss-items', function () use ($rssFetcher) {
        $feedId = !empty($_GET['feed_id']) ? (int)$_GET['feed_id'] : null;
        json_response(['items' => $rssFetcher->allItems(100, $feedId)]);
    });
}
