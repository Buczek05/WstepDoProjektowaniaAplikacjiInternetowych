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
            $days  = Plan::clampDays($workspace['plan'], $this->periodDays());
            $from  = $this->periodFrom($to, $days);
            $vars['allowedPeriods'] = Plan::allowedPeriods($workspace['plan']);

            // Optional channel (?channel=code) and country (?country=id) filters
            // — both scope the whole page and combine.
            $channels = $stats->getChannels($orgId);
            $channel  = in_array($_GET['channel'] ?? '', array_column($channels, 'code'), true) ? $_GET['channel'] : '';

            $countries = $stats->getCountriesForOrg($orgId);
            $countryId = (int)($_GET['country'] ?? 0);
            if (!in_array($countryId, array_map('intval', array_column($countries, 'id')), true)) {
                $countryId = 0;
            }

            $ch  = $channel !== '' ? $channel : null;
            $cty = $countryId ?: null;
            $vars += [
                'days'          => $days,
                'channels'      => $channels,
                'channel'       => $channel,
                'countries'     => $countries,
                'country'       => $countryId,
                'totals'        => $stats->getRangeTotals($orgId, $from, $to, $ch, $cty),
                'salesCategory' => $stats->getSalesByCategory($orgId, $from, $to, $ch, $cty),
                'salesChannel'  => $stats->getSalesByChannel($orgId, $from, $to, $ch, $cty),
                'revenueTrend'  => $stats->getRevenueTrend($orgId, $from, $to, $ch, $cty),
                'recentSales'   => $stats->getRecentSales($orgId, 15, $ch, $from, $to, $cty),
            ];
        }

        return $this->render('sales', $vars);
    }
}
