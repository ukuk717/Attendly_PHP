<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Services\PayslipService;
use Attendly\Security\CsrfToken;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class PayslipController
{
    public function __construct(private ?View $view = null, private ?Repository $repository = null, private ?PayslipService $service = null)
    {
        $this->repository = $this->repository ?? new Repository();
        $this->service = $this->service ?? new PayslipService($this->repository);
        $this->view = $this->view ?? new View(dirname(__DIR__, 2) . '/views');
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $admin = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            return $this->error($response, 403, 'forbidden');
        }
        $data = (array)$request->getParsedBody();
        $employeeId = isset($data['employee_id']) ? (int)$data['employee_id'] : 0;
        $summary = trim((string)($data['summary'] ?? ''));
        $sentOnStr = trim((string)($data['sent_on'] ?? ''));
        $netAmount = null;
        if (isset($data['net_amount']) && $data['net_amount'] !== '') {
            if (!is_numeric($data['net_amount'])) {
                return $this->error($response, 400, 'net_amount must be numeric');
            }
            $netAmount = (float)$data['net_amount'];
        }
        if ($employeeId <= 0 || $summary === '' || $sentOnStr === '') {
            return $this->error($response, 400, 'employee_id, summary, sent_on are required');
        }
        $sentOn = AppTime::parseDate($sentOnStr);
        if ($sentOn === null) {
            return $this->error($response, 400, 'invalid sent_on');
        }

        try {
            $result = $this->service->send([
                'tenant_id' => $admin['tenant_id'],
                'employee_id' => $employeeId,
                'uploaded_by' => $admin['id'],
                'sent_on' => $sentOn,
                'summary' => $summary,
                'net_amount' => $netAmount,
            ]);
        } catch (\Throwable $e) {
            return $this->error($response, 400, 'send_failed');
        }
        $response->getBody()->write(json_encode(['ok' => true, 'id' => $result['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function showForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $admin = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $employees = $this->repository->listEmployeesForTenant($admin['tenant_id'], 200);
        $maxUploadMb = filter_var($_ENV['PAYSLIP_UPLOAD_MAX_MB'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 50]]);
        if ($maxUploadMb === false) {
            $maxUploadMb = 10;
        }
        $html = $this->view->renderWithLayout('admin_payslips_send', [
            'title' => '給与明細送信',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'employees' => $employees,
            'maxUploadMb' => (int)$maxUploadMb,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function sendFromForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips/send');
        }
        try {
            $admin = $this->requireAdmin($request);
        } catch (\Throwable $e) {
            Flash::add('error', '権限がありません。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $data = (array)$request->getParsedBody();
        $employeeId = isset($data['employee_id']) ? (int)$data['employee_id'] : 0;
        $summary = trim((string)($data['summary'] ?? ''));
        $sentOnStr = trim((string)($data['sent_on'] ?? ''));
        $netAmount = null;
        if (isset($data['net_amount']) && $data['net_amount'] !== '') {
            if (!is_numeric($data['net_amount'])) {
                Flash::add('error', '支給額は数値で入力してください。');
                return $response->withStatus(303)->withHeader('Location', '/admin/payslips/send');
            }
            $netAmount = (float)$data['net_amount'];
        }
        if ($employeeId <= 0 || $summary === '' || $sentOnStr === '') {
            Flash::add('error', '従業員・支給日・概要は必須です。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips/send');
        }
        $sentOn = AppTime::parseDate($sentOnStr);
        if ($sentOn === null) {
            Flash::add('error', '支給日の形式が不正です。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips/send');
        }

        $uploadedFile = $this->getOptionalUploadedPayslip($request);
        if ($uploadedFile !== null && $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            Flash::add('error', '給与明細ファイルのアップロードに失敗しました。');
            return $response->withStatus(303)->withHeader('Location', '/admin/payslips/send');
        }
        try {
            $this->service->send([
                'tenant_id' => $admin['tenant_id'],
                'employee_id' => $employeeId,
                'uploaded_by' => $admin['id'],
                'sent_on' => $sentOn,
                'summary' => $summary,
                'net_amount' => $netAmount,
            ], $uploadedFile);
            Flash::add('success', '給与明細を送信しました。');
        } catch (\RuntimeException $e) {
            Flash::add('error', $e->getMessage());
        } catch (\Throwable) {
            Flash::add('error', '送信に失敗しました。');
        }
        return $response->withStatus(303)->withHeader('Location', '/admin/payslips/send');
    }

    /**
     * @return array{id:int,tenant_id:int,email:string,role:string}
     */
    private function requireAdmin(ServerRequestInterface $request): array
    {
        $sessionUser = $request->getAttribute('currentUser');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            throw new \RuntimeException('認証が必要です。');
        }
        $user = $this->repository->findUserById((int)$sessionUser['id']);
        if ($user === null || !in_array(($user['role'] ?? ''), ['admin', 'tenant_admin'], true)) {
            throw new \RuntimeException('権限がありません。');
        }
        if ($user['tenant_id'] === null) {
            throw new \RuntimeException('テナントに所属していません。');
        }
        return [
            'id' => $user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    private function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }

    private function getOptionalUploadedPayslip(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        if (!is_array($files) || !isset($files['payslip_file'])) {
            return null;
        }
        $file = $files['payslip_file'];
        if (!$file instanceof UploadedFileInterface) {
            return null;
        }
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }
}
