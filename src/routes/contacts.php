<?php

declare(strict_types=1);

function register_contact_routes(Router $router, ContactRepository $contacts, AutomationRepository $automations): void
{
    $router->get('/api/contacts', function () use ($contacts) {
        $stage = $_GET['stage'] ?? null;
        $search = $_GET['search'] ?? null;
        json_response(['items' => $contacts->all($stage, $search)]);
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
        json_response(['item' => $contact], 201);
    });

    $router->get('/api/contacts/{id}', function (array $params) use ($contacts) {
        $contact = $contacts->find((int)$params['id']);
        if (!$contact) {
            json_response(['error' => 'Not found'], 404);
            return;
        }
        $contact['activities'] = $contacts->activities((int)$params['id']);
        $contact['deals'] = $contacts->contactDeals((int)$params['id']);
        $contact['tasks'] = $contacts->contactTasks((int)$params['id']);
        $contact['contact_notes'] = $contacts->contactNotes((int)$params['id']);
        json_response(['item' => $contact]);
    });

    $router->patch('/api/contacts/{id}', function (array $params) use ($contacts, $automations) {
        $data = request_json();
        $old = $contacts->find((int)$params['id']);
        $contact = $contacts->update((int)$params['id'], $data);
        if ($contact && $old && isset($data['stage']) && $old['stage'] !== $data['stage']) {
            $contacts->logActivity((int)$params['id'], 'stage_changed', "Stage changed from {$old['stage']} to {$data['stage']}");
            $automations->fire('contact.stage_changed', ['contact_id' => $contact['id'], 'old_stage' => $old['stage'], 'new_stage' => $data['stage']]);
        }
        $contact ? json_response(['item' => $contact]) : json_response(['error' => 'Not found'], 404);
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

    // =========================================================================
    // Deals
    // =========================================================================

    $router->get('/api/deals', function () use ($contacts) {
        $status = $_GET['status'] ?? null;
        json_response(['items' => $contacts->allDeals($status)]);
    });

    $router->post('/api/deals', function () use ($contacts) {
        $data = request_json();
        if (empty($data['title']) || empty($data['contact_id'])) {
            json_response(['error' => 'title and contact_id are required'], 400);
            return;
        }
        json_response(['item' => $contacts->createDeal($data)], 201);
    });

    $router->get('/api/deals/{id}', function (array $params) use ($contacts) {
        $deal = $contacts->findDeal((int)$params['id']);
        $deal ? json_response(['item' => $deal]) : json_response(['error' => 'Not found'], 404);
    });

    $router->patch('/api/deals/{id}', function (array $params) use ($contacts) {
        $data = request_json();
        $deal = $contacts->updateDeal((int)$params['id'], $data);
        $deal ? json_response(['item' => $deal]) : json_response(['error' => 'Not found'], 404);
    });

    $router->delete('/api/deals/{id}', function (array $params) use ($contacts) {
        $contacts->deleteDeal((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });

    // =========================================================================
    // Tasks
    // =========================================================================

    $router->get('/api/tasks', function () use ($contacts) {
        $status = $_GET['status'] ?? null;
        json_response(['items' => $contacts->allTasks($status)]);
    });

    $router->post('/api/tasks', function () use ($contacts) {
        $data = request_json();
        if (empty($data['title'])) {
            json_response(['error' => 'title is required'], 400);
            return;
        }
        json_response(['item' => $contacts->createTask($data)], 201);
    });

    $router->patch('/api/tasks/{id}', function (array $params) use ($contacts) {
        $data = request_json();
        $task = $contacts->updateTask((int)$params['id'], $data);
        $task ? json_response(['item' => $task]) : json_response(['error' => 'Not found'], 404);
    });

    $router->delete('/api/tasks/{id}', function (array $params) use ($contacts) {
        $contacts->deleteTask((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });

    // =========================================================================
    // Notes
    // =========================================================================

    $router->post('/api/contacts/{id}/notes', function (array $params) use ($contacts) {
        $data = request_json();
        if (empty($data['content'])) {
            json_response(['error' => 'content is required'], 400);
            return;
        }
        json_response(['item' => $contacts->createNote((int)$params['id'], $data['content'])], 201);
    });

    $router->delete('/api/notes/{id}', function (array $params) use ($contacts) {
        $contacts->deleteNote((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
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
        $headers = ['email', 'first_name', 'last_name', 'company', 'phone', 'stage', 'score', 'source', 'tags', 'created_at'];
        if (empty($all)) {
            csv_response(implode(',', $headers), 'contacts.csv');
            return;
        }
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        foreach ($all as $row) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = $row[$h] ?? '';
            }
            fputcsv($output, $line);
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
        if (!is_array($ids)) { json_response(['error' => 'IDs must be an array'], 422); return; }
        $ids = array_map('intval', $ids);
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
