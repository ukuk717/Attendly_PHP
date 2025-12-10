<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SplFileObject;

final class TimesheetExportService
{
    private Repository $repository;
    private string $exportDir;

    public function __construct(?Repository $repository = null, ?string $exportDir = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->exportDir = $exportDir ?: dirname(__DIR__, 2) . '/storage/exports';
    }

    /**
     * @param array{tenant_id:int,start:DateTimeImmutable,end:DateTimeImmutable,user_id:?int,timezone?:string} $filters
     * @return array{path:string,filename:string}
     */
    public function export(array $filters): array
    {
        $tenant = $this->repository->findTenantById($filters['tenant_id']);
        if ($tenant === null || ($tenant['status'] ?? '') !== 'active') {
            throw new RuntimeException('テナントが無効です。');
        }
        $targetTz = AppTime::timezone();
        if (!empty($filters['timezone'])) {
            try {
                $targetTz = new DateTimeZone((string)$filters['timezone']);
            } catch (\Throwable) {
                $targetTz = AppTime::timezone();
            }
        }
        $rows = $this->repository->fetchWorkSessions($filters['tenant_id'], $filters['start'], $filters['end'], $filters['user_id'] ?? null);

        if (!is_dir($this->exportDir) && !mkdir($concurrentDirectory = $this->exportDir, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('エクスポートディレクトリを作成できませんでした。');
        }

        $filename = sprintf(
            'timesheets_%s_%s.csv',
            $filters['start']->format('Ymd'),
            $filters['end']->format('Ymd')
        );
        $path = $this->exportDir . '/' . $filename;
        $file = new SplFileObject($path, 'w');

        $headers = [
            'employee_id',
            'email',
            'first_name',
            'last_name',
            'start_time_local',
            'end_time_local',
            'duration_minutes',
        ];
        $file->fputcsv($headers);

        foreach ($rows as $row) {
            $start = $row['start_time']->setTimezone($targetTz);
            $end = $row['end_time']?->setTimezone($targetTz);
            $durationMinutes = null;
            if ($row['end_time'] !== null) {
                $interval = $row['start_time']->diff($row['end_time']);
                $days = $interval->days !== false ? $interval->days : 0;
                $durationMinutes = ($days * 24 * 60) + ($interval->h * 60) + $interval->i + (int)floor($interval->s / 60);
            }
            $file->fputcsv([
                $row['user_id'],
                $row['email'],
                $row['first_name'],
                $row['last_name'],
                $start->format('Y-m-d H:i:s'),
                $end?->format('Y-m-d H:i:s'),
                $durationMinutes,
            ]);
        }

        return ['path' => $path, 'filename' => $filename];
    }
}
