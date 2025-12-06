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

        try {
            $result = $this->service->togglePunch((int)$user['id']);
            if ($result['status'] === 'opened') {
                Flash::add('success', '勤務を開始しました。');
            } else {
                Flash::add('success', '勤務を終了しました。');
            }
        } catch (\Throwable $e) {
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
