<?php

declare(strict_types=1);

function register_social_routes(Router $router, SocialAccountRepository $socialAccounts, ?SocialPublisher $socialPublisher): void
{
    $router->get('/api/social-accounts', fn() => json_response(['items' => $socialAccounts->all()]));

    $router->post('/api/social-accounts', function () use ($socialAccounts) {
        $p = request_json();
        foreach (['platform', 'account_name'] as $r) {
            if (empty($p[$r])) { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        json_response(['item' => $socialAccounts->create($p)], 201);
    });

    $router->put('/api/social-accounts/{id}', fn($p) => json_response(['item' => $socialAccounts->update((int)$p['id'], request_json())]));

    $router->delete('/api/social-accounts/{id}', function ($p) use ($socialAccounts) {
        $socialAccounts->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/social-accounts/{id}/test', function ($p) use ($socialAccounts, $socialPublisher) {
        if (!$socialPublisher) { json_response(['error' => 'Social publisher not available'], 500); return; }
        $account = $socialAccounts->find((int)$p['id']);
        if (!$account) { json_response(['error' => 'Account not found'], 404); return; }
        json_response(['ok' => true, 'platform' => $account['platform'], 'account' => $account['account_name']]);
    });
}
