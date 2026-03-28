<?php

declare(strict_types=1);

final class Auth
{
    private PDO $pdo;
    private ?array $cachedUser = null;

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
                'secure' => !empty($_SERVER['HTTPS']),
            ]);
            session_start();
        }
    }

    /* ---- user CRUD ---- */

    public function createUser(string $username, string $password, string $role = 'admin'): array
    {
        if (!$this->isStrongPassword($password)) {
            throw new InvalidArgumentException('Password must be at least 10 characters and include uppercase, lowercase, number, and symbol.');
        }
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

    public function isStrongPassword(string $password): bool
    {
        if (strlen($password) < 10) {
            return false;
        }
        return (bool)preg_match('/[A-Z]/', $password)
            && (bool)preg_match('/[a-z]/', $password)
            && (bool)preg_match('/\d/', $password)
            && (bool)preg_match('/[^a-zA-Z0-9]/', $password);
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
        $attempt = $this->attemptLogin($username, $password);
        if (($attempt['status'] ?? 'invalid') !== 'ok' || empty($attempt['user'])) {
            return null;
        }
        return $attempt['user'];
    }

    public function attemptLogin(string $username, string $password): array
    {
        $user = $this->findByUsername($username);
        if (!$user) {
            return ['status' => 'invalid'];
        }

        $now = time();
        $lockedUntil = (int)($user['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return [
                'status' => 'locked',
                'retry_after' => $lockedUntil - $now,
            ];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return $this->recordFailedLogin((int)$user['id'], (int)($user['failed_login_attempts'] ?? 0), $now);
        }

        $this->resetFailedLoginState((int)$user['id']);
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->cachedUser = null;
        return [
            'status' => 'ok',
            'user' => $user,
        ];
    }

    public function logout(): void
    {
        $this->startSession();
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => 1,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
        session_destroy();
        $this->cachedUser = null;
    }

    /* ---- auth check (session OR bearer token) ---- */

    public function currentUser(): ?array
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        // try bearer token first (stateless API)
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
            $this->cachedUser = $this->findByToken($token);
            return $this->cachedUser;
        }

        // fall back to session
        $this->startSession();
        if (!empty($_SESSION['user_id'])) {
            $this->cachedUser = $this->findUser((int)$_SESSION['user_id']);
            return $this->cachedUser;
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
        $ip = $this->clientIp();
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

    private function clientIp(): string
    {
        $trustProxy = in_array(
            strtolower((string)app_config('TRUST_PROXY_HEADERS', 'false')),
            ['1', 'true', 'yes', 'on'],
            true
        );

        if ($trustProxy) {
            $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                $candidates = array_map('trim', explode(',', $xff));
                foreach ($candidates as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        return $candidate;
                    }
                }
            }
        }

        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false ? $remoteAddr : '127.0.0.1';
    }

    private function recordFailedLogin(int $userId, int $currentAttempts, int $attemptedAt): array
    {
        $maxAttempts = max(1, (int)app_config('LOGIN_LOCKOUT_ATTEMPTS', '5'));
        $lockSeconds = max(60, (int)app_config('LOGIN_LOCKOUT_SECONDS', '900'));
        $newAttempts = $currentAttempts + 1;

        if ($newAttempts >= $maxAttempts) {
            $lockedUntil = $attemptedAt + $lockSeconds;
            $this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, last_failed_login_at = :attempted, locked_until = :locked_until WHERE id = :id')->execute([
                ':attempted' => $attemptedAt,
                ':locked_until' => $lockedUntil,
                ':id' => $userId,
            ]);
            return [
                'status' => 'locked',
                'retry_after' => $lockSeconds,
            ];
        }

        $this->pdo->prepare('UPDATE users SET failed_login_attempts = :attempts, last_failed_login_at = :attempted, locked_until = NULL WHERE id = :id')->execute([
            ':attempts' => $newAttempts,
            ':attempted' => $attemptedAt,
            ':id' => $userId,
        ]);
        return ['status' => 'invalid'];
    }

    private function resetFailedLoginState(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, last_failed_login_at = NULL, locked_until = NULL WHERE id = :id')->execute([
            ':id' => $userId,
        ]);
    }
}
