<?php

require_once __DIR__ . '/../Attribute/AllowedMethods.php';

function checkRequestAllowed(object $controller, string $methodName): void
{
    $reflection = new ReflectionMethod($controller, $methodName);
    $attributes = $reflection->getAttributes(AllowedMethods::class);

    if (empty($attributes)) {
        return;
    }

    $allowed = $attributes[0]->newInstance()->methods;

    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowed));
        include 'public/views/404.html';
        exit();
    }
}
