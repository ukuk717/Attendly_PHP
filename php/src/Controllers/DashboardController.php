<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Services\BreakComplianceService;
use Attendly\Services\AnnouncementService;
use Attendly\Services\WorkSessionService;
use Attendly\Support\AppTime;
use Attendly\Support\BreakFeature;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DashboardController
{
    private const SESSION_YEAR_MIN = 2000;
    private const SESSION_YEAR_MAX = 2100;

    private Repository $repository;
    private WorkSessionService $workSessions;
    private AnnouncementService $announcements;
    private View $view;
    private bool $breaksEnabled;

    public function __construct(?View $view = null, ?Repository $repository = null, ?WorkSessionService $workSessions = null, ?AnnouncementService $announcements = null)
    {
        $this->view = $view ?? new View(dirname(__DIR__, 2) . '/views');
        $this->repository = $repository ?? new Repository();
        $this->workSessions = $workSessions ?? new WorkSessionService($this->repository);
        $this->announcements = $announcements ?? new AnnouncementService($this->repository);
        $this->breaksEnabled = BreakFeature::isEnabled();
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUser = $request->getAttribute('currentUser');
        if (!is_array($currentUser) || empty($currentUser['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $role = (string)($currentUser['role'] ?? 'employee');
        if (in_array($role, ['platform_admin', 'admin'], true) && empty($currentUser['tenant_id'])) {
            return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
        }
        if ($role === 'tenant_admin') {
            return $this->renderTenantAdmin($request, $response, $currentUser);
        }
        return $this->renderEmployee($request, $response, $currentUser);
    }

    private function renderEmployee(ServerRequestInterface $request, ResponseInterface $response, array $user): ResponseInterface
    {
        $data = $this->workSessions->buildUserDashboardData((int)$user['id']);
        $announcementBox = $this->announcements->listForUser((int)$user['id'], 5);
        $announcementModal = $this->consumeLoginAnnouncements((int)$user['id']);
        $html = $this->view->renderWithLayout('dashboard', [
            'title' => 'ダッシュボード',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'currentPath' => $request->getUri()->getPath(),
            'passkeyRecommendation' => $this->buildPasskeyRecommendation((int)$user['id']),
            'openSession' => $data['open_session'],
            'openBreak' => $data['open_break'],
            'recentSessions' => $data['recent_sessions'],
            'dailySummary' => $data['daily_summary'],
            'monthlyTotal' => $data['monthly_total_formatted'],
            'timezone' => $data['timezone'],
            'breaksEnabled' => $this->breaksEnabled,
            'announcementBox' => $announcementBox,
            'announcementModal' => $announcementModal,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function renderTenantAdmin(ServerRequestInterface $request, ResponseInterface $response, array $user): ResponseInterface
    {
        if (empty($user['tenant_id'])) {
            Flash::add('error', 'テナント情報が不足しています。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $metrics = $this->workSessions->buildTenantDashboardData((int)$user['tenant_id']);
        $payrolls = $this->repository->listPayrollRecordsByTenant((int)$user['tenant_id'], 5);
        $payrollRows = array_map(static function (array $row): array {
            $sentOn = $row['sent_on'] instanceof \DateTimeInterface 
                ? $row['sent_on']->setTimezone(AppTime::timezone())->format('Y-m-d')
                : 'N/A';
            $sentAt = $row['sent_at'] instanceof \DateTimeInterface
                ? $row['sent_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : 'N/A';
            
            return [
                'id' => $row['id'],
                'sent_on' => $sentOn,
                'sent_at' => $sentAt,
                'file_name' => $row['original_file_name'],
            ];
        }, $payrolls);
        $metrics['recent_payrolls'] = $payrollRows;

        $tenantId = (int)$user['tenant_id'];
        [$targetYear, $targetMonth, $queryString] = $this->normalizeYearMonth($request->getQueryParams());
        $monthStart = new \DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $targetYear, $targetMonth),
            AppTime::timezone()
        );
        $monthEndExclusive = $monthStart->modify('+1 month');

        $allEmployees = $this->repository->listEmployeesByTenantIncludingInactive($tenantId, 500);
        $employeesActive = [];
        $employeesInactive = [];
        foreach ($allEmployees as $employee) {
            if (($employee['status'] ?? '') === 'active') {
                $employeesActive[] = $employee;
                continue;
            }
            $employee['deactivatedAtDisplay'] = $employee['deactivated_at'] instanceof \DateTimeInterface
                ? $employee['deactivated_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : '';
            $employeesInactive[] = $employee;
        }
        $sortByName = static function (array $a, array $b): int {
            return strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
        };
        usort($employeesActive, $sortByName);
        usort($employeesInactive, $sortByName);

        $tenantSessions = $this->repository->listWorkSessionsByTenantOverlapping($tenantId, $monthStart, $monthEndExclusive);
        $breaksBySessionId = [];
        if ($this->breaksEnabled && $tenantSessions !== []) {
            $sessionIds = array_map(static fn(array $row): int => (int)$row['id'], $tenantSessions);
            $sessionIds = array_values(array_unique(array_filter($sessionIds, static fn(int $id): bool => $id > 0)));
            try {
                $breakRows = $this->repository->listWorkSessionBreaksBySessionIds($sessionIds);
                foreach ($breakRows as $breakRow) {
                    $sid = (int)$breakRow['work_session_id'];
                    if (!isset($breaksBySessionId[$sid])) {
                        $breaksBySessionId[$sid] = [];
                    }
                    $breaksBySessionId[$sid][] = $breakRow;
                }
            } catch (\PDOException $e) {
                if ($e->getCode() !== '42S02') {
                    throw $e;
                }
            }
        }
        $sessionsByUserId = [];
        foreach ($tenantSessions as $session) {
            $uid = (int)$session['user_id'];
            if (!isset($sessionsByUserId[$uid])) {
                $sessionsByUserId[$uid] = [];
            }
            $sessionsByUserId[$uid][] = $session;
        }

        $monthlySummary = [];
        $edgeMinutes = $this->breaksEnabled ? BreakComplianceService::edgeBreakWarningMinutes() : 0;
        foreach ($employeesActive as $employee) {
            $employeeId = (int)$employee['id'];
            $sessions = $sessionsByUserId[$employeeId] ?? [];
            $minutes = $this->calculateNetMinutesWithinRange($sessions, $monthStart, $monthEndExclusive, $breaksBySessionId);
            $breakWarnings = $this->breaksEnabled
                ? $this->calculateBreakWarningStatsWithinRange(
                    $sessions,
                    $monthStart,
                    $monthEndExclusive,
                    $breaksBySessionId,
                    $edgeMinutes
                )
                : ['shortageDays' => 0, 'edgeDays' => 0];
            $monthlySummary[] = [
                'user' => [
                    'id' => $employeeId,
                    'username' => (string)$employee['username'],
                    'email' => (string)$employee['email'],
                ],
                'totalMinutes' => $minutes,
                'formattedTotal' => $this->formatMinutesToHM($minutes),
                'breakShortageDays' => $breakWarnings['shortageDays'],
                'edgeBreakWarningDays' => $breakWarnings['edgeDays'],
            ];
        }

        $tenant = $this->repository->findTenantById($tenantId);
        if ($tenant === null) {
            Flash::add('error', 'テナント情報が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $retentionYears = $this->getRetentionYears();
        $announcementBox = $this->announcements->listForUser((int)$user['id'], 5);
        $announcementModal = $this->consumeLoginAnnouncements((int)$user['id']);

        $html = $this->view->renderWithLayout('tenant_admin_dashboard', [
            'title' => 'テナント管理ダッシュボード',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'currentPath' => $request->getUri()->getPath(),
            'passkeyRecommendation' => $this->buildPasskeyRecommendation((int)$user['id']),
            'metrics' => $metrics,
            'tenantId' => $tenantId,
            'targetYear' => $targetYear,
            'targetMonth' => $targetMonth,
            'queryString' => $queryString,
            'monthlySummary' => $monthlySummary,
            'employeesActive' => $employeesActive,
            'employeesInactive' => $employeesInactive,
            'retentionYears' => $retentionYears,
            'tenantSettings' => [
                'requireEmailVerification' => !empty($tenant['require_employee_email_verification']),
            ],
            'announcementBox' => $announcementBox,
            'announcementModal' => $announcementModal,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function consumeLoginAnnouncements(int $userId): array
    {
        if (empty($_SESSION['show_login_announcements'])) {
            return [];
        }
        unset($_SESSION['show_login_announcements']);
        return $this->announcements->listForLoginModal($userId, 3);
    }

    private function calculateNetMinutesWithinRange(array $sessions, \DateTimeImmutable $start, \DateTimeImmutable $endExclusive, array $breaksBySessionId): int
    {
        $minutes = 0;
        foreach ($sessions as $session) {
            if (
                empty($session['start_time']) || !$session['start_time'] instanceof \DateTimeImmutable ||
                empty($session['end_time']) || !$session['end_time'] instanceof \DateTimeImmutable
            ) {
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
            if (
                empty($break['start_time']) || !$break['start_time'] instanceof \DateTimeImmutable
            ) {
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

    private function calculateBreakWarningStatsWithinRange(
        array $sessions,
        \DateTimeImmutable $start,
        \DateTimeImmutable $endExclusive,
        array $breaksBySessionId,
        int $edgeMinutes
    ): array {
        if (!$this->breaksEnabled) {
            return ['shortageDays' => 0, 'edgeDays' => 0];
        }
        $dailyNetMinutes = [];
        $dailyBreakMinutes = [];
        $dailyEdgeWarning = [];

        foreach ($sessions as $session) {
            if (
                empty($session['id'])
                || empty($session['start_time'])
                || !$session['start_time'] instanceof \DateTimeImmutable
                || empty($session['end_time'])
                || !$session['end_time'] instanceof \DateTimeImmutable
            ) {
                continue;
            }
            $sessionId = (int)$session['id'];
            $sessionStart = $session['start_time'];
            $sessionEnd = $session['end_time'];

            $boundedStart = $sessionStart < $start ? $start : $sessionStart;
            $boundedEnd = $sessionEnd > $endExclusive ? $endExclusive : $sessionEnd;
            if ($boundedEnd <= $boundedStart) {
                continue;
            }
            $grossMinutes = (int)floor(($boundedEnd->getTimestamp() - $boundedStart->getTimestamp()) / 60);
            $breakMinutes = $this->sumBreakExcludedMinutes(
                $breaksBySessionId[$sessionId] ?? [],
                $boundedStart,
                $boundedEnd
            );
            $netMinutes = max(0, $grossMinutes - $breakMinutes);
            $dateKey = $sessionStart->setTimezone(AppTime::timezone())->format('Y-m-d');

            if (!isset($dailyNetMinutes[$dateKey])) {
                $dailyNetMinutes[$dateKey] = 0;
            }
            if (!isset($dailyBreakMinutes[$dateKey])) {
                $dailyBreakMinutes[$dateKey] = 0;
            }
            $dailyNetMinutes[$dateKey] += $netMinutes;
            $dailyBreakMinutes[$dateKey] += $breakMinutes;

            if (BreakComplianceService::hasEdgeBreakWarning(
                $breaksBySessionId[$sessionId] ?? [],
                $sessionStart,
                $sessionEnd,
                $edgeMinutes
            )) {
                $dailyEdgeWarning[$dateKey] = true;
            }
        }

        $shortageDays = 0;
        foreach ($dailyNetMinutes as $date => $netMinutes) {
            $shortage = BreakComplianceService::breakShortageMinutes(
                (int)$netMinutes,
                (int)($dailyBreakMinutes[$date] ?? 0)
            );
            if ($shortage > 0) {
                $shortageDays++;
            }
        }

        $edgeDays = 0;
        foreach ($dailyEdgeWarning as $value) {
            if ($value) {
                $edgeDays++;
            }
        }

        return [
            'shortageDays' => $shortageDays,
            'edgeDays' => $edgeDays,
        ];
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

    private function getRetentionYears(): int
    {
        $raw = $_ENV['DATA_RETENTION_YEARS'] ?? 5;
        $years = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['default' => 5, 'min_range' => 1]]);
        if ($years === false) {
            return 5;
        }
        return max(1, (int)$years);
    }

    /**
     * @return array{userId:int,hasPasskey:bool}
     */
    private function buildPasskeyRecommendation(int $userId): array
    {
        $hasPasskey = false;
        try {
            $hasPasskey = $this->repository->countPasskeysByUser($userId) > 0;
        } catch (\PDOException $e) {
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
        } catch (\Throwable) {
            $hasPasskey = false;
        }
        return [
            'userId' => $userId,
            'hasPasskey' => $hasPasskey,
        ];
    }
}
