<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Services\TimesheetExportService;
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
        private ?TimesheetExportService $service = null
    ) {
        $this->repository = $this->repository ?? new Repository();
        $this->service = $this->service ?? new TimesheetExportService($this->repository);
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
        $startStr = trim((string)($data['start_date'] ?? ''));
        $endStr = trim((string)($data['end_date'] ?? ''));
        if ($startStr === '' || $endStr === '') {
            return $this->error($response, 400, 'start_date and end_date are required');
        }
        $startDate = AppTime::parseDate($startStr);
        $endDate = AppTime::parseDate($endStr);
        if ($startDate === null || $endDate === null) {
            return $this->error($response, 400, 'invalid date format');
        }
        $start = $startDate->setTime(0, 0, 0);
        $end = $endDate->setTime(23, 59, 59);
        if ($end < $start) {
            return $this->error($response, 400, 'end_date must be after start_date');
        }
        $userId = null;
        if (!empty($data['user_id'])) {
            $userId = (int)$data['user_id'];
        }
        try {
            $result = $this->service->export([
                'tenant_id' => $user['tenant_id'],
                'start' => $start,
                'end' => $end,
                'user_id' => $userId,
                'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Asia/Tokyo',
            ]);
        } catch (\Throwable $e) {
            return $this->error($response, 400, 'export_failed');
        }

        $allowedDir = realpath(dirname(__DIR__, 2) . '/storage/exports');
        $actualPath = realpath($result['path']);
        if ($allowedDir === false || $actualPath === false || !str_starts_with($actualPath, $allowedDir . DIRECTORY_SEPARATOR)) {
            return $this->error($response, 500, 'invalid_export_path');
        }
        if (!is_file($actualPath) || !is_readable($actualPath)) {
            return $this->error($response, 500, 'failed_to_read_export');
        }
        $csv = file_get_contents($actualPath);
        if ($csv === false) {
            return $this->error($response, 500, 'failed_to_read_export');
        }
        $disposition = sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $result['filename'], rawurlencode($result['filename']));
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', $disposition);
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
        $startStr = trim((string)($data['start_date'] ?? ''));
        $endStr = trim((string)($data['end_date'] ?? ''));
        if ($startStr === '' || $endStr === '') {
            Flash::add('error', '開始日と終了日を入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }
        $startDate = AppTime::parseDate($startStr);
        $endDate = AppTime::parseDate($endStr);
        if ($startDate === null || $endDate === null) {
            Flash::add('error', '日付の形式が不正です。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }
        $start = $startDate->setTime(0, 0, 0);
        $end = $endDate->setTime(23, 59, 59);
        if ($end < $start) {
            Flash::add('error', '終了日は開始日以降を指定してください。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }
        $userId = null;
        if (!empty($data['user_id'])) {
            $userId = (int)$data['user_id'];
        }
        try {
            $result = $this->service->export([
                'tenant_id' => $user['tenant_id'],
                'start' => $start,
                'end' => $end,
                'user_id' => $userId,
                'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Asia/Tokyo',
            ]);
        } catch (\Throwable $e) {
            Flash::add('error', 'エクスポートに失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }

        $allowedDir = realpath(dirname(__DIR__, 2) . '/storage/exports');
        $actualPath = realpath($result['path']);
        if ($allowedDir === false || $actualPath === false || !str_starts_with($actualPath, $allowedDir . DIRECTORY_SEPARATOR)) {
            Flash::add('error', 'エクスポートファイルのパス検証に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }
        if (!is_file($actualPath) || !is_readable($actualPath)) {
            Flash::add('error', 'エクスポートファイルの読み込みに失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }
        $csv = file_get_contents($actualPath);
        if ($csv === false) {
            Flash::add('error', 'エクスポートファイルの読み込みに失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/admin/timesheets/export');
        }
        $disposition = sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $result['filename'], rawurlencode($result['filename']));
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', $disposition);
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
}
