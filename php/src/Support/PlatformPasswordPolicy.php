<?php

declare(strict_types=1);

namespace Attendly\Support;

final class PlatformPasswordPolicy
{
    /**
     * @return array{ok:bool,errors:string[]}
     */
    public static function validate(string $password): array
    {
        $errors = [];
        if (mb_strlen($password, 'UTF-8') < 12) {
            $errors[] = 'プラットフォーム管理者のパスワードは12文字以上にしてください。';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'プラットフォーム管理者のパスワードには大文字を含めてください。';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'プラットフォーム管理者のパスワードには小文字を含めてください。';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'プラットフォーム管理者のパスワードには数字を含めてください。';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'プラットフォーム管理者のパスワードには記号を含めてください。';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }
}
