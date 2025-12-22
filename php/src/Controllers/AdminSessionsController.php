<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Services\BreakComplianceService;
use Attendly\Support\AppTime;
use Attendly\Support\BreakFeature;
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
    private bool $breaksEnabled;

    public function __construct(private View $view, ?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->breaksEnabled = BreakFeature::isEnabled();
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
        $breaksBySessionId = $this->fetchBreaksBySessionId($records);
        $this->setBreaksBySessionIdCache($breaksBySessionId);
        $edgeMinutes = BreakComplianceService::edgeBreakWarningMinutes();
        $breaksEnabled = $this->breaksEnabled;
        $sessions = array_map(function (array $session) use ($edgeMinutes, $breaksEnabled): array {
            $durationMinutes = null;
            $breakMinutesValue = null;
            $breakMinutesDisplay = '--';
            $breakShortageMinutes = 0;
            $edgeBreakWarning = false;
            if ($session['end_time'] !== null) {
                $sessionBreaks = $this->breaksForSession((int)$session['id']);
                $grossMinutes = $this->diffMinutes($session['start_time'], $session['end_time']);
                if ($breaksEnabled) {
                    $breakMinutesValue = $this->sumBreakExcludedMinutes(
                        $sessionBreaks,
                        $session['start_time'],
                        $session['end_time']
                    );
                    $breakMinutesDisplay = $breakMinutesValue . '分';
                    $durationMinutes = max(0, $grossMinutes - $breakMinutesValue);
                    $breakShortageMinutes = BreakComplianceService::breakShortageMinutes($durationMinutes, $breakMinutesValue);
                    $edgeBreakWarning = BreakComplianceService::hasEdgeBreakWarning(
                        $sessionBreaks,
                        $session['start_time'],
                        $session['end_time'],
                        $edgeMinutes
                    );
                } else {
                    $breakMinutesValue = 0;
                    $breakMinutesDisplay = '0:00';
                    $durationMinutes = $grossMinutes;
                }
            } elseif (!$breaksEnabled) {
                $breakMinutesValue = 0;
                $breakMinutesDisplay = '0:00';
            }
            return [
                'id' => $session['id'],
                'startInput' => $session['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d\TH:i'),
                'endInput' => $session['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d\TH:i') ?? '',
                'startDisplay' => $session['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'endDisplay' => $session['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i') ?? '記録中',
                'formattedMinutes' => $durationMinutes !== null ? $this->formatMinutesToHM($durationMinutes) : '--',
                'breakMinutes' => $breakMinutesValue,
                'breakMinutesDisplay' => $breakMinutesDisplay,
                'breakShortageMinutes' => $breakShortageMinutes,
                'edgeBreakWarning' => $edgeBreakWarning,
            ];
        }, $records);

        $overlapping = $this->repository->listWorkSessionsByUserOverlapping($employeeId, $monthStart, $monthEndExclusive);
        $overlappingBreaksBySessionId = $this->fetchBreaksBySessionId($overlapping);
        $totalMinutes = $this->calculateNetMinutesWithinRange($overlapping, $monthStart, $monthEndExclusive, $overlappingBreaksBySessionId);
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
            'breaksEnabled' => $breaksEnabled,
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

    private function calculateNetMinutesWithinRange(array $sessions, \DateTimeImmutable $start, \DateTimeImmutable $endExclusive, array $breaksBySessionId): int
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
            $grossMinutes = (int)floor(($boundedEnd->getTimestamp() - $boundedStart->getTimestamp()) / 60);
            $breakMinutes = $this->breaksEnabled
                ? $this->sumBreakExcludedMinutes(
                    $breaksBySessionId[(int)($session['id'] ?? 0)] ?? [],
                    $boundedStart,
                    $boundedEnd
                )
                : 0;
            $minutes += max(0, $grossMinutes - $breakMinutes);
        }
        return $minutes;
    }

    private function sumBreakExcludedMinutes(array $breaks, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): int
    {
        $minutes = 0;
        foreach ($breaks as $break) {
            if (empty($break['start_time']) || !$break['start_time'] instanceof \DateTimeImmutable) {
                continue;
            }
            $start = $break['start_time'];
            $end = isset($break['end_time']) && $break['end_time'] instanceof \DateTimeImmutable ? $break['end_time'] : $rangeEnd;
            if ($end <= $start) {
                continue;
            }
            $boundedStart = $start < $rangeStart ? $rangeStart : $start;
            $boundedEnd = $end > $rangeEnd ? $rangeEnd : $end;
            if ($boundedEnd <= $boundedStart) {
                continue;
            }
            $minutes += (int)floor(($boundedEnd->getTimestamp() - $boundedStart->getTimestamp()) / 60);
        }
        return $minutes;
    }

    /**
     * @param array<int, array{id:int}> $sessions
     * @return array<int, array<int, array{work_session_id:int,start_time:\DateTimeImmutable,end_time:?\DateTimeImmutable}>>
     */
    private function fetchBreaksBySessionId(array $sessions): array
    {
        $breaksBySessionId = [];
        if (!$this->breaksEnabled) {
            return $breaksBySessionId;
        }
        if ($sessions === []) {
            return $breaksBySessionId;
        }

        $sessionIds = array_map(static fn(array $row): int => (int)$row['id'], $sessions);
        $sessionIds = array_values(array_unique(array_filter($sessionIds, static fn(int $id): bool => $id > 0)));
        if ($sessionIds === []) {
            return $breaksBySessionId;
        }

        try {
            $breakRows = $this->repository->listWorkSessionBreaksBySessionIds($sessionIds);
            foreach ($breakRows as $breakRow) {
                $sessionId = (int)$breakRow['work_session_id'];
                if (!isset($breaksBySessionId[$sessionId])) {
                    $breaksBySessionId[$sessionId] = [];
                }
                $breaksBySessionId[$sessionId][] = $breakRow;
            }
        } catch (\PDOException $e) {
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
        }

        return $breaksBySessionId;
    }

    private function setBreaksBySessionIdCache(array $breaksBySessionId): void
    {
        $this->breaksBySessionIdCache = $breaksBySessionId;
    }

    private array $breaksBySessionIdCache = [];

    private function breaksForSession(int $sessionId): array
    {
        if ($sessionId <= 0) {
            return [];
        }
        return $this->breaksBySessionIdCache[$sessionId] ?? [];
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
