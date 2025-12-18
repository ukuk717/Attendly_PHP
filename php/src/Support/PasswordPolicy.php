<?php

declare(strict_types=1);

namespace Attendly\Support;

final class PasswordPolicy
{
    public function __construct(private int $minLength = 8, private int $maxLength = 256)
    {
        if ($this->minLength < 8) {
            $this->minLength = 8; // セキュリティ下限
        }
        if ($this->maxLength < $this->minLength) {
            $this->maxLength = max(8, $this->minLength);
        }
    }

    public function getMinLength(): int
    {
        return $this->minLength;
    }

    public function getMaxLength(): int
    {
        return $this->maxLength;
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
        if ($this->maxLength > 0 && mb_strlen($password, 'UTF-8') > $this->maxLength) {
            $errors[] = "パスワードは {$this->maxLength} 文字以内で入力してください。";
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }
}
