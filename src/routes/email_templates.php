<?php

declare(strict_types=1);

function register_email_template_routes(Router $router, EmailTemplateRepository $emailTemplates): void
{
    $router->get('/api/email-templates', fn() => json_response(['items' => $emailTemplates->all()]));

    $router->get('/api/email-templates/{id}', function (array $params) use ($emailTemplates) {
        $tpl = $emailTemplates->find((int)$params['id']);
        $tpl ? json_response($tpl) : json_response(['error' => 'Not found'], 404);
    });

    $router->post('/api/email-templates', function () use ($emailTemplates) {
        $data = request_json();
        if (empty($data['name']) || empty($data['html_template'])) {
            json_response(['error' => 'Missing: name, html_template'], 422);
            return;
        }
        json_response(['item' => $emailTemplates->create($data)], 201);
    });

    $router->put('/api/email-templates/{id}', function (array $params) use ($emailTemplates) {
        $item = $emailTemplates->update((int)$params['id'], request_json());
        $item ? json_response(['item' => $item]) : json_response(['error' => 'Not found'], 404);
    });

    $router->post('/api/email-templates/{id}/render', function (array $params) use ($emailTemplates) {
        $data = request_json();
        $vars = $data['variables'] ?? [];
        json_response($emailTemplates->render((int)$params['id'], $vars));
    });

    $router->delete('/api/email-templates/{id}', function (array $params) use ($emailTemplates) {
        $emailTemplates->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Cannot delete built-in template'], 403);
    });
}
