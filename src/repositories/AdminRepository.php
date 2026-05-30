<?php

require_once 'Repository.php';

/**
 * User & access administration.
 *  - Global admin   = users.role = 'admin' (platform-wide).
 *  - Company admin  = organization_members.role = 'admin' for a given org.
 * All authorization checks live here / in AdminController; the UI only reflects them.
 */
class AdminRepository extends Repository {
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isGlobalAdmin(int $userId): bool
    {
        $q = $this->database->prepare("SELECT role = 'admin' FROM users WHERE id = :id");
        $q->execute(['id' => $userId]);
        return (bool)$q->fetchColumn();
    }

    /** True if the user may open the admin panel at all. */
    public function canAccessAdmin(int $userId): bool
    {
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }
        $q = $this->database->prepare(
            "SELECT 1 FROM organization_members WHERE user_id = :id AND role = 'admin' LIMIT 1"
        );
        $q->execute(['id' => $userId]);
        return (bool)$q->fetchColumn();
    }

    /** Organizations the user can manage (all for a global admin, else admin-of). */
    public function getManagedOrganizations(int $userId): array
    {
        if ($this->isGlobalAdmin($userId)) {
            $q = $this->database->prepare("SELECT id, name, plan FROM organizations ORDER BY name");
            $q->execute();
            return $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $q = $this->database->prepare(
            "SELECT o.id, o.name, o.plan
             FROM organization_members m JOIN organizations o ON o.id = m.organization_id
             WHERE m.user_id = :id AND m.role = 'admin' ORDER BY o.name"
        );
        $q->execute(['id' => $userId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** May this user administer a specific organization? */
    public function canManageOrg(int $userId, int $orgId): bool
    {
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }
        $q = $this->database->prepare(
            "SELECT 1 FROM organization_members
             WHERE user_id = :id AND organization_id = :org AND role = 'admin' LIMIT 1"
        );
        $q->execute(['id' => $userId, 'org' => $orgId]);
        return (bool)$q->fetchColumn();
    }

    /** Members of an organization with their membership role. */
    public function getMembers(int $orgId): array
    {
        $q = $this->database->prepare(
            "SELECT u.id, u.email, u.full_name, u.role AS global_role, m.role AS member_role
             FROM organization_members m JOIN users u ON u.id = m.user_id
             WHERE m.organization_id = :org
             ORDER BY (m.role = 'admin') DESC, u.email"
        );
        $q->execute(['org' => $orgId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findUserByEmail(string $email): ?array
    {
        $q = $this->database->prepare("SELECT id, email, full_name, role FROM users WHERE email = :email LIMIT 1");
        $q->execute(['email' => $email]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function emailExists(string $email): bool
    {
        return $this->findUserByEmail($email) !== null;
    }

    /** Create an organization with a unique slug derived from its name. */
    public function createOrganization(string $name, string $plan): int
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
        $base = trim($base, '-') ?: 'org';
        $slug = $base; $i = 1;
        $check = $this->database->prepare("SELECT 1 FROM organizations WHERE slug = :s");
        while (true) {
            $check->execute(['s' => $slug]);
            if (!$check->fetchColumn()) break;
            $slug = $base . '-' . (++$i);
        }
        $q = $this->database->prepare(
            "INSERT INTO organizations (name, slug, plan) VALUES (:n, :s, :p) RETURNING id"
        );
        $q->execute(['n' => $name, 's' => $slug, 'p' => $plan]);
        return (int)$q->fetchColumn();
    }

    /** Create a user (unique username from email) attached to an organization. */
    public function createUser(string $email, string $hashedPassword, string $fullName, string $globalRole, int $orgId): int
    {
        $base = preg_replace('/[^a-z0-9_.]/', '', strtolower(explode('@', $email)[0]));
        $base = substr($base ?: 'user', 0, 40);
        $username = $base; $i = 1;
        $check = $this->database->prepare("SELECT 1 FROM users WHERE username = :u");
        while (true) {
            $check->execute(['u' => $username]);
            if (!$check->fetchColumn()) break;
            $username = substr($base, 0, 36) . '_' . (++$i);
        }
        $q = $this->database->prepare(
            "INSERT INTO users (organization_id, username, email, password, full_name, role)
             VALUES (:org, :un, :em, :pw, :fn, :role) RETURNING id"
        );
        $q->execute([
            'org' => $orgId, 'un' => $username, 'em' => $email,
            'pw' => $hashedPassword, 'fn' => $fullName, 'role' => $globalRole,
        ]);
        return (int)$q->fetchColumn();
    }

    /** Add or update a user's role in an organization. */
    public function upsertMembership(int $orgId, int $userId, string $role): void
    {
        $q = $this->database->prepare(
            "INSERT INTO organization_members (organization_id, user_id, role)
             VALUES (:org, :uid, :role)
             ON CONFLICT (organization_id, user_id) DO UPDATE SET role = EXCLUDED.role"
        );
        $q->execute(['org' => $orgId, 'uid' => $userId, 'role' => $role]);
    }

    public function removeMembership(int $orgId, int $userId): void
    {
        $q = $this->database->prepare(
            "DELETE FROM organization_members WHERE organization_id = :org AND user_id = :uid"
        );
        $q->execute(['org' => $orgId, 'uid' => $userId]);
    }
}
