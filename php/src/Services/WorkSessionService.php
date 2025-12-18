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
     * @return array{status:'opened'|'closed', session:array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}, break_auto_closed?:bool}
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
     *   open_break:?array{id:int,work_session_id:int,break_type:string,is_compensated:bool,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,note:?string},
     *   recent_sessions:array<int,array{start:string,end:string,status:string,duration:string,break_shortage_minutes:int,edge_break_warning:bool}>,
     *   daily_summary:array<int,array{date:string,minutes:int,formatted:string,break_shortage_minutes:int,edge_break_warning:bool}>,
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

        $openBreak = null;
        if ($open !== null) {
            try {
                $openBreak = $this->repository->findOpenWorkSessionBreak((int)$open['id']);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '42S02') {
                    throw $e;
                }
            }
        }

        $sessionIds = array_map(static fn(array $s): int => (int)$s['id'], $sessions);
        $recentSessions = $this->repository->listRecentWorkSessionsByUser($userId, 10);
        foreach ($recentSessions as $s) {
            $sessionIds[] = (int)$s['id'];
        }
        $sessionIds = array_values(array_unique(array_filter($sessionIds, static fn(int $id): bool => $id > 0)));

        $breaksBySessionId = [];
        if ($sessionIds !== []) {
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

        $openDayKey = null;
        if ($open !== null && isset($open['start_time']) && $open['start_time'] instanceof DateTimeImmutable) {
            $openDayKey = $open['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d');
        }
        $edgeMinutes = BreakComplianceService::edgeBreakWarningMinutes();

        $dailyMinutes = [];
        $dailyBreakMinutes = [];
        $dailyEdgeWarnings = [];
        $monthlyTotal = 0;
        foreach ($sessions as $session) {
            $end = $session['end_time'] ?? $now;
            $sessionBreaks = $breaksBySessionId[(int)$session['id']] ?? [];
            $grossMinutes = $this->diffMinutes($session['start_time'], $end);
            $breakMinutes = $this->sumBreakExcludedMinutes(
                $sessionBreaks,
                $session['start_time'],
                $end
            );
            $minutes = max(0, $grossMinutes - $breakMinutes);
            $startDateKey = $session['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d');
            if (!isset($dailyMinutes[$startDateKey])) {
                $dailyMinutes[$startDateKey] = 0;
            }
            $dailyMinutes[$startDateKey] += $minutes;
            if (!isset($dailyBreakMinutes[$startDateKey])) {
                $dailyBreakMinutes[$startDateKey] = 0;
            }
            $dailyBreakMinutes[$startDateKey] += $breakMinutes;
            if ($session['end_time'] !== null && $session['end_time'] instanceof DateTimeImmutable) {
                $edgeWarning = BreakComplianceService::hasEdgeBreakWarning(
                    $sessionBreaks,
                    $session['start_time'],
                    $session['end_time'],
                    $edgeMinutes
                );
                if ($edgeWarning) {
                    $dailyEdgeWarnings[$startDateKey] = true;
                }
            }
            if ($end > $monthStart) {
                $monthlyStart = $session['start_time'] < $monthStart ? $monthStart : $session['start_time'];
                $monthlyGross = $this->diffMinutes($monthlyStart, $end);
                $monthlyBreak = $this->sumBreakExcludedMinutes(
                    $sessionBreaks,
                    $monthlyStart,
                    $end
                );
                $monthlyTotal += max(0, $monthlyGross - $monthlyBreak);
            }
        }

        krsort($dailyMinutes);
        $dailySummary = [];
        foreach ($dailyMinutes as $date => $minutes) {
            if ($date < $rangeStart->setTimezone(AppTime::timezone())->format('Y-m-d')) {
                continue;
            }
            $breakShortageMinutes = 0;
            $edgeBreakWarning = !empty($dailyEdgeWarnings[$date]);
            if ($openDayKey === null || $date !== $openDayKey) {
                $breakShortageMinutes = BreakComplianceService::breakShortageMinutes(
                    $minutes,
                    (int)($dailyBreakMinutes[$date] ?? 0)
                );
            }
            $dailySummary[] = [
                'date' => $date,
                'minutes' => $minutes,
                'formatted' => $this->formatMinutes($minutes),
                'break_shortage_minutes' => $breakShortageMinutes,
                'edge_break_warning' => $edgeBreakWarning,
            ];
        }

        $recentFormatted = array_map(function (array $row) use ($now, $breaksBySessionId, $edgeMinutes): array {
            $end = $row['end_time'] ?? $now;
            $sessionBreaks = $breaksBySessionId[(int)$row['id']] ?? [];
            $grossMinutes = $this->diffMinutes($row['start_time'], $end);
            $breakMinutes = $this->sumBreakExcludedMinutes(
                $sessionBreaks,
                $row['start_time'],
                $end
            );
            $netMinutes = max(0, $grossMinutes - $breakMinutes);
            $breakShortageMinutes = 0;
            $edgeBreakWarning = false;
            if ($row['end_time'] !== null && $row['end_time'] instanceof DateTimeImmutable) {
                $breakShortageMinutes = BreakComplianceService::breakShortageMinutes($netMinutes, $breakMinutes);
                $edgeBreakWarning = BreakComplianceService::hasEdgeBreakWarning(
                    $sessionBreaks,
                    $row['start_time'],
                    $row['end_time'],
                    $edgeMinutes
                );
            }
            return [
                'start' => $row['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'end' => $row['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i') ?? '勤務中',
                'status' => $row['end_time'] === null ? 'open' : 'closed',
                'duration' => $this->formatMinutes($netMinutes),
                'break_shortage_minutes' => $breakShortageMinutes,
                'edge_break_warning' => $edgeBreakWarning,
            ];
        }, $recentSessions);

        return [
            'open_session' => $open,
            'open_break' => $openBreak,
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

        $breaksBySessionId = [];
        if ($recentSessions !== []) {
            $sessionIds = array_map(static fn(array $row): int => (int)$row['id'], $recentSessions);
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

        $recent = array_map(function (array $row) use ($now, $breaksBySessionId): array {
            $label = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? ''));
            if ($label === '') {
                $label = $row['email'] ?? '従業員';
            }
            $end = $row['end_time'] ?? $now;
            $grossMinutes = $this->diffMinutes($row['start_time'], $end);
            $breakMinutes = $this->sumBreakExcludedMinutes(
                $breaksBySessionId[(int)$row['id']] ?? [],
                $row['start_time'],
                $end
            );
            $netMinutes = max(0, $grossMinutes - $breakMinutes);
            return [
                'user_label' => $label,
                'start' => $row['start_time']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'end' => $row['end_time']?->setTimezone(AppTime::timezone())->format('Y-m-d H:i') ?? '勤務中',
                'status' => $row['end_time'] === null ? 'open' : 'closed',
                'duration' => $this->formatMinutes($netMinutes),
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

    /**
     * @param array<int, array{
     *   start_time:DateTimeImmutable,
     *   end_time:?DateTimeImmutable
     * }> $breaks
     */
    private function sumBreakExcludedMinutes(array $breaks, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): int
    {
        $minutes = 0;
        foreach ($breaks as $break) {
            $start = $break['start_time'];
            $end = $break['end_time'] ?? $rangeEnd;
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
