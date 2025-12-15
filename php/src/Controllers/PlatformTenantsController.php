<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Security\SensitiveLogPayload;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlatformTenantsController
{
    private Repository $repository;

    public function __construct(private View $view, ?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $platform = $this->requirePlatformUser($request);

        $query = $request->getQueryParams();
        $pageRaw = $query['page'] ?? 1;
        $page = filter_var($pageRaw, FILTER_VALIDATE_INT, ['options' => ['default' => 1]]);
        if ($page === false) {
            $page = 1;
        }
        $page = max(1, (int)$page);
        $limit = 200;
        $offset = ($page - 1) * $limit;

        $total = $this->repository->countTenantAdminsForPlatform();
        $admins = $this->repository->listTenantAdminsForPlatform($limit, $offset);

        $adminIds = [];
        foreach ($admins as $admin) {
            if (!empty($admin['id'])) {
                $adminIds[] = (int)$admin['id'];
            }
        }
        $hasTotpMap = $this->repository->mapUserIdsWithVerifiedMfaType($adminIds, 'totp');
        $latestResetMap = $this->repository->mapLatestTenantAdminMfaResetLogsByUser($adminIds);

        $rows = [];
        foreach ($admins as $admin) {
            $adminId = (int)$admin['id'];
            $hasTotp = isset($hasTotpMap[$adminId]);
            $latest = $latestResetMap[$adminId] ?? null;
            $rows[] = [
                'id' => $adminId,
                'username' => (string)$admin['username'],
                'email' => (string)$admin['email'],
                'phoneNumber' => $admin['phone_number'] !== null ? (string)$admin['phone_number'] : null,
                'tenantName' => $admin['tenant_name'] !== null ? (string)$admin['tenant_name'] : null,
                'tenantUid' => $admin['tenant_uid'] !== null ? (string)$admin['tenant_uid'] : null,
                'status' => (string)$admin['status'],
                'hasMfa' => $hasTotp,
                'lastReset' => $this->formatResetLog($latest),
            ];
        }

        $pagination = [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasPrev' => $page > 1,
            'hasNext' => ($offset + $limit) < $total,
        ];

        $html = $this->view->renderWithLayout('platform_tenants', [
            'title' => 'テナント管理',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'platformUser' => $platform,
            'tenantAdmins' => $rows,
            'pagination' => $pagination,
        ], 'platform_layout');

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function resetTenantAdminMfa(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $platform = $this->requirePlatformUser($request);
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $targetId = isset($args['userId']) ? (int)$args['userId'] : 0;
        if ($targetId <= 0) {
            Flash::add('error', '対象のテナント管理者が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $tenantAdmin = $this->repository->findTenantAdminById($targetId);
        if ($tenantAdmin === null) {
            Flash::add('error', '対象のテナント管理者が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 200) {
            Flash::add('error', 'リセット理由を入力してください（200文字以内）。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $confirmed = strtolower(trim((string)($body['confirmed'] ?? '')));
        if ($confirmed !== 'yes') {
            Flash::add('error', '確認にチェックしてください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $existingTotp = $this->repository->findVerifiedMfaMethodRawByType($tenantAdmin['id'], 'totp');
        $recoveryCodes = $this->repository->listRecoveryCodesRawByUser($tenantAdmin['id']);

        try {
            $previousMethodPayload = $existingTotp !== null ? SensitiveLogPayload::encrypt($existingTotp) : null;
            $previousRecoveryPayload = $recoveryCodes !== [] ? SensitiveLogPayload::encrypt($recoveryCodes) : null;
        } catch (\Throwable) {
            Flash::add('error', '監査ログの暗号化に失敗したため、リセット処理を中断しました。システム管理者へ連絡してください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        try {
            $this->repository->beginTransaction();
            $this->repository->createTenantAdminMfaResetLog(
                $tenantAdmin['id'],
                $platform['id'],
                $reason,
                $previousMethodPayload,
                $previousRecoveryPayload
            );
            $this->repository->deleteMfaMethodsByUserAndType($tenantAdmin['id'], 'totp');
            $this->repository->deleteRecoveryCodesByUser($tenantAdmin['id']);
            $this->repository->deleteTrustedDevicesByUser($tenantAdmin['id']);
            $this->repository->commit();
        } catch (\Throwable) {
            $this->repository->rollback();
            Flash::add('error', '2FAリセットに失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        Flash::add('success', sprintf('テナント管理者「%s」の2FAをリセットしました。', (string)$tenantAdmin['username']));
        return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
    }

    public function rollbackTenantAdminMfa(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $platform = $this->requirePlatformUser($request);
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $targetId = isset($args['userId']) ? (int)$args['userId'] : 0;
        if ($targetId <= 0) {
            Flash::add('error', '対象のテナント管理者が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $tenantAdmin = $this->repository->findTenantAdminById($targetId);
        if ($tenantAdmin === null) {
            Flash::add('error', '対象のテナント管理者が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $rollbackReason = trim((string)($body['rollbackReason'] ?? ''));
        if ($rollbackReason === '' || mb_strlen($rollbackReason, 'UTF-8') > 200) {
            Flash::add('error', '取消理由を入力してください（200文字以内）。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $logId = (int)($body['logId'] ?? 0);
        if ($logId <= 0) {
            Flash::add('error', '取り消す対象のリセット情報が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $latest = $this->repository->getLatestTenantAdminMfaResetLog($tenantAdmin['id']);
        if ($latest === null || (int)$latest['id'] !== $logId) {
            Flash::add('error', '直前のリセット情報が一致しないため、取り消しできません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        if ($latest['rolled_back_at'] !== null) {
            Flash::add('error', 'このリセットはすでに取り消されています。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $currentMethod = $this->repository->findVerifiedMfaMethodByType($tenantAdmin['id'], 'totp');
        if ($currentMethod !== null) {
            Flash::add('error', '現在2FAが再設定されているため、取り消しできません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $methodRead = SensitiveLogPayload::tryRead($latest['previous_method_json']);
        if (!$methodRead['ok']) {
            Flash::add('error', '監査ログの復号に失敗したため、取り消しできません。システム管理者へ連絡してください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $codesRead = SensitiveLogPayload::tryRead($latest['previous_recovery_codes_json']);
        if (!$codesRead['ok']) {
            Flash::add('error', '監査ログの復号に失敗したため、取り消しできません。システム管理者へ連絡してください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $previousMethod = $methodRead['value'];
        $previousRecoveryCodes = $codesRead['value'] ?? [];

        try {
            $this->repository->beginTransaction();
            $this->repository->deleteMfaMethodsByUserAndType($tenantAdmin['id'], 'totp');
            if (is_array($previousMethod) && $previousMethod !== []) {
                $this->repository->restoreTotpMethodFromSnapshot($tenantAdmin['id'], $previousMethod);
            }

            $this->repository->deleteRecoveryCodesByUser($tenantAdmin['id']);
            if (is_array($previousRecoveryCodes) && $previousRecoveryCodes !== []) {
                $this->repository->restoreRecoveryCodesFromSnapshot($tenantAdmin['id'], $previousRecoveryCodes);
            }

            $this->repository->markTenantAdminMfaResetRolledBack($logId, $rollbackReason, $platform['id']);
            $this->repository->commit();
        } catch (\Throwable) {
            $this->repository->rollback();
            Flash::add('error', '取り消し処理に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        Flash::add('success', sprintf('テナント管理者「%s」の直前の2FAリセットを取り消しました。', (string)$tenantAdmin['username']));
        return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
    }

    /**
     * @return array{id:int,email:string,name:?string}
     */
    private function requirePlatformUser(ServerRequestInterface $request): array
    {
        $sessionUser = $request->getAttribute('currentUser');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            throw new \RuntimeException('認証が必要です。');
        }
        if (($sessionUser['role'] ?? null) !== 'admin') {
            throw new \RuntimeException('権限がありません。');
        }
        if (array_key_exists('tenant_id', $sessionUser) && $sessionUser['tenant_id'] !== null) {
            throw new \RuntimeException('プラットフォーム管理者ではありません。');
        }
        return [
            'id' => (int)$sessionUser['id'],
            'email' => (string)($sessionUser['email'] ?? ''),
            'name' => isset($sessionUser['name']) ? (string)$sessionUser['name'] : null,
        ];
    }

    /**
     * @param array{id:int,reason:string,created_at:\DateTimeImmutable,rolled_back_at:?\\DateTimeImmutable,rollback_reason:?string}|null $log
     * @return array{id:int,reason:string,createdAtDisplay:string,rolledBackAtDisplay:?string,rollbackReason:?string}|null
     */
    private function formatResetLog(?array $log): ?array
    {
        if ($log === null) {
            return null;
        }
        $created = $log['created_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i');
        $rolledBack = $log['rolled_back_at']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i');
        return [
            'id' => (int)$log['id'],
            'reason' => (string)$log['reason'],
            'createdAtDisplay' => $created,
            'rolledBackAtDisplay' => $rolledBack,
            'rollbackReason' => $log['rollback_reason'] !== null ? (string)$log['rollback_reason'] : null,
        ];
    }
}
