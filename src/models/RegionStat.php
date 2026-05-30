<?php

require_once __DIR__ . '/Money.php';

/**
 * DTO — a plain read-model that carries processed per-country statistics from
 * the repository to the Global view. No identity, no persistence logic; just
 * moves data between layers. Revenue is wrapped in the Money value object.
 */
final class RegionStat {
    public function __construct(
        public readonly string $countryName,
        public readonly ?string $regionCluster,
        public readonly Money $revenue,
        public readonly int $orders,
        public readonly ?float $momPct,
        public readonly ?string $topChannel,
        public readonly ?int $topChannelShare,
        public readonly ?string $topCategory,
        public readonly string $status,
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            (string)$r['name'],
            $r['region_cluster'] ?? null,
            new Money((float)$r['revenue']),
            (int)$r['orders'],
            isset($r['mom_pct']) && $r['mom_pct'] !== null ? (float)$r['mom_pct'] : null,
            $r['top_channel'] ?? null,
            isset($r['top_channel_share']) && $r['top_channel_share'] !== null ? (int)$r['top_channel_share'] : null,
            $r['top_category'] ?? null,
            (string)($r['status'] ?? 'performing'),
        );
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->status));
    }
}
