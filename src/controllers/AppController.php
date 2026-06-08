<?php

require_once __DIR__ . '/../models/Plan.php';

class AppController {
    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            // Harden session cookie: HttpOnly (C3), Secure over HTTPS (D3), SameSite=Strict (E3)
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    protected function isGet(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }

    protected function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    protected function requireLogin(): void
    {
        if (empty($_SESSION['user_id'])) {
            $this->redirect('/login');
        }
    }

    /** Selected reporting window in days from ?days=, else the user's saved default. */
    protected function periodDays(): int
    {
        $allowed = [7, 30, 90, 365];
        $default = (int)($_SESSION['default_period'] ?? 30);
        $default = in_array($default, $allowed, true) ? $default : 30;
        $days    = (int)($_GET['days'] ?? $default);
        return in_array($days, $allowed, true) ? $days : $default;
    }

    /** Start date for a trailing window of $days ending on $to (inclusive). */
    protected function periodFrom(string $to, int $days): string
    {
        return date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days'));
    }

    protected function redirect(string $path): void
    {
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $url    = "{$scheme}://{$_SERVER['HTTP_HOST']}";
        header("Location: {$url}{$path}");
        exit();
    }

    protected function render(string $template = null, array $variables = [])
    {
        $templatePath    = 'public/views/' . $template . '.html';
        $templatePath404 = 'public/views/404.html';

        if (file_exists($templatePath)) {
            extract($variables);
            ob_start();
            include $templatePath;
            echo ob_get_clean();
        } else {
            ob_start();
            include $templatePath404;
            echo ob_get_clean();
        }
    }
}
