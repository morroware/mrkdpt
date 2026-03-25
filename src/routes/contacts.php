<?php

declare(strict_types=1);

function register_contact_routes(Router $router, ContactRepository $contacts, AutomationRepository $automations): void
{
    $router->get('/api/contacts', function () use ($contacts) {
        $stage = $_GET['stage'] ?? null;
        $search = $_GET['search'] ?? null;
        json_response($contacts->all($stage, $search));
    });

    $router->get('/api/contacts/metrics', fn() => json_response($contacts->metrics()));

    $router->get('/api/contacts/stages', fn() => json_response($contacts->stageBreakdown()));

    $router->post('/api/contacts', function () use ($contacts, $automations) {
        $data = request_json();
        if (empty($data['email'])) {
            json_response(['error' => 'email is required'], 400);
            return;
        }
        $contact = $contacts->create($data);
        $automations->fire('contact.created', ['contact_id' => $contact['id'], 'email' => $contact['email'], 'source' => $data['source'] ?? 'manual']);
        json_response($contact, 201);
    });

    $router->get('/api/contacts/{id}', function (array $params) use ($contacts) {
        $contact = $contacts->find((int)$params['id']);
        if (!$contact) {
            json_response(['error' => 'Not found'], 404);
            return;
        }
        $contact['activities'] = $contacts->activities((int)$params['id']);
        json_response($contact);
    });

    $router->patch('/api/contacts/{id}', function (array $params) use ($contacts, $automations) {
        $data = request_json();
        $old = $contacts->find((int)$params['id']);
        $contact = $contacts->update((int)$params['id'], $data);
        if ($contact && $old && isset($data['stage']) && $old['stage'] !== $data['stage']) {
            $contacts->logActivity((int)$params['id'], 'stage_changed', "Stage changed from {$old['stage']} to {$data['stage']}");
            $automations->fire('contact.stage_changed', ['contact_id' => $contact['id'], 'old_stage' => $old['stage'], 'new_stage' => $data['stage']]);
        }
        $contact ? json_response($contact) : json_response(['error' => 'Not found'], 404);
    });

    $router->delete('/api/contacts/{id}', function (array $params) use ($contacts) {
        $contacts->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });

    $router->post('/api/contacts/{id}/activity', function (array $params) use ($contacts) {
        $data = request_json();
        $contacts->logActivity((int)$params['id'], $data['type'] ?? 'note', $data['description'] ?? '', $data['data'] ?? []);
        json_response(['ok' => true]);
    });

    // CSV Import
    $router->post('/api/contacts/import', function () use ($contacts, $automations) {
        $data = request_json();
        $csv = $data['csv'] ?? '';
        if (empty($csv)) {
            json_response(['error' => 'Missing: csv'], 422);
            return;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $csv));
        $imported = 0;
        $skipped = 0;
        $headers = null;

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') continue;
            $row = str_getcsv($line);

            if ($headers === null) {
                $headers = array_map(fn($h) => strtolower(trim($h)), $row);
                if (in_array('email', $headers)) continue;
                // If first row doesn't look like headers, treat as data
                $headers = ['email', 'first_name', 'last_name', 'company', 'phone', 'stage', 'tags'];
            }

            $record = [];
            foreach ($headers as $idx => $key) {
                $record[$key] = $row[$idx] ?? '';
            }

            if (empty($record['email']) || !filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            $record['source'] = $data['source'] ?? 'csv_import';
            $contact = $contacts->create($record);
            $automations->fire('contact.created', ['contact_id' => $contact['id'], 'email' => $contact['email'], 'source' => 'csv_import']);
            $imported++;
        }

        json_response(['imported' => $imported, 'skipped' => $skipped]);
    });

    // CSV Export
    $router->get('/api/contacts/export', function () use ($contacts) {
        $all = $contacts->all();
        if (empty($all)) {
            csv_response('email,first_name,last_name,company,phone,stage,score,source,tags,created_at', 'contacts.csv');
            return;
        }
        $headers = array_keys($all[0]);
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        foreach ($all as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        csv_response($csv, 'contacts.csv');
    });

    // Bulk operations
    $router->post('/api/contacts/bulk', function () use ($contacts) {
        $data = request_json();
        $ids = $data['ids'] ?? [];
        $action = $data['action'] ?? '';
        if (empty($ids)) {
            json_response(['error' => 'No IDs provided'], 422);
            return;
        }

        $count = 0;
        switch ($action) {
            case 'delete':
                foreach ($ids as $id) {
                    if ($contacts->delete((int)$id)) $count++;
                }
                break;
            case 'update_stage':
                $stage = $data['stage'] ?? 'lead';
                foreach ($ids as $id) {
                    $contacts->update((int)$id, ['stage' => $stage]);
                    $count++;
                }
                break;
            case 'add_tag':
                $tag = $data['tag'] ?? '';
                foreach ($ids as $id) {
                    $c = $contacts->find((int)$id);
                    if ($c) {
                        $tags = $c['tags'] ? $c['tags'] . ',' . $tag : $tag;
                        $contacts->update((int)$id, ['tags' => $tags]);
                        $count++;
                    }
                }
                break;
            case 'add_score':
                $points = (int)($data['points'] ?? 0);
                foreach ($ids as $id) {
                    $c = $contacts->find((int)$id);
                    if ($c) {
                        $contacts->update((int)$id, ['score' => $c['score'] + $points]);
                        $count++;
                    }
                }
                break;
        }
        json_response(['affected' => $count]);
    });
}
