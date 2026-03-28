<?php

declare(strict_types=1);

function register_review_routes(Router $router, PDO $pdo, AiService $aiService): void
{
    // GET /api/reviews/stats - must be before /api/reviews/{id}
    $router->get('/api/reviews/stats', function () use ($pdo) {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
        $avgRating = (float)$pdo->query('SELECT COALESCE(AVG(rating), 0) FROM reviews')->fetchColumn();

        $byPlatform = [];
        $stmt = $pdo->query('SELECT platform, COUNT(*) as count, AVG(rating) as avg_rating FROM reviews GROUP BY platform');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byPlatform[$row['platform']] = [
                'count' => (int)$row['count'],
                'avg_rating' => round((float)$row['avg_rating'], 2),
            ];
        }

        $byStatus = [];
        $stmt = $pdo->query('SELECT response_status, COUNT(*) as count FROM reviews GROUP BY response_status');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byStatus[$row['response_status']] = (int)$row['count'];
        }

        // Sentiment breakdown based on rating
        $positive = (int)$pdo->query('SELECT COUNT(*) FROM reviews WHERE rating >= 4')->fetchColumn();
        $neutral = (int)$pdo->query('SELECT COUNT(*) FROM reviews WHERE rating = 3')->fetchColumn();
        $negative = (int)$pdo->query('SELECT COUNT(*) FROM reviews WHERE rating <= 2')->fetchColumn();

        json_response([
            'total' => $total,
            'avg_rating' => round($avgRating, 2),
            'by_platform' => $byPlatform,
            'by_status' => $byStatus,
            'sentiment' => [
                'positive' => $positive,
                'neutral' => $neutral,
                'negative' => $negative,
            ],
        ]);
    });

    // GET /api/reviews - list all with optional platform filter
    $router->get('/api/reviews', function () use ($pdo) {
        $platform = $_GET['platform'] ?? '';
        if ($platform !== '') {
            $stmt = $pdo->prepare('SELECT * FROM reviews WHERE platform = :platform ORDER BY created_at DESC');
            $stmt->execute([':platform' => $platform]);
        } else {
            $stmt = $pdo->query('SELECT * FROM reviews ORDER BY created_at DESC');
        }
        json_response(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    });

    // POST /api/reviews - create review (manual entry)
    $router->post('/api/reviews', function () use ($pdo) {
        $p = request_json();
        foreach (['platform', 'reviewer_name', 'rating'] as $r) {
            if (empty($p[$r]) && $p[$r] !== '0') {
                json_response(['error' => "Missing: {$r}"], 422);
                return;
            }
        }
        $rating = (int)$p['rating'];
        if ($rating < 1 || $rating > 5) {
            json_response(['error' => 'Rating must be between 1 and 5'], 422);
            return;
        }
        $allowed = ['google', 'yelp', 'facebook', 'manual'];
        if (!in_array($p['platform'], $allowed, true)) {
            json_response(['error' => 'Invalid platform. Allowed: ' . implode(', ', $allowed)], 422);
            return;
        }

        $now = gmdate(DATE_ATOM);
        $stmt = $pdo->prepare('INSERT INTO reviews (platform, reviewer_name, rating, review_text, response_text, response_status, created_at)
            VALUES (:platform, :reviewer_name, :rating, :review_text, :response_text, :response_status, :created_at)');
        $stmt->execute([
            ':platform' => $p['platform'],
            ':reviewer_name' => $p['reviewer_name'],
            ':rating' => $rating,
            ':review_text' => $p['review_text'] ?? '',
            ':response_text' => $p['response_text'] ?? '',
            ':response_status' => $p['response_status'] ?? 'pending',
            ':created_at' => $now,
        ]);
        $id = (int)$pdo->lastInsertId();
        $item = $pdo->prepare('SELECT * FROM reviews WHERE id = :id');
        $item->execute([':id' => $id]);
        json_response(['item' => $item->fetch(PDO::FETCH_ASSOC)], 201);
    });

    // GET /api/reviews/{id}
    $router->get('/api/reviews/{id}', function ($p) use ($pdo) {
        $stmt = $pdo->prepare('SELECT * FROM reviews WHERE id = :id');
        $stmt->execute([':id' => (int)$p['id']]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        $item ? json_response(['item' => $item]) : json_response(['error' => 'Not found'], 404);
    });

    // PUT /api/reviews/{id} - update review (add response, etc.)
    $router->put('/api/reviews/{id}', function ($p) use ($pdo) {
        $id = (int)$p['id'];
        $stmt = $pdo->prepare('SELECT * FROM reviews WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            json_response(['error' => 'Not found'], 404);
            return;
        }

        $data = request_json();
        $fields = ['platform', 'reviewer_name', 'rating', 'review_text', 'response_text', 'response_status', 'responded_at'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "{$f} = :{$f}";
                $params[":{$f}"] = $data[$f];
            }
        }
        if ($sets === []) {
            json_response(['item' => $existing]);
            return;
        }

        // Auto-set responded_at when response_status changes to responded
        if (($data['response_status'] ?? '') === 'responded' && !array_key_exists('responded_at', $data)) {
            $sets[] = 'responded_at = :responded_at';
            $params[':responded_at'] = gmdate(DATE_ATOM);
        }

        $sql = 'UPDATE reviews SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        $stmt = $pdo->prepare('SELECT * FROM reviews WHERE id = :id');
        $stmt->execute([':id' => $id]);
        json_response(['item' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    });

    // DELETE /api/reviews/{id}
    $router->delete('/api/reviews/{id}', function ($p) use ($pdo) {
        $pdo->prepare('DELETE FROM reviews WHERE id = :id')->execute([':id' => (int)$p['id']]);
        json_response(['ok' => true]);
    });

    // POST /api/reviews/{id}/respond - generate AI response
    $router->post('/api/reviews/{id}/respond', function ($p) use ($pdo, $aiService) {
        $id = (int)$p['id'];
        $stmt = $pdo->prepare('SELECT * FROM reviews WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$review) {
            json_response(['error' => 'Not found'], 404);
            return;
        }

        $rating = (int)$review['rating'];
        $reviewText = $review['review_text'] ?: '(No review text provided)';
        $reviewerName = $review['reviewer_name'];

        if ($rating >= 4) {
            $tone = 'warm, grateful, and professional';
            $instruction = 'Write a thank-you response expressing genuine appreciation for the positive feedback. Mention something specific from their review if possible. Encourage them to visit again or continue the relationship.';
        } elseif ($rating === 3) {
            $tone = 'professional, understanding, and constructive';
            $instruction = 'Write a balanced response acknowledging their feedback. Thank them for sharing, address any concerns mentioned, and express commitment to improvement.';
        } else {
            $tone = 'empathetic, apologetic, and solution-oriented';
            $instruction = 'Write an empathetic response acknowledging their dissatisfaction. Apologize sincerely, address concerns raised, offer to make things right, and invite them to contact you directly to resolve the issue.';
        }

        $prompt = <<<PROMPT
You are writing a business owner's response to a customer review on {$review['platform']}.

Reviewer: {$reviewerName}
Rating: {$rating}/5
Review: {$reviewText}

Instructions: {$instruction}

Tone: {$tone}

Write a concise, authentic response (2-4 sentences). Do not use generic templates. Be specific to what the reviewer said. Do not include a subject line or greeting — start directly with the response text.
PROMPT;

        $systemPrompt = $aiService->buildSystemPrompt();
        $body = $aiService->generate($prompt);

        json_response([
            'item' => [
                'review_id' => $id,
                'response_text' => trim($body),
            ],
        ]);
    });
}
