<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Services\TimesheetExportService;
use Attendly\Services\SignedDownloadService;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class TimesheetExportController
{
    public function __construct(
        private ?View $view = null,
        private ?Repository $repository = null,
        private ?TimesheetExportService $service = null,
        private ?SignedDownloadService $signedDownloads = null
    ) {
        $this->repository = $this->repository ?? new Repository();
        $this->service = $this->service ?? new TimesheetExportService($this->repository);
        $this->signedDownloads = $this->signedDownloads ?? new SignedDownloadService($this->repository);
        $this->view = $this->view ?? new View(dirname(__DIR__, 2) . '/views');
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            return $this->error($response, 403, 'forbidden');
        }
        $data = (array)$request->getParsedBody();
        $export = $this->performExport($data, $user, $response, false);
        if ($export instanceof ResponseInterface) {
            return $export;
        }

        $signed = $this->issueSignedDownload($export, $user['id'] ?? null);
        if ($signed === null) {
            return $this->error($response, 500, 'signed_url_failed');
        }
        $payload = [
            'ok' => true,
            'url' => $signed['url'],
            'expires_at' => $signed['expires_at']->format(DATE_ATOM),
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function showForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $employees = $this->repository->listEmployeesForTenant($user['tenant_id'], 200);
        $html = $this->view->renderWithLayout('admin_timesheets_export', [
            'title' => '勤怠エクスポート',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'employees' => $employees,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function exportFromForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }
        try {
            $user = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $data = (array)$request->getParsedBody();
        $export = $this->performExport($data, $user, $response, true);
        if ($export instanceof ResponseInterface) {
            return $export;
        }

        $signed = $this->issueSignedDownload($export, $user['id'] ?? null);
        if ($signed === null) {
            Flash::add('error', '署名URLの発行に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }
        return $response->withStatus(303)->withHeader('Location', $signed['url']);
    }

    public function exportMonthlyFromDashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        try {
            $admin = $this->requireAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $data = (array)$request->getParsedBody();
        $employeeId = isset($data['userId']) ? (int)$data['userId'] : (int)($data['user_id'] ?? 0);
        $year = (int)($data['year'] ?? 0);
        $month = (int)($data['month'] ?? 0);
        $format = strtolower(trim((string)($data['format'] ?? 'excel')));
        if (!in_array($format, ['excel', 'pdf', 'csv'], true)) {
            $format = 'excel';
        }

        if ($employeeId <= 0) {
            Flash::add('error', '従業員を選択してください。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            Flash::add('error', '出力対象の年月が正しくありません。');
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
        if (($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '無効化された従業員のデータはエクスポートできません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $monthStart = new \DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            AppTime::timezone()
        );
        $monthEndExclusive = $monthStart->modify('+1 month');
        if ($monthEndExclusive === false) {
            Flash::add('error', '出力対象の年月が正しくありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $monthEnd = $monthEndExclusive->modify('-1 second');
        if ($monthEnd === false) {
            Flash::add('error', '出力対象の年月が正しくありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        try {
            $result = $this->service->export([
                'tenant_id' => $admin['tenant_id'],
                'start' => $monthStart,
                'end' => $monthEnd,
                'user_id' => $employeeId,
                'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Asia/Tokyo',
                'format' => $format,
            ]);
        } catch (\Throwable) {
            Flash::add('error', 'エクスポートに失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $signed = $this->issueSignedDownload($result, $admin['id'] ?? null);
        if ($signed === null) {
            Flash::add('error', '署名URLの発行に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        return $response->withStatus(303)->withHeader('Location', $signed['url']);
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

    private function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }

    /**
     * @return array{path:string,filename:string,content_type:string}|ResponseInterface
     */
    private function performExport(array $data, array $user, ResponseInterface $response, bool $useFlash = false): array|ResponseInterface
    {
        $startStr = trim((string)($data['start_date'] ?? ''));
        $endStr = trim((string)($data['end_date'] ?? ''));
        $format = strtolower(trim((string)($data['format'] ?? 'excel')));
        if (!in_array($format, ['excel', 'pdf', 'csv'], true)) {
            $format = 'excel';
        }
        if ($startStr === '' || $endStr === '') {
            return $useFlash
                ? $this->flashError('開始日と終了日を入力してください。', $response)
                : $this->error($response, 400, 'start_date and end_date are required');
        }
        $startDate = AppTime::parseDate($startStr);
        $endDate = AppTime::parseDate($endStr);
        if ($startDate === null || $endDate === null) {
            return $useFlash
                ? $this->flashError('日付の形式が不正です。', $response)
                : $this->error($response, 400, 'invalid date format');
        }
        $start = $startDate->setTime(0, 0, 0);
        $end = $endDate->setTime(23, 59, 59);
        if ($end < $start) {
            return $useFlash
                ? $this->flashError('終了日は開始日以降を指定してください。', $response)
                : $this->error($response, 400, 'end_date must be after start_date');
        }

        $userId = null;
        if (!empty($data['user_id'])) {
            $userId = (int)$data['user_id'];
            $targetUser = $this->repository->findUserById($userId);
            if ($targetUser === null || (int)$targetUser['tenant_id'] !== $user['tenant_id']) {
                return $useFlash
                    ? $this->flashError('従業員の選択が不正です。', $response)
                    : $this->error($response, 403, 'user does not belong to your tenant');
            }
        }

        try {
            $result = $this->service->export([
                'tenant_id' => $user['tenant_id'],
                'start' => $start,
                'end' => $end,
                'user_id' => $userId,
                'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Asia/Tokyo',
                'format' => $format,
            ]);
        } catch (\Throwable $e) {
            return $useFlash
                ? $this->flashError('エクスポートに失敗しました。', $response)
                : $this->error($response, 400, 'export_failed');
        }

        return [
            'path' => $result['path'],
            'filename' => $result['filename'],
            'content_type' => $result['content_type'],
        ];
    }

    private function issueSignedDownload(array $export, ?int $createdBy): ?array
    {
        $allowedDir = realpath(dirname(__DIR__, 2) . '/storage/exports');
        $actualPath = realpath((string)$export['path']);
        if ($allowedDir === false || $actualPath === false || !str_starts_with($actualPath, $allowedDir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!is_file($actualPath) || !is_readable($actualPath)) {
            return null;
        }
        $contentType = $this->validateContentType((string)($export['content_type'] ?? ''));
        return $this->signedDownloads->issueForExport($actualPath, (string)$export['filename'], $contentType, $createdBy);
    }

    private function validateContentType(string $contentType): string
    {
        $normalized = strtolower(trim($contentType));
        if ($normalized === '') {
            return 'application/octet-stream';
        }

        $allowed = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv; charset=utf-8',
            'text/csv',
            'application/octet-stream',
        ];
        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }
        return 'application/octet-stream';
    }

    private function flashError(string $message, ResponseInterface $response): ResponseInterface
    {
        Flash::add('error', $message);
        return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
    }
}
