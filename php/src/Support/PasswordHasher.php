<?php

declare(strict_types=1);

namespace Attendly\Support;

final class PasswordHasher
{
    private string $pepper;

    public function __construct(?string $pepper = null)
    {
        $pepper = $pepper ?? (string)($_ENV['PASSWORD_PEPPER'] ?? '');
        $pepper = trim($pepper);
        $this->pepper = $pepper;
    }

    public function hasPepper(): bool
    {
        return $this->pepper !== '';
    }

    /**
     * @return array{ok:bool,usedLegacy:bool}
     */
    public function verify(string $password, string $hash): array
    {
        $hash = trim($hash);
        if ($hash === '') {
            return ['ok' => false, 'usedLegacy' => false];
        }

        if ($this->hasPepper()) {
            $peppered = $this->pepperPassword($password);
            if (password_verify($peppered, $hash)) {
                return ['ok' => true, 'usedLegacy' => false];
            }
        }

        if (password_verify($password, $hash)) {
            return ['ok' => true, 'usedLegacy' => $this->hasPepper()];
        }

        return ['ok' => false, 'usedLegacy' => false];
    }

    public function hash(string $password): string
    {
        $input = $this->hasPepper() ? $this->pepperPassword($password) : $password;
        $hash = password_hash($input, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('パスワードのハッシュ化に失敗しました。');
        }
        return $hash;
    }

    public function shouldRehash(string $hash): bool
    {
        $hash = trim($hash);
        if ($hash === '') {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            return true;
        }
        
        // If pepper is configured but hash appears to be non-peppered (legacy),
        // flag for rehashing. We detect this by attempting to verify an empty
        // password - if it's peppered, verification would use HMAC output.
        // However, this is imperfect. Better approach: check usedLegacy in verify()
        // at authentication time.
        return false;
    }

    private function pepperPassword(string $password): string
    {
        return hash_hmac('sha256', $password, $this->pepper);
    }
}

