<?php

require_once __DIR__ . '/Database.php';

class UsersRepository
{
    private static ?self $instance = null;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getUserByEmail(string $email): ?array
    {
        // C5: Only select the columns we actually need.
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password, is_active FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function createUser(string $email, string $hashedPassword, string $username): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (email, password, username) VALUES (:email, :password, :username)'
        );
        $stmt->execute([
            'email' => $email,
            'password' => $hashedPassword,
            'username' => $username,
        ]);
    }

    public function getAllUsers(): array
    {
        $stmt = $this->db->query(
            'SELECT id, username, email, created_at, is_active FROM users ORDER BY created_at DESC'
        );
        return $stmt->fetchAll();
    }
}
