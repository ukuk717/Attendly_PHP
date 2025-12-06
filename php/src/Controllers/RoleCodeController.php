<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Services\RoleCodeService;
use Attendly\Security\CsrfToken;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RoleCodeController
{
    public function __construct(private ?View $view = null, private ?Repository $repository = null, private ?RoleCodeService $service = null)
    {
        $this->repository = $this->repository ?? new Repository();
        $this->service = $this->service ?? new RoleCodeService($this->repository);
        $this->view = $this->view ?? new View(dirname(__DIR__, 2) . '/views');
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            return $this->error($response, 403, 'forbidden');
        }
        $tenantId = $user['tenant_id'];
        $items = $this->service->listForTenant($tenantId, 100);
        $payload = [
            'items' => array_map(static function (array $row): array {
                return [
                    'id' => $row['id'],
                    'code' => $row['code'],
                    'expires_at' => $row['expires_at']?->format(\DateTimeInterface::ATOM),
                    'max_uses' => $row['max_uses'],
                    'usage_count' => $row['usage_count'],
                    'is_disabled' => $row['is_disabled'],
                    'created_at' => $row['created_at']?->format(\DateTimeInterface::ATOM),
                ];
            }, $items),
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            return $this->error($response, 403, 'forbidden');
        }
        $data = (array)$request->getParsedBody();
        $maxUses = $this->nullableInt($data['max_uses'] ?? null, 1, 100000);
        $expiresAt = null;
        if (!empty($data['expires_at'])) {
            $expiresAt = AppTime::parseDate((string)$data['expires_at']);
            if ($expiresAt === null) {
                return $this->error($response, 400, 'invalid_expires_at');
            }
        }
        $created = $this->service->create([
            'tenant_id' => $user['tenant_id'],
            'created_by' => $user['id'],
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
        ]);
        $payload = [
            'id' => $created['id'],
            'code' => $created['code'],
            'expires_at' => $created['expires_at']?->format(\DateTimeInterface::ATOM),
            'max_uses' => $created['max_uses'],
            'usage_count' => $created['usage_count'],
            'is_disabled' => $created['is_disabled'],
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus(201)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function disable(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            return $this->error($response, 403, 'forbidden');
        }
        $roleCodeId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($roleCodeId <= 0) {
            return $this->error($response, 400, 'invalid_role_code_id');
        }
        $roleCode = $this->repository->findRoleCodeById($roleCodeId);
        if ($roleCode === null || $roleCode['tenant_id'] !== $user['tenant_id']) {
            return $this->error($response, 404, 'not_found');
        }
        $this->service->disable($roleCodeId);
        $response->getBody()->write(json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function showPage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $items = $this->service->listForTenant($user['tenant_id'], 200);
        $html = $this->view->renderWithLayout('admin_role_codes', [
            'title' => 'ロールコード管理',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'items' => $items,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function createFromForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/admin/role-codes');
        }
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $data = (array)$request->getParsedBody();
        $maxUses = $this->nullableInt($data['max_uses'] ?? null, 1, 100000);
        $expiresAt = null;
        if (!empty($data['expires_at'])) {
            $expiresAt = AppTime::parseDate((string)$data['expires_at']);
            if ($expiresAt === null) {
                Flash::add('error', '有効期限の形式が不正です。');
                return $response->withStatus(303)->withHeader('Location', '/admin/role-codes');
            }
        }
        try {
            $created = $this->service->create([
                'tenant_id' => $user['tenant_id'],
                'created_by' => $user['id'],
                'max_uses' => $maxUses,
                'expires_at' => $expiresAt,
            ]);
            Flash::add('success', 'ロールコードを発行しました: ' . $created['code']);
        } catch (\Throwable $e) {
            Flash::add('error', 'ロールコードの発行に失敗しました。');
        }
        return $response->withStatus(303)->withHeader('Location', '/admin/role-codes');
    }

    public function disableFromForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/admin/role-codes');
        }
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $roleCodeId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($roleCodeId <= 0) {
            Flash::add('error', 'ロールコードIDが不正です。');
            return $response->withStatus(303)->withHeader('Location', '/admin/role-codes');
        }
        $roleCode = $this->repository->findRoleCodeById($roleCodeId);
        if ($roleCode === null || $roleCode['tenant_id'] !== $user['tenant_id']) {
            Flash::add('error', 'ロールコードが見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/admin/role-codes');
        }
        try {
            $this->service->disable($roleCodeId);
            Flash::add('success', 'ロールコードを無効化しました。');
        } catch (\Throwable $e) {
            Flash::add('error', '無効化に失敗しました。');
        }
        return $response->withStatus(303)->withHeader('Location', '/admin/role-codes');
    }

    /**
     * @return array{id:int,tenant_id:int,email:string,role:string}
     */
    private function requireAdmin(ServerRequestInterface $request): array
    {
        $sessionUser = $request->getAttribute('currentUser');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            throw new \RuntimeException('認証が必要です。');
        }
        $user = $this->repository->findUserById((int)$sessionUser['id']);
        if ($user === null || ($user['role'] ?? '') !== 'admin') {
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

    private function nullableInt(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \RuntimeException('数値を指定してください。');
        }
        $intVal = (int)$value;
        if ($intVal < $min || $intVal > $max) {
            throw new \RuntimeException('指定された範囲外の値です。');
        }
        return $intVal;
    }

    private function error(ResponseInterface $response, int $status, string $code): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $code], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }
}
