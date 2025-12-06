<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\Support\SessionAuth;
use Attendly\View;
use Attendly\Database\Repository;
use Attendly\Services\EmailOtpService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Attendly\Support\PasswordPolicy;
use RuntimeException;

final class RegisterController
{
    private int $minPasswordLength;
    private PasswordPolicy $passwordPolicy;
    private Repository $repository;
    private EmailOtpService $emailOtpService;

    public function __construct(private View $view, ?Repository $repository = null, ?EmailOtpService $emailOtpService = null)
    {
        $rawMin = $_ENV['MIN_PASSWORD_LENGTH'] ?? 12;
        $this->minPasswordLength = filter_var($rawMin, FILTER_VALIDATE_INT, ['options' => ['min_range' => 8]]) ?: 12;
        if ($this->minPasswordLength < 8) {
            $this->minPasswordLength = 8; // セキュリティ下限
        }
        $this->passwordPolicy = new PasswordPolicy($this->minPasswordLength);
        $this->repository = $repository ?? new Repository();
        $this->emailOtpService = $emailOtpService ?? new EmailOtpService($this->repository);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (SessionAuth::getUser() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $query = $request->getQueryParams();
        $roleCode = isset($query['roleCode']) ? (string)$query['roleCode'] : '';

        $html = $this->view->renderWithLayout('register', [
            'title' => '新規アカウント登録',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'roleCodeValue' => $roleCode,
            'minPasswordLength' => $this->minPasswordLength,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $roleCode = trim((string)($data['roleCode'] ?? ''));
        $lastName = trim((string)($data['lastName'] ?? ''));
        $firstName = trim((string)($data['firstName'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $verificationCode = trim((string)($data['verificationCode'] ?? ''));
        $password = (string)($data['password'] ?? '');

        $errors = [];
        if ($roleCode === '' || mb_strlen($roleCode, 'UTF-8') > 32 || !preg_match('/^[A-Za-z0-9]+$/', $roleCode)) {
            $errors[] = 'ロールコードを英数字で入力してください。';
        }
        if ($lastName === '' || $firstName === '') {
            $errors[] = '姓と名を入力してください。';
        }
        if (mb_strlen($lastName, 'UTF-8') > 64 || mb_strlen($firstName, 'UTF-8') > 64) {
            $errors[] = '氏名は64文字以内で入力してください。';
        }
        if ($email === '' || mb_strlen($email, 'UTF-8') > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '有効なメールアドレスを入力してください。';
        }
        if ($verificationCode !== '' && !preg_match('/^[0-9]{6}$/', $verificationCode)) {
            $errors[] = '確認コードは6桁の数字で入力してください。';
        }
        if (mb_strlen($password, 'UTF-8') > 128) {
            $errors[] = 'パスワードが長すぎます。128文字以内で入力してください。';
        }
        $policyResult = $this->passwordPolicy->validate($password);
        if (!$policyResult['ok']) {
            $errors = array_merge($errors, $policyResult['errors']);
        }

        if (!empty($errors)) {
            foreach ($errors as $err) {
                Flash::add('error', $err);
            }
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        $normalizedRoleCode = strtoupper($roleCode);
        try {
            $roleCodeRow = $this->repository->findRoleCodeByCode($normalizedRoleCode);
        } catch (RuntimeException $e) {
            Flash::add('error', 'ロールコードの確認中にエラーが発生しました。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }
        if ($roleCodeRow === null) {
            Flash::add('error', 'ロールコードが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        $now = AppTime::now();
        if ($roleCodeRow['is_disabled']) {
            Flash::add('error', 'このロールコードは無効化されています。管理者へお問い合わせください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }
        if ($roleCodeRow['expires_at'] !== null && $roleCodeRow['expires_at'] <= $now) {
            Flash::add('error', 'ロールコードの有効期限が切れています。新しいコードを取得してください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }
        if ($roleCodeRow['max_uses'] !== null && $roleCodeRow['usage_count'] >= $roleCodeRow['max_uses']) {
            Flash::add('error', 'ロールコードの利用上限に達しました。管理者へお問い合わせください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        $tenant = $this->repository->findTenantById($roleCodeRow['tenant_id']);
        if ($tenant === null || $tenant['status'] !== 'active') {
            Flash::add('error', 'テナント情報を確認できません。管理者へお問い合わせください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        $existingUser = $this->repository->findUserByEmail($email);
        $username = $this->buildUsername($lastName, $firstName);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            Flash::add('error', 'パスワードの処理中にエラーが発生しました。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        $requiresEmailVerification = $tenant['require_employee_email_verification'];

        if (!$requiresEmailVerification) {
            if ($existingUser !== null) {
                Flash::add('error', 'このメールアドレスはすでに登録されています。');
                return $response->withStatus(303)->withHeader('Location', '/register');
            }

            $pdo = $this->repository->getPdo();
            $started = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            try {
                $this->repository->createUser([
                    'tenant_id' => $roleCodeRow['tenant_id'],
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'role' => 'employee',
                    'status' => 'active',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]);
                $this->repository->incrementRoleCodeWithLimit($roleCodeRow['id']);
                if ($started) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($started && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                Flash::add('error', '登録処理中にエラーが発生しました。時間をおいて再度お試しください。');
                return $response->withStatus(303)->withHeader('Location', '/register');
            }

            Flash::add('success', 'アカウントを作成しました。ログインしてください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        // メール認証が必要なテナント
        $pdo = $this->repository->getPdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        try {
            if ($existingUser !== null) {
                $sameTenant = $existingUser['tenant_id'] === $roleCodeRow['tenant_id'];
                $isEmployee = $existingUser['role'] === 'employee';
                $isInactive = $existingUser['status'] === 'inactive';
                if (!$sameTenant || !$isEmployee) {
                    if ($started && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    Flash::add('error', 'このメールアドレスは他のテナントで使用されています。');
                    return $response->withStatus(303)->withHeader('Location', '/register');
                }
                if ($existingUser['status'] === 'active') {
                    if ($started && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    Flash::add('error', '既に登録が完了しています。ログインをお試しください。');
                    return $response->withStatus(303)->withHeader('Location', '/login');
                }
                $this->repository->updateUserPassword($existingUser['id'], $passwordHash);
                $this->repository->updateUserProfile($existingUser['id'], $firstName, $lastName);
                $userId = $existingUser['id'];
            } else {
                $created = $this->repository->createUser([
                    'tenant_id' => $roleCodeRow['tenant_id'],
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'role' => 'employee',
                    'status' => 'inactive',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]);
                $userId = $created['id'];
            }
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::add('error', '登録処理中にエラーが発生しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        $verificationSucceeded = false;
        $verifyReason = null;
        if ($verificationCode !== '') {
            $verify = $this->emailOtpService->verify('employee_register', $userId, $email, $verificationCode);
            if ($verify['ok']) {
                $verificationSucceeded = true;
            } elseif (($verify['reason'] ?? '') === 'locked' && isset($verify['retry_at'])) {
                Flash::add('error', '試行回数の上限に達しました。しばらく待ってからお試しください。');
                return $response->withStatus(303)->withHeader('Location', '/register');
            }
            $verifyReason = $verify['reason'] ?? null;
        }

        if ($verificationSucceeded) {
            $pdo = $this->repository->getPdo();
            $started = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            try {
                $this->repository->updateUserStatus($userId, 'active');
                $this->repository->deleteEmailOtpRequests(['user_id' => $userId, 'purpose' => 'employee_register']);
                $this->repository->incrementRoleCodeWithLimit($roleCodeRow['id']);
                if ($started) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($started && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                Flash::add('error', '登録処理中にエラーが発生しました。時間をおいて再度お試しください。');
                return $response->withStatus(303)->withHeader('Location', '/register');
            }
            Flash::add('success', '登録が完了しました。ログインしてください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $shouldIssue = $verificationCode === '' || $verifyReason === 'expired' || $verifyReason === 'not_found' || $verifyReason === null;
        if ($verificationCode !== '' && !$verificationSucceeded) {
            if ($verifyReason === 'expired') {
                Flash::add('error', '確認コードの有効期限が切れています。新しいコードを送信しました。');
            } elseif ($verifyReason === 'invalid_code' || $verifyReason === 'not_found') {
                Flash::add('error', '確認コードが一致しません。必要に応じて再送してください。');
            }
        }

        if ($shouldIssue) {
            try {
                $this->emailOtpService->issue('employee_register', $userId, $email, $roleCodeRow['tenant_id'], $roleCodeRow['id']);
            } catch (\Throwable $e) {
                Flash::add('error', '確認コードの送信に失敗しました。時間をおいて再度お試しください。');
                return $response->withStatus(303)->withHeader('Location', '/register');
            }
            Flash::add('info', '確認コードを送信しました。メールを確認し、コードを入力してください。');
        }

        $_SESSION['pending_registration'] = [
            'user_id' => $userId,
            'email' => $email,
            'role_code_id' => $roleCodeRow['id'],
        ];

        return $response->withStatus(303)->withHeader('Location', '/register/verify?email=' . rawurlencode($email));
    }

    private function buildUsername(string $lastName, string $firstName): string
    {
        $raw = trim($lastName . $firstName);
        if ($raw === '') {
            $raw = 'user';
        }
        return mb_substr($raw, 0, 255, 'UTF-8');
    }
}
