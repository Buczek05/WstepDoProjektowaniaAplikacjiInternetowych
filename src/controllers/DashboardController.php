<?php

require_once __DIR__ . '/AppController.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';
require_once __DIR__ . '/../helpers/Session.php';

class DashboardController extends AppController
{
    public function index($id = null)
    {
        if (!Session::isLoggedIn()) {
            $this->redirect('/login');
            return;
        }

        $userRepository = UsersRepository::getInstance();
        $users = $userRepository->getAllUsers();

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $this->render('dashboard', [
            'title' => 'WDPAI - Dashboard',
            'users' => $users,
            'currentUser' => Session::currentUser(),
            'flash' => $flash,
        ]);
    }
}
