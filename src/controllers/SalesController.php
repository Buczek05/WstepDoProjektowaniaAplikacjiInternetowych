<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/StatsRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class SalesController extends AppController {

    #[AllowedMethods(['GET'])]
    public function index()
    {
        $this->requireLogin();

        $stats     = StatsRepository::getInstance();
        $userId    = (int)$_SESSION['user_id'];
        $workspace = $stats->getActiveWorkspace($userId);
        $to        = $workspace ? $stats->getLatestStatDate((int)$workspace['id']) : null;

        $vars = [
            'title'     => 'Sales',
            'active'    => 'sales',
            'workspace' => $workspace,
            'statDate'  => $to,
            'userEmail' => $_SESSION['user_email'] ?? '',
        ];

        if ($workspace && $to) {
            $orgId = (int)$workspace['id'];
            $from  = date('Y-m-d', strtotime($to . ' -29 days'));
            $vars += [
                'totals'        => $stats->getRangeTotals($orgId, $from, $to),
                'salesCategory' => $stats->getSalesByCategory($orgId, $from, $to),
                'salesChannel'  => $stats->getSalesByChannel($orgId, $from, $to),
                'revenueTrend'  => $stats->getRevenueTrend($orgId, $from, $to),
                'recentSales'   => $stats->getRecentSales($orgId, 15),
            ];
        }

        return $this->render('sales', $vars);
    }
}
