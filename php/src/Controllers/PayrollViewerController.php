<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Services\SignedDownloadService;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PayrollViewerController
{
    private Repository $repository;
    private View $view;
    private SignedDownloadService $signedDownloads;
    private string $storageDir;

    public function __construct(
        ?View $view = null,
        ?Repository $repository = null,
        ?SignedDownloadService $signedDownloads = null,
        ?string $storageDir = null
    )
    {
        $this->repository = $repository ?? new Repository();
        $this->view = $view ?? new View(dirname(__DIR__, 2) . '/views');
        $this->signedDownloads = $signedDownloads ?? new SignedDownloadService($this->repository);
        $this->storageDir = $storageDir ?: dirname(__DIR__, 2) . '/storage/payslips';
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $records = $this->repository->listPayrollRecordsByEmployee((int)$user['id'], 50);
        $items = array_map(static function (array $row): array {
            if (!$row['sent_on'] instanceof \DateTimeInterface || !$row['sent_at'] instanceof \DateTimeInterface) {
                throw new \RuntimeException('Invalid payroll record date format');
            }
            return [
                'id' => $row['id'],
                'sent_on' => $row['sent_on']->setTimezone(AppTime::timezone())->format('Y-m-d'),
                'sent_at' => $row['sent_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i'),
                'file_name' => $row['original_file_name'],
            ];
        }, $records);

        $html = $this->view->renderWithLayout('employee_payrolls', [
            'title' => '給与明細',
            'csrf' => \Attendly\Security\CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'items' => $items,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            Flash::add('error', 'ログインが必要です。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $recordId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($recordId <= 0) {
            Flash::add('error', '明細が見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/payrolls');
        }
        $record = $this->repository->findPayrollRecordById($recordId);
        if ($record === null || (int)$record['employee_id'] !== (int)$user['id']) {
            Flash::add('error', '明細が見つからないか、アクセス権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/payrolls');
        }

        try {
            $signed = $this->signedDownloads->issueForPayslip([
                'id' => $record['id'],
                'stored_file_path' => $record['stored_file_path'],
                'original_file_name' => $record['original_file_name'],
                'mime_type' => $record['mime_type'] ?? null,
            ], null);
        } catch (\Throwable) {
            Flash::add('error', '署名URLの発行に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/payrolls');
        }
        return $response->withStatus(303)->withHeader('Location', $signed['url']);
    }
}
