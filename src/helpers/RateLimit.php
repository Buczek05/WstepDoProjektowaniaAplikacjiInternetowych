<?php

class RateLimit
{
    private const STORAGE_DIR = '/tmp/wdpai_ratelimit';

    private static function ensureStorage(): void
    {
        if (!is_dir(self::STORAGE_DIR)) {
            @mkdir(self::STORAGE_DIR, 0700, true);
        }
    }

    private static function pathFor(string $key): string
    {
        return self::STORAGE_DIR . '/' . sha1($key);
    }

    /**
     * @return int[] timestamps still within the window
     */
    private static function load(string $key, int $windowSec): array
    {
        $path = self::pathFor($key);
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $now = time();
        $cutoff = $now - $windowSec;

        $valid = [];
        foreach ($data as $ts) {
            if (is_int($ts) && $ts > $cutoff) {
                $valid[] = $ts;
            } elseif (is_numeric($ts) && (int)$ts > $cutoff) {
                $valid[] = (int)$ts;
            }
        }

        return $valid;
    }

    private static function save(string $key, array $timestamps): void
    {
        self::ensureStorage();
        $path = self::pathFor($key);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $encoded = json_encode(array_values($timestamps));
        if ($encoded === false) {
            return;
        }

        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            return;
        }

        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }

    public static function tooMany(string $key, int $max = 5, int $windowSec = 900): bool
    {
        $valid = self::load($key, $windowSec);
        return count($valid) >= $max;
    }

    public static function record(string $key): void
    {
        // Use the default window for trimming on record so the file does not grow unbounded.
        $windowSec = 900;
        $valid = self::load($key, $windowSec);
        $valid[] = time();
        self::save($key, $valid);
    }

    public static function clear(string $key): void
    {
        $path = self::pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
