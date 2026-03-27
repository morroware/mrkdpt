<?php

declare(strict_types=1);

function register_auth_routes(Router $router, Auth $auth): void
{
    $router->post('/api/login', function () use ($auth) {
        $data = request_json();
        $username = strtolower(trim((string)($data['username'] ?? '')));
        $rateLimitKey = $username !== '' ? 'login:' . $username : 'login:anonymous';
        if (!$auth->rateLimit($rateLimitKey, 10, 300)) {
            json_response(['error' => 'Too many login attempts. Try again later.'], 429);
            return;
        }
        $user = $auth->login($username, (string)($data['password'] ?? ''));
        if (!$user) {
            json_response(['error' => 'Invalid credentials'], 401);
            return;
        }
        json_response([
            'user' => ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']],
            'csrf_token' => $auth->csrfToken(),
            'api_token' => $user['api_token'],
        ]);
    });

    $router->post('/api/logout', function () use ($auth) {
        $auth->logout();
        json_response(['ok' => true]);
    });

    $router->get('/api/setup-status', function () use ($auth) {
        json_response(['needs_setup' => $auth->userCount() === 0]);
    });

    $router->get('/api/me', function () use ($auth) {
        $user = $auth->currentUser();
        if ($user) {
            json_response(['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'], 'api_token' => $user['api_token'], 'csrf_token' => $auth->csrfToken()]);
        } else {
            json_response(['error' => 'Not authenticated'], 401);
        }
    });

    $router->post('/api/regenerate-token', function () use ($auth) {
        $user = $auth->currentUser();
        if (!$user) { json_response(['error' => 'Auth required'], 401); return; }
        $token = $auth->regenerateToken($user['id']);
        json_response(['api_token' => $token]);
    });
}
