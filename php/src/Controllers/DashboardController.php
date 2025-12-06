<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Services\WorkSessionService;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DashboardController
{
    private Repository $repository;
    private WorkSessionService $workSessions;
    private View $view;

    public function __construct(?View $view = null, ?Repository $repository = null, ?WorkSessionService $workSessions = null)
    {
        $this->view = $view ?? new View(dirname(__DIR__, 2) . '/views');
        $this->repository = $repository ?? new Repository();
        $this->workSessions = $workSessions ?? new WorkSessionService($this->repository);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUser = $request->getAttribute('currentUser');
        if (!is_array($currentUser) || empty($currentUser['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $role = (string)($currentUser['role'] ?? 'employee');
        if ($role === 'tenant_admin' || $role === 'admin') {
            return $this->renderTenantAdmin($request, $response, $currentUser);
        }
        return $this->renderEmployee($request, $response, $currentUser);
    }

    private function renderEmployee(ServerRequestInterface $request, ResponseInterface $response, array $user): ResponseInterface
    {
        $data = $this->workSessions->buildUserDashboardData((int)$user['id']);
        $html = $this->view->renderWithLayout('dashboard', [
            'title' => 'ダッシュボード',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'openSession' => $data['open_session'],
            'recentSessions' => $data['recent_sessions'],
            'dailySummary' => $data['daily_summary'],
            'monthlyTotal' => $data['monthly_total_formatted'],
            'timezone' => $data['timezone'],
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function renderTenantAdmin(ServerRequestInterface $request, ResponseInterface $response, array $user): ResponseInterface
    {
        if (empty($user['tenant_id'])) {
            Flash::add('error', 'テナント情報が不足しています。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $metrics = $this->workSessions->buildTenantDashboardData((int)$user['tenant_id']);
        $payrolls = $this->repository->listPayrollRecordsByTenant((int)$user['tenant_id'], 5);
        $payrollRows = array_map(static function (array $row): array {
            $sentOn = $row['sent_on'] instanceof \DateTimeInterface 
                ? $row['sent_on']->setTimezone(AppTime::timezone())->format('Y-m-d')
                : 'N/A';
            $sentAt = $row['sent_at'] instanceof \DateTimeInterface
                ? $row['sent_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : 'N/A';
            
            return [
                'id' => $row['id'],
                'sent_on' => $sentOn,
                'sent_at' => $sentAt,
                'file_name' => $row['original_file_name'],
            ];
        }, $payrolls);
        $metrics['recent_payrolls'] = $payrollRows;

        $html = $this->view->renderWithLayout('tenant_admin_dashboard', [
            'title' => 'テナント管理ダッシュボード',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'metrics' => $metrics,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
