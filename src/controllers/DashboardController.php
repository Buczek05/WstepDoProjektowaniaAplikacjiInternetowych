<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class DashboardController extends AppController {

    #[AllowedMethods(['GET'])]
    public function index()
    {
        $this->requireLogin();

        $usersRepository = UsersRepository::getInstance();
        $users = $usersRepository->getUsers();

        return $this->render('index', [
            'title' => 'Dashboard',
            'users' => $users,
        ]);
    }
}
