<?php

require_once __DIR__ . '/Session.php';

class Csrf
{
    public static function field(): string
    {
        $token = Session::getCsrfToken();
        $safe = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<input type="hidden" name="csrf_token" value="' . $safe . '">';
    }
}
