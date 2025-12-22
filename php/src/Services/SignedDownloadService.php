<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\Base64Url;

final class SignedDownloadService
{
    private Repository $repository;
    private string $baseUrl;
    private int $ttlSeconds;

    public function __construct(?Repository $repository = null, ?string $baseUrl = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->baseUrl = rtrim((string)($baseUrl ?? ($_ENV['APP_BASE_URL'] ?? '')), '/');
        $ttlDays = filter_var($_ENV['SIGNED_URL_TTL_DAYS'] ?? 7, FILTER_VALIDATE_INT, [
            'options' => ['default' => 7, 'min_range' => 1, 'max_range' => 30],
        ]);
        if ($ttlDays === false) {
            $ttlDays = 7;
        }
        $this->ttlSeconds = (int)$ttlDays * 86400;
    }

    /**
     * @return array{token:string,url:string,expires_at:\DateTimeImmutable}
     */
    public function issueForExport(string $filePath, string $fileName, string $contentType, ?int $createdBy = null): array
    {
        return $this->issue([
            'target_type' => 'timesheet_export',
            'source_id' => null,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'content_type' => $contentType,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @param array{id:int,stored_file_path:string,original_file_name:string,mime_type:?string} $record
     * @return array{token:string,url:string,expires_at:\DateTimeImmutable}
     */
    public function issueForPayslip(array $record, ?int $createdBy = null): array
    {
        return $this->issue([
            'target_type' => 'payslip',
            'source_id' => (int)$record['id'],
            'file_path' => (string)$record['stored_file_path'],
            'file_name' => (string)$record['original_file_name'],
            'content_type' => $record['mime_type'] ?? null,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @param array{
     *   target_type:string,
     *   source_id:?int,
     *   file_path:string,
     *   file_name:string,
     *   content_type:?string,
     *   created_by:?int
     * } $data
     * @return array{token:string,url:string,expires_at:\DateTimeImmutable}
     */
    private function issue(array $data): array
    {
        $token = Base64Url::encode(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = AppTime::now()->modify('+' . $this->ttlSeconds . ' seconds');
        if ($expiresAt === false) {
            $expiresAt = AppTime::now()->modify('+7 days') ?: AppTime::now();
        }

        $fileName = $this->sanitizeFileName((string)$data['file_name']);
        $this->repository->createSignedDownload([
            'token_hash' => $tokenHash,
            'target_type' => (string)$data['target_type'],
            'source_id' => $data['source_id'] ?? null,
            'file_path' => (string)$data['file_path'],
            'file_name' => $fileName,
            'content_type' => $data['content_type'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'url' => $this->buildUrl($token),
            'expires_at' => $expiresAt,
        ];
    }

    private function buildUrl(string $token): string
    {
        $path = '/downloads/' . $token;
        if ($this->baseUrl === '') {
            return $path;
        }
        return $this->baseUrl . $path;
    }

    private function sanitizeFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            return 'download';
        }
        $fileName = str_replace(['"', "\r", "\n"], '', $fileName);
        if ($fileName === '') {
            return 'download';
        }
        return $fileName;
    }
}
