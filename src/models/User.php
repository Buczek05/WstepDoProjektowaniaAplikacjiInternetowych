<?php

/**
 * Entity — maps a row of the `users` table. Has an identity (id) and behaviour.
 * The password hash lives on the entity but is never exposed to views (B5).
 */
class User {
    private ?int $id;
    private string $username;
    private string $email;
    private ?string $fullName;
    private ?string $password;
    private bool $isActive;
    private ?int $organizationId;

    public function __construct(
        ?int $id, string $username, string $email,
        ?string $fullName = null, ?string $password = null,
        bool $isActive = true, ?int $organizationId = null
    ) {
        $this->id             = $id;
        $this->username       = $username;
        $this->email          = $email;
        $this->fullName       = $fullName;
        $this->password       = $password;
        $this->isActive       = $isActive;
        $this->organizationId = $organizationId;
    }

    /** Map an associative DB row (FETCH_ASSOC) into an entity. */
    public static function fromArray(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (string)($row['username'] ?? ''),
            (string)($row['email'] ?? ''),
            $row['full_name'] ?? null,
            $row['password'] ?? null,
            isset($row['is_active']) ? (bool)$row['is_active'] : true,
            isset($row['organization_id']) ? (int)$row['organization_id'] : null,
        );
    }

    public function getId(): ?int            { return $this->id; }
    public function getUsername(): string    { return $this->username; }
    public function getEmail(): string       { return $this->email; }
    public function getFullName(): ?string   { return $this->fullName; }
    public function getPassword(): ?string   { return $this->password; }
    public function isActive(): bool         { return $this->isActive; }
    public function getOrganizationId(): ?int { return $this->organizationId; }

    /** Verify a plaintext password against this user's hash. */
    public function verifyPassword(string $plain): bool
    {
        return $this->password !== null && password_verify($plain, $this->password);
    }
}
