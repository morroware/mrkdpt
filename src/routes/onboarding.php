<?php

declare(strict_types=1);

function register_onboarding_routes(Router $router, AiAutopilot $autopilot): void
{
    $router->get('/api/onboarding/status', function () use ($autopilot) {
        $profile = $autopilot->getBusinessProfile();
        json_response([
            'onboarding_completed' => (bool)($profile['onboarding_completed'] ?? false),
            'autopilot_run'        => (bool)($profile['autopilot_run'] ?? false),
            'has_profile'          => $profile !== null,
        ]);
    });

    $router->get('/api/onboarding/profile', function () use ($autopilot) {
        $profile = $autopilot->getBusinessProfile();
        json_response(['profile' => $profile]);
    });

    $router->post('/api/onboarding/profile', function () use ($autopilot) {
        $data = request_json();
        $data['onboarding_completed'] = 1;
        $id = $autopilot->saveBusinessProfile($data);
        json_response(['id' => $id, 'saved' => true]);
    });

    $router->post('/api/onboarding/discover', function () use ($autopilot) {
        $data = request_json();
        $url = trim((string)($data['website_url'] ?? ''));
        if ($url === '') {
            json_response(['error' => 'Missing: website_url'], 422);
            return;
        }
        $result = $autopilot->discoverFromWebsite($url);
        if (!empty($result['error'])) {
            json_response(['error' => $result['error']], 422);
            return;
        }
        json_response(['item' => $result]);
    });
}
