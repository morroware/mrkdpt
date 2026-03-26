<?php

declare(strict_types=1);

function register_autopilot_routes(Router $router, AiAutopilot $autopilot): void
{
    // Launch full onboarding autopilot pipeline
    $router->post('/api/autopilot/launch', function () use ($autopilot) {
        $profile = $autopilot->getBusinessProfile();
        if (!$profile) {
            json_response(['error' => 'Business profile not found. Complete onboarding first.'], 422);
            return;
        }
        $result = $autopilot->launchOnboarding($profile);
        json_response(['item' => $result]);
    });

    // Launch campaign-specific autopilot
    $router->post('/api/autopilot/campaign', function () use ($autopilot) {
        $params = request_json();
        $result = $autopilot->launchCampaignAutopilot($params);
        json_response(['item' => $result]);
    });

    // Generate weekly plan
    $router->post('/api/autopilot/weekly', function () use ($autopilot) {
        $result = $autopilot->generateWeeklyPlan();
        json_response(['item' => $result]);
    });

    // Poll task status
    $router->get('/api/autopilot/status', function () use ($autopilot) {
        $taskId = (int)($_GET['id'] ?? 0);
        if ($taskId > 0) {
            $task = $autopilot->getTaskStatus($taskId);
        } else {
            $type = $_GET['type'] ?? null;
            $task = $autopilot->getLatestTask($type);
        }
        json_response(['task' => $task]);
    });

    // List AI-generated assets
    $router->get('/api/autopilot/assets', function () use ($autopilot) {
        $status = $_GET['status'] ?? '';
        $assets = $status ? $autopilot->getAssets($status) : $autopilot->getAllAssets();
        json_response(['items' => $assets]);
    });

    // Approve an asset
    $router->post('/api/autopilot/assets/approve', function () use ($autopilot) {
        $data = request_json();
        $id = (int)($data['id'] ?? 0);
        if (!$id) { json_response(['error' => 'Missing asset ID'], 422); return; }
        $autopilot->approveAsset($id);
        json_response(['approved' => true]);
    });

    // Reject an asset
    $router->post('/api/autopilot/assets/reject', function () use ($autopilot) {
        $data = request_json();
        $id = (int)($data['id'] ?? 0);
        if (!$id) { json_response(['error' => 'Missing asset ID'], 422); return; }
        $autopilot->rejectAsset($id);
        json_response(['rejected' => true]);
    });
}
