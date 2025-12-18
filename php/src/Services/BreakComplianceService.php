<?php

declare(strict_types=1);

namespace Attendly\Services;

use DateInterval;
use DateTimeImmutable;

final class BreakComplianceService
{
    public static function requiredBreakMinutes(int $netMinutes): int
    {
        if ($netMinutes > 8 * 60) {
            return 60;
        }
        if ($netMinutes > 6 * 60) {
            return 45;
        }
        return 0;
    }

    public static function breakShortageMinutes(int $netMinutes, int $breakMinutes): int
    {
        $required = self::requiredBreakMinutes($netMinutes);
        return max(0, $required - max(0, $breakMinutes));
    }

    public static function edgeBreakWarningMinutes(): int
    {
        $raw = $_ENV['EDGE_BREAK_WARNING_MINUTES'] ?? 10;
        $minutes = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => [
                'default' => 10,
                'min_range' => 0,
                'max_range' => 120,
            ],
        ]);
        if ($minutes === false) {
            return 10;
        }
        return max(0, (int)$minutes);
    }

    /**
     * @param array<int, array{start_time:DateTimeImmutable,end_time:?DateTimeImmutable}> $breaks
     */
    public static function hasEdgeBreakWarning(
        array $breaks,
        DateTimeImmutable $sessionStart,
        DateTimeImmutable $sessionEnd,
        ?int $edgeMinutes = null
    ): bool {
        $edgeMinutes = $edgeMinutes ?? self::edgeBreakWarningMinutes();
        if ($edgeMinutes <= 0) {
            return false;
        }

        $edgeInterval = new DateInterval('PT' . $edgeMinutes . 'M');
        $startEdge = $sessionStart->add($edgeInterval);
        $endEdge = $sessionEnd->sub($edgeInterval);

        foreach ($breaks as $break) {
            if (!isset($break['start_time']) || !$break['start_time'] instanceof DateTimeImmutable) {
                continue;
            }
            $breakStart = $break['start_time'];
            $breakEnd = isset($break['end_time']) && $break['end_time'] instanceof DateTimeImmutable
                ? $break['end_time']
                : $sessionEnd;

            if ($breakStart <= $startEdge) {
                return true;
            }
            if ($breakEnd >= $endEdge) {
                return true;
            }
        }

        return false;
    }
}

