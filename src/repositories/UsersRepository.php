<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/User.php';

class UsersRepository extends Repository {
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** @return User[] mapped from rows. */
    public function getUsers(): array
    {
        $query = $this->database->prepare(
            "SELECT id, username, email, full_name, is_active, created_at FROM users ORDER BY created_at DESC"
        );
        $query->execute();
        return array_map(
            fn(array $row) => User::fromArray($row),
            $query->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function getUserByEmail(string $email): ?User
    {
        $query = $this->database->prepare(
            "SELECT id, username, email, full_name, password, is_active, theme, default_period
             FROM users WHERE email = :email LIMIT 1"
        );
        $query->execute(['email' => $email]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? User::fromArray($row) : null;
    }

    public function createUser(string $username, string $email, string $hashedPassword, string $fullName): void
    {
        $query = $this->database->prepare(
            "INSERT INTO users (username, email, full_name, password, is_active) VALUES (?, ?, ?, ?, true)"
        );
        $query->execute([$username, $email, $fullName, $hashedPassword]);
    }

    /* ---- self-service account (Account & Preferences page) ---- */

    public function getProfile(int $userId): ?array
    {
        $q = $this->database->prepare(
            "SELECT id, full_name, email, theme, default_period FROM users WHERE id = :id"
        );
        $q->execute(['id' => $userId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function emailTakenByOther(string $email, int $excludeId): bool
    {
        $q = $this->database->prepare("SELECT 1 FROM users WHERE email = :em AND id <> :id LIMIT 1");
        $q->execute(['em' => $email, 'id' => $excludeId]);
        return (bool)$q->fetchColumn();
    }

    public function updateProfile(int $userId, string $fullName, string $email): void
    {
        $q = $this->database->prepare(
            "UPDATE users SET full_name = :fn, email = :em, updated_at = now() WHERE id = :id"
        );
        $q->execute(['fn' => $fullName, 'em' => $email, 'id' => $userId]);
    }

    public function getPasswordHash(int $userId): ?string
    {
        $q = $this->database->prepare("SELECT password FROM users WHERE id = :id");
        $q->execute(['id' => $userId]);
        $h = $q->fetchColumn();
        return $h !== false ? $h : null;
    }

    public function updatePassword(int $userId, string $hashedPassword): void
    {
        $q = $this->database->prepare("UPDATE users SET password = :pw, updated_at = now() WHERE id = :id");
        $q->execute(['pw' => $hashedPassword, 'id' => $userId]);
    }

    public function updatePreferences(int $userId, string $theme, int $defaultPeriod): void
    {
        $q = $this->database->prepare(
            "UPDATE users SET theme = :t, default_period = :d, updated_at = now() WHERE id = :id"
        );
        $q->execute(['t' => $theme, 'd' => $defaultPeriod, 'id' => $userId]);
    }
}
