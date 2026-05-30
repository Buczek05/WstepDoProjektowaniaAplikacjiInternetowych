<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/StatsRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class MarketingController extends AppController {

    #[AllowedMethods(['GET'])]
    public function index()
    {
        $this->requireLogin();

        $stats     = StatsRepository::getInstance();
        $userId    = (int)$_SESSION['user_id'];
        $workspace = $stats->getActiveWorkspace($userId);
        $to        = $workspace ? $stats->getLatestStatDate((int)$workspace['id']) : null;

        $vars = [
            'title'     => 'Marketing',
            'active'    => 'marketing',
            'workspace' => $workspace,
            'statDate'  => $to,
            'userEmail' => $_SESSION['user_email'] ?? '',
        ];

        if ($workspace && $to) {
            $orgId = (int)$workspace['id'];
            $from  = date('Y-m-d', strtotime($to . ' -29 days'));
            $vars += [
                'cards'      => $stats->getMarketingCards($orgId, $to),
                'byPlatform' => $stats->getMarketingByPlatform($orgId, $from, $to),
                'trend'      => $stats->getMarketingTrend($orgId, $from, $to),
            ];
        }

        return $this->render('marketing', $vars);
    }
}
