<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\Mailer;
use Attendly\Support\RateLimiter;
use DateTimeImmutable;
use RuntimeException;

final class PayslipService
{
    private Repository $repository;
    private Mailer $mailer;
    private string $storageDir;

    public function __construct(?Repository $repository = null, ?Mailer $mailer = null, ?string $storageDir = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->mailer = $mailer ?? new Mailer();
        $this->storageDir = $storageDir ?: dirname(__DIR__, 2) . '/storage/payslips';
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
    public function send(array $data): array
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
        $bodyLines = [
            "{$employee['name']} 様",
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

        // 保存用ファイル（テキスト）を生成
        if (!is_dir($this->storageDir) && !mkdir($concurrentDirectory = $this->storageDir, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('給与明細ディレクトリを作成できませんでした。');
        }
        $fileName = sprintf('payslip_%d_%s_%s.txt', $data['employee_id'], $data['sent_on']->format('Ymd'), bin2hex(random_bytes(3)));
        $filePath = $this->storageDir . '/' . $fileName;
        $now = AppTime::now();
        $content = $body . "\n\n保存時刻(" . AppTime::timezone()->getName() . '): ' . $now->format('Y-m-d H:i:s');
        $written = file_put_contents($filePath, $content, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('給与明細ファイルの書き込みに失敗しました。');
        }

        $record = null;
        try {
            $record = $this->repository->createPayrollRecord([
                'tenant_id' => $data['tenant_id'],
                'employee_id' => $data['employee_id'],
                'uploaded_by' => $data['uploaded_by'],
                'original_file_name' => $fileName,
                'stored_file_path' => $filePath,
                'mime_type' => 'text/plain',
                'file_size' => $written,
                'sent_on' => $data['sent_on'],
                'sent_at' => $now,
            ]);
            if (empty($employee['email'])) {
                throw new RuntimeException('従業員のメールアドレスが設定されていません。');
            }
            $this->mailer->send($employee['email'], $subject, $body);
        } catch (\Throwable $e) {
            @unlink($filePath);  // Clean up file
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
}
