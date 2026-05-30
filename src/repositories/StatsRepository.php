<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/RegionStat.php';

/**
 * Reads the already-processed sales statistics (the fact_* tables) for a given
 * organization (workspace). Singleton, mirrors UsersRepository.
 */
class StatsRepository extends Repository {
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Active workspace of a user (users.organization_id) + how many they belong to. */
    public function getActiveWorkspace(int $userId): ?array
    {
        $query = $this->database->prepare(
            "SELECT o.id, o.name, o.plan,
                    (SELECT count(*) FROM organization_members m WHERE m.user_id = u.id) AS workspace_count
             FROM users u
             JOIN organizations o ON o.id = u.organization_id
             WHERE u.id = :uid
             LIMIT 1"
        );
        $query->execute(['uid' => $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Most recent day that has processed facts for this org. */
    public function getLatestStatDate(int $orgId): ?string
    {
        $query = $this->database->prepare(
            "SELECT max(stat_date)::text FROM fact_kpi_daily WHERE organization_id = :org"
        );
        $query->execute(['org' => $orgId]);
        $date = $query->fetchColumn();
        return $date ?: null;
    }

    /**
     * Headline KPI cards aggregated over [from, to], keyed by metric_key, each
     * with metric_value, target_value (summed over the window; rate averaged),
     * delta_pct vs the previous equal-length window, and unit.
     */
    public function getHeadlineKpisRange(int $orgId, string $from, string $to): array
    {
        $len      = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
        $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($len - 1) . ' day'));

        $agg = $this->database->prepare(
            "SELECT COALESCE(SUM(gross_revenue),0) AS rev, COALESCE(SUM(orders_count),0) AS ord
             FROM fact_sales_daily WHERE organization_id = :org AND stat_date BETWEEN :f AND :t");
        $ses = $this->database->prepare(
            "SELECT COALESCE(SUM(sessions),0) FROM traffic_daily WHERE organization_id = :org AND stat_date BETWEEN :f AND :t");

        $agg->execute(['org' => $orgId, 'f' => $from, 't' => $to]);          $cur     = $agg->fetch(PDO::FETCH_ASSOC);
        $agg->execute(['org' => $orgId, 'f' => $prevFrom, 't' => $prevTo]);  $prev    = $agg->fetch(PDO::FETCH_ASSOC);
        $ses->execute(['org' => $orgId, 'f' => $from, 't' => $to]);          $sesCur  = (int)$ses->fetchColumn();
        $ses->execute(['org' => $orgId, 'f' => $prevFrom, 't' => $prevTo]);  $sesPrev = (int)$ses->fetchColumn();

        $convCur  = $sesCur  > 0 ? 100 * (float)$cur['ord']  / $sesCur  : 0.0;
        $convPrev = $sesPrev > 0 ? 100 * (float)$prev['ord'] / $sesPrev : 0.0;

        $tq = $this->database->prepare(
            "SELECT metric_key, SUM(target_value) AS s, AVG(target_value) AS a
             FROM metric_targets WHERE organization_id = :org AND period = 'daily' AND period_start BETWEEN :f AND :t
             GROUP BY metric_key");
        $tq->execute(['org' => $orgId, 'f' => $from, 't' => $to]);
        $tg = [];
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $r) { $tg[$r['metric_key']] = $r; }

        $delta = fn($c, $p) => $p > 0 ? round(100 * ($c - $p) / $p, 1) : null;

        return [
            'total_revenue' => [
                'metric_value' => (float)$cur['rev'],
                'target_value' => isset($tg['total_revenue']) ? (float)$tg['total_revenue']['s'] : null,
                'delta_pct'    => $delta((float)$cur['rev'], (float)$prev['rev']),
                'unit'         => 'currency',
            ],
            'total_orders' => [
                'metric_value' => (float)$cur['ord'],
                'target_value' => isset($tg['total_orders']) ? (float)$tg['total_orders']['s'] : null,
                'delta_pct'    => $delta((float)$cur['ord'], (float)$prev['ord']),
                'unit'         => 'count',
            ],
            'conversion_rate' => [
                'metric_value' => round($convCur, 2),
                'target_value' => isset($tg['conversion_rate']) ? round((float)$tg['conversion_rate']['a'], 2) : null,
                'delta_pct'    => $convPrev > 0 ? round($convCur - $convPrev, 2) : null,
                'unit'         => 'percent',
            ],
        ];
    }

    /** Headline KPI cards for one day, keyed by metric_key (total_revenue, ...). */
    public function getHeadlineKpis(int $orgId, string $date): array
    {
        $query = $this->database->prepare(
            "SELECT metric_key, metric_value, target_value, prev_value, delta_pct, unit
             FROM fact_kpi_daily
             WHERE organization_id = :org AND stat_date = :d AND scope = 'overall'"
        );
        $query->execute(['org' => $orgId, 'd' => $date]);

        $kpis = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kpis[$row['metric_key']] = $row;
        }
        return $kpis;
    }

    /** Sales grouped by channel over [from, to], optionally limited to one channel. */
    public function getSalesByChannel(int $orgId, string $from, string $to, ?string $channelCode = null): array
    {
        $cond = ''; $p = ['org' => $orgId, 'from' => $from, 'to' => $to];
        if ($channelCode) { $cond = ' AND ch.code = :code'; $p['code'] = $channelCode; }
        $query = $this->database->prepare(
            "SELECT ch.name, SUM(f.gross_revenue) AS revenue, SUM(f.orders_count) AS orders
             FROM fact_sales_daily f
             JOIN channels ch ON ch.id = f.channel_id
             WHERE f.organization_id = :org AND f.stat_date BETWEEN :from AND :to $cond
             GROUP BY ch.name
             ORDER BY revenue DESC"
        );
        $query->execute($p);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Sales grouped by category over [from, to], optionally limited to one channel. */
    public function getSalesByCategory(int $orgId, string $from, string $to, ?string $channelCode = null): array
    {
        // fact_category_daily has no channel dimension, so when a channel filter
        // is applied we aggregate from the raw orders/items instead.
        if ($channelCode) {
            $query = $this->database->prepare(
                "SELECT cat.name, SUM(oi.line_total) AS revenue
                 FROM orders o
                 JOIN order_items oi ON oi.order_id = o.id
                 JOIN categories cat ON cat.id = oi.category_id
                 JOIN channels ch ON ch.id = o.channel_id
                 WHERE o.organization_id = :org AND o.ordered_at::date BETWEEN :from AND :to
                   AND o.status NOT IN ('cancelled','refunded') AND ch.code = :code
                 GROUP BY cat.name ORDER BY revenue DESC"
            );
            $query->execute(['org' => $orgId, 'from' => $from, 'to' => $to, 'code' => $channelCode]);
            return $query->fetchAll(PDO::FETCH_ASSOC);
        }

        $query = $this->database->prepare(
            "SELECT c.name, SUM(f.gross_revenue) AS revenue
             FROM fact_category_daily f
             JOIN categories c ON c.id = f.category_id
             WHERE f.organization_id = :org AND f.stat_date BETWEEN :from AND :to
             GROUP BY c.name
             ORDER BY revenue DESC"
        );
        $query->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Daily revenue series for the trend chart, optionally limited to one channel. */
    public function getRevenueTrend(int $orgId, string $from, string $to, ?string $channelCode = null): array
    {
        if ($channelCode) {
            $query = $this->database->prepare(
                "SELECT f.stat_date::text AS day, SUM(f.gross_revenue) AS revenue
                 FROM fact_sales_daily f JOIN channels ch ON ch.id = f.channel_id
                 WHERE f.organization_id = :org AND f.stat_date BETWEEN :from AND :to AND ch.code = :code
                 GROUP BY f.stat_date ORDER BY f.stat_date"
            );
            $query->execute(['org' => $orgId, 'from' => $from, 'to' => $to, 'code' => $channelCode]);
            return $query->fetchAll(PDO::FETCH_ASSOC);
        }

        $query = $this->database->prepare(
            "SELECT stat_date::text AS day, metric_value AS revenue
             FROM fact_kpi_daily
             WHERE organization_id = :org AND scope = 'overall' AND metric_key = 'total_revenue'
               AND stat_date BETWEEN :from AND :to
             ORDER BY stat_date"
        );
        $query->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Channels for a tenant (for the channel filter dropdown). */
    public function getChannels(int $orgId): array
    {
        $q = $this->database->prepare(
            "SELECT id, name, code FROM channels WHERE organization_id = :org ORDER BY name"
        );
        $q->execute(['org' => $orgId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Latest individual sales, optionally by channel code and date window. */
    public function getRecentSales(int $orgId, int $limit = 8, ?string $channelCode = null, ?string $from = null, ?string $to = null): array
    {
        $limit  = max(1, min(50, $limit)); // clamp; inlined (PDO can't bind LIMIT well)
        $params = ['org' => $orgId];
        $where  = 'o.organization_id = :org';
        if ($channelCode !== null && $channelCode !== '') {
            $where .= ' AND ch.code = :code';
            $params['code'] = $channelCode;
        }
        if ($from !== null && $to !== null) {
            $where .= ' AND o.ordered_at::date BETWEEN :from AND :to';
            $params['from'] = $from;
            $params['to']   = $to;
        }
        $query = $this->database->prepare(
            "SELECT o.order_code, cu.full_name AS customer, ch.name AS channel,
                    o.total_amount, o.status, o.ordered_at::text AS ordered_at
             FROM orders o
             LEFT JOIN customers cu ON cu.id = o.customer_id
             JOIN channels ch ON ch.id = o.channel_id
             WHERE $where
             ORDER BY o.ordered_at DESC
             LIMIT $limit"
        );
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ===================== SALES PAGE ===================== */

    /** Aggregate totals over a window, optionally limited to one channel. */
    public function getRangeTotals(int $orgId, string $from, string $to, ?string $channelCode = null): array
    {
        $cond = ''; $p = ['org' => $orgId, 'from' => $from, 'to' => $to];
        if ($channelCode) { $cond = ' AND ch.code = :code'; $p['code'] = $channelCode; }

        $q = $this->database->prepare(
            "SELECT COALESCE(SUM(f.gross_revenue),0) AS revenue,
                    COALESCE(SUM(f.orders_count),0) AS orders,
                    COALESCE(SUM(f.units_sold),0)   AS units
             FROM fact_sales_daily f JOIN channels ch ON ch.id = f.channel_id
             WHERE f.organization_id = :org AND f.stat_date BETWEEN :from AND :to $cond"
        );
        $q->execute($p);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'orders' => 0, 'units' => 0];

        $s = $this->database->prepare(
            "SELECT COALESCE(SUM(t.sessions),0) FROM traffic_daily t JOIN channels ch ON ch.id = t.channel_id
             WHERE t.organization_id = :org AND t.stat_date BETWEEN :from AND :to $cond"
        );
        $s->execute($p);
        $sessions = (int)$s->fetchColumn();

        $row['conversion'] = $sessions > 0 ? round(100 * (float)$row['orders'] / $sessions, 2) : 0.0;
        return $row;
    }

    /* ===================== MARKETING PAGE ===================== */

    /** Per-platform marketing cards for one day (from the ad-spend source feed). */
    public function getMarketingCards(int $orgId, string $date): array
    {
        $q = $this->database->prepare(
            "SELECT platform, spend, budget, conversions, attributed_revenue,
                    CASE WHEN spend > 0 THEN round(attributed_revenue / spend, 2) END AS roi,
                    CASE WHEN budget > 0 THEN round(100 * spend / budget) END AS budget_util
             FROM ad_spend_daily
             WHERE organization_id = :org AND stat_date = :d
             ORDER BY platform"
        );
        $q->execute(['org' => $orgId, 'd' => $date]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Per-platform marketing cards aggregated over [from, to]. */
    public function getMarketingCardsRange(int $orgId, string $from, string $to): array
    {
        $q = $this->database->prepare(
            "SELECT platform,
                    SUM(spend)              AS spend,
                    SUM(budget)             AS budget,
                    SUM(conversions)        AS conversions,
                    SUM(attributed_revenue) AS attributed_revenue,
                    CASE WHEN SUM(spend)  > 0 THEN round(SUM(attributed_revenue) / SUM(spend), 2) END AS roi,
                    CASE WHEN SUM(budget) > 0 THEN round(100 * SUM(spend) / SUM(budget))         END AS budget_util
             FROM ad_spend_daily
             WHERE organization_id = :org AND stat_date BETWEEN :from AND :to
             GROUP BY platform ORDER BY platform"
        );
        $q->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Per-platform totals over a window (spend, revenue, roi, conversions). */
    public function getMarketingByPlatform(int $orgId, string $from, string $to): array
    {
        $q = $this->database->prepare(
            "SELECT platform,
                    SUM(ad_spend)           AS spend,
                    SUM(attributed_revenue) AS revenue,
                    SUM(conversions)        AS conversions,
                    CASE WHEN SUM(ad_spend) > 0 THEN round(SUM(attributed_revenue)/SUM(ad_spend),2) END AS roi
             FROM fact_marketing_daily
             WHERE organization_id = :org AND stat_date BETWEEN :from AND :to
             GROUP BY platform ORDER BY revenue DESC"
        );
        $q->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Daily aggregated ROI for the trend chart. */
    public function getMarketingTrend(int $orgId, string $from, string $to): array
    {
        $q = $this->database->prepare(
            "SELECT stat_date::text AS day,
                    CASE WHEN SUM(ad_spend) > 0 THEN round(SUM(attributed_revenue)/SUM(ad_spend),2) ELSE 0 END AS roi
             FROM fact_marketing_daily
             WHERE organization_id = :org AND stat_date BETWEEN :from AND :to
             GROUP BY stat_date ORDER BY stat_date"
        );
        $q->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ===================== GLOBAL PAGE ===================== */

    /** Aggregate cross-region summary over a window. */
    public function getGlobalSummary(int $orgId, string $from, string $to): array
    {
        $q = $this->database->prepare(
            "SELECT COALESCE(SUM(gross_revenue),0) AS revenue,
                    COALESCE(SUM(orders_count),0)  AS orders,
                    COUNT(DISTINCT country_id)     AS countries
             FROM fact_sales_daily
             WHERE organization_id = :org AND stat_date BETWEEN :from AND :to AND country_id IS NOT NULL"
        );
        $q->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'orders' => 0, 'countries' => 0];
    }

    /**
     * Per-country cards: revenue, MoM% vs the previous equal-length window,
     * top channel + share, top category, derived status.
     */
    public function getRegions(int $orgId, string $from, string $to): array
    {
        // previous window of equal length, immediately before [from, to]
        $len      = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
        $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($len - 1) . ' day'));

        // current revenue/orders per country
        $cur = $this->database->prepare(
            "SELECT co.id, co.name, co.region_cluster,
                    SUM(f.gross_revenue) AS revenue, SUM(f.orders_count) AS orders
             FROM fact_sales_daily f JOIN countries co ON co.id = f.country_id
             WHERE f.organization_id = :org AND f.stat_date BETWEEN :from AND :to
             GROUP BY co.id, co.name, co.region_cluster"
        );
        $cur->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        $regions = [];
        foreach ($cur->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $regions[(int)$r['id']] = $r + ['mom_pct' => null, 'top_channel' => null, 'top_channel_share' => null, 'top_category' => null];
        }

        // previous revenue per country -> MoM
        $prev = $this->database->prepare(
            "SELECT country_id, SUM(gross_revenue) AS revenue FROM fact_sales_daily
             WHERE organization_id = :org AND stat_date BETWEEN :from AND :to AND country_id IS NOT NULL
             GROUP BY country_id"
        );
        $prev->execute(['org' => $orgId, 'from' => $prevFrom, 'to' => $prevTo]);
        foreach ($prev->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['country_id'];
            if (isset($regions[$id]) && (float)$r['revenue'] > 0) {
                $regions[$id]['mom_pct'] = round(100 * ((float)$regions[$id]['revenue'] - (float)$r['revenue']) / (float)$r['revenue'], 1);
            }
        }

        // top channel + share per country
        $ch = $this->database->prepare(
            "SELECT f.country_id, c.name, SUM(f.gross_revenue) AS revenue
             FROM fact_sales_daily f JOIN channels c ON c.id = f.channel_id
             WHERE f.organization_id = :org AND f.stat_date BETWEEN :from AND :to AND f.country_id IS NOT NULL
             GROUP BY f.country_id, c.name"
        );
        $ch->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        $byChannel = [];
        foreach ($ch->fetchAll(PDO::FETCH_ASSOC) as $r) { $byChannel[(int)$r['country_id']][] = $r; }
        foreach ($byChannel as $cid => $rows) {
            if (!isset($regions[$cid])) continue;
            usort($rows, fn($a, $b) => (float)$b['revenue'] <=> (float)$a['revenue']);
            $total = array_sum(array_map(fn($x) => (float)$x['revenue'], $rows));
            $regions[$cid]['top_channel']       = $rows[0]['name'];
            $regions[$cid]['top_channel_share'] = $total > 0 ? round(100 * (float)$rows[0]['revenue'] / $total) : 0;
        }

        // top category per country (from the raw orders/items, since facts don't join the two)
        $cat = $this->database->prepare(
            "SELECT o.country_id, cat.name, SUM(oi.line_total) AS revenue
             FROM orders o JOIN order_items oi ON oi.order_id = o.id JOIN categories cat ON cat.id = oi.category_id
             WHERE o.organization_id = :org AND o.ordered_at::date BETWEEN :from AND :to
               AND o.status NOT IN ('cancelled','refunded') AND o.country_id IS NOT NULL
             GROUP BY o.country_id, cat.name"
        );
        $cat->execute(['org' => $orgId, 'from' => $from, 'to' => $to]);
        $byCat = [];
        foreach ($cat->fetchAll(PDO::FETCH_ASSOC) as $r) { $byCat[(int)$r['country_id']][] = $r; }
        foreach ($byCat as $cid => $rows) {
            if (!isset($regions[$cid])) continue;
            usort($rows, fn($a, $b) => (float)$b['revenue'] <=> (float)$a['revenue']);
            $regions[$cid]['top_category'] = $rows[0]['name'];
        }

        // derive a status, sort by revenue desc
        foreach ($regions as &$r) {
            $r['status'] = ($r['mom_pct'] !== null && $r['mom_pct'] < 0) ? 'needs_attention' : 'performing';
        }
        unset($r);
        usort($regions, fn($a, $b) => (float)$b['revenue'] <=> (float)$a['revenue']);

        // Map rows -> RegionStat DTOs (revenue wrapped in the Money value object).
        return array_map(fn(array $r) => RegionStat::fromRow($r), $regions);
    }

    /* ===================== SETTINGS PAGE ===================== */

    /** All workspaces a user belongs to, with their role. */
    public function getMemberships(int $userId): array
    {
        $q = $this->database->prepare(
            "SELECT o.id, o.name, o.plan, m.role
             FROM organization_members m JOIN organizations o ON o.id = m.organization_id
             WHERE m.user_id = :uid ORDER BY o.id"
        );
        $q->execute(['uid' => $userId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Switch the user's active workspace — only succeeds if they are actually a
     * member of the target organization (prevents switching into a foreign org).
     */
    public function switchWorkspace(int $userId, int $orgId): bool
    {
        $q = $this->database->prepare(
            "UPDATE users SET organization_id = :org, updated_at = now()
             WHERE id = :uid AND EXISTS (
                SELECT 1 FROM organization_members m
                WHERE m.user_id = :uid AND m.organization_id = :org)"
        );
        $q->execute(['org' => $orgId, 'uid' => $userId]);
        return $q->rowCount() > 0;
    }
}
