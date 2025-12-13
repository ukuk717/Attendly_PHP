<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminTenantSettingsController
{
    private Repository $repository;

    public function __construct(?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
    }

    public function updateEmailVerification(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        try {
            $admin = $this->requireTenantAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $body = (array)$request->getParsedBody();
        $normalized = strtolower(trim((string)($body['requireEmployeeEmailVerification'] ?? '')));
        $nextValue = in_array($normalized, ['on', '1', 'true', 'yes'], true);

        try {
            $this->repository->updateTenantRegistrationSettings($admin['tenant_id'], $nextValue);
        } catch (\Throwable) {
            Flash::add('error', '設定の保存に失敗しました。時間をおいて再試行してください。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        Flash::add(
            'success',
            $nextValue ? '従業員登録時のメール確認を有効化しました。' : '従業員登録時のメール確認を無効化しました。'
        );
        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    /**
     * @return array{id:int,tenant_id:int,email:string,role:string}
     */
    private function requireTenantAdmin(ServerRequestInterface $request): array
    {
        $sessionUser = $request->getAttribute('currentUser');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            throw new \RuntimeException('認証が必要です。');
        }
        $user = $this->repository->findUserById((int)$sessionUser['id']);
        if ($user === null || !in_array(($user['role'] ?? ''), ['admin', 'tenant_admin'], true)) {
            throw new \RuntimeException('権限がありません。');
        }
        if ($user['tenant_id'] === null) {
            throw new \RuntimeException('テナントに所属していません。');
        }
        return [
            'id' => $user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }
}

