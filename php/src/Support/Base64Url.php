<?php

declare(strict_types=1);

namespace Attendly\Support;

final class Base64Url
{
    public static function encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $base64 = strtr($value, '-_', '+/');
        $pad = strlen($base64) % 4;
        if ($pad !== 0) {
            $base64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($base64, true);
        return $decoded === false ? null : $decoded;
    }
}
