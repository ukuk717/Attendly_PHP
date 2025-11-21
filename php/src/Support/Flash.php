<?php

declare(strict_types=1);

namespace Attendly\Support;

final class Flash
{
    private const SESSION_KEY = '_flash';

    public static function add(string $type, string $message): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Retrieve and clear flash messages.
     *
     * @return array<int, array{type:string, message:string}>
     */
    public static function consume(): array
    {
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);
        return is_array($messages) ? $messages : [];
    }
}
