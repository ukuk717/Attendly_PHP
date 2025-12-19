<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\Support\Mailer;
use Attendly\Support\RateLimiter;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final class AdminPayslipsController
{
    private Repository $repository;
    private Mailer $mailer;
    private string $storageDir;
    private int $perPage;

    public function __construct(private View $view, ?Repository $repository = null, ?Mailer $mailer = null, ?string $storageDir = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->mailer = $mailer ?? new Mailer();
        $this->storageDir = $storageDir ?: dirname(__DIR__, 2) . '/storage/payslips';
        $this->perPage = 50;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $admin = $this->requireAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $query = $request->getQueryParams();
        $page = $this->sanitizePage($query['page'] ?? 1);
        $employeeId = $this->sanitizeOptionalEmployeeId($query['employee_id'] ?? null);

        if ($employeeId !== null) {
            $employee = $this->repository->findUserById($employeeId);
            if (
                $employee === null
                || (int)($employee['tenant_id'] ?? 0) !== (int)$admin['tenant_id']
                || (string)($employee['role'] ?? '') !== 'employee'
            ) {
                Flash::add('error', '従業員の指定が不正です。');
                $employeeId = null;
            }
        }

        $offset = ($page - 1) * $this->perPage;
        $total = $this->repository->countPayrollRecordsByTenantForAdmin($admin['tenant_id'], $employeeId);
        $rows = $this->repository->listPayrollRecordsByTenantForAdmin($admin['tenant_id'], $this->perPage, $offset, $employeeId);

        $items = array_map(function (array $row): array {
            $sentOn = $row['sent_on'] instanceof \DateTimeInterface
                ? $row['sent_on']->setTimezone(AppTime::timezone())->format('Y-m-d')
                : 'N/A';
            $sentAt = $row['sent_at'] instanceof \DateTimeInterface
                ? $row['sent_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : 'N/A';

            $label = $this->buildEmployeeLabel(
                $row['employee_last_name'] ?? null,
                $row['employee_first_name'] ?? null,
                $row['employee_email'] ?? null,
                (int)$row['employee_id'],
                $row['employee_status'] ?? null
            );

            return [
                'id' => (int)$row['id'],
                'employee_label' => $label,
                'sent_on' => $sentOn,
                'sent_at' => $sentAt,
                'file_name' => basename((string)$row['original_file_name']),
                'file_size' => $row['file_size'] !== null ? (int)$row['file_size'] : null,
            ];
        }, $rows);

        $employees = $this->repository->listEmployeesByTenantIncludingInactive($admin['tenant_id'], 500);
        $employeeOptions = array_map(static function (array $emp): array {
            $last = trim((string)($emp['username'] ?? ''));
            $email = trim((string)($emp['email'] ?? ''));
            $status = (string)($emp['status'] ?? '');
            $label = $email !== '' ? "{$last} ({$email})" : $last;
            if ($label === '') {
                $label = 'ID: ' . (string)$emp['id'];
            }
            if ($status !== '' && $status !== 'active') {
                $label .= '（' . $status . '）';
            }
            return [
                'id' => (int)$emp['id'],
                'label' => $label,
            ];
        }, $employees);

        $filterQuery = $employeeId !== null ? ('employee_id=' . rawurlencode((string)$employeeId)) : '';
        $pagination = [
            'page' => $page,
            'limit' => $this->perPage,
            'total' => $total,
            'hasPrev' => $page > 1,
            'hasNext' => ($offset + $this->perPage) < $total,
            'prevUrl' => $this->buildListUrl($page - 1, $employeeId),
            'nextUrl' => $this->buildListUrl($page + 1, $employeeId),
            'filterQuery' => $filterQuery,
        ];

        $html = $this->view->renderWithLayout('admin_payslips', [
            'title' => '給与明細管理',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'items' => $items,
            'employeeOptions' => $employeeOptions,
            'selectedEmployeeId' => $employeeId,
            'pagination' => $pagination,
            'returnTo' => $this->buildListUrl($page, $employeeId),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $admin = $this->requireAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $recordId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($recordId <= 0) {
            Flash::add('error', '明細が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips');
        }

        $record = $this->repository->findPayrollRecordById($recordId);
        if ($record === null || (int)$record['tenant_id'] !== (int)$admin['tenant_id']) {
            Flash::add('error', '明細が見つからないか、アクセス権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips');
        }

        $realBase = realpath($this->storageDir);
        $realPath = realpath((string)$record['stored_file_path']);
        if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            Flash::add('error', 'ファイルのパス検証に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips');
        }
        if (!is_file($realPath) || !is_readable($realPath)) {
            Flash::add('error', '明細ファイルを開けませんでした。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips');
        }

        $fileName = basename((string)$record['original_file_name']);
        $fileName = str_replace(['"', "\r", "\n"], '', $fileName);
        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            Flash::add('error', '明細ファイルを開けませんでした。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips');
        }

        $size = filesize($realPath);
        $stream = new Stream($handle);
        $disposition = sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fileName, rawurlencode($fileName));
        $response = $response->withBody($stream);
        if ($size !== false) {
            $response = $response->withHeader('Content-Length', (string)$size);
        }
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', $disposition);
    }

    public function resend(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $returnTo = $this->sanitizeReturnTo((string)($body['return_to'] ?? ''));
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }

        try {
            $admin = $this->requireAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $recordId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($recordId <= 0) {
            Flash::add('error', '明細が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }

        // レートリミット（多重送信防止）
        if (!RateLimiter::allow("payslip_resend:tenant:{$admin['tenant_id']}", 20, 300)) {
            Flash::add('error', '送信回数の上限に達しました（テナント）。時間をおいて再試行してください。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }
        if (!RateLimiter::allow("payslip_resend:record:{$recordId}", 3, 300)) {
            Flash::add('error', '同じ明細の再送はしばらくお待ちください。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }

        $record = $this->repository->findPayrollRecordById($recordId);
        if ($record === null || (int)$record['tenant_id'] !== (int)$admin['tenant_id']) {
            Flash::add('error', '明細が見つからないか、アクセス権限がありません。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }

        $employee = $this->repository->findUserById((int)$record['employee_id']);
        if (
            $employee === null
            || (int)($employee['tenant_id'] ?? 0) !== (int)$admin['tenant_id']
            || (string)($employee['role'] ?? '') !== 'employee'
        ) {
            Flash::add('error', '従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }
        if (($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '無効化された従業員には送信できません。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }
        $employeeEmail = trim((string)($employee['email'] ?? ''));
        if ($employeeEmail === '') {
            Flash::add('error', '従業員のメールアドレスが設定されていません。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }

        $brand = $_ENV['APP_BRAND_NAME'] ?? 'Attendly';
        $sentOn = $record['sent_on'] instanceof \DateTimeInterface
            ? $record['sent_on']->setTimezone(AppTime::timezone())->format('Y-m-d')
            : 'N/A';
        $subject = sprintf('【%s】給与明細のご案内（再送・%s）', $brand, $sentOn);
        $portalUrl = $this->buildPayrollPortalUrl();
        $employeeName = trim((string)($employee['name'] ?? ''));
        if ($employeeName === '') {
            $employeeName = trim((string)($employee['last_name'] ?? '') . ' ' . (string)($employee['first_name'] ?? ''));
        }
        if ($employeeName === '') {
            $employeeName = '従業員';
        }
        $lines = [
            "{$employeeName} 様",
            '',
            "{$brand} から給与明細のご案内（再送）です。",
            'ログイン後、以下のページから明細を確認してください。',
            $portalUrl !== null ? $portalUrl : '（ポータルURL未設定のため、ログイン後に「給与明細」画面を開いてください）',
            '',
            '対象の支給日: ' . $sentOn,
            '',
            'このメールに心当たりがない場合は、管理者へご連絡ください。',
        ];
        $bodyText = implode("\n", $lines);

        try {
            $this->mailer->send($employeeEmail, $subject, $bodyText);
        } catch (\Throwable $e) {
            error_log('Failed to resend payslip: ' . $e->getMessage());
            Flash::add('error', '再送に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', $returnTo);
        }

        Flash::add('success', '給与明細の案内メールを再送しました。');
        return $response->withStatus(303)->withHeader('Location', $returnTo);
    }

    private function sanitizeReturnTo(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '/admin/payslips';
        }
        if (!str_starts_with($value, '/admin/payslips')) {
            return '/admin/payslips';
        }
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            return '/admin/payslips';
        }
        return $value;
    }

    private function buildListUrl(int $page, ?int $employeeId): string
    {
        $page = max(1, $page);
        $params = ['page' => (string)$page];
        if ($employeeId !== null && $employeeId > 0) {
            $params['employee_id'] = (string)$employeeId;
        }
        return '/admin/payslips?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function buildPayrollPortalUrl(): ?string
    {
        $base = trim((string)($_ENV['APP_BASE_URL'] ?? ''));
        if ($base === '') {
            return null;
        }
        $base = rtrim($base, '/');
        return $base . '/payrolls';
    }

    private function buildEmployeeLabel(?string $lastName, ?string $firstName, ?string $email, int $employeeId, ?string $status): string
    {
        $name = trim((string)$lastName . ' ' . (string)$firstName);
        $emailValue = trim((string)$email);
        if ($name !== '' && $emailValue !== '') {
            $label = "{$name} ({$emailValue})";
        } elseif ($emailValue !== '') {
            $label = $emailValue;
        } elseif ($name !== '') {
            $label = $name;
        } else {
            $label = 'ID: ' . (string)$employeeId;
        }
        if ($status !== null && $status !== '' && $status !== 'active') {
            $label .= '（' . $status . '）';
        }
        return $label;
    }

    private function sanitizePage(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => 1]]);
        if ($page === false) {
            $page = 1;
        }
        return max(1, (int)$page);
    }

    private function sanitizeOptionalEmployeeId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $employeeId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        if ($employeeId === false) {
            return null;
        }
        $employeeId = (int)$employeeId;
        return $employeeId > 0 ? $employeeId : null;
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
}
