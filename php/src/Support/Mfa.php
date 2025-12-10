<?php

declare(strict_types=1);

namespace Attendly\Support;

/**
 * TOTPおよびリカバリコードのユーティリティ。
 */
final class Mfa
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateTotpSecret(int $bytes = 20): string
    {
        $bytes = max(10, min(64, $bytes));
        $random = random_bytes($bytes);
        return self::base32Encode($random);
    }

    public static function buildTotpUri(string $secretBase32, string $label, string $issuer = 'Attendly'): string
    {
        if ($secretBase32 === '') {
            return '';
        }
        $safeLabel = rawurlencode(trim($label) !== '' ? trim($label) : 'user');
        $safeIssuer = rawurlencode($issuer !== '' ? $issuer : 'Attendly');
        return sprintf('otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30', $safeLabel, $secretBase32, $safeIssuer);
    }

    public static function verifyTotp(string $secretBase32, string $token, int $digits = 6, int $period = 30, int $window = 1): bool
    {
        $token = preg_replace('/[^0-9]/', '', $token) ?? '';
        if ($token === '' || strlen($token) !== $digits) {
            return false;
        }
        $secret = self::base32Decode($secretBase32);
        if ($secret === '') {
            return false;
        }
        $secretHash = hash('sha256', $secret);
        $period = max(1, $period);
        $window = max(0, $window);
        $timeStep = (int)floor(time() / $period);
        $mod = 10 ** $digits;
        $replayTtl = max($period, $period * ($window + 1));
        for ($i = -$window; $i <= $window; $i++) {
            $counter = $timeStep + $i;
            if ($counter < 0) {
                continue;
            }
            $code = self::hotp($secret, $counter, $digits, $mod);
            if (hash_equals($code, $token)) {
                $replayKey = "mfa_totp_replay:{$secretHash}:{$counter}";
                if (!RateLimiter::allow($replayKey, 1, $replayTtl)) {
                    return false;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{code_hash:string,input:string}
     */
    public static function hashRecoveryCode(string $value): array
    {
        $normalized = self::normalizeRecoveryCode($value);
        return [
            'input' => $normalized,
            'code_hash' => hash('sha256', $normalized),
        ];
    }

    public static function normalizeRecoveryCode(string $value): string
    {
        return strtoupper(trim(preg_replace('/[^0-9A-Z]/', '', $value) ?? ''));
    }

    /**
     * @return string[]
     */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        $count = max(1, min(20, $count));
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::generateRecoveryCode();
        }
        return $codes;
    }

    private static function generateRecoveryCode(int $length = 10): string
    {
        $length = max(8, min(12, $length));
        $chars = '2346789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $bytes = random_bytes($length * 2);
        $result = '';
        $max = strlen($chars);
        foreach (str_split($bytes) as $byte) {
            if (strlen($result) >= $length) {
                break;
            }
            $index = ord($byte) % $max;
            $result .= $chars[$index];
        }
        $result = strtoupper(substr($result, 0, $length));
        return substr($result, 0, 5) . '-' . substr($result, 5);
    }

    private static function hotp(string $secret, int $counter, int $digits, int $mod): string
    {
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hmac = hash_hmac('sha1', $binCounter, $secret, true);
        $offset = ord($hmac[19]) & 0x0F;
        $binary =
            ((ord($hmac[$offset]) & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
            (ord($hmac[$offset + 3]) & 0xFF);
        $otp = $binary % $mod;
        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $value): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        if ($clean === '') {
            return '';
        }
        $buffer = 0;
        $bits = 0;
        $output = '';
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $char = $clean[$i];
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                return '';
            }
            $buffer = ($buffer << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $output;
    }

    private static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }
        $alphabet = self::BASE32_ALPHABET;
        $output = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($binary);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($binary[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $index = ($buffer >> $bits) & 0x1F;
                $output .= $alphabet[$index];
            }
        }
        if ($bits > 0) {
            $index = ($buffer << (5 - $bits)) & 0x1F;
            $output .= $alphabet[$index];
        }
        return $output;
    }
}
