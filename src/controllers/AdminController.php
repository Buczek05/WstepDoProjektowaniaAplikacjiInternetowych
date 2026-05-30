<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/AdminRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class AdminController extends AppController {

    private const ROLES = ['admin', 'manager', 'analyst', 'viewer'];

    #[AllowedMethods(['GET'])]
    public function index()
    {
        $this->requireLogin();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];

        if (!$admin->canAccessAdmin($userId)) {
            http_response_code(403);
            $this->redirect('/dashboard');
        }

        $managed = $admin->getManagedOrganizations($userId);
        $orgs    = [];
        foreach ($managed as $o) {
            $orgs[] = ['org' => $o, 'members' => $admin->getMembers((int)$o['id'])];
        }

        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        return $this->render('admin', [
            'title'      => 'Admin',
            'active'     => 'admin',
            'workspace'  => null,
            'userEmail'  => $_SESSION['user_email'] ?? '',
            'isGlobal'   => $admin->isGlobalAdmin($userId),
            'managed'    => $managed,
            'orgs'       => $orgs,
            'roles'      => self::ROLES,
            'flash'      => $flash,
        ]);
    }

    #[AllowedMethods(['POST'])]
    public function createCompany()
    {
        $this->guard();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];

        if (!$admin->isGlobalAdmin($userId)) {
            return $this->respond(false, 'Only a global admin can create companies.');
        }
        $name = trim($_POST['name'] ?? '');
        $plan = trim($_POST['plan'] ?? 'Free');
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->respond(false, 'Company name is required (max 120 chars).');
        }
        $plan = $plan !== '' ? $plan : 'Free';
        $id   = $admin->createOrganization($name, $plan);
        return $this->respond(true, 'Company "' . $name . '" created.', [
            'action'  => 'create-company',
            'company' => ['id' => $id, 'name' => $name, 'plan' => $plan],
        ]);
    }

    #[AllowedMethods(['POST'])]
    public function createUser()
    {
        $this->guard();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];

        $orgId      = (int)($_POST['organization_id'] ?? 0);
        $email      = trim($_POST['email'] ?? '');
        $fullName   = trim($_POST['full_name'] ?? '');
        $password   = $_POST['password'] ?? '';
        $memberRole = $_POST['member_role'] ?? 'viewer';
        $globalRole = $_POST['global_role'] ?? 'viewer';

        if (!$admin->canManageOrg($userId, $orgId)) {
            return $this->respond(false, 'You cannot manage that company.');
        }
        if (!in_array($memberRole, self::ROLES, true)) {
            return $this->respond(false, 'Invalid role.');
        }
        // Only a global admin may mint another global admin.
        if (!$admin->isGlobalAdmin($userId) || !in_array($globalRole, self::ROLES, true)) {
            $globalRole = 'viewer';
        }
        if ($fullName === '' || mb_strlen($fullName) > 100) {
            return $this->respond(false, 'Full name is required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            return $this->respond(false, 'Valid email is required.');
        }
        if (strlen($password) < 8 || strlen($password) > 200) {
            return $this->respond(false, 'Password must be at least 8 characters.');
        }
        if ($admin->emailExists($email)) {
            return $this->respond(false, 'A user with that email already exists — use "Add member" instead.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $newId = $admin->createUser($email, $hash, $fullName, $globalRole, $orgId);
        $admin->upsertMembership($orgId, $newId, $memberRole);
        return $this->respond(true, 'User ' . $email . ' created and added.', [
            'action'          => 'create-user',
            'organization_id' => $orgId,
            'member'          => ['id' => $newId, 'full_name' => $fullName, 'email' => $email, 'global_role' => $globalRole, 'member_role' => $memberRole],
        ]);
    }

    #[AllowedMethods(['POST'])]
    public function addMember()
    {
        $this->guard();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];

        $orgId      = (int)($_POST['organization_id'] ?? 0);
        $email      = trim($_POST['email'] ?? '');
        $memberRole = $_POST['member_role'] ?? 'viewer';

        if (!$admin->canManageOrg($userId, $orgId)) {
            return $this->respond(false, 'You cannot manage that company.');
        }
        if (!in_array($memberRole, self::ROLES, true)) {
            return $this->respond(false, 'Invalid role.');
        }
        $target = $admin->findUserByEmail($email);
        if (!$target) {
            return $this->respond(false, 'No user with that email — create them first.');
        }
        $admin->upsertMembership($orgId, (int)$target['id'], $memberRole);
        return $this->respond(true, $email . ' added/updated.', [
            'action'          => 'add-member',
            'organization_id' => $orgId,
            'member'          => ['id' => (int)$target['id'], 'full_name' => $target['full_name'], 'email' => $target['email'], 'global_role' => $target['role'] ?? 'viewer', 'member_role' => $memberRole],
        ]);
    }

    #[AllowedMethods(['POST'])]
    public function removeMember()
    {
        $this->guard();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];

        $orgId  = (int)($_POST['organization_id'] ?? 0);
        $target = (int)($_POST['user_id'] ?? 0);

        if (!$admin->canManageOrg($userId, $orgId)) {
            return $this->respond(false, 'You cannot manage that company.');
        }
        if ($target === $userId) {
            return $this->respond(false, 'You cannot remove your own access.');
        }
        $admin->removeMembership($orgId, $target);
        return $this->respond(true, 'Member removed.', [
            'action'          => 'remove-member',
            'organization_id' => $orgId,
            'user_id'         => $target,
        ]);
    }

    /** Shared guard for POST actions: login + CSRF. */
    private function guard(): void
    {
        $this->requireLogin();
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $this->respond(false, 'Invalid CSRF token.');
        }
    }

    private function wantsJson(): bool
    {
        return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /** Respond as JSON for AJAX, otherwise flash + redirect (progressive enhancement). */
    private function respond(bool $ok, string $msg, array $extra = []): void
    {
        if ($this->wantsJson()) {
            if (!$ok) {
                http_response_code(422);
            }
            header('Content-Type: application/json');
            echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra));
            exit();
        }
        $_SESSION['admin_flash'] = ['type' => $ok ? 'ok' : 'err', 'msg' => $msg];
        $this->redirect('/admin');
    }
}
