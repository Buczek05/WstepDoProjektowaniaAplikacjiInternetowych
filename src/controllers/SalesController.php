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
            $days  = $this->periodDays();
            $from  = $this->periodFrom($to, $days);

            // Optional channel filter (?channel=code) for the Recent Sales table.
            $channels = $stats->getChannels($orgId);
            $valid    = array_column($channels, 'code');
            $channel  = $_GET['channel'] ?? '';
            if (!in_array($channel, $valid, true)) {
                $channel = '';
            }

            $ch = $channel !== '' ? $channel : null;
            $vars += [
                'days'          => $days,
                'channels'      => $channels,
                'channel'       => $channel,
                'totals'        => $stats->getRangeTotals($orgId, $from, $to, $ch),
                'salesCategory' => $stats->getSalesByCategory($orgId, $from, $to, $ch),
                'salesChannel'  => $stats->getSalesByChannel($orgId, $from, $to, $ch),
                'revenueTrend'  => $stats->getRevenueTrend($orgId, $from, $to, $ch),
                'recentSales'   => $stats->getRecentSales($orgId, 15, $ch, $from, $to),
            ];
        }

        return $this->render('sales', $vars);
    }
}
