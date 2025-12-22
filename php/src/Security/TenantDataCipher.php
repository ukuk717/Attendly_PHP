<?php

declare(strict_types=1);

namespace Attendly\Security;

/**
 * テナント情報の暗号化/復号（AES-256-GCM）。
 *
 * - 保存形式: "enc:" . base64(iv[12] + tag[16] + ciphertext)
 * - 既存の平文値は互換のためそのまま返す。
 */
final class TenantDataCipher
{
    private const PREFIX = 'enc:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function encrypt(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL 拡張が利用できません。');
        }
        $key = self::resolveKey();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $value,
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

    public static function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        if (!str_starts_with($raw, self::PREFIX)) {
            return $raw;
        }
        $b64 = substr($raw, strlen(self::PREFIX));
        $decoded = base64_decode($b64, true);
        if (!is_string($decoded) || strlen($decoded) < (self::IV_BYTES + self::TAG_BYTES + 1)) {
            return null;
        }
        if (!extension_loaded('openssl')) {
            return null;
        }
        $iv = substr($decoded, 0, self::IV_BYTES);
        $tag = substr($decoded, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($decoded, self::IV_BYTES + self::TAG_BYTES);
        try {
            $key = self::resolveKey();
        } catch (\Throwable) {
            return null;
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
            return null;
        }
        return $plain;
    }

    /**
     * @return string 32 bytes binary key
     */
    private static function resolveKey(): string
    {
        $configured = trim((string)($_ENV['TENANT_DATA_ENCRYPTION_KEY'] ?? ''));
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'local'));
        $isProduction = $env === 'production' || $env === 'prod';

        if ($configured === '') {
            if ($isProduction) {
                throw new \RuntimeException('TENANT_DATA_ENCRYPTION_KEY が未設定です。');
            }
            $fallback = trim((string)($_ENV['SESSION_SECRET'] ?? ''));
            if ($fallback === '') {
                throw new \RuntimeException('SESSION_SECRET が未設定のため暗号鍵を生成できません。');
            }
            return hash('sha256', 'tenant_data:' . $fallback, true);
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
                throw new \RuntimeException('TENANT_DATA_ENCRYPTION_KEY は32バイト以上のhex/base64で指定してください。');
            }
            $keyBytes = hash('sha256', $configured, true);
        }

        if (!is_string($keyBytes) || strlen($keyBytes) !== 32) {
            throw new \RuntimeException('暗号鍵の生成に失敗しました。');
        }
        return $keyBytes;
    }
}
