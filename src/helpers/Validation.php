<?php

class Validation
{
    public static function email(string $email): bool
    {
        if (strlen($email) > 100) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function password(string $password): ?string
    {
        $len = strlen($password);

        if ($len < 8) {
            return 'Hasło musi mieć co najmniej 8 znaków.';
        }

        if ($len > 200) {
            return 'Hasło może mieć maksymalnie 200 znaków.';
        }

        return null;
    }

    public static function username(string $username): ?string
    {
        $len = strlen($username);

        if ($len < 3) {
            return 'Nazwa użytkownika musi mieć co najmniej 3 znaki.';
        }

        if ($len > 50) {
            return 'Nazwa użytkownika może mieć maksymalnie 50 znaków.';
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $username)) {
            return 'Nazwa użytkownika może zawierać tylko litery, cyfry, znak podkreślenia oraz myślnik.';
        }

        return null;
    }
}
