<?php

require_once 'Repository.php';

class UsersRepository extends Repository {
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getUsers(): array
    {
        $query = $this->database->prepare(
            "SELECT id, username, email, full_name, is_active, created_at FROM users ORDER BY created_at DESC"
        );
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserByEmail(string $email): ?array
    {
        $query = $this->database->prepare(
            "SELECT id, username, email, full_name, password, is_active FROM users WHERE email = :email LIMIT 1"
        );
        $query->execute(['email' => $email]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function createUser(string $username, string $email, string $hashedPassword, string $fullName): void
    {
        $query = $this->database->prepare(
            "INSERT INTO users (username, email, full_name, password, is_active) VALUES (?, ?, ?, ?, true)"
        );
        $query->execute([$username, $email, $fullName, $hashedPassword]);
    }
}
