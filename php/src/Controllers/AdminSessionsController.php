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

final class AdminSessionsController
{
    private const SESSION_YEAR_MIN = 2000;
    private const SESSION_YEAR_MAX = 2100;
    private const OVERLAP_ERROR_MESSAGE = '他の勤怠記録と時間が重複しています。修正対象の時間帯を見直してください。';
    private const YEAR_RANGE_MESSAGE = '2000年から2100年までの日時を入力してください。';

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
        if ($employeeId <= 0) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        if (($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '無効化された従業員の勤怠記録にはアクセスできません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $monthStart = new \DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $targetYear, $targetMonth),
            AppTime::timezone()
        );
        $monthEndExclusive = $monthStart->modify('+1 month');
        $monthEnd = $monthEndExclusive->modify('-1 second');

        $records = $this->repository->listWorkSessionsByUserBetween($employeeId, $monthStart, $monthEnd);
        $sessions = array_map(function (array $session): array {
            $durationMinutes = null;
            if ($session['end_time'] !== null) {
                $durationMinutes = $this->diffMinutes($session['start_time'], $session['end_time']);
            }
            return [
                'id' => $session['id'],
                'startInput' => $session['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d\TH:i'),
                'endInput' => $session['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d\TH:i') ?? '',
                'startDisplay' => $session['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'endDisplay' => $session['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i') ?? '記録中',
                'formattedMinutes' => $durationMinutes !== null ? $this->formatMinutesToHM($durationMinutes) : '--',
            ];
        }, $records);

        $overlapping = $this->repository->listWorkSessionsByUserOverlapping($employeeId, $monthStart, $monthEndExclusive);
        $totalMinutes = $this->calculateMinutesWithinRange($overlapping, $monthStart, $monthEndExclusive);
        $monthlySummary = [
            'totalMinutes' => $totalMinutes,
            'formattedTotal' => $this->formatMinutesToHM($totalMinutes),
        ];

        $html = $this->view->renderWithLayout('admin_sessions', [
            'title' => '勤務記録訂正',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'employee' => [
                'id' => $employee['id'],
                'username' => $employee['username'],
                'email' => $employee['email'],
            ],
            'sessions' => $sessions,
            'targetYear' => $targetYear,
            'targetMonth' => $targetMonth,
            'monthlySummary' => $monthlySummary,
            'queryString' => $queryString,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function add(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $employeeId = isset($args['userId']) ? (int)$args['userId'] : 0;
        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $redirectUrl = $employeeId > 0 ? "/admin/employees/{$employeeId}/sessions{$queryString}" : '/dashboard';

        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }
        try {
            $admin = $this->requireTenantAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        if (($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '無効化された従業員の勤怠記録は編集できません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $body = (array)$request->getParsedBody();
        $startInput = trim((string)($body['startTime'] ?? ''));
        $endInput = trim((string)($body['endTime'] ?? ''));
        $start = $this->parseDateTimeInput($startInput);
        $end = $this->parseDateTimeInput($endInput);
        if ($start === null || $end === null) {
            Flash::add('error', '開始と終了の日時を正しく入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }
        if (!$this->isDateWithinAllowedRange($start) || !$this->isDateWithinAllowedRange($end)) {
            Flash::add('error', self::YEAR_RANGE_MESSAGE);
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }
        if ($this->diffMinutes($start, $end) <= 0) {
            Flash::add('error', '終了時刻は開始時刻より後に設定してください。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }
        if ($this->repository->hasOverlappingWorkSessions($employeeId, $start, $end, null)) {
            Flash::add('error', self::OVERLAP_ERROR_MESSAGE);
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        try {
            $this->repository->createWorkSessionWithEnd($employeeId, $start, $end);
        } catch (\Throwable) {
            Flash::add('error', '勤務記録の追加に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        Flash::add('success', '勤務記録を追加しました。');
        return $response->withStatus(303)->withHeader('Location', $redirectUrl);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $employeeId = isset($args['userId']) ? (int)$args['userId'] : 0;
        $sessionId = isset($args['sessionId']) ? (int)$args['sessionId'] : 0;
        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $redirectUrl = $employeeId > 0 ? "/admin/employees/{$employeeId}/sessions{$queryString}" : '/dashboard';

        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }
        try {
            $admin = $this->requireTenantAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        if (($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '無効化された従業員の勤怠記録は編集できません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $sessionRecord = $sessionId > 0 ? $this->repository->findWorkSessionById($sessionId) : null;
        if ($sessionRecord === null || (int)$sessionRecord['user_id'] !== $employeeId) {
            Flash::add('error', '該当する勤務記録が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        $body = (array)$request->getParsedBody();
        $startInput = trim((string)($body['startTime'] ?? ''));
        $endInput = trim((string)($body['endTime'] ?? ''));
        $start = $this->parseDateTimeInput($startInput);
        if ($start === null) {
            Flash::add('error', '開始時刻を正しく入力してください。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }
        if (!$this->isDateWithinAllowedRange($start)) {
            Flash::add('error', self::YEAR_RANGE_MESSAGE);
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        $end = null;
        if ($endInput !== '') {
            $end = $this->parseDateTimeInput($endInput);
            if ($end === null) {
                Flash::add('error', '終了時刻を正しく入力してください。');
                return $response->withStatus(303)->withHeader('Location', $redirectUrl);
            }
            if (!$this->isDateWithinAllowedRange($end)) {
                Flash::add('error', self::YEAR_RANGE_MESSAGE);
                return $response->withStatus(303)->withHeader('Location', $redirectUrl);
            }
            if ($this->diffMinutes($start, $end) <= 0) {
                Flash::add('error', '終了時刻は開始時刻より後に設定してください。');
                return $response->withStatus(303)->withHeader('Location', $redirectUrl);
            }
        }

        if ($this->repository->hasOverlappingWorkSessions($employeeId, $start, $end, $sessionRecord['id'])) {
            Flash::add('error', self::OVERLAP_ERROR_MESSAGE);
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        try {
            $this->repository->updateWorkSessionTimes($sessionRecord['id'], $start, $end);
        } catch (\Throwable) {
            Flash::add('error', '勤務記録の更新に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        Flash::add('success', '勤務記録を更新しました。');
        return $response->withStatus(303)->withHeader('Location', $redirectUrl);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $employeeId = isset($args['userId']) ? (int)$args['userId'] : 0;
        $sessionId = isset($args['sessionId']) ? (int)$args['sessionId'] : 0;
        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $redirectUrl = $employeeId > 0 ? "/admin/employees/{$employeeId}/sessions{$queryString}" : '/dashboard';
        $confirmUrl = ($employeeId > 0 && $sessionId > 0)
            ? "/admin/employees/{$employeeId}/sessions/{$sessionId}/delete/confirm{$queryString}"
            : $redirectUrl;

        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }
        $body = (array)$request->getParsedBody();
        $confirmed = strtolower(trim((string)($body['confirmed'] ?? '')));
        if ($confirmed !== 'yes') {
            return $response->withStatus(303)->withHeader('Location', $confirmUrl);
        }
        try {
            $admin = $this->requireTenantAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        if (($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '無効化された従業員の勤怠記録は削除できません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $sessionRecord = $sessionId > 0 ? $this->repository->findWorkSessionById($sessionId) : null;
        if ($sessionRecord === null || (int)$sessionRecord['user_id'] !== $employeeId) {
            Flash::add('error', '該当する勤務記録が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        try {
            $this->repository->deleteWorkSession($sessionRecord['id']);
        } catch (\Throwable) {
            Flash::add('error', '勤務記録の削除に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        Flash::add('success', '勤務記録を削除しました。');
        return $response->withStatus(303)->withHeader('Location', $redirectUrl);
    }

    public function confirmDelete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $admin = $this->requireTenantAdmin($request);
        } catch (\Throwable) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $employeeId = isset($args['userId']) ? (int)$args['userId'] : 0;
        $sessionId = isset($args['sessionId']) ? (int)$args['sessionId'] : 0;
        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $redirectUrl = $employeeId > 0 ? "/admin/employees/{$employeeId}/sessions{$queryString}" : '/dashboard';

        if ($employeeId <= 0 || $sessionId <= 0) {
            Flash::add('error', '対象の勤務記録が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }
        $employee = $this->findEmployeeForAdmin($admin['tenant_id'], $employeeId);
        if ($employee === null) {
            Flash::add('error', '対象の従業員が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        if (($employee['status'] ?? '') !== 'active') {
            Flash::add('error', '無効化された従業員の勤怠記録は削除できません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $sessionRecord = $this->repository->findWorkSessionById($sessionId);
        if ($sessionRecord === null || (int)$sessionRecord['user_id'] !== $employeeId) {
            Flash::add('error', '該当する勤務記録が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', $redirectUrl);
        }

        $html = $this->view->renderWithLayout('admin_session_delete_confirm', [
            'title' => '勤務記録削除の確認',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'employee' => [
                'id' => $employee['id'],
                'username' => $employee['username'],
                'email' => $employee['email'],
            ],
            'session' => [
                'id' => (int)$sessionRecord['id'],
                'startDisplay' => $sessionRecord['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'endDisplay' => $sessionRecord['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i') ?? '記録中',
            ],
            'queryString' => $queryString,
            'redirectUrl' => $redirectUrl,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, AppTime::timezone());
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

    private function diffMinutes(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        if ($end <= $start) {
            return 0;
        }
        return (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
    }

    private function calculateMinutesWithinRange(array $sessions, \DateTimeImmutable $start, \DateTimeImmutable $endExclusive): int
    {
        $minutes = 0;
        foreach ($sessions as $session) {
            if (empty($session['end_time']) || !$session['end_time'] instanceof \DateTimeImmutable) {
                continue;
            }
            $sessionStart = $session['start_time'];
            $sessionEnd = $session['end_time'];
            $boundedStart = $sessionStart < $start ? $start : $sessionStart;
            $boundedEnd = $sessionEnd > $endExclusive ? $endExclusive : $sessionEnd;
            if ($boundedEnd <= $boundedStart) {
                continue;
            }
            $minutes += (int)floor(($boundedEnd->getTimestamp() - $boundedStart->getTimestamp()) / 60);
        }
        return $minutes;
    }

    private function formatMinutesToHM(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0分';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours === 0) {
            return "{$rest}分";
        }
        return sprintf('%d時間%02d分', $hours, $rest);
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }
}
