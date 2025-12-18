<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final class PayrollViewerController
{
    private Repository $repository;
    private View $view;
    private string $storageDir;

    public function __construct(?View $view = null, ?Repository $repository = null, ?string $storageDir = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->view = $view ?? new View(dirname(__DIR__, 2) . '/views');
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
            if (!$row['sent_on'] instanceof \DateTime || !$row['sent_at'] instanceof \DateTime) {
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

        $realBase = realpath($this->storageDir);
        $realPath = realpath($record['stored_file_path']);
        if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            Flash::add('error', 'ファイルのパス検証に失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/payrolls');
        }
        if (!is_file($realPath) || !is_readable($realPath)) {
            Flash::add('error', '明細ファイルを開けませんでした。');
            return $response->withStatus(303)->withHeader('Location', '/payrolls');
        }
        $fileName = basename($record['original_file_name']);
        // Remove characters that could break header syntax
        $fileName = str_replace(['"', "\r", "\n"], '', $fileName);
        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            Flash::add('error', '明細ファイルを開けませんでした。');
            return $response->withStatus(303)->withHeader('Location', '/payrolls');
        }
        $size = filesize($realPath);
        $stream = new Stream($handle);
        $disposition = sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fileName, rawurlencode($fileName));
        $response = $response->withBody($stream);
        if ($size !== false) {
            $response = $response->withHeader('Content-Length', (string)$size);
        }
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', $disposition);
    }
}
