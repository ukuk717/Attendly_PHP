<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Services\WorkSessionService;
use Attendly\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class WorkSessionController
{
    public function __construct(private WorkSessionService $service = new WorkSessionService())
    {
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isValidCsrf($request)) {
            Flash::add('error', 'CSRFトークンが無効です。再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            Flash::add('error', 'ログインが必要です。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $errorId = uniqid('work_session_', true);
        $userRef = hash('sha256', (string)$user['id']);

        try {
            $result = $this->service->togglePunch((int)$user['id']);
            if ($result['status'] === 'opened') {
                Flash::add('success', '勤務を開始しました。');
            } else {
                Flash::add('success', '勤務を終了しました。');
                if (!empty($result['break_auto_closed'])) {
                    Flash::add('info', '休憩中の記録があったため、勤務終了時刻で休憩を自動終了しました。必要に応じて管理者へ訂正をご相談ください。');
                }
            }
        } catch (\Throwable $e) {
            $errorId = uniqid('work_session_', true);
            $userRef = hash('sha256', (string)$user['id']);
            error_log(sprintf(
                'Work session toggle failed [Error ID: %s, user_ref: %s]: %s',
                $errorId,
                $userRef,
                $e->getMessage()
            ));
            // TODO: Send full stack trace to secure logging sink with PII scrubbing
            // SecureLogger::logException($e, ['error_id' => $errorId, 'user_ref' => $userRef]);
            Flash::add('error', '打刻処理に失敗しました。時間をおいて再度お試しください。');
        }

        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }
}
