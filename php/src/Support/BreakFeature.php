<?php

declare(strict_types=1);

namespace Attendly\Support;

final class BreakFeature
{
    public static function isEnabled(): bool
    {
        $raw = $_ENV['BREAKS_ENABLED'] ?? 'true';
        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            return true;
        }
        return $value;
    }
}
