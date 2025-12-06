<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;

final class RoleCodeService
{
    private Repository $repository;

    public function __construct(?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
    }

    /**
     * @param array{tenant_id:int,created_by:int,max_uses:?int,expires_at:?DateTimeImmutable} $data
     * @return array{id:int,tenant_id:int,code:string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool}
     */
    public function create(array $data): array
    {
        $tenant = $this->repository->findTenantById($data['tenant_id']);
        if ($tenant === null || ($tenant['status'] ?? '') !== 'active') {
            throw new RuntimeException('テナントが無効です。');
        }

        $code = $this->generateUniqueCode();
        $payload = [
            'tenant_id' => $data['tenant_id'],
            'code' => $code,
            'expires_at' => $data['expires_at'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
            'created_by' => $data['created_by'],
        ];

        return $this->repository->createRoleCode($payload);
    }

    /**
     * @return array<int, array{id:int,tenant_id:int,code:string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool,created_at:DateTimeImmutable}>
     */
    public function listForTenant(int $tenantId, int $limit = 100): array
    {
        if ($limit <= 0 || $limit > 1000) {
            throw new RuntimeException('制限値は1から1000の間である必要があります。');
        }
        return $this->repository->listRoleCodes($tenantId, $limit);
    }

    public function disable(int $roleCodeId): void
    {
        $existing = $this->repository->findRoleCodeById($roleCodeId);
        if ($existing === null) {
            throw new RuntimeException('ロールコードが見つかりません。');
        }
        $this->repository->disableRoleCode($roleCodeId);
    }

    private function generateUniqueCode(): string
    {
        // 10 文字の英数字を生成し、重複時はリトライ（衝突確率を極小化）
        for ($i = 0; $i < 5; $i++) {
            $raw = bin2hex(random_bytes(8));
            $code = strtoupper(substr($raw, 0, 10));
            if ($this->repository->findRoleCodeByCode($code) === null) {
                return $code;
            }
        }
        throw new RuntimeException('ロールコードの生成に失敗しました。');
    }
}
