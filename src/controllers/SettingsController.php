<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';
require_once __DIR__ . '/../repositories/StatsRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class SettingsController extends AppController {

    private const PERIODS = [7, 30, 90, 365];

    #[AllowedMethods(['GET'])]
    public function index()
    {
        $this->requireLogin();
        $userId  = (int)$_SESSION['user_id'];
        $profile = UsersRepository::getInstance()->getProfile($userId);

        $flash = $_SESSION['settings_flash'] ?? null;
        unset($_SESSION['settings_flash']);

        return $this->render('settings', [
            'title'         => 'Account',
            'active'        => 'settings',
            'workspace'     => StatsRepository::getInstance()->getActiveWorkspace($userId),
            'userEmail'     => $_SESSION['user_email'] ?? '',
            'profile'       => $profile,
            'theme'         => $_SESSION['theme'] ?? 'dark',
            'defaultPeriod' => (int)($_SESSION['default_period'] ?? 30),
            'periods'       => self::PERIODS,
            'flash'         => $flash,
        ]);
    }

    #[AllowedMethods(['POST'])]
    public function updateProfile()
    {
        $this->guard();
        $users  = UsersRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];

        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        if ($fullName === '' || mb_strlen($fullName) > 100) {
            return $this->done('err', 'Full name is required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            return $this->done('err', 'Valid email is required.');
        }
        if ($users->emailTakenByOther($email, $userId)) {
            return $this->done('err', 'That email is already in use.');
        }
        $users->updateProfile($userId, $fullName, $email);
        $_SESSION['user_email'] = $email;
        return $this->done('ok', 'Profile updated.');
    }

    #[AllowedMethods(['POST'])]
    public function changePassword()
    {
        $this->guard();
        $users  = UsersRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $hash = $users->getPasswordHash($userId);
        if (!$hash || !password_verify($current, $hash)) {
            return $this->done('err', 'Current password is incorrect.');
        }
        if (strlen($new) < 8 || strlen($new) > 200) {
            return $this->done('err', 'New password must be at least 8 characters.');
        }
        if ($new !== $confirm) {
            return $this->done('err', 'New passwords do not match.');
        }
        $users->updatePassword($userId, password_hash($new, PASSWORD_BCRYPT));
        return $this->done('ok', 'Password changed.');
    }

    #[AllowedMethods(['POST'])]
    public function updatePreferences()
    {
        $this->guard();
        $users  = UsersRepository::getInstance();
        $userId = (int)$_SESSION['user_id'];

        $theme  = ($_POST['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
        $period = (int)($_POST['default_period'] ?? 30);
        if (!in_array($period, self::PERIODS, true)) {
            $period = 30;
        }
        $users->updatePreferences($userId, $theme, $period);
        $_SESSION['theme']          = $theme;
        $_SESSION['default_period'] = $period;
        return $this->done('ok', 'Preferences saved.');
    }

    private function guard(): void
    {
        $this->requireLogin();
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            $this->redirect('/settings');
        }
    }

    private function done(string $type, string $msg): void
    {
        $_SESSION['settings_flash'] = ['type' => $type, 'msg' => $msg];
        $this->redirect('/settings');
    }
}
