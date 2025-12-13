<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Services\WorkSessionService;
use Attendly\Support\AppTime;
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
    private View $view;

    public function __construct(?View $view = null, ?Repository $repository = null, ?WorkSessionService $workSessions = null)
    {
        $this->view = $view ?? new View(dirname(__DIR__, 2) . '/views');
        $this->repository = $repository ?? new Repository();
        $this->workSessions = $workSessions ?? new WorkSessionService($this->repository);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUser = $request->getAttribute('currentUser');
        if (!is_array($currentUser) || empty($currentUser['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $role = (string)($currentUser['role'] ?? 'employee');
        if ($role === 'tenant_admin' || $role === 'admin') {
            return $this->renderTenantAdmin($request, $response, $currentUser);
        }
        return $this->renderEmployee($request, $response, $currentUser);
    }

    private function renderEmployee(ServerRequestInterface $request, ResponseInterface $response, array $user): ResponseInterface
    {
        $data = $this->workSessions->buildUserDashboardData((int)$user['id']);
        $html = $this->view->renderWithLayout('dashboard', [
            'title' => 'ダッシュボード',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'openSession' => $data['open_session'],
            'recentSessions' => $data['recent_sessions'],
            'dailySummary' => $data['daily_summary'],
            'monthlyTotal' => $data['monthly_total_formatted'],
            'timezone' => $data['timezone'],
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
        $sessionsByUserId = [];
        foreach ($tenantSessions as $session) {
            $uid = (int)$session['user_id'];
            if (!isset($sessionsByUserId[$uid])) {
                $sessionsByUserId[$uid] = [];
            }
            $sessionsByUserId[$uid][] = $session;
        }

        $monthlySummary = [];
        foreach ($employeesActive as $employee) {
            $employeeId = (int)$employee['id'];
            $sessions = $sessionsByUserId[$employeeId] ?? [];
            $minutes = $this->calculateMinutesWithinRange($sessions, $monthStart, $monthEndExclusive);
            $monthlySummary[] = [
                'user' => [
                    'id' => $employeeId,
                    'username' => (string)$employee['username'],
                    'email' => (string)$employee['email'],
                ],
                'totalMinutes' => $minutes,
                'formattedTotal' => $this->formatMinutesToHM($minutes),
            ];
        }

        $tenant = $this->repository->findTenantById($tenantId);
        if ($tenant === null) {
            Flash::add('error', 'テナント情報が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $retentionYears = $this->getRetentionYears();

        $html = $this->view->renderWithLayout('tenant_admin_dashboard', [
            'title' => 'テナント管理ダッシュボード',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
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

    private function calculateMinutesWithinRange(array $sessions, \DateTimeImmutable $start, \DateTimeImmutable $endExclusive): int
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

    private function getRetentionYears(): int
    {
        $raw = $_ENV['DATA_RETENTION_YEARS'] ?? 5;
        $years = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['default' => 5, 'min_range' => 1]]);
        if ($years === false) {
            return 5;
        }
        return max(1, (int)$years);
    }
}
