<?php

declare(strict_types=1);

function register_email_routes(
    Router $router,
    EmailListRepository $emailLists,
    SubscriberRepository $subscribers,
    EmailCampaignRepository $emailCampaigns,
    ?EmailService $emailService,
    Webhooks $webhooks,
    ?AutomationRepository $automations = null
): void {
    /* ---- Email Lists ---- */
    $router->get('/api/email-lists', fn() => json_response(['items' => $emailLists->all()]));

    $router->post('/api/email-lists', function () use ($emailLists) {
        $p = request_json();
        if (empty($p['name'])) { json_response(['error' => 'Missing: name'], 422); return; }
        json_response(['item' => $emailLists->create($p)], 201);
    });

    $router->delete('/api/email-lists/{id}', function ($p) use ($emailLists) {
        $emailLists->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    /* ---- Subscribers ---- */
    $router->get('/api/subscribers', function () use ($subscribers) {
        $listId = !empty($_GET['list_id']) ? (int)$_GET['list_id'] : null;
        json_response(['items' => $subscribers->all($listId)]);
    });

    $router->post('/api/subscribers', function () use ($subscribers, $webhooks, $automations) {
        $p = request_json();
        if (empty($p['email']) || empty($p['list_id'])) {
            json_response(['error' => 'Missing: email, list_id'], 422);
            return;
        }
        $result = $subscribers->create($p);
        if (is_string($result)) {
            json_response(['error' => $result], 409);
        } else {
            $webhooks->dispatch('subscriber.added', $result);
            if ($automations) {
                $automations->fire('subscriber.added', [
                    'email' => $result['email'] ?? '',
                    'list_id' => $result['list_id'] ?? null,
                    'subscriber_id' => $result['id'] ?? null,
                ]);
            }
            json_response(['item' => $result], 201);
        }
    });

    $router->post('/api/subscribers/import', function () use ($subscribers) {
        $data = request_json();
        if (empty($data['list_id']) || empty($data['csv'])) {
            json_response(['error' => 'Missing: list_id, csv'], 422);
            return;
        }
        $result = $subscribers->importCsv((int)$data['list_id'], $data['csv']);
        json_response($result);
    });

    $router->delete('/api/subscribers/{id}', function ($p) use ($subscribers) {
        $subscribers->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    /* ---- Email Campaigns ---- */
    $router->get('/api/email-campaigns', fn() => json_response(['items' => $emailCampaigns->all()]));

    $router->post('/api/email-campaigns', function () use ($emailCampaigns) {
        $p = request_json();
        foreach (['name', 'subject'] as $r) {
            if (empty($p[$r])) { json_response(['error' => "Missing: {$r}"], 422); return; }
        }
        json_response(['item' => $emailCampaigns->create($p)], 201);
    });

    $router->get('/api/email-campaigns/{id}', fn($p) => json_response(['item' => $emailCampaigns->find((int)$p['id'])]));

    $router->put('/api/email-campaigns/{id}', fn($p) => json_response(['item' => $emailCampaigns->update((int)$p['id'], request_json())]));

    $router->delete('/api/email-campaigns/{id}', function ($p) use ($emailCampaigns) {
        $emailCampaigns->delete((int)$p['id']);
        json_response(['ok' => true]);
    });

    $router->post('/api/email-campaigns/{id}/send', function ($p) use ($emailService, $emailCampaigns, $webhooks, $automations) {
        if (!$emailService) { json_response(['error' => 'Email service not configured'], 500); return; }
        $campaign = $emailCampaigns->find((int)$p['id']);
        if (!$campaign) { json_response(['error' => 'Campaign not found'], 404); return; }
        $result = $emailService->sendCampaign((int)$p['id']);
        $emailCampaigns->update((int)$p['id'], [
            'status' => 'sent',
            'sent_count' => $result['sent'] ?? 0,
            'sent_at' => gmdate(DATE_ATOM),
        ]);
        $webhooks->dispatch('email.sent', array_merge($result, ['campaign_id' => (int)$p['id']]));
        if ($automations) {
            $automations->fire('email.sent', [
                'campaign_id' => (int)$p['id'],
                'campaign_name' => $campaign['name'] ?? '',
                'list_id' => $campaign['list_id'] ?? null,
                'sent_count' => $result['sent'] ?? 0,
            ]);
        }
        json_response($result);
    });

    $router->post('/api/email-campaigns/{id}/test', function ($p) use ($emailService, $emailCampaigns) {
        if (!$emailService) { json_response(['error' => 'Email service not configured'], 500); return; }
        $data = request_json();
        $campaign = $emailCampaigns->find((int)$p['id']);
        if (!$campaign) { json_response(['error' => 'Campaign not found'], 404); return; }
        $to = $data['to'] ?? '';
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) { json_response(['error' => 'Invalid email address'], 422); return; }
        $ok = $emailService->sendTestEmail($to, $campaign['subject'], $campaign['body_html'], $campaign['body_text']);
        json_response(['success' => $ok]);
    });

    $router->get('/api/email-campaigns/{id}/stats', function ($p) use ($emailService) {
        if (!$emailService) { json_response(['error' => 'Email service not configured'], 500); return; }
        json_response($emailService->getCampaignStats((int)$p['id']));
    });
}
