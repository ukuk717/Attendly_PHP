<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\Mailer;
use Attendly\Support\RateLimiter;
use DateTimeImmutable;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class PayslipService
{
    private Repository $repository;
    private Mailer $mailer;
    private string $storageDir;
    private int $maxUploadBytes;

    public function __construct(?Repository $repository = null, ?Mailer $mailer = null, ?string $storageDir = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->mailer = $mailer ?? new Mailer();
        $this->storageDir = $storageDir ?: dirname(__DIR__, 2) . '/storage/payslips';
        $maxMb = filter_var($_ENV['PAYSLIP_UPLOAD_MAX_MB'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 50]]);
        if ($maxMb === false) {
            $maxMb = 10;
        }
        $this->maxUploadBytes = (int)$maxMb * 1024 * 1024;
    }

    /**
     * @param array{
     *   tenant_id:int,
     *   employee_id:int,
     *   uploaded_by:int,
     *   sent_on:DateTimeImmutable,
     *   summary:string,
     *   net_amount:?float
     * } $data
     * @return array{id:int,stored_file_path:string}
     */
    public function send(array $data, ?UploadedFileInterface $uploadedFile = null): array
    {
        $employee = $this->repository->findUserById($data['employee_id']);
        if ($employee === null || $employee['tenant_id'] !== $data['tenant_id']) {
            throw new RuntimeException('従業員が見つからないか、テナントが一致しません。');
        }
        if (($employee['status'] ?? '') !== 'active') {
            throw new RuntimeException('従業員が有効ではありません。');
        }

        // レートリミット（IP ではなくテナント/従業員単位で防御）
        if (!RateLimiter::allow("payslip_send:tenant:{$data['tenant_id']}", 20, 300)) {
            throw new RuntimeException('送信回数の上限に達しました（テナント）。時間をおいて再試行してください。');
        }
        if (!RateLimiter::allow("payslip_send:employee:{$data['employee_id']}", 5, 300)) {
            throw new RuntimeException('送信回数の上限に達しました（従業員）。時間をおいて再試行してください。');
        }

        $brand = $_ENV['APP_BRAND_NAME'] ?? 'Attendly';
        $subject = sprintf('【%s】給与明細のご案内（%s）', $brand, $data['sent_on']->format('Y-m-d'));
        $employeeLabel = $this->buildEmployeeLabel($employee);
        $bodyLines = [
            "{$employeeLabel} 様",
            '',
            "{$brand} から給与明細のご案内です。",
            '以下の内容をご確認ください。',
            '',
            '支給日: ' . $data['sent_on']->format('Y-m-d'),
            $data['net_amount'] !== null ? ('支給額(目安): ' . number_format($data['net_amount'], 0) . ' 円') : null,
            '概要: ' . $data['summary'],
            '',
            '詳細は社内ポータルで確認してください。',
        ];
        $body = implode("\n", array_filter($bodyLines, static fn($line) => $line !== null));

        // 保存先ディレクトリ
        if (!is_dir($this->storageDir) && !mkdir($concurrentDirectory = $this->storageDir, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('給与明細ディレクトリを作成できませんでした。');
        }

        $now = AppTime::now();

        $storedFilePath = null;
        $originalFileName = null;
        $mimeType = null;
        $fileSize = null;

        if ($uploadedFile !== null && $this->isUploadedFilePresent($uploadedFile)) {
            $save = $this->storeUploadedPdf($uploadedFile, (int)$data['employee_id'], $data['sent_on']);
            $storedFilePath = $save['stored_file_path'];
            $originalFileName = $save['original_file_name'];
            $mimeType = 'application/pdf';
            $fileSize = $save['file_size'];
        } else {
            // 添付なし: 互換のためテキスト形式で保存
            $fileName = sprintf('payslip_%d_%s_%s.txt', $data['employee_id'], $data['sent_on']->format('Ymd'), bin2hex(random_bytes(3)));
            $filePath = $this->storageDir . '/' . $fileName;
            $content = $body . "\n\n保存時刻(" . AppTime::timezone()->getName() . '): ' . $now->format('Y-m-d H:i:s');
            $written = file_put_contents($filePath, $content, LOCK_EX);
            if ($written === false) {
                throw new RuntimeException('給与明細ファイルの書き込みに失敗しました。');
            }
            $storedFilePath = $filePath;
            $originalFileName = $fileName;
            $mimeType = 'text/plain';
            $fileSize = $written;
        }

        $record = null;
        try {
            $record = $this->repository->createPayrollRecord([
                'tenant_id' => $data['tenant_id'],
                'employee_id' => $data['employee_id'],
                'uploaded_by' => $data['uploaded_by'],
                'original_file_name' => $originalFileName,
                'stored_file_path' => $storedFilePath,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'sent_on' => $data['sent_on'],
                'sent_at' => $now,
            ]);
            if (empty($employee['email'])) {
                throw new RuntimeException('従業員のメールアドレスが設定されていません。');
            }
            $this->mailer->send($employee['email'], $subject, $body);
        } catch (\Throwable $e) {
            if (is_string($storedFilePath) && $storedFilePath !== '') {
                @unlink($storedFilePath);
            }
            if (isset($record['id'])) {
                $this->repository->deletePayrollRecord((int)$record['id']);
            }
            throw new RuntimeException('給与明細の保存または送信に失敗しました。', 0, $e);
        }

        return [
            'id' => $record['id'],
            'stored_file_path' => $record['stored_file_path'],
        ];
    }

    /**
     * @param array{id:int,username:string,email:string} $employee
     */
    private function buildEmployeeLabel(array $employee): string
    {
        $name = trim((string)($employee['username'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $email = trim((string)($employee['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }
        return '従業員';
    }

    private function isUploadedFilePresent(UploadedFileInterface $file): bool
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return false;
        }
        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            return false;
        }
        return true;
    }

    /**
     * @return array{stored_file_path:string,original_file_name:string,file_size:int}
     */
    private function storeUploadedPdf(UploadedFileInterface $file, int $employeeId, DateTimeImmutable $sentOn): array
    {
        $size = $file->getSize() ?? 0;
        if ($size <= 0 || $size > $this->maxUploadBytes) {
            throw new RuntimeException('添付ファイルのサイズが不正です。');
        }

        $original = $this->sanitizeOriginalFilename((string)($file->getClientFilename() ?? ''));
        if (!str_ends_with(strtolower($original), '.pdf')) {
            $original .= '.pdf';
        }

        $this->assertPdfSignature($file);

        $storedName = sprintf('payslip_u%d_%s_%s.pdf', $employeeId, $sentOn->format('Ymd'), bin2hex(random_bytes(6)));
        $storedPath = $this->storageDir . '/' . $storedName;

        try {
            $file->moveTo($storedPath);
        } catch (\Throwable $e) {
            throw new RuntimeException('添付ファイルの保存に失敗しました。', 0, $e);
        }

        $writtenSize = filesize($storedPath);
        if ($writtenSize === false || $writtenSize <= 0) {
            @unlink($storedPath);
            throw new RuntimeException('添付ファイルの保存に失敗しました。');
        }

        return [
            'stored_file_path' => $storedPath,
            'original_file_name' => $original,
            'file_size' => (int)$writtenSize,
        ];
    }

    private function sanitizeOriginalFilename(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'payslip';
        }
        $value = str_replace(['\\', '/', "\0"], '_', $value);
        $value = preg_replace('/[\r\n]/', '', $value) ?? $value;
        $value = preg_replace('/[<>:"|?*]/', '_', $value) ?? $value;
        $value = trim($value, " .\t");
        if ($value === '') {
            return 'payslip';
        }
        if (mb_strlen($value, 'UTF-8') > 200) {
            $value = mb_substr($value, 0, 200, 'UTF-8');
        }
        return $value;
    }

    private function assertPdfSignature(UploadedFileInterface $file): void
    {
        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(1024);
        $stream->rewind();
        if (!is_string($head) || $head === '' || strpos($head, '%PDF-') === false) {
            throw new RuntimeException('添付ファイルはPDFのみ対応しています。');
        }
    }
}
