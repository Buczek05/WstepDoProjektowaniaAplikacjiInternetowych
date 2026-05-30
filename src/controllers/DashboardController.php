<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/StatsRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class DashboardController extends AppController {

    #[AllowedMethods(['GET'])]
    public function index()
    {
        $this->requireLogin();

        $stats     = StatsRepository::getInstance();
        $userId    = (int)$_SESSION['user_id'];
        $workspace = $stats->getActiveWorkspace($userId);

        // No workspace yet — render the dashboard with an empty state.
        if (!$workspace) {
            return $this->render('dashboard', [
                'title'     => 'Dashboard',
                'workspace' => null,
                'statDate'  => null,
                'userEmail' => $_SESSION['user_email'] ?? '',
            ]);
        }

        $orgId = (int)$workspace['id'];
        $to    = $stats->getLatestStatDate($orgId);

        if ($to === null) {
            return $this->render('dashboard', [
                'title'     => 'Dashboard',
                'workspace' => $workspace,
                'statDate'  => null,
                'userEmail' => $_SESSION['user_email'] ?? '',
            ]);
        }

        // Trailing window ending on the latest processed day (?days= filter).
        $days = $this->periodDays();
        $from = $this->periodFrom($to, $days);

        return $this->render('dashboard', [
            'title'         => 'Dashboard',
            'active'        => 'dashboard',
            'days'          => $days,
            'workspace'     => $workspace,
            'statDate'      => $to,
            'kpis'          => $stats->getHeadlineKpis($orgId, $to),
            'salesChannel'  => $stats->getSalesByChannel($orgId, $from, $to),
            'salesCategory' => $stats->getSalesByCategory($orgId, $from, $to),
            'revenueTrend'  => $stats->getRevenueTrend($orgId, $from, $to),
            'recentSales'   => $stats->getRecentSales($orgId, 8),
            'userEmail'     => $_SESSION['user_email'] ?? '',
        ]);
    }
}
