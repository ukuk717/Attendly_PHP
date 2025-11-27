<?php

declare(strict_types=1);

namespace Attendly\Support;

/**
 * 軽量レートリミッター。
 * - driver: apcu / file / memory。環境変数 RATE_LIMIT_DRIVER で指定、未指定なら APCu 優先でフォールバック。
 * - file ドライバは storage/ratelimiter.json（既定）を単一ファイルでロックしながら更新する。
 * - memory ドライバは単一プロセスのみ有効。PHP-FPM マルチワーカーでは共有されないため、本番は apcu/file を推奨。
 */
final class RateLimiter
{
    /** @var array<string, array{window:int,count:int,ttl:int}> */
    private static array $memoryStore = [];
    private static ?string $driver = null;

    public static function allow(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $driver = self::detectDriver();
        if ($driver === 'apcu') {
            return self::allowApcu($key, $maxAttempts, $windowSeconds);
        }
        if ($driver === 'file') {
            $result = self::allowFile($key, $maxAttempts, $windowSeconds);
            if ($result !== null) {
                return $result;
            }
        }
        return self::allowMemory($key, $maxAttempts, $windowSeconds);
    }

    private static function detectDriver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }
        $env = strtolower((string)($_ENV['RATE_LIMIT_DRIVER'] ?? 'auto'));
        if ($env === 'apcu' && self::isApcuAvailable()) {
            self::$driver = 'apcu';
            return self::$driver;
        }
        if ($env === 'file') {
            self::$driver = 'file';
            return self::$driver;
        }
        if ($env === 'memory') {
            self::$driver = 'memory';
            return self::$driver;
        }
        self::$driver = self::isApcuAvailable() ? 'apcu' : 'file';
        return self::$driver;
    }

    private static function isApcuAvailable(): bool
    {
        return function_exists('apcu_fetch') && (
            filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN) ||
            (PHP_SAPI === 'cli' && filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN))
        );
    }

    private static function allowApcu(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $now = time();
        $cacheKey = "ratelimit:{$key}";
        for ($i = 0; $i < 5; $i++) {
            $state = apcu_fetch($cacheKey, $success);
            if (!$success || !is_array($state) || !isset($state['window'], $state['count'])) {
                $newState = ['window' => $now, 'count' => 1];
                if (apcu_add($cacheKey, $newState, $windowSeconds)) {
                    return true;
                }
                continue;
            }

            $windowStart = (int)$state['window'];
            $count = (int)$state['count'];
            if (($now - $windowStart) >= $windowSeconds) {
                $newState = ['window' => $now, 'count' => 1];
            } else {
                if ($count >= $maxAttempts) {
                    return false;
                }
                $newState = ['window' => $windowStart, 'count' => $count + 1];
            }

            if (apcu_cas($cacheKey, $state, $newState)) {
                return true;
            }
        }
        // CAS リトライ枯渇時はメモリドライバへフォールバックし、意図しない拒否を避ける
        return self::allowMemory($key, $maxAttempts, $windowSeconds);
    }

    private static function allowFile(string $key, int $maxAttempts, int $windowSeconds): ?bool
    {
        $path = self::getFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                return null;
            }
            $raw = stream_get_contents($handle);
            $store = [];
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $store = $decoded;
                }
            }
            $now = time();
            $limit = self::getMaxKeys();
            // expire stale entries
            foreach ($store as $storedKey => $state) {
                if (!is_array($state) || !isset($state['window'], $state['ttl'])) {
                    unset($store[$storedKey]);
                    continue;
                }
                if (($now - (int)$state['window']) >= (int)$state['ttl']) {
                    unset($store[$storedKey]);
                }
            }

            if (isset($store[$key])) {
                $state = $store[$key];
                $windowStart = (int)($state['window'] ?? $now);
                $count = (int)($state['count'] ?? 0);
                if (($now - $windowStart) >= $windowSeconds) {
                    $store[$key] = ['window' => $now, 'count' => 1, 'ttl' => $windowSeconds];
                } else {
                    if ($count >= $maxAttempts) {
                        flock($handle, LOCK_UN);
                        fclose($handle);
                        return false;
                    }
                    $store[$key] = ['window' => $windowStart, 'count' => $count + 1, 'ttl' => $windowSeconds];
                }
            } else {
                $store[$key] = ['window' => $now, 'count' => 1, 'ttl' => $windowSeconds];
            }

            if (count($store) > $limit) {
                uasort($store, static fn(array $a, array $b): int => ($a['window'] ?? 0) <=> ($b['window'] ?? 0));
                $store = array_slice($store, -$limit, null, true);
            }

            rewind($handle);
            ftruncate($handle, 0);
            $encoded = json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                flock($handle, LOCK_UN);
                fclose($handle);
                return null;
            }
            fwrite($handle, $encoded);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            return true;
        } catch (\Throwable) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return null;
        }
    }

    private static function allowMemory(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $now = time();
        if (!isset(self::$memoryStore[$key])) {
            self::$memoryStore[$key] = ['window' => $now, 'count' => 1, 'ttl' => $windowSeconds];
            return true;
        }
        $state = self::$memoryStore[$key];
        $windowStart = (int)($state['window'] ?? $now);
        $count = (int)($state['count'] ?? 0);
        if (($now - $windowStart) >= $windowSeconds) {
            self::$memoryStore[$key] = ['window' => $now, 'count' => 1, 'ttl' => $windowSeconds];
            return true;
        }
        if ($count >= $maxAttempts) {
            return false;
        }
        self::$memoryStore[$key] = ['window' => $windowStart, 'count' => $count + 1, 'ttl' => $windowSeconds];
        return true;
    }

    private static function getFilePath(): string
    {
        $custom = trim((string)($_ENV['RATE_LIMIT_FILE_PATH'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        return dirname(__DIR__, 2) . '/storage/ratelimiter.json';
    }

    private static function getMaxKeys(): int
    {
        $value = (int)($_ENV['RATE_LIMIT_MAX_KEYS'] ?? 5000);
        return max(100, min(20000, $value));
    }
}
