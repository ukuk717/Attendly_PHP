<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
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
     * @param array{tenant_id:int,created_by:int,employment_type?:?string,max_uses:?int,expires_at:?DateTimeImmutable} $data
     * @return array{id:int,tenant_id:int,code:string,employment_type:?string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool}
     */
    public function create(array $data): array
    {
        if (!isset($data['tenant_id'], $data['created_by'])) {
            throw new RuntimeException('必須パラメータが不足しています。');
        }

        $tenant = $this->repository->findTenantById((int)$data['tenant_id']);
        if ($tenant === null || ($tenant['status'] ?? '') !== 'active') {
            throw new RuntimeException('テナントが無効です。');
        }

        $code = $this->generateUniqueCode();
        $employmentType = isset($data['employment_type']) ? strtolower(trim((string)$data['employment_type'])) : null;
        if ($employmentType === '') {
            $employmentType = null;
        }
        $allowedTypes = [null, 'part_time', 'full_time'];
        if (!in_array($employmentType, $allowedTypes, true)) {
            throw new RuntimeException('雇用区分が不正です。');
        }

        $payload = [
            'tenant_id' => (int)$data['tenant_id'],
            'code' => $code,
            'employment_type' => $employmentType,
            'expires_at' => ($data['expires_at'] ?? null) instanceof DateTimeImmutable ? $data['expires_at'] : null,
            'max_uses' => isset($data['max_uses']) ? (int)$data['max_uses'] : null,
            'created_by' => (int)$data['created_by'],
        ];

        return $this->repository->createRoleCode($payload);
    }

    /**
     * @return array<int, array{id:int,tenant_id:int,code:string,employment_type:?string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool,created_at:DateTimeImmutable}>
     */
    public function listForTenant(int $tenantId, int $limit = 100): array
    {
        if ($limit <= 0 || $limit > 1000) {
            throw new RuntimeException('制限値は1から1000の間で指定してください。');
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
        // 10桁の英数字コードを生成し、重複時はリトライ
        for ($i = 0; $i < 5; $i++) {
            $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $chars[random_int(0, 35)];
            }
            if ($this->repository->findRoleCodeByCode($code) === null) {
                return $code;
            }
        }
        throw new RuntimeException('ロールコードの生成に失敗しました。');
    }
}
