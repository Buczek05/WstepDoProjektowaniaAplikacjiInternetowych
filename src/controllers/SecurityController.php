<?php

require_once __DIR__ . '/AppController.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';
require_once __DIR__ . '/../helpers/Session.php';
require_once __DIR__ . '/../helpers/Csrf.php';
require_once __DIR__ . '/../helpers/RateLimit.php';
require_once __DIR__ . '/../helpers/Validation.php';

class SecurityController extends AppController
{
    public function login()
    {
        if (!$this->isPost()) {
            return $this->render('login', ['csrf' => Csrf::field()]);
        }

        // CSRF check (A1)
        $csrfToken = $_POST['csrf_token'] ?? null;
        if (!Session::validateCsrf(is_string($csrfToken) ? $csrfToken : null)) {
            http_response_code(403);
            return $this->render('login', [
                'csrf' => Csrf::field(),
                'messages' => ['Niepoprawny token CSRF'],
            ]);
        }

        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        // D2: Length caps to prevent resource abuse (e.g. bcrypt long-input DoS, oversized inputs).
        if (strlen($email) > 100 || strlen($password) > 200) {
            http_response_code(400);
            return $this->render('login', [
                'csrf' => Csrf::field(),
                'messages' => ['Zbyt długie dane wejściowe'],
            ]);
        }

        // C1: Email format. Use generic message to avoid user enumeration.
        if (!Validation::email($email)) {
            http_response_code(400);
            return $this->render('login', [
                'csrf' => Csrf::field(),
                'messages' => ['Niepoprawny email lub hasło'],
            ]);
        }

        // A4 + E5: Rate-limit per email + IP.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateKey = 'login:' . $email . ':' . $ip;

        if (RateLimit::tooMany($rateKey)) {
            http_response_code(429);
            error_log("Login rate-limited for {$email} from {$ip}");
            sleep(2);
            return $this->render('login', [
                'csrf' => Csrf::field(),
                'messages' => ['Za dużo prób. Spróbuj ponownie później.'],
            ]);
        }

        $userRepository = UsersRepository::getInstance();
        $user = $userRepository->getUserByEmail($email);

        $passwordOk = false;
        if ($user && isset($user['password']) && is_string($user['password'])) {
            $passwordOk = password_verify($password, $user['password']);
        }

        if (!$user || !$passwordOk) {
            RateLimit::record($rateKey);
            // Never log password.
            error_log("Failed login for {$email} from {$ip}");
            http_response_code(401);
            return $this->render('login', [
                'csrf' => Csrf::field(),
                'messages' => ['Niepoprawny email lub hasło'],
            ]);
        }

        // Success.
        RateLimit::clear($rateKey);
        Session::login((int)$user['id'], (string)$user['email'], (string)$user['username']);
        $this->redirect('/dashboard');
    }

    public function register()
    {
        if (!$this->isPost()) {
            return $this->render('register', ['csrf' => Csrf::field()]);
        }

        // CSRF check (A1)
        $csrfToken = $_POST['csrf_token'] ?? null;
        if (!Session::validateCsrf(is_string($csrfToken) ? $csrfToken : null)) {
            http_response_code(403);
            return $this->render('register', [
                'csrf' => Csrf::field(),
                'messages' => ['Niepoprawny token CSRF'],
            ]);
        }

        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        $username = trim((string)($_POST['username'] ?? ''));

        // D2: Length caps.
        if (strlen($email) > 100 || strlen($password) > 200 || strlen($username) > 100) {
            http_response_code(400);
            return $this->render('register', [
                'csrf' => Csrf::field(),
                'messages' => ['Zbyt długie dane wejściowe'],
            ]);
        }

        if ($email === '' || $password === '' || $password2 === '' || $username === '') {
            http_response_code(400);
            return $this->render('register', [
                'csrf' => Csrf::field(),
                'messages' => ['Uzupełnij wszystkie pola'],
            ]);
        }

        if (!Validation::email($email)) {
            http_response_code(400);
            return $this->render('register', [
                'csrf' => Csrf::field(),
                'messages' => ['Niepoprawny format email'],
            ]);
        }

        $usernameError = Validation::username($username);
        if ($usernameError !== null) {
            http_response_code(400);
            return $this->render('register', [
                'csrf' => Csrf::field(),
                'messages' => [$usernameError],
            ]);
        }

        $passwordError = Validation::password($password);
        if ($passwordError !== null) {
            http_response_code(400);
            return $this->render('register', [
                'csrf' => Csrf::field(),
                'messages' => [$passwordError],
            ]);
        }

        if ($password !== $password2) {
            http_response_code(400);
            return $this->render('register', [
                'csrf' => Csrf::field(),
                'messages' => ['Hasła nie są zgodne'],
            ]);
        }

        $repo = UsersRepository::getInstance();

        // C4: Avoid user enumeration — same flash, same redirect, regardless of whether email exists.
        $flash = 'Jeśli email jest dostępny, konto zostało utworzone. Możesz się zalogować.';

        $existing = $repo->getUserByEmail($email);
        if (!$existing) {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $repo->createUser($email, $hashedPassword, $username);
        }

        Session::start();
        $_SESSION['flash'] = $flash;

        $this->redirect('/login');
    }

    public function logout()
    {
        Session::logout();
        $this->redirect('/login');
    }
}
