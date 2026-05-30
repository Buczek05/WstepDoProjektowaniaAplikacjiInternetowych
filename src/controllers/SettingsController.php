<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/StatsRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class SettingsController extends AppController {

    #[AllowedMethods(['GET'])]
    public function index()
    {
        $this->requireLogin();

        $stats     = StatsRepository::getInstance();
        $userId    = (int)$_SESSION['user_id'];
        $workspace = $stats->getActiveWorkspace($userId);

        return $this->render('settings', [
            'title'       => 'Settings',
            'active'      => 'settings',
            'workspace'   => $workspace,
            'statDate'    => $workspace ? $stats->getLatestStatDate((int)$workspace['id']) : null,
            'userEmail'   => $_SESSION['user_email'] ?? '',
            'memberships' => $stats->getMemberships($userId),
        ]);
    }
}
