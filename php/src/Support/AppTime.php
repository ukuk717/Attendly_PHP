<?php

declare(strict_types=1);

namespace Attendly\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * アプリ内で利用するタイムゾーンと日時の統一ヘルパー。
 * DB 保存・表示はすべて APP_TIMEZONE（デフォルト: Asia/Tokyo）に揃える。
 */
final class AppTime
{
    private const DEFAULT_TIMEZONE = 'Asia/Tokyo';

    private static ?DateTimeZone $cachedTz = null;

    public static function timezone(): DateTimeZone
    {
        if (self::$cachedTz instanceof DateTimeZone) {
            return self::$cachedTz;
        }
        $tzName = trim((string)($_ENV['APP_TIMEZONE'] ?? self::DEFAULT_TIMEZONE));
        try {
            self::$cachedTz = new DateTimeZone($tzName !== '' ? $tzName : self::DEFAULT_TIMEZONE);
        } catch (\Throwable) {
            self::$cachedTz = new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
        return self::$cachedTz;
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    public static function parseDate(string $value): ?DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::timezone());
        return $dt ?: null;
    }

    public static function parseDateTime(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, self::timezone());
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatForStorage(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::timezone())->format('Y-m-d H:i:s.v');
    }

    public static function formatDateOnly(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::timezone())->format('Y-m-d');
    }

    public static function fromStorage(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, self::timezone());
        } catch (\Throwable) {
            return null;
        }
    }
}
