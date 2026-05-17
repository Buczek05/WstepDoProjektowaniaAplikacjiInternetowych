<?php

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = !empty($_SERVER['HTTPS']);

        // Configure cookie params before starting the session.
        $params = [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ];

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            session_set_cookie_params(
                $params['lifetime'],
                $params['path'] . '; samesite=' . $params['samesite'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Hardening ini settings.
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Strict');
        if ($secure) {
            @ini_set('session.cookie_secure', '1');
        }

        session_start();
    }

    public static function getCsrfToken(): string
    {
        self::start();

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(?string $token): bool
    {
        self::start();

        if (!is_string($token) || $token === '') {
            return false;
        }

        $stored = $_SESSION['csrf_token'] ?? null;
        if (!is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    public static function login(int $userId, string $email, string $username): void
    {
        self::start();

        // Prevent session fixation: regenerate the session ID on privilege change.
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => $userId,
            'email' => $email,
            'username' => $username,
        ];
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return isset($_SESSION['user']['id']);
    }

    public static function currentUser(): ?array
    {
        self::start();

        if (!isset($_SESSION['user']['id'])) {
            return null;
        }

        $user = $_SESSION['user'];

        return [
            'id' => (int)$user['id'],
            'email' => (string)($user['email'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
        ];
    }

    public static function logout(): void
    {
        self::start();

        $_SESSION = [];
        session_unset();

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?? '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => $params['secure'] ?? false,
                    'httponly' => $params['httponly'] ?? true,
                    'samesite' => $params['samesite'] ?? 'Strict',
                ]
            );
        }

        session_destroy();
    }
}
