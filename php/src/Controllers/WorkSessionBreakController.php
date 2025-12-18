<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class WorkSessionBreakController
{
    private Repository $repository;

    public function __construct(?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
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
            $this->repository->startWorkSessionBreakAtomic((int)$user['id'], AppTime::now(), 'rest');
            Flash::add('success', '休憩を開始しました。');
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                Flash::add('error', '休憩機能が未設定です。DBスキーマを適用してください。');
            } else {
                $this->logException('work_session_break_start_pdo', $e);
                Flash::add('error', '休憩開始に失敗しました。');
            }
        } catch (\Throwable $e) {
            $this->logException('work_session_break_start', $e);
            Flash::add('error', '休憩開始に失敗しました。');
        }

        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    public function end(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
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
            $this->repository->endWorkSessionBreakAtomic((int)$user['id'], AppTime::now());
            Flash::add('success', '休憩を終了しました。');
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                Flash::add('error', '休憩機能が未設定です。DBスキーマを適用してください。');
            } else {
                $this->logException('work_session_break_end_pdo', $e);
                Flash::add('error', '休憩終了に失敗しました。');
            }
        } catch (\Throwable $e) {
            $this->logException('work_session_break_end', $e);
            Flash::add('error', '休憩終了に失敗しました。');
        }

        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    private function isValidCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = (string)($body['csrf_token'] ?? '');
        return $token !== '' && hash_equals(CsrfToken::getToken(), $token);
    }

    private function logException(string $context, \Throwable $e): void
    {
        $message = str_replace(["\r", "\n"], ' ', $e->getMessage());
        $code = $e->getCode();
        error_log(sprintf('[%s] %s code=%s message=%s', $context, get_class($e), (string)$code, $message));
    }
}
