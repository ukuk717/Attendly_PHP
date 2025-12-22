<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final class SignedDownloadController
{
    private Repository $repository;
    private string $exportsDir;
    private string $payslipsDir;

    public function __construct(?Repository $repository = null, ?string $exportsDir = null, ?string $payslipsDir = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->exportsDir = $exportsDir ?: dirname(__DIR__, 2) . '/storage/exports';
        $this->payslipsDir = $payslipsDir ?: dirname(__DIR__, 2) . '/storage/payslips';
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = isset($args['token']) ? (string)$args['token'] : '';
        if (!$this->isValidToken($token)) {
            return $this->error($response, 404);
        }

        $hash = hash('sha256', $token);
        $record = $this->repository->findSignedDownloadByHash($hash);
        if ($record === null) {
            return $this->error($response, 404);
        }
        if ($record['revoked_at'] !== null || $record['expires_at'] <= AppTime::now()) {
            return $this->error($response, 404);
        }

        $baseDir = $this->resolveBaseDir($record['target_type']);
        if ($baseDir === null) {
            return $this->error($response, 404);
        }

        $realBase = realpath($baseDir);
        $realPath = realpath($record['file_path']);
        if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            return $this->error($response, 404);
        }
        if (!is_file($realPath) || !is_readable($realPath)) {
            return $this->error($response, 404);
        }

        if ($record['target_type'] === 'payslip' && $record['source_id'] !== null) {
            try {
                $this->repository->markPayrollRecordDownloaded($record['source_id'], AppTime::now());
            } catch (\Throwable) {
                // Keep download available even if audit update fails.
            }
        }

        try {
            $this->repository->touchSignedDownload($record['id'], AppTime::now());
        } catch (\Throwable) {
            // Continue download even if tracking fails.
        }

        $downloadName = basename($record['file_name']);
        $downloadName = str_replace(['"', "\r", "\n"], '', $downloadName);
        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            return $this->error($response, 404);
        }
        $size = filesize($realPath);
        $stream = new Stream($handle);
        $disposition = sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $downloadName, rawurlencode($downloadName));
        $response = $response->withBody($stream);
        if ($size !== false) {
            $response = $response->withHeader('Content-Length', (string)$size);
        }

        return $response
            ->withHeader('Content-Type', $this->validateContentType($record['content_type'] ?? ''))
            ->withHeader('Content-Disposition', $disposition);
    }

    private function resolveBaseDir(string $targetType): ?string
    {
        return match ($targetType) {
            'timesheet_export' => $this->exportsDir,
            'payslip' => $this->payslipsDir,
            default => null,
        };
    }

    private function isValidToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        if (strlen($token) < 20 || strlen($token) > 200) {
            return false;
        }
        return (bool)preg_match('/^[A-Za-z0-9_-]+$/', $token);
    }

    private function validateContentType(string $contentType): string
    {
        $normalized = strtolower(trim($contentType));
        if ($normalized === '') {
            return 'application/octet-stream';
        }
        $allowed = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv; charset=utf-8',
            'text/csv',
            'application/octet-stream',
            'text/plain',
        ];
        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }
        return 'application/octet-stream';
    }

    private function error(ResponseInterface $response, int $status): ResponseInterface
    {
        $response->getBody()->write('not_found');
        return $response->withStatus($status)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
