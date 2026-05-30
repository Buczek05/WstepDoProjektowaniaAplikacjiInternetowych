<?php

/**
 * Plan — value object describing what a workspace's subscription unlocks.
 * Two tiers only: Free and Pro. Legacy labels (e.g. "Premium Workspace",
 * "Pro Workspace") normalize to 'pro'; everything else to 'free'.
 */
final class Plan {
    public const FREE = 'free';
    public const PRO  = 'pro';

    public static function normalize(?string $raw): string
    {
        $r = strtolower(trim((string)$raw));
        return (str_contains($r, 'pro') || str_contains($r, 'premium')) ? self::PRO : self::FREE;
    }

    public static function isPro(?string $plan): bool
    {
        return self::normalize($plan) === self::PRO;
    }

    /** Human label used across the UI. */
    public static function label(?string $plan): string
    {
        return self::isPro($plan) ? 'Pro' : 'Free';
    }

    /** Stored/display value used when writing the plan back. */
    public static function storeValue(?string $plan): string
    {
        return self::isPro($plan) ? 'Pro' : 'Free';
    }

    /** Reporting periods the plan may use. */
    public static function allowedPeriods(?string $plan): array
    {
        return self::isPro($plan) ? [7, 30, 90, 365] : [7, 30];
    }

    /** Clamp a requested period to one the plan allows. */
    public static function clampDays(?string $plan, int $days): int
    {
        $allowed = self::allowedPeriods($plan);
        return in_array($days, $allowed, true) ? $days : 30;
    }

    /** Max members a company on this plan may have (Free is capped). */
    public static function maxMembers(?string $plan): int
    {
        return self::isPro($plan) ? PHP_INT_MAX : 3;
    }

    public static function hasMarketing(?string $plan): bool { return self::isPro($plan); }
    public static function hasGlobal(?string $plan): bool    { return self::isPro($plan); }

    /** Whether a named feature is available. Used for page gating. */
    public static function allows(?string $plan, string $feature): bool
    {
        return match ($feature) {
            'marketing', 'global' => self::isPro($plan),
            default               => true,
        };
    }
}
