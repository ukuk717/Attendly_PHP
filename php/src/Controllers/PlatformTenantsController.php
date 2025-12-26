<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Security\SensitiveLogPayload;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\Support\PasswordHasher;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlatformTenantsController
{
    private Repository $repository;
    private int $tenantsPerPage;
    private int $adminsPerPage;

    public function __construct(private View $view, ?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->tenantsPerPage = 100;
        $this->adminsPerPage = 200;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $platform = $this->requirePlatformUser($request);

        $query = $request->getQueryParams();
        $tenantPage = $this->sanitizePage($query['tenants_page'] ?? 1);
        $adminsPage = $this->sanitizePage($query['admins_page'] ?? ($query['page'] ?? 1));

        $tenantsOffset = ($tenantPage - 1) * $this->tenantsPerPage;
        $adminsOffset = ($adminsPage - 1) * $this->adminsPerPage;

        $tenantTotal = $this->repository->countTenantsForPlatform();
        $tenantRows = $this->repository->listTenantsForPlatform($this->tenantsPerPage, $tenantsOffset);
        $tenants = array_map(static function (array $tenant): array {
            $createdAt = $tenant['created_at'] instanceof \DateTimeImmutable
                ? $tenant['created_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : null;
            $tenant['created_at_display'] = $createdAt;
            return $tenant;
        }, $tenantRows);

        $totalAdmins = $this->repository->countTenantAdminsForPlatform();
        $admins = $this->repository->listTenantAdminsForPlatform($this->adminsPerPage, $adminsOffset);

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
            $tenantCreatedAt = $admin['tenant_created_at'] instanceof \DateTimeImmutable
                ? $admin['tenant_created_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : null;
            $rows[] = [
                'id' => $adminId,
                'username' => (string)$admin['username'],
                'email' => (string)$admin['email'],
                'phoneNumber' => $admin['phone_number'] !== null ? (string)$admin['phone_number'] : null,
                'tenantName' => $admin['tenant_name'] !== null ? (string)$admin['tenant_name'] : null,
                'tenantUid' => $admin['tenant_uid'] !== null ? (string)$admin['tenant_uid'] : null,
                'tenantContactEmail' => $admin['tenant_contact_email'] !== null ? (string)$admin['tenant_contact_email'] : null,
                'tenantContactPhone' => $admin['tenant_contact_phone'] !== null ? (string)$admin['tenant_contact_phone'] : null,
                'tenantCreatedAt' => $tenantCreatedAt,
                'tenantStatus' => $admin['tenant_status'] !== null ? (string)$admin['tenant_status'] : null,
                'status' => (string)$admin['status'],
                'hasMfa' => $hasTotp,
                'lastReset' => $this->formatResetLog($latest),
            ];
        }

        $tenantsPagination = [
            'page' => $tenantPage,
            'limit' => $this->tenantsPerPage,
            'total' => $tenantTotal,
            'hasPrev' => $tenantPage > 1,
            'hasNext' => ($tenantsOffset + $this->tenantsPerPage) < $tenantTotal,
        ];
        $adminsPagination = [
            'page' => $adminsPage,
            'limit' => $this->adminsPerPage,
            'total' => $totalAdmins,
            'hasPrev' => $adminsPage > 1,
            'hasNext' => ($adminsOffset + $this->adminsPerPage) < $totalAdmins,
        ];

        $generated = $_SESSION['generated_tenant_credential'] ?? null;
        if (isset($_SESSION['generated_tenant_credential'])) {
            unset($_SESSION['generated_tenant_credential']);
        }

        $html = $this->view->renderWithLayout('platform_tenants', [
            'title' => 'テナント管理',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'platformUser' => $platform,
            'tenants' => $tenants,
            'tenantsPagination' => $tenantsPagination,
            'tenantAdmins' => $rows,
            'adminsPagination' => $adminsPagination,
            'generated' => is_array($generated) ? $generated : null,
        ], 'platform_layout');

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function createTenant(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requirePlatformUser($request);
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $name = trim((string)($body['name'] ?? ''));
        $contactEmail = trim((string)($body['contactEmail'] ?? ''));
        $adminEmail = trim((string)($body['adminEmail'] ?? ''));
        $contactPhone = trim((string)($body['contactPhone'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 255 || preg_match('/[\r\n]/', $name)) {
            Flash::add('error', 'テナント名を入力してください（255文字以内）。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $email = strtolower($contactEmail);
        if ($email === '' || mb_strlen($email, 'UTF-8') > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            Flash::add('error', '連絡先メールアドレスを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $adminEmail = strtolower($adminEmail);
        if ($adminEmail === '' || mb_strlen($adminEmail, 'UTF-8') > 254 || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $adminEmail)) {
            Flash::add('error', '管理者メールアドレスを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        if ($contactPhone !== '') {
            $contactPhone = preg_replace('/[^\d+]/', '', $contactPhone) ?? '';
            if ($contactPhone === '' || mb_strlen($contactPhone, 'UTF-8') > 32) {
                Flash::add('error', '電話番号が不正です。');
                return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
            }
        } else {
            $contactPhone = null;
        }
        $confirmed = strtolower(trim((string)($body['confirmed'] ?? '')));
        if ($confirmed !== 'yes') {
            Flash::add('error', '確認にチェックしてください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $existingAdmin = $this->repository->findUserByEmail($adminEmail);
        if ($existingAdmin !== null) {
            Flash::add('error', '指定された管理者メールアドレスは既に使用されています。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $initialPassword = $this->generateInitialPassword(16);
        $hasher = new PasswordHasher();
        try {
            $passwordHash = $hasher->hash($initialPassword);
        } catch (\Throwable) {
            Flash::add('error', '初期パスワードの生成に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        try {
            $this->repository->beginTransaction();
            $tenant = $this->repository->createTenant($name, $email, $contactPhone);
            $this->repository->createUser([
                'tenant_id' => $tenant['id'],
                'username' => $adminEmail,
                'email' => $adminEmail,
                'password_hash' => $passwordHash,
                'role' => 'tenant_admin',
                'status' => 'active',
                'must_change_password' => true,
                'phone_number' => $contactPhone,
            ]);
            $this->repository->commit();
        } catch (\Throwable) {
            $this->repository->rollback();
            Flash::add('error', 'テナントの作成に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $_SESSION['generated_tenant_credential'] = [
            'tenant_uid' => $tenant['tenant_uid'],
            'admin_email' => $adminEmail,
            'initial_password' => $initialPassword,
        ];

        Flash::add('success', sprintf('テナント「%s」を作成しました。', (string)($tenant['name'] ?? '')));
        return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
    }

    public function updateTenantStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requirePlatformUser($request);
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $tenantId = isset($args['tenantId']) ? (int)$args['tenantId'] : 0;
        if ($tenantId <= 0) {
            Flash::add('error', '対象のテナントが見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $tenant = $this->repository->findTenantById($tenantId);
        if ($tenant === null) {
            Flash::add('error', '対象のテナントが見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $action = strtolower(trim((string)($body['action'] ?? '')));
        $nextStatus = null;
        if ($action === 'deactivate') {
            $nextStatus = 'inactive';
        } elseif ($action === 'activate') {
            $nextStatus = 'active';
        }
        if ($nextStatus === null) {
            Flash::add('error', '無効な操作です。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        $confirmed = strtolower(trim((string)($body['confirmed'] ?? '')));
        if ($confirmed !== 'yes') {
            Flash::add('error', '確認にチェックしてください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        try {
            $this->repository->updateTenantStatus($tenantId, $nextStatus);
        } catch (\Throwable) {
            Flash::add('error', 'ステータス更新に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        $label = $tenant['name'] !== null ? (string)$tenant['name'] : ('ID:' . (string)$tenantId);
        Flash::add('success', sprintf('テナント「%s」を%sしました。', $label, $nextStatus === 'active' ? '再開' : '停止'));
        return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
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
        $verifyCheck = $this->verifyTenantAdminIdentity($tenantAdmin, $body);
        if (!$verifyCheck['ok']) {
            Flash::add('error', $verifyCheck['message']);
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
            Flash::add('error', '二段階認証リセットに失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }

        Flash::add('success', sprintf('テナント管理者「%s」の二段階認証をリセットしました。', (string)$tenantAdmin['username']));
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
        $verifyCheck = $this->verifyTenantAdminIdentity($tenantAdmin, $body);
        if (!$verifyCheck['ok']) {
            Flash::add('error', $verifyCheck['message']);
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
            Flash::add('error', '現在二段階認証が再設定されているため、取り消しできません。');
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

        Flash::add('success', sprintf('テナント管理者「%s」の直前の二段階認証リセットを取り消しました。', (string)$tenantAdmin['username']));
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
        if (!in_array(($sessionUser['role'] ?? null), ['platform_admin', 'admin'], true)) {
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

    private function sanitizePage(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => 1]]);
        if ($page === false) {
            $page = 1;
        }
        return max(1, (int)$page);
    }

    private function generateInitialPassword(int $length = 16): string
    {
        $length = max(12, $length);
        $sets = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%&*?',
        ];
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $tmp = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $tmp;
        }
        return implode('', $chars);
    }

    /**
     * @param array{
     *   email:string,
     *   phone_number:?string,
     *   tenant_contact_email:?string,
     *   tenant_contact_phone:?string,
     *   tenant_uid:?string,
     *   tenant_created_at:?\\DateTimeImmutable
     * } $tenantAdmin
     * @param array<string,mixed> $body
     * @return array{ok:bool,message:string}
     */
    private function verifyTenantAdminIdentity(array $tenantAdmin, array $body): array
    {
        $matches = 0;
        $checked = 0;

        $inputEmail = $this->normalizeEmail($body['verifyEmail'] ?? null);
        if ($inputEmail !== '') {
            $checked++;
            $adminEmail = $this->normalizeEmail($tenantAdmin['email'] ?? null);
            $contactEmail = $this->normalizeEmail($tenantAdmin['tenant_contact_email'] ?? null);
            if ($inputEmail === $adminEmail || ($contactEmail !== '' && $inputEmail === $contactEmail)) {
                $matches++;
            }
        }

        $inputPhoneLast4 = $this->normalizePhoneLast4($body['verifyPhoneLast4'] ?? null);
        if ($inputPhoneLast4 !== null) {
            $checked++;
            $candidates = [];
            $adminPhone = $this->normalizePhoneLast4($tenantAdmin['phone_number'] ?? null);
            $tenantPhone = $this->normalizePhoneLast4($tenantAdmin['tenant_contact_phone'] ?? null);
            if ($adminPhone !== null) {
                $candidates[] = $adminPhone;
            }
            if ($tenantPhone !== null && $tenantPhone !== $adminPhone) {
                $candidates[] = $tenantPhone;
            }
            if (in_array($inputPhoneLast4, $candidates, true)) {
                $matches++;
            }
        }

        $inputDate = $this->parseRegistrationInput($body['verifyRegisteredAt'] ?? null);
        if ($inputDate !== null) {
            $checked++;
            $createdAt = $tenantAdmin['tenant_created_at'] ?? null;
            if ($createdAt instanceof \DateTimeImmutable) {
                $createdLocal = $createdAt->setTimezone(AppTime::timezone());
                if ($inputDate['hasTime']) {
                    if ($createdLocal->format('Y-m-d H:i') === $inputDate['date']->format('Y-m-d H:i')) {
                        $matches++;
                    }
                } else {
                    if ($createdLocal->format('Y-m-d') === $inputDate['date']->format('Y-m-d')) {
                        $matches++;
                    }
                }
            }
        }

        $inputTenantUid = trim((string)($body['verifyTenantUid'] ?? ''));
        if ($inputTenantUid !== '') {
            $checked++;
            $tenantUid = trim((string)($tenantAdmin['tenant_uid'] ?? ''));
            if ($tenantUid !== '' && strcasecmp($tenantUid, $inputTenantUid) === 0) {
                $matches++;
            }
        }

        if ($checked < 2) {
            return [
                'ok' => false,
                'message' => '本人確認情報を2点以上入力してください。',
            ];
        }
        if ($matches < 2) {
            return [
                'ok' => false,
                'message' => '本人確認情報が一致しないため、操作できません。',
            ];
        }
        return [
            'ok' => true,
            'message' => '',
        ];
    }

    private function normalizeEmail(mixed $value): string
    {
        $email = strtolower(trim((string)($value ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $email;
    }

    private function normalizePhoneLast4(mixed $value): ?string
    {
        $digits = preg_replace('/\\D/', '', (string)($value ?? '')) ?? '';
        if ($digits === '' || strlen($digits) < 4) {
            return null;
        }
        return substr($digits, -4);
    }

    /**
     * @return array{date:\\DateTimeImmutable,hasTime:bool}|null
     */
    private function parseRegistrationInput(mixed $value): ?array
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $tz = AppTime::timezone();
        $formats = [
            'Y-m-d H:i:s',
            'Y/m/d H:i:s',
            'Y-m-d H:i',
            'Y/m/d H:i',
            'Y-m-d',
            'Y/m/d',
        ];
        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw, $tz);
            if ($parsed === false) {
                continue;
            }
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            return [
                'date' => $parsed,
                'hasTime' => str_contains($format, 'H'),
            ];
        }
        return null;
    }
}
