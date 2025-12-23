<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminEmployeesController
{
    public function __construct(private ?Repository $repository = null)
    {
        $this->repository = $this->repository ?? new Repository();
    }

    public function updateStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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

        $employeeId = isset($args['userId']) ? (int)$args['userId'] : 0;
        if ($employeeId <= 0) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $employee = $this->repository->findUserById($employeeId);
        if (
            $employee === null
            || ($employee['role'] ?? '') !== 'employee'
            || (int)$employee['tenant_id'] !== $admin['tenant_id']
        ) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $body = (array)$request->getParsedBody();
        $action = strtolower(trim((string)($body['action'] ?? '')));
        if ($action === 'deactivate') {
            if (($employee['status'] ?? '') === 'inactive') {
                Flash::add('info', 'すでに無効化されています。');
            } else {
                $this->repository->updateUserStatus($employeeId, 'inactive');
                Flash::add('success', '従業員アカウントを無効化しました。');
            }
        } elseif ($action === 'activate') {
            if (($employee['status'] ?? '') === 'active') {
                Flash::add('info', 'すでに有効です。');
            } else {
                $this->repository->updateUserStatus($employeeId, 'active');
                Flash::add('success', '従業員アカウントを再有効化しました。');
            }
        } else {
            Flash::add('error', '不明な操作です。');
        }

        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    public function resetMfa(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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

        $employeeId = isset($args['userId']) ? (int)$args['userId'] : 0;
        if ($employeeId <= 0) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $employee = $this->repository->findUserById($employeeId);
        if (
            $employee === null
            || ($employee['role'] ?? '') !== 'employee'
            || (int)$employee['tenant_id'] !== $admin['tenant_id']
        ) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        try {
            $this->repository->deleteMfaMethodsByUserAndType($employeeId, 'totp');
            $this->repository->deleteRecoveryCodesByUser($employeeId);
            $this->repository->deleteTrustedDevicesByUser($employeeId);
        } catch (\Throwable) {
            Flash::add('error', '2FAのリセットに失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $username = $employee['username'] ?? ('ID:' . (string)$employeeId);
        Flash::add('success', sprintf('従業員「%s」の2FAをリセットしました。', $username));
        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    public function updateEmploymentType(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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

        $employeeId = isset($args['userId']) ? (int)$args['userId'] : 0;
        if ($employeeId <= 0) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $employee = $this->repository->findUserById($employeeId);
        if (
            $employee === null
            || ($employee['role'] ?? '') !== 'employee'
            || (int)$employee['tenant_id'] !== $admin['tenant_id']
        ) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $body = (array)$request->getParsedBody();
        $employmentTypeRaw = $body['employment_type'] ?? null;
        $employmentType = $employmentTypeRaw !== null ? trim((string)$employmentTypeRaw) : null;
        if ($employmentType === '') {
            $employmentType = null;
        }

        if ($employmentType !== null) {
            $employmentType = strtolower($employmentType);
            $employmentType = str_replace('-', '_', $employmentType);
        }

        // UI/ロールコードと同じ表現（part_time/full_time）に統一する
        $allowedTypes = [null, 'part_time', 'full_time'];
        if (!in_array($employmentType, $allowedTypes, true)) {
            Flash::add('error', '無効な雇用区分です。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        try {
            $this->repository->updateUserEmploymentType($employeeId, $employmentType);
            Flash::add('success', '雇用区分を更新しました。');
        } catch (\Throwable) {
            Flash::add('error', '雇用区分の更新に失敗しました。');
        }

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
        if ($user === null) {
            throw new \RuntimeException('権限がありません。');
        }
        $tenantId = $user['tenant_id'] !== null ? (int)$user['tenant_id'] : null;
        $role = $user['role'] ?? null;
        if ($role === 'admin' && $tenantId !== null) {
            $role = 'tenant_admin';
        }
        if ($role !== 'tenant_admin') {
            throw new \RuntimeException('権限がありません。');
        }
        if ($tenantId === null) {
            throw new \RuntimeException('テナントに所属していません。');
        }
        return [
            'id' => $user['id'],
            'tenant_id' => $tenantId,
            'email' => $user['email'],
            'role' => $role,
        ];
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }
}
