<?php

declare(strict_types=1);

namespace Attendly\Security;

/**
 * 監査ログに保存する機微情報（MFAスナップショット等）の暗号化/復号ユーティリティ。
 *
 * - 暗号化形式: AES-256-GCM
 * - 保存形式: "enc:" . base64(iv[12] + tag[16] + ciphertext)
 * - 復号時は "enc:" プレフィックスを判定し、暗号化/平文JSONの両方を読み取れる。
 */
final class SensitiveLogPayload
{
    private const PREFIX = 'enc:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /**
     * @param array<string,mixed>|array<int,mixed> $payload
     */
    public static function encrypt(array $payload): string
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL 拡張が利用できません。');
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $key = self::resolveKey();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $json,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );
        if (!is_string($ciphertext) || $ciphertext === '' || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('暗号化に失敗しました。');
        }

        $blob = $iv . $tag . $ciphertext;
        return self::PREFIX . base64_encode($blob);
    }

    /**
     * @template T
     * @param T $default
     * @return T|array<string,mixed>|array<int,mixed>
     */
    public static function read(?string $value, mixed $default): mixed
    {
        $result = self::tryRead($value);
        return $result['ok'] ? ($result['value'] ?? $default) : $default;
    }

    /**
     * @return array{ok:bool,value:?array}
     */
    public static function tryRead(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return ['ok' => true, 'value' => null];
        }
        $raw = (string)$value;

        if (str_starts_with($raw, self::PREFIX)) {
            $b64 = substr($raw, strlen(self::PREFIX));
            $decoded = base64_decode($b64, true);
            if (!is_string($decoded) || strlen($decoded) < (self::IV_BYTES + self::TAG_BYTES + 1)) {
                return ['ok' => false, 'value' => null];
            }
            if (!extension_loaded('openssl')) {
                return ['ok' => false, 'value' => null];
            }
            $iv = substr($decoded, 0, self::IV_BYTES);
            $tag = substr($decoded, self::IV_BYTES, self::TAG_BYTES);
            $ciphertext = substr($decoded, self::IV_BYTES + self::TAG_BYTES);
            try {
                $key = self::resolveKey();
            } catch (\Throwable) {
                return ['ok' => false, 'value' => null];
            }
            $plain = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            if (!is_string($plain) || $plain === '') {
                return ['ok' => false, 'value' => null];
            }
            try {
                $decodedJson = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
                return ['ok' => is_array($decodedJson), 'value' => is_array($decodedJson) ? $decodedJson : null];
            } catch (\Throwable) {
                return ['ok' => false, 'value' => null];
            }
        }

        try {
            $decodedJson = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return ['ok' => is_array($decodedJson), 'value' => is_array($decodedJson) ? $decodedJson : null];
        } catch (\Throwable) {
            return ['ok' => false, 'value' => null];
        }
    }

    /**
     * @return string 32 bytes binary key
     */
    private static function resolveKey(): string
    {
        $configured = trim((string)($_ENV['MFA_RESET_LOG_ENCRYPTION_KEY'] ?? ''));
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'local'));
        $isProduction = $env === 'production' || $env === 'prod';

        if ($configured === '') {
            if ($isProduction) {
                throw new \RuntimeException('MFA_RESET_LOG_ENCRYPTION_KEY が未設定です。');
            }
            $fallback = trim((string)($_ENV['SESSION_SECRET'] ?? ''));
            if ($fallback === '') {
                throw new \RuntimeException('SESSION_SECRET が未設定のため暗号鍵を生成できません。');
            }
            return hash('sha256', 'mfa_reset_logs:' . $fallback, true);
        }

        $keyBytes = null;
        $maybeHex = preg_match('/\A[0-9a-fA-F]+\z/', $configured) === 1 && (strlen($configured) % 2 === 0);
        if ($maybeHex && strlen($configured) >= 64) {
            $bin = hex2bin($configured);
            if (is_string($bin) && strlen($bin) >= 32) {
                $keyBytes = substr($bin, 0, 32);
            }
        }
        if ($keyBytes === null) {
            $bin = base64_decode($configured, true);
            if (is_string($bin) && strlen($bin) >= 32) {
                $keyBytes = substr($bin, 0, 32);
            }
        }
        if ($keyBytes === null) {
            if ($isProduction) {
                throw new \RuntimeException('MFA_RESET_LOG_ENCRYPTION_KEY は32バイト以上のhex/base64で指定してください。');
            }
            $keyBytes = hash('sha256', $configured, true);
        }

        if (!is_string($keyBytes) || strlen($keyBytes) !== 32) {
            throw new \RuntimeException('暗号鍵の生成に失敗しました。');
        }
        return $keyBytes;
    }
}
