<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminSessionBreaksController
{
    private const SESSION_YEAR_MIN = 2000;
    private const SESSION_YEAR_MAX = 2100;

    private Repository $repository;

    public function __construct(private View $view, ?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $admin = $this->requireTenantAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $employeeId = isset($args['userId']) ? (int)$args['userId'] : 0;
        $sessionId = isset($args['sessionId']) ? (int)$args['sessionId'] : 0;
        if ($employeeId <= 0 || $sessionId <= 0) {
            Flash::add('error', '対象が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null || ($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $session = $this->repository->findWorkSessionById($sessionId);
        if ($session === null || (int)$session['user_id'] !== $employeeId) {
            Flash::add('error', '勤務記録が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $breaks = [];
        try {
            $breaks = $this->repository->listWorkSessionBreaksBySessionId($sessionId);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                Flash::add('error', '休憩機能が未設定です。DBスキーマを適用してください。');
                return $response->withStatus(303)->withHeader('Location', '/dashboard');
            }
            throw $e;
        }

        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());

        $html = $this->view->renderWithLayout('admin_session_breaks', [
            'title' => '休憩区間の編集',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'timezone' => AppTime::timezone()->getName(),
            'employee' => [
                'id' => $employee['id'],
                'username' => $employee['username'],
                'email' => $employee['email'],
            ],
            'session' => [
                'id' => $session['id'],
                'startDisplay' => $session['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'endDisplay' => $session['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i') ?? '記録中',
                'startInput' => $session['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d\\TH:i'),
                'endInput' => $session['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d\\TH:i') ?? '',
                'isOpen' => $session['end_time'] === null,
            ],
            'breaks' => $breaks,
            'targetYear' => $targetYear,
            'targetMonth' => $targetMonth,
            'queryString' => $queryString,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function add(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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
        $sessionId = isset($args['sessionId']) ? (int)$args['sessionId'] : 0;
        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $redirect = "/admin/employees/{$employeeId}/sessions/{$sessionId}/breaks{$queryString}";

        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null || ($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        $session = $this->repository->findWorkSessionById($sessionId);
        if ($session === null || (int)$session['user_id'] !== $employeeId) {
            Flash::add('error', '勤務記録が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        $body = (array)$request->getParsedBody();
        $breakType = strtolower(trim((string)($body['breakType'] ?? 'rest')));
        if (!in_array($breakType, ['rest', 'other'], true)) {
            $breakType = 'rest';
        }
        $isCompensated = !empty($body['isCompensated']);
        $note = trim((string)($body['note'] ?? ''));
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && mb_strlen($note, 'UTF-8') > 255) {
            Flash::add('error', 'メモは255文字以内で入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        $startDt = $this->parseDateTimeInput((string)($body['startTime'] ?? ''));
        $endDt = $this->parseDateTimeInput((string)($body['endTime'] ?? ''));
        if ($startDt === null) {
            Flash::add('error', '休憩開始日時を入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        if (!$this->isDateWithinAllowedRange($startDt) || ($endDt !== null && !$this->isDateWithinAllowedRange($endDt))) {
            Flash::add('error', '2000年から2100年までの日時を入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        if ($endDt !== null && $endDt <= $startDt) {
            Flash::add('error', '終了日時は開始日時より後を指定してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        if (!$this->isBreakWithinSession($session, $startDt, $endDt)) {
            Flash::add('error', '休憩区間は勤務セッションの範囲内で入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        try {
            $existing = $this->repository->listWorkSessionBreaksBySessionId($sessionId);
            if ($this->hasOverlappingBreak($existing, $startDt, $endDt, null, $session['end_time'] ?? AppTime::now())) {
                Flash::add('error', '他の休憩区間と時間が重複しています。');
                return $response->withStatus(303)->withHeader('Location', $redirect);
            }
            $this->repository->createWorkSessionBreak([
                'work_session_id' => $sessionId,
                'break_type' => $breakType,
                'is_compensated' => $isCompensated,
                'start_time' => $startDt,
                'end_time' => $endDt,
                'note' => $note,
            ]);
            Flash::add('success', '休憩区間を追加しました。');
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                Flash::add('error', '休憩機能が未設定です。DBスキーマを適用してください。');
            } else {
                throw $e;
            }
        } catch (\Throwable) {
            Flash::add('error', '休憩区間の追加に失敗しました。');
        }

        return $response->withStatus(303)->withHeader('Location', $redirect);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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
        $sessionId = isset($args['sessionId']) ? (int)$args['sessionId'] : 0;
        $breakId = isset($args['breakId']) ? (int)$args['breakId'] : 0;
        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $redirect = "/admin/employees/{$employeeId}/sessions/{$sessionId}/breaks{$queryString}";

        if ($employeeId <= 0 || $sessionId <= 0 || $breakId <= 0) {
            Flash::add('error', '対象が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null || ($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        $session = $this->repository->findWorkSessionById($sessionId);
        if ($session === null || (int)$session['user_id'] !== $employeeId) {
            Flash::add('error', '勤務記録が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        $break = $this->repository->findWorkSessionBreakById($breakId);
        if ($break === null || (int)$break['work_session_id'] !== $sessionId) {
            Flash::add('error', '休憩区間が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        $body = (array)$request->getParsedBody();
        $breakType = strtolower(trim((string)($body['breakType'] ?? 'rest')));
        if (!in_array($breakType, ['rest', 'other'], true)) {
            $breakType = 'rest';
        }
        $isCompensated = !empty($body['isCompensated']);
        $note = trim((string)($body['note'] ?? ''));
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && mb_strlen($note, 'UTF-8') > 255) {
            Flash::add('error', 'メモは255文字以内で入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        $startDt = $this->parseDateTimeInput((string)($body['startTime'] ?? ''));
        $endDt = $this->parseDateTimeInput((string)($body['endTime'] ?? ''));
        if ($startDt === null) {
            Flash::add('error', '休憩開始日時を入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        if (!$this->isDateWithinAllowedRange($startDt) || ($endDt !== null && !$this->isDateWithinAllowedRange($endDt))) {
            Flash::add('error', '2000年から2100年までの日時を入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        if ($endDt !== null && $endDt <= $startDt) {
            Flash::add('error', '終了日時は開始日時より後を指定してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        if (!$this->isBreakWithinSession($session, $startDt, $endDt)) {
            Flash::add('error', '休憩区間は勤務セッションの範囲内で入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        try {
            $existing = $this->repository->listWorkSessionBreaksBySessionId($sessionId);
            if ($this->hasOverlappingBreak($existing, $startDt, $endDt, $breakId, $session['end_time'] ?? AppTime::now())) {
                Flash::add('error', '他の休憩区間と時間が重複しています。');
                return $response->withStatus(303)->withHeader('Location', $redirect);
            }
            $this->repository->updateWorkSessionBreak($breakId, [
                'break_type' => $breakType,
                'is_compensated' => $isCompensated,
                'start_time' => $startDt,
                'end_time' => $endDt,
                'note' => $note,
            ]);
            Flash::add('success', '休憩区間を更新しました。');
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                Flash::add('error', '休憩機能が未設定です。DBスキーマを適用してください。');
            } else {
                throw $e;
            }
        } catch (\Throwable) {
            Flash::add('error', '休憩区間の更新に失敗しました。');
        }

        return $response->withStatus(303)->withHeader('Location', $redirect);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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
        $sessionId = isset($args['sessionId']) ? (int)$args['sessionId'] : 0;
        $breakId = isset($args['breakId']) ? (int)$args['breakId'] : 0;
        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $redirect = "/admin/employees/{$employeeId}/sessions/{$sessionId}/breaks{$queryString}";

        if ($employeeId <= 0 || $sessionId <= 0 || $breakId <= 0) {
            Flash::add('error', '対象が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null || ($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        $session = $this->repository->findWorkSessionById($sessionId);
        if ($session === null || (int)$session['user_id'] !== $employeeId) {
            Flash::add('error', '勤務記録が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }
        $break = $this->repository->findWorkSessionBreakById($breakId);
        if ($break === null || (int)$break['work_session_id'] !== $sessionId) {
            Flash::add('error', '休憩区間が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirect);
        }

        try {
            $this->repository->deleteWorkSessionBreak($breakId);
            Flash::add('success', '休憩区間を削除しました。');
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                Flash::add('error', '休憩機能が未設定です。DBスキーマを適用してください。');
            } else {
                throw $e;
            }
        } catch (\Throwable) {
            Flash::add('error', '休憩区間の削除に失敗しました。');
        }

        return $response->withStatus(303)->withHeader('Location', $redirect);
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

    private function findEmployeeForAdmin(int $tenantId, int $employeeId): ?array
    {
        $employee = $this->repository->findUserById($employeeId);
        if ($employee === null || ($employee['role'] ?? '') !== 'employee') {
            return null;
        }
        if ((int)$employee['tenant_id'] !== $tenantId) {
            return null;
        }
        return $employee;
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }

    /**
     * @return array{0:int,1:int,2:string}
     */
    private function normalizeYearMonth(array $query): array
    {
        $now = AppTime::now();
        $year = isset($query['year']) ? (int)$query['year'] : (int)$now->format('Y');
        $month = isset($query['month']) ? (int)$query['month'] : (int)$now->format('n');
        if ($year < self::SESSION_YEAR_MIN || $year > self::SESSION_YEAR_MAX) {
            $year = (int)$now->format('Y');
        }
        if ($month < 1 || $month > 12) {
            $month = (int)$now->format('n');
        }
        return [$year, $month, sprintf('?year=%d&month=%d', $year, $month)];
    }

    private function parseDateTimeInput(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, AppTime::timezone());
        if ($dt instanceof \DateTimeImmutable) {
            return $dt;
        }
        return AppTime::parseDateTime($value);
    }

    private function isDateWithinAllowedRange(\DateTimeImmutable $dt): bool
    {
        $year = (int)$dt->format('Y');
        return $year >= self::SESSION_YEAR_MIN && $year <= self::SESSION_YEAR_MAX;
    }

    private function isBreakWithinSession(array $session, \DateTimeImmutable $breakStart, ?\DateTimeImmutable $breakEnd): bool
    {
        if (empty($session['start_time']) || !$session['start_time'] instanceof \DateTimeImmutable) {
            return false;
        }
        $sessionStart = $session['start_time'];
        $sessionEnd = isset($session['end_time']) && $session['end_time'] instanceof \DateTimeImmutable ? $session['end_time'] : null;

        if ($breakStart < $sessionStart) {
            return false;
        }
        if ($breakEnd !== null) {
            if ($breakEnd <= $breakStart) {
                return false;
            }
            if ($sessionEnd !== null && $breakEnd > $sessionEnd) {
                return false;
            }
        } else {
            if ($sessionEnd !== null) {
                return false;
            }
        }

        return true;
    }

    private function hasOverlappingBreak(array $existingBreaks, \DateTimeImmutable $candidateStart, ?\DateTimeImmutable $candidateEnd, ?int $excludeBreakId, \DateTimeImmutable $openEnd): bool
    {
        $cEnd = $candidateEnd ?? $openEnd;
        foreach ($existingBreaks as $break) {
            if (!is_array($break) || empty($break['id']) || empty($break['start_time'])) {
                continue;
            }
            $bid = (int)$break['id'];
            if ($excludeBreakId !== null && $bid === $excludeBreakId) {
                continue;
            }
            if (!$break['start_time'] instanceof \DateTimeImmutable) {
                continue;
            }
            $bStart = $break['start_time'];
            $bEnd = isset($break['end_time']) && $break['end_time'] instanceof \DateTimeImmutable ? $break['end_time'] : $openEnd;
            if ($bEnd <= $bStart) {
                continue;
            }
            if ($candidateStart < $bEnd && $cEnd > $bStart) {
                return true;
            }
        }
        return false;
    }
}
