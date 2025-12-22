<?php

declare(strict_types=1);

use Attendly\Database\Repository;
use Attendly\Support\AppTime;

$projectRoot = dirname(__DIR__);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!is_file($autoloadPath)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run \"composer install\" in php/.\n");
    exit(1);
}

require $autoloadPath;
require $projectRoot . '/src/bootstrap.php';

attendly_load_env($projectRoot);

function env_int(string $key, int $default, int $min, int $max): int
{
    $raw = $_ENV[$key] ?? null;
    if ($raw === null || trim((string)$raw) === '') {
        return $default;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function safe_realpath(string $path): ?string
{
    $real = realpath($path);
    return $real === false ? null : $real;
}

/**
 * @return array{scanned:int,deleted:int,freed_bytes:int,remaining:int}
 */
function prune_export_files(string $dir, int $retentionDays, int $maxFiles, int $maxTotalBytes): array
{
    $base = safe_realpath($dir);
    if ($base === null || !is_dir($base)) {
        return ['scanned' => 0, 'deleted' => 0, 'freed_bytes' => 0, 'remaining' => 0];
    }

    $candidates = [];
    $scanned = 0;

    foreach (new DirectoryIterator($base) as $file) {
        if ($file->isDot() || !$file->isFile() || $file->isLink()) {
            continue;
        }
        $name = $file->getFilename();
        if (!preg_match('/^timesheets_.*\\.(csv|xml|pdf|xls|xlsx)\\z/u', $name)) {
            continue;
        }
        $real = $file->getRealPath();
        if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $scanned++;
        $size = $file->getSize();
        $mtime = $file->getMTime();
        $candidates[] = ['path' => $real, 'mtime' => $mtime, 'size' => $size];
    }

    usort($candidates, static fn(array $a, array $b): int => ($a['mtime'] <=> $b['mtime']));

    $deleted = 0;
    $freed = 0;
    $now = time();
    $cutoff = $retentionDays > 0 ? ($now - ($retentionDays * 86400)) : null;

    $remaining = [];
    foreach ($candidates as $item) {
        if ($cutoff !== null && $item['mtime'] < $cutoff) {
            if (@unlink($item['path'])) {
                $deleted++;
                $freed += (int)$item['size'];
                continue;
            }
        }
        $remaining[] = $item;
    }

    // Enforce max files / total bytes by deleting oldest first.
    $totalBytes = array_sum(array_map(static fn(array $row): int => (int)$row['size'], $remaining));
    while (count($remaining) > $maxFiles || $totalBytes > $maxTotalBytes) {
        $oldest = array_shift($remaining);
        if ($oldest === null) {
            break;
        }
        if (@unlink($oldest['path'])) {
            $deleted++;
            $freed += (int)$oldest['size'];
            $totalBytes -= (int)$oldest['size'];
        } else {
            // If deletion fails, stop to avoid an infinite loop.
            break;
        }
    }

    return [
        'scanned' => $scanned,
        'deleted' => $deleted,
        'freed_bytes' => $freed,
        'remaining' => count($remaining),
    ];
}

/**
 * @return array{archived:int,files_deleted:int,skipped:int}
 */
function archive_old_payrolls(Repository $repo, string $payslipDir, int $retentionDays, int $limit): array
{
    if ($retentionDays <= 0) {
        return ['archived' => 0, 'files_deleted' => 0, 'skipped' => 0];
    }
    $base = safe_realpath($payslipDir);
    if ($base === null || !is_dir($base)) {
        return ['archived' => 0, 'files_deleted' => 0, 'skipped' => 0];
    }

    $cutoff = AppTime::now()->sub(new DateInterval('P' . $retentionDays . 'D'));
    $records = $repo->listPayrollRecordsForCleanup($cutoff, $limit);
    if ($records === []) {
        return ['archived' => 0, 'files_deleted' => 0, 'skipped' => 0];
    }

    $archived = 0;
    $filesDeleted = 0;
    $skipped = 0;
    $archivedAt = AppTime::now();

    foreach ($records as $record) {
        $path = trim((string)$record['stored_file_path']);
        if ($path === '') {
            $skipped++;
            continue;
        }
        $real = safe_realpath($path);
        if ($real === null) {
            $repo->archivePayrollRecord((int)$record['id'], $archivedAt);
            $archived++;
            continue;
        }
        if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            $skipped++;
            continue;
        }

        if (is_file($real)) {
            if (@unlink($real)) {
                $filesDeleted++;
                $repo->archivePayrollRecord((int)$record['id'], $archivedAt);
                $archived++;
            } else {
                $skipped++;
            }
            continue;
        }

        $repo->archivePayrollRecord((int)$record['id'], $archivedAt);
        $archived++;
    }

    return ['archived' => $archived, 'files_deleted' => $filesDeleted, 'skipped' => $skipped];
}

$exportRetentionDays = env_int('EXPORT_RETENTION_DAYS', 7, 0, 3650);
$exportMaxFiles = env_int('EXPORT_MAX_FILES', 500, 10, 1000000);
$exportMaxTotalMb = env_int('EXPORT_MAX_TOTAL_MB', 256, 1, 200000);
$exportMaxTotalBytes = $exportMaxTotalMb * 1024 * 1024;

$resultExports = prune_export_files($projectRoot . '/storage/exports', $exportRetentionDays, $exportMaxFiles, $exportMaxTotalBytes);

$payrollRetentionDays = env_int('PAYROLL_RETENTION_DAYS', 0, 0, 36500);
$payrollCleanupLimit = env_int('PAYROLL_CLEANUP_LIMIT', 200, 1, 5000);
$signedCleanupLimit = env_int('SIGNED_URL_CLEANUP_LIMIT', 5000, 100, 10000);

$repo = new Repository();
$signedDeleted = $repo->deleteExpiredSignedDownloads(AppTime::now(), $signedCleanupLimit);

$resultPayrolls = ['archived' => 0, 'files_deleted' => 0, 'skipped' => 0];
if ($payrollRetentionDays > 0) {
    $resultPayrolls = archive_old_payrolls($repo, $projectRoot . '/storage/payslips', $payrollRetentionDays, $payrollCleanupLimit);
}

echo "[exports] scanned={$resultExports['scanned']} deleted={$resultExports['deleted']} remaining={$resultExports['remaining']} freed_bytes={$resultExports['freed_bytes']}\n";
echo "[signed_urls] deleted={$signedDeleted}\n";
echo "[payrolls] archived={$resultPayrolls['archived']} files_deleted={$resultPayrolls['files_deleted']} skipped={$resultPayrolls['skipped']}\n";
