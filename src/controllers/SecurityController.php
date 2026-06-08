<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class SecurityController extends AppController {

    #[AllowedMethods(['GET', 'POST'])]
    public function login()
    {
        if (!$this->isPost()) {
            $flash = $_SESSION['flash'] ?? null;
            unset($_SESSION['flash']);
            return $this->render('login', [
                'csrf_token' => $_SESSION['csrf_token'],
                'flash'      => $flash,
            ]);
        }

        // CSRF check (B2)
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
            http_response_code(403);
            return $this->render('login', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Invalid CSRF token',
            ]);
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Input length caps (D2)
        if (strlen($email) > 100 || strlen($password) > 200) {
            http_response_code(400);
            return $this->render('login', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Input too long',
            ]);
        }

        if (empty($email) || empty($password)) {
            http_response_code(400);
            return $this->render('login', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Fill all fields',
            ]);
        }

        // Email format validation (C1) — generic message to avoid enumeration (B1)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            return $this->render('login', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Invalid email or password',
            ]);
        }

        // Rate limiting (A4) — session-based sliding window
        $attempts    = (int)($_SESSION['login_attempts'] ?? 0);
        $lastAttempt = (int)($_SESSION['login_last_attempt'] ?? 0);
        if (time() - $lastAttempt > 900) {
            $attempts = 0;
        }
        if ($attempts >= 5) {
            http_response_code(429);
            sleep(2);
            return $this->render('login', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Too many login attempts. Try again later.',
            ]);
        }

        $userRepository = UsersRepository::getInstance();
        $user           = $userRepository->getUserByEmail($email);

        $passwordOk = $user && $user->verifyPassword($password);

        if (!$user || !$passwordOk) {
            $newAttempts                    = $attempts + 1;
            $_SESSION['login_attempts']     = $newAttempts;
            $_SESSION['login_last_attempt'] = time();
            // A4: progressive brute-force delay once attempts pile up
            if ($newAttempts > 3) {
                sleep(2);
            }
            // E5: log without password; B1: generic message
            error_log('Failed login for ' . $email . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            http_response_code(401);
            return $this->render('login', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Invalid email or password',
            ]);
        }

        // Clear rate-limit on success
        unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);

        // Create session (B3: regenerate ID to prevent session fixation)
        session_regenerate_id(true);
        $_SESSION['user_id']        = $user->getId();
        $_SESSION['user_email']     = $user->getEmail();
        $_SESSION['user_firstname'] = $user->getUsername();
        $_SESSION['is_logged_in']   = true;
        $_SESSION['theme']          = $user->getTheme();
        $_SESSION['default_period'] = $user->getDefaultPeriod();

        $this->redirect('/dashboard');
    }

    #[AllowedMethods(['GET', 'POST'])]
    public function register()
    {
        if (!$this->isPost()) {
            return $this->render('register', ['csrf_token' => $_SESSION['csrf_token']]);
        }

        // CSRF check (C2)
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
            http_response_code(403);
            return $this->render('register', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Invalid CSRF token',
            ]);
        }

        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $firstName = trim($_POST['firstName'] ?? '');
        $lastName  = trim($_POST['lastName'] ?? '');

        // Input length caps (D2)
        if (strlen($email) > 100 || strlen($password) > 200 || strlen($firstName) > 50 || strlen($lastName) > 50) {
            http_response_code(400);
            return $this->render('register', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Input too long',
            ]);
        }

        if (empty($email) || empty($password) || empty($password2) || empty($firstName) || empty($lastName)) {
            http_response_code(400);
            return $this->render('register', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Fill all fields',
            ]);
        }

        // Email format (C1)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            return $this->render('register', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Invalid email format',
            ]);
        }

        // Password complexity (B4)
        if (strlen($password) < 8) {
            http_response_code(400);
            return $this->render('register', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Password must be at least 8 characters',
            ]);
        }

        if ($password !== $password2) {
            http_response_code(400);
            return $this->render('register', [
                'csrf_token' => $_SESSION['csrf_token'],
                'messages'   => 'Passwords are not the same',
            ]);
        }

        $userRepository = UsersRepository::getInstance();

        // C4: don't reveal whether email is already taken
        $flash    = 'If the email is available, your account has been created. You can now log in.';
        $existing = $userRepository->getUserByEmail($email);
        if (!$existing) {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $userRepository->createUser($firstName, $email, $hashedPassword, $firstName . ' ' . $lastName);
        }

        $_SESSION['flash'] = $flash;
        $this->redirect('/login');
    }

    #[AllowedMethods(['GET', 'POST'])]
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $url = "{$scheme}://{$_SERVER['HTTP_HOST']}";
        header("Location: {$url}/login");
        exit();
    }
}
