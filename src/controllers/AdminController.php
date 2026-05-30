<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/AdminRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class AdminController extends AppController {

    private const ROLES     = ['admin', 'manager', 'analyst', 'viewer'];
    private const PLANS      = ['Free', 'Pro'];
    private const PAGE_SIZE  = 20;

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

        $search = trim((string)($_GET['q'] ?? ''));
        $total  = $admin->countManagedOrganizations($userId, $search);
        $pages  = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
        $companies = $admin->getManagedOrganizationsPaged($userId, $search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        return $this->render('admin', [
            'title'     => 'Admin',
            'active'    => 'admin',
            'workspace' => null,
            'userEmail' => $_SESSION['user_email'] ?? '',
            'isGlobal'  => $admin->isGlobalAdmin($userId),
            'companies' => $companies,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'search'    => $search,
            'roles'     => self::ROLES,
            'plans'     => self::PLANS,
            'flash'     => $flash,
        ]);
    }

    /* ===================== AJAX reads ===================== */

    #[AllowedMethods(['GET'])]
    public function companies()
    {
        $this->requireLogin();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];
        if (!$admin->canAccessAdmin($userId)) { return $this->json(['ok' => false], 403); }

        $search = trim((string)($_GET['q'] ?? ''));
        $total  = $admin->countManagedOrganizations($userId, $search);
        $pages  = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
        $items  = $admin->getManagedOrganizationsPaged($userId, $search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return $this->json(['ok' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages]);
    }

    #[AllowedMethods(['GET'])]
    public function members()
    {
        $this->requireLogin();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];
        $orgId  = (int)($_GET['org'] ?? 0);
        if (!$admin->canManageOrg($userId, $orgId)) { return $this->json(['ok' => false], 403); }

        $org = $admin->getOrganization($orgId);
        $max = Plan::maxMembers($org['plan'] ?? 'Free');
        return $this->json([
            'ok'          => true,
            'members'     => $admin->getMembers($orgId),
            'plan'        => Plan::label($org['plan'] ?? 'Free'),
            'max_members' => $max === PHP_INT_MAX ? null : $max,
        ]);
    }

    #[AllowedMethods(['GET'])]
    public function searchCompanies()
    {
        $this->requireLogin();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];
        if (!$admin->canAccessAdmin($userId)) { return $this->json(['ok' => false], 403); }
        return $this->json(['ok' => true, 'items' => $admin->searchManagedCompanies($userId, trim((string)($_GET['q'] ?? '')))]);
    }

    /* ===================== mutations ===================== */

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
        $plan = in_array($_POST['plan'] ?? '', self::PLANS, true) ? $_POST['plan'] : 'Free';
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->respond(false, 'Company name is required (max 120 chars).');
        }
        $id = $admin->createOrganization($name, $plan);
        return $this->respond(true, 'Company "' . $name . '" created.', [
            'action'  => 'create-company',
            'company' => ['id' => $id, 'name' => $name, 'plan' => $plan, 'member_count' => 0],
        ]);
    }

    #[AllowedMethods(['POST'])]
    public function updateCompany()
    {
        $this->guard();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];
        $orgId  = (int)($_POST['organization_id'] ?? 0);
        if (!$admin->canManageOrg($userId, $orgId)) {
            return $this->respond(false, 'You cannot manage that company.');
        }
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->respond(false, 'Company name is required.');
        }
        // Only a global admin may change the plan.
        $plan = null;
        if ($admin->isGlobalAdmin($userId) && in_array($_POST['plan'] ?? '', self::PLANS, true)) {
            $plan = $_POST['plan'];
        }
        $admin->updateOrganization($orgId, $name, $plan);
        $org = $admin->getOrganization($orgId);
        return $this->respond(true, 'Company updated.', [
            'action'  => 'update-company',
            'company' => ['id' => $orgId, 'name' => $org['name'], 'plan' => $org['plan']],
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
        if (($cap = $this->memberCapError($admin, $orgId)) !== null) {
            return $this->respond(false, $cap);
        }
        if (!in_array($memberRole, self::ROLES, true)) {
            return $this->respond(false, 'Invalid role.');
        }
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

        $newId = $admin->createUser($email, password_hash($password, PASSWORD_BCRYPT), $fullName, $globalRole, $orgId);
        $admin->upsertMembership($orgId, $newId, $memberRole);
        return $this->respond(true, 'User ' . $email . ' created and added.', [
            'action'          => 'create-user',
            'organization_id' => $orgId,
            'member'          => ['id' => $newId, 'full_name' => $fullName, 'email' => $email, 'global_role' => $globalRole, 'member_role' => $memberRole],
        ]);
    }

    #[AllowedMethods(['POST'])]
    public function updateUser()
    {
        $this->guard();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];
        if (!$admin->isGlobalAdmin($userId)) {
            return $this->respond(false, 'Only a global admin can edit user profiles.');
        }
        $target = (int)($_POST['user_id'] ?? 0);
        $u = $admin->getUser($target);
        if (!$u) { return $this->respond(false, 'User not found.'); }

        $fullName   = trim($_POST['full_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $globalRole = in_array($_POST['global_role'] ?? '', self::ROLES, true) ? $_POST['global_role'] : null;
        $password   = $_POST['password'] ?? '';

        if ($fullName === '' || mb_strlen($fullName) > 100) {
            return $this->respond(false, 'Full name is required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            return $this->respond(false, 'Valid email is required.');
        }
        if ($admin->emailTakenByOther($email, $target)) {
            return $this->respond(false, 'That email is already used by another user.');
        }
        $hash = null;
        if ($password !== '') {
            if (strlen($password) < 8 || strlen($password) > 200) {
                return $this->respond(false, 'Password must be at least 8 characters.');
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
        }
        $admin->updateUser($target, $fullName, $email, $globalRole, $hash);
        return $this->respond(true, 'User updated.', [
            'action'      => 'update-user',
            'user_id'     => $target,
            'full_name'   => $fullName,
            'email'       => $email,
            'global_role' => $globalRole ?? $u['role'],
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
        // A brand-new seat counts against the plan cap; a role update does not.
        if (!$admin->isMember($orgId, (int)$target['id']) && ($cap = $this->memberCapError($admin, $orgId)) !== null) {
            return $this->respond(false, $cap);
        }
        $admin->upsertMembership($orgId, (int)$target['id'], $memberRole);
        return $this->respond(true, $email . ' added/updated.', [
            'action'          => 'add-member',
            'organization_id' => $orgId,
            'member'          => ['id' => (int)$target['id'], 'full_name' => $target['full_name'], 'email' => $target['email'], 'global_role' => $target['role'] ?? 'viewer', 'member_role' => $memberRole],
        ]);
    }

    #[AllowedMethods(['POST'])]
    public function setRole()
    {
        $this->guard();
        $admin  = AdminRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];
        $orgId  = (int)($_POST['organization_id'] ?? 0);
        $target = (int)($_POST['user_id'] ?? 0);
        $role   = $_POST['member_role'] ?? '';
        if (!$admin->canManageOrg($userId, $orgId)) {
            return $this->respond(false, 'You cannot manage that company.');
        }
        if (!in_array($role, self::ROLES, true)) {
            return $this->respond(false, 'Invalid role.');
        }
        $admin->upsertMembership($orgId, $target, $role);
        return $this->respond(true, 'Role updated.', [
            'action'          => 'set-role',
            'organization_id' => $orgId,
            'user_id'         => $target,
            'member_role'     => $role,
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

    /* ===================== helpers ===================== */

    /** Returns an error string if the company is at its plan's member cap, else null. */
    private function memberCapError(AdminRepository $admin, int $orgId): ?string
    {
        $org = $admin->getOrganization($orgId);
        $max = Plan::maxMembers($org['plan'] ?? 'Free');
        if ($admin->countMembers($orgId) >= $max) {
            return 'The Free plan is limited to ' . $max . ' members. Upgrade this company to Pro.';
        }
        return null;
    }

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

    private function json(array $payload, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit();
    }

    /** JSON for AJAX, otherwise flash + redirect (progressive enhancement). */
    private function respond(bool $ok, string $msg, array $extra = []): void
    {
        if ($this->wantsJson()) {
            $this->json(array_merge(['ok' => $ok, 'msg' => $msg], $extra), $ok ? 200 : 422);
        }
        $_SESSION['admin_flash'] = ['type' => $ok ? 'ok' : 'err', 'msg' => $msg];
        $this->redirect('/admin');
    }
}
