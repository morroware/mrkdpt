<?php

declare(strict_types=1);

function register_automation_routes(Router $router, AutomationRepository $automations): void
{
    $router->get('/api/automations', fn() => json_response(['items' => $automations->all()]));

    $router->get('/api/automations/options', fn() => json_response([
        'trigger_events' => AutomationRepository::triggerEvents(),
        'action_types' => AutomationRepository::actionTypes(),
    ]));

    $router->post('/api/automations', function () use ($automations) {
        $data = request_json();
        if (empty($data['name']) || empty($data['trigger_event']) || empty($data['action_type'])) {
            json_response(['error' => 'name, trigger_event, action_type are required'], 400);
            return;
        }
        json_response(['item' => $automations->create($data)], 201);
    });

    $router->get('/api/automations/{id}', function (array $params) use ($automations) {
        $rule = $automations->find((int)$params['id']);
        $rule ? json_response($rule) : json_response(['error' => 'Not found'], 404);
    });

    $router->patch('/api/automations/{id}', function (array $params) use ($automations) {
        $data = request_json();
        $rule = $automations->update((int)$params['id'], $data);
        $rule ? json_response($rule) : json_response(['error' => 'Not found'], 404);
    });

    $router->delete('/api/automations/{id}', function (array $params) use ($automations) {
        $automations->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });
}
