<?php

declare(strict_types=1);

namespace Attendly\Support;

/**
 * Lightweight rate limiter.
 * - APCu を利用する場合はウィンドウ開始時刻とカウンタを CAS で更新し、リセットのレースを抑制。
 * - APCu が使えない場合は単一プロセスのみ有効な静的ストアにフォールバック。
 * - 高並列/複数ワーカー環境で厳密な制限が必要な場合は Redis/Memcached などの共有ストアを使用してください。
 */
final class RateLimiter
{
    /**
     * @var array<string, array<int>>
     */
    private static array $store = [];

    public static function allow(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $now = time();
        $apcuEnabled = function_exists('apcu_fetch') && (
            filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN) ||
            (PHP_SAPI === 'cli' && filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN))
        );

        if ($apcuEnabled) {
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
                if (($now - $windowStart) > $windowSeconds) {
                    $newState = ['window' => $now, 'count' => 1];
                } else {
                    if ($count >= $maxAttempts) {
                        return false;
                    }
                    $newState = ['window' => $windowStart, 'count' => $count + 1];
                }

                if (apcu_cas($cacheKey, $state, $newState)) {
                    apcu_store($cacheKey, $newState, $windowSeconds);
                    return true;
                }
            }
            // CAS が衝突し続けた場合は保守的に拒否
            return false;
        }

        if (!isset(self::$store[$key])) {
            self::$store[$key] = [];
        }
        self::$store[$key] = array_filter(
            self::$store[$key],
            static fn(int $ts): bool => ($now - $ts) <= $windowSeconds
        );

        if (count(self::$store[$key]) >= $maxAttempts) {
            return false;
        }

        self::$store[$key][] = $now;
        return true;
    }
}
