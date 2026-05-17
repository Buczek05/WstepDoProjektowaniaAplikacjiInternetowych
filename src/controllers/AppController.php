<?php

require_once __DIR__ . '/../helpers/Session.php';

class AppController
{
    private string $request;

    public function __construct()
    {
        // Defense-in-depth: enforce HTTPS at the application layer when explicitly enabled.
        // nginx still handles the actual redirect in production.
        $forceHttps = getenv('FORCE_HTTPS');
        if ($forceHttps === 'true' && empty($_SERVER['HTTPS'])) {
            http_response_code(426);
            echo 'HTTPS required';
            exit;
        }

        Session::start();

        $this->request = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    protected function isGet(): bool
    {
        return $this->request === 'GET';
    }

    protected function isPost(): bool
    {
        return $this->request === 'POST';
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }

    protected function render(string $template, array $variables = []): void
    {
        $templatePath = 'public/views/' . $template . '.php';

        if (!file_exists($templatePath)) {
            http_response_code(404);
            include 'public/views/404.html';
            return;
        }

        extract($variables);
        require $templatePath;
    }
}
