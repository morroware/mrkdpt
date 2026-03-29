<?php

declare(strict_types=1);

/**
 * JobQueue — SQLite-backed async job queue.
 *
 * Prevents long-running tasks (email campaigns, AI generation, social publishing)
 * from blocking HTTP requests. Jobs are enqueued during the request and processed
 * by cron.php on a 1-minute interval.
 *
 * Job types:
 *   - email_campaign   : Send an email campaign to all subscribers
 *   - ai_pipeline      : Execute an AI pipeline
 *   - ai_agent_task    : Execute an AI agent step
 *   - social_publish   : Publish to a social platform
 *   - webhook_dispatch : Fire a webhook
 *
 * Usage:
 *   $queue = new JobQueue($pdo);
 *   $queue->push('email_campaign', ['campaign_id' => 42]);
 *   // Later, in cron:
 *   $queue->process($handlers, limit: 20);
 */
final class JobQueue
{
    public function __construct(private PDO $pdo) {}

    /**
     * Enqueue a new job for async processing.
     *
     * @param string      $type        Job type identifier (e.g. 'email_campaign')
     * @param array       $payload     Arbitrary data passed to the handler
     * @param string      $queue       Queue name for grouping (default: 'default')
     * @param int         $priority    Higher = processed first (default: 0)
     * @param int         $maxAttempts Max retry count before marking failed (default: 3)
     * @param string|null $scheduledFor ISO 8601 timestamp to delay processing until
     * @return int The new job ID
     */
    public function push(
        string $type,
        array $payload = [],
        string $queue = 'default',
        int $priority = 0,
        int $maxAttempts = 3,
        ?string $scheduledFor = null,
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO jobs (queue, job_type, payload_json, status, max_attempts, priority, scheduled_for, created_at)
            VALUES (:queue, :type, :payload, "pending", :max, :priority, :scheduled, :created)
        ');
        $stmt->execute([
            ':queue'     => $queue,
            ':type'      => $type,
            ':payload'   => json_encode($payload),
            ':max'       => $maxAttempts,
            ':priority'  => $priority,
            ':scheduled' => $scheduledFor,
            ':created'   => gmdate(DATE_ATOM),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Process pending jobs from the queue.
     *
     * @param array<string, callable> $handlers Map of job_type => handler function.
     *   Each handler receives (array $payload, int $jobId) and should throw on failure.
     * @param int    $limit Max jobs to process per call
     * @param string $queue Queue name to process (empty = all queues)
     * @return array{processed: int, failed: int, errors: string[]}
     */
    public function process(array $handlers, int $limit = 20, string $queue = ''): array
    {
        $now = gmdate(DATE_ATOM);
        $stats = ['processed' => 0, 'failed' => 0, 'errors' => []];

        $sql = '
            SELECT * FROM jobs
            WHERE status = "pending"
              AND (scheduled_for IS NULL OR scheduled_for <= :now)
        ';
        $params = [':now' => $now, ':limit' => $limit];

        if ($queue !== '') {
            $sql .= ' AND queue = :queue';
            $params[':queue'] = $queue;
        }

        $sql .= ' ORDER BY priority DESC, id ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            $type = $job['job_type'];
            $payload = json_decode($job['payload_json'] ?? '{}', true) ?: [];
            $attempts = (int) $job['attempts'] + 1;

            if (!isset($handlers[$type])) {
                $this->markFailed($jobId, $attempts, "No handler registered for job type: {$type}");
                $stats['failed']++;
                $stats['errors'][] = "Job #{$jobId}: unknown type '{$type}'";
                continue;
            }

            // Mark as processing
            $this->pdo->prepare('UPDATE jobs SET status = "processing", started_at = :s, attempts = :a WHERE id = :id')
                ->execute([':s' => gmdate(DATE_ATOM), ':a' => $attempts, ':id' => $jobId]);

            try {
                $handlers[$type]($payload, $jobId);
                $this->pdo->prepare('UPDATE jobs SET status = "completed", completed_at = :c WHERE id = :id')
                    ->execute([':c' => gmdate(DATE_ATOM), ':id' => $jobId]);
                $stats['processed']++;
            } catch (\Throwable $e) {
                $maxAttempts = (int) $job['max_attempts'];
                if ($attempts >= $maxAttempts) {
                    $this->markFailed($jobId, $attempts, $e->getMessage());
                    $stats['failed']++;
                    $stats['errors'][] = "Job #{$jobId} ({$type}): {$e->getMessage()}";
                } else {
                    // Reschedule with exponential backoff: 30s, 120s, 480s...
                    $delay = (int) (30 * pow(4, $attempts - 1));
                    $retryAt = gmdate(DATE_ATOM, time() + $delay);
                    $this->pdo->prepare('UPDATE jobs SET status = "pending", error = :e, scheduled_for = :sf WHERE id = :id')
                        ->execute([':e' => $e->getMessage(), ':sf' => $retryAt, ':id' => $jobId]);
                }
            }
        }

        return $stats;
    }

    private function markFailed(int $jobId, int $attempts, string $error): void
    {
        $this->pdo->prepare('UPDATE jobs SET status = "failed", error = :e, attempts = :a, completed_at = :c WHERE id = :id')
            ->execute([
                ':e'  => mb_substr($error, 0, 5000),
                ':a'  => $attempts,
                ':c'  => gmdate(DATE_ATOM),
                ':id' => $jobId,
            ]);
    }

    /**
     * Recover jobs stuck in "processing" for longer than $timeout seconds.
     * These are likely from crashed workers.
     */
    public function recoverStale(int $timeout = 300): int
    {
        $cutoff = gmdate(DATE_ATOM, time() - $timeout);
        $stmt = $this->pdo->prepare('
            UPDATE jobs SET status = "pending", error = "Recovered from stale processing state"
            WHERE status = "processing" AND started_at < :cutoff
        ');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Purge completed/failed jobs older than $days days.
     */
    public function purgeOld(int $days = 7): int
    {
        $cutoff = gmdate('Y-m-d\TH:i:s', time() - $days * 86400);
        $stmt = $this->pdo->prepare('
            DELETE FROM jobs WHERE status IN ("completed", "failed") AND created_at < :cutoff
        ');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Get queue statistics for monitoring.
     */
    public function stats(): array
    {
        $rows = $this->pdo->query('
            SELECT status, COUNT(*) as count FROM jobs GROUP BY status
        ')->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'pending'    => (int) ($rows['pending'] ?? 0),
            'processing' => (int) ($rows['processing'] ?? 0),
            'completed'  => (int) ($rows['completed'] ?? 0),
            'failed'     => (int) ($rows['failed'] ?? 0),
        ];
    }

    /**
     * List recent jobs for admin UI.
     */
    public function recent(int $limit = 50, ?string $status = null): array
    {
        $sql = 'SELECT * FROM jobs';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
