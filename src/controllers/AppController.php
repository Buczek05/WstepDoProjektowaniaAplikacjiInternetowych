<?php

class AppController
{
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
