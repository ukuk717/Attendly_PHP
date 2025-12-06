<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use DateInterval;
use DateTimeImmutable;

final class WorkSessionService
{
    public function __construct(private Repository $repository = new Repository())
    {
    }

    /**
     * 勤務開始/終了をワンクリックで切り替える。
     *
     * @return array{status:'opened'|'closed', session:array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}}
     */
    public function togglePunch(int $userId): array
    {
        $now = AppTime::now();
        return $this->repository->toggleWorkSessionAtomic($userId, $now);
    }

    /**
     * 従業員ダッシュボード表示用の集計データ。
     *
     * @return array{
     *   open_session:?array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable},
     *   recent_sessions:array<int,array{start:string,end:string,status:string,duration:string}>,
     *   daily_summary:array<int,array{date:string,minutes:int,formatted:string}>,
     *   monthly_total_minutes:int,
     *   monthly_total_formatted:string,
     *   timezone:string
     * }
     */
    public function buildUserDashboardData(int $userId): array
    {
        $now = AppTime::now();
        $rangeStart = $now->sub(new DateInterval('P29D'))->setTime(0, 0, 0);
        $monthStart = (new DateTimeImmutable(
            $now->format('Y-m-01 00:00:00'),
            AppTime::timezone()
        ));
        $historyStart = $monthStart < $rangeStart ? $monthStart : $rangeStart;
        $rangeEnd = $now->setTime(23, 59, 59);

        $sessions = $this->repository->listWorkSessionsByUserBetween($userId, $historyStart, $rangeEnd);
        $open = $this->repository->findOpenWorkSession($userId);

        $dailyMinutes = [];
        $monthlyTotal = 0;
        foreach ($sessions as $session) {
            $end = $session['end_time'] ?? $now;
            $minutes = $this->diffMinutes($session['start_time'], $end);
            $startDateKey = $session['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d');
            if (!isset($dailyMinutes[$startDateKey])) {
                $dailyMinutes[$startDateKey] = 0;
            }
            $dailyMinutes[$startDateKey] += $minutes;
            if ($end > $monthStart) {
                $monthlyStart = $session['start_time'] < $monthStart ? $monthStart : $session['start_time'];
                $monthlyTotal += $this->diffMinutes($monthlyStart, $end);
            }
        }

        krsort($dailyMinutes);
        $dailySummary = [];
        foreach ($dailyMinutes as $date => $minutes) {
            if ($date < $rangeStart->setTimezone(AppTime::timezone())->format('Y-m-d')) {
                continue;
            }
            $dailySummary[] = [
                'date' => $date,
                'minutes' => $minutes,
                'formatted' => $this->formatMinutes($minutes),
            ];
        }

        $recentSessions = $this->repository->listRecentWorkSessionsByUser($userId, 10);
        $recentFormatted = array_map(function (array $row) use ($now): array {
            $end = $row['end_time'] ?? $now;
            return [
                'start' => $row['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'end' => $row['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i') ?? '勤務中',
                'status' => $row['end_time'] === null ? 'open' : 'closed',
                'duration' => $this->formatMinutes($this->diffMinutes($row['start_time'], $end)),
            ];
        }, $recentSessions);

        return [
            'open_session' => $open,
            'recent_sessions' => $recentFormatted,
            'daily_summary' => $dailySummary,
            'monthly_total_minutes' => $monthlyTotal,
            'monthly_total_formatted' => $this->formatMinutes($monthlyTotal),
            'timezone' => AppTime::timezone()->getName(),
        ];
    }

    /**
     * テナント管理者向けのサマリー（KPI + 最近の打刻）。
     *
     * @return array{
     *   active_employees:int,
     *   open_sessions:int,
     *   recent_sessions:array<int,array{user_label:string,start:string,end:string,status:string,duration:string}>,
     *   timezone:string
     * }
     */
    public function buildTenantDashboardData(int $tenantId): array
    {
        $active = $this->repository->countActiveEmployees($tenantId);
        $openCount = $this->repository->countOpenWorkSessionsByTenant($tenantId);
        $recentSessions = $this->repository->listRecentWorkSessionsByTenant($tenantId, 8);
        $now = AppTime::now();

        $recent = array_map(function (array $row) use ($now): array {
            $label = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? ''));
            if ($label === '') {
                $label = $row['email'] ?? '従業員';
            }
            $end = $row['end_time'] ?? $now;
            return [
                'user_label' => $label,
                'start' => $row['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'end' => $row['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i') ?? '勤務中',
                'status' => $row['end_time'] === null ? 'open' : 'closed',
                'duration' => $this->formatMinutes($this->diffMinutes($row['start_time'], $end)),
            ];
        }, $recentSessions);

        return [
            'active_employees' => $active,
            'open_sessions' => $openCount,
            'recent_sessions' => $recent,
            'timezone' => AppTime::timezone()->getName(),
        ];
    }

    private function diffMinutes(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($end < $start) {
            return 0;
        }
        $interval = $start->diff($end);
        return ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i + (int)floor($interval->s / 60);
    }

    private function formatMinutes(int $minutes): string
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
}
