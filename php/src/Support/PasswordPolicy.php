<?php

declare(strict_types=1);

namespace Attendly\Support;

final class PasswordPolicy
{
    public function __construct(private int $minLength = 12)
    {
        if ($this->minLength < 8) {
            $this->minLength = 8; // セキュリティ下限
        }
    }

    public function getMinLength(): int
    {
        return $this->minLength;
    }

    /**
     * @return array{ok:bool, errors:string[]}
     */
    public function validate(string $password): array
    {
        $errors = [];
        if (mb_strlen($password, 'UTF-8') < $this->minLength) {
            $errors[] = "パスワードは {$this->minLength} 文字以上にしてください。";
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            $errors[] = 'パスワードには英字を含めてください。';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'パスワードには数字を含めてください。';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'パスワードには記号を含めてください。';
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }
}
