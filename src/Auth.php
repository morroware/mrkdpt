<?php

declare(strict_types=1);

final class Auth
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ---- session helpers ---- */

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 86400 * 7,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /* ---- user CRUD ---- */

    public function createUser(string $username, string $password, string $role = 'admin'): array
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('INSERT INTO users(username, password_hash, role, api_token, created_at) VALUES(:u,:p,:r,:t,:c)');
        $stmt->execute([
            ':u' => strtolower(trim($username)),
            ':p' => password_hash($password, PASSWORD_BCRYPT),
            ':r' => $role,
            ':t' => $token,
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->findUser((int)$this->pdo->lastInsertId());
    }

    public function findUser(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => strtolower(trim($username))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE api_token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function userCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function allUsers(): array
    {
        return $this->pdo->query('SELECT id, username, role, created_at FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function regenerateToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('UPDATE users SET api_token = :t WHERE id = :id');
        $stmt->execute([':t' => $token, ':id' => $userId]);
        return $token;
    }

    /* ---- login / logout ---- */

    public function login(string $username, string $password): ?array
    {
        $user = $this->findByUsername($username);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        $this->startSession();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $user;
    }

    public function logout(): void
    {
        $this->startSession();
        $_SESSION = [];
        session_destroy();
    }

    /* ---- auth check (session OR bearer token) ---- */

    public function currentUser(): ?array
    {
        // try bearer token first (stateless API)
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
            return $this->findByToken($token);
        }

        // fall back to session
        $this->startSession();
        if (!empty($_SESSION['user_id'])) {
            return $this->findUser((int)$_SESSION['user_id']);
        }

        return null;
    }

    public function requireAuth(): array
    {
        $user = $this->currentUser();
        if (!$user) {
            json_response(['error' => 'Authentication required'], 401);
            exit;
        }
        return $user;
    }

    /* ---- CSRF ---- */

    public function csrfToken(): string
    {
        $this->startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function verifyCsrf(): bool
    {
        // skip CSRF check for bearer-token (stateless) requests
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return true;
        }

        $this->startSession();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    /* ---- rate limiting ---- */

    public function rateLimit(string $key, int $maxAttempts = 30, int $windowSeconds = 60): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $identifier = $ip . ':' . $key;
        $now = time();
        $windowStart = $now - $windowSeconds;

        // clean old entries
        $this->pdo->prepare('DELETE FROM rate_limits WHERE identifier = :id AND attempted_at < :w')->execute([
            ':id' => $identifier,
            ':w' => $windowStart,
        ]);

        // count recent
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE identifier = :id AND attempted_at >= :w');
        $stmt->execute([':id' => $identifier, ':w' => $windowStart]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= $maxAttempts) {
            return false; // rate limited
        }

        // record attempt
        $this->pdo->prepare('INSERT INTO rate_limits(identifier, attempted_at) VALUES(:id, :a)')->execute([
            ':id' => $identifier,
            ':a' => $now,
        ]);

        return true;
    }
}
