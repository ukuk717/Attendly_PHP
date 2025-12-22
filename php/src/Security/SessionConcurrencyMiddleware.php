<?php

declare(strict_types=1);

namespace Attendly\Security;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\Support\SessionAuth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 同時ログイン制御（単一セッション）:
 * - ログイン完了時に user_active_sessions.session_hash を更新（最新のみ有効）。
 * - 既存セッションが次にアクセスしたタイミングで不一致を検出し、ログアウトさせる。
 */
final class SessionConcurrencyMiddleware implements MiddlewareInterface
{
    private Repository $repository;

    public function __construct(?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = SessionAuth::getUser();
        if (!is_array($user) || empty($user['id'])) {
            return $handler->handle($request);
        }

        $userId = (int)$user['id'];
        $sessionKey = SessionAuth::getSessionKey();
        if ($sessionKey === null || $sessionKey === '') {
            SessionAuth::clear();
            Flash::add('error', 'セッションが無効になりました。ログインし直してください。');
            return $handler->handle($request);
        }
        $sessionHash = hash('sha256', $sessionKey);

        try {
            $record = $this->repository->findUserActiveSession($userId);
        } catch (\PDOException $e) {
            // 移行途中でテーブル未作成の場合に全体を落とさない（要: DB適用）
            if ($e->getCode() === '42S02') {
                error_log('[auth] user_active_sessions table missing; concurrent login control is disabled until schema is applied');
                return $handler->handle($request);
            }
            throw $e;
        }

        if ($record === null) {
            SessionAuth::clear();
            Flash::add('error', 'セッションが無効になりました。ログインし直してください。');
            return $handler->handle($request);
        }

        if (!hash_equals((string)$record['session_hash'], $sessionHash)) {
            $message = $this->buildForcedLogoutMessage($record);
            $userRef = hash('sha256', (string)$userId);
            $ipRef = $record['last_login_ip'] !== null ? substr(hash('sha256', (string)$record['last_login_ip']), 0, 12) : 'unknown';
            $uaRef = $record['last_login_ua'] !== null ? substr(hash('sha256', (string)$record['last_login_ua']), 0, 12) : 'unknown';
            error_log(sprintf(
                '[auth] concurrent login: forced logout user_ref=%s login_ip_ref=%s login_ua_ref=%s',
                $userRef,
                $ipRef,
                $uaRef
            ));
            SessionAuth::clear();
            Flash::add('error', $message);
            return $handler->handle($request);
        }

        try {
            $loginSession = $this->repository->findLoginSessionByHash($sessionHash);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                return $handler->handle($request);
            }
            throw $e;
        }
        if ($loginSession !== null && $loginSession['revoked_at'] !== null) {
            SessionAuth::clear();
            Flash::add('error', 'セッションが失効しました。ログインし直してください。');
            return $handler->handle($request);
        }

        return $handler->handle($request);
    }

    /**
     * @param array{last_login_at:\DateTimeImmutable,last_login_ip:?string,last_login_ua:?string} $record
     */
    private function buildForcedLogoutMessage(array $record): string
    {
        $when = $record['last_login_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i');
        // Mask IP for privacy (show only first 2 octets)
        $ip = $record['last_login_ip'] !== null 
            ? preg_replace('/(\d+\.\d+)\.\d+\.\d+/', '$1.x.x', (string)$record['last_login_ip'])
            : null;
        // Simplify UA to just device type
        $ua = $record['last_login_ua'] !== null 
            ? (preg_match('/mobile/i', (string)$record['last_login_ua']) ? 'モバイル端末' : 'デスクトップ')
            : null;

        $parts = ["時刻: {$when}"];
        if ($ip !== null && $ip !== '') {
            $parts[] = "IP: {$ip}";
        }
        if ($ua !== null && $ua !== '') {
            $parts[] = "端末: {$ua}";
        }
        $detail = implode(' / ', $parts);

        return "別の端末でログインがあったためログアウトしました。（{$detail}）";
    }
}
