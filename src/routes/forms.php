<?php

declare(strict_types=1);

function register_form_routes(Router $router, FormRepository $forms, ContactRepository $contacts, AutomationRepository $automations, ?Auth $auth = null): void
{
    $router->get('/api/forms', fn() => json_response($forms->all()));

    $router->post('/api/forms', function () use ($forms) {
        $data = request_json();
        if (empty($data['name'])) {
            json_response(['error' => 'name is required'], 400);
            return;
        }
        json_response($forms->create($data), 201);
    });

    $router->get('/api/forms/{id}', function (array $params) use ($forms) {
        $form = $forms->find((int)$params['id']);
        $form ? json_response($form) : json_response(['error' => 'Not found'], 404);
    });

    $router->patch('/api/forms/{id}', function (array $params) use ($forms) {
        $data = request_json();
        $form = $forms->update((int)$params['id'], $data);
        $form ? json_response($form) : json_response(['error' => 'Not found'], 404);
    });

    $router->delete('/api/forms/{id}', function (array $params) use ($forms) {
        $forms->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });

    $router->get('/api/forms/{id}/submissions', function (array $params) use ($forms) {
        json_response($forms->submissions((int)$params['id']));
    });

    $router->get('/api/forms/{id}/embed', function (array $params) use ($forms) {
        $form = $forms->find((int)$params['id']);
        if (!$form) {
            json_response(['error' => 'Not found'], 404);
            return;
        }
        $baseUrl = env_value('APP_URL', '');
        json_response(['embed_code' => $forms->embedCode($form, $baseUrl)]);
    });

    // Public submission endpoint (no CSRF required - handled separately)
    $router->post('/api/forms/{slug}/submit', function (array $params) use ($forms, $contacts, $automations, $auth) {
        // Rate limit public form submissions to prevent spam
        if (!$auth || !$auth->rateLimit('form_submit', 10, 60)) {
            json_response(['error' => 'Too many submissions. Please try again later.'], 429);
            return;
        }
        $form = $forms->findBySlug($params['slug']);
        if (!$form) {
            json_response(['error' => 'Form not found'], 404);
            return;
        }
        $data = request_json();
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
        $pageUrl = $_SERVER['HTTP_REFERER'] ?? '';

        $result = $forms->submit($form['id'], $data, $ipHash, $pageUrl, $contacts);
        $automations->fire('form.submitted', [
            'form_id' => $form['id'],
            'form_name' => $form['name'],
            'contact_id' => $result['contact_id'] ?? null,
            'email' => $data['email'] ?? null,
        ]);
        json_response($result);
    });
}
