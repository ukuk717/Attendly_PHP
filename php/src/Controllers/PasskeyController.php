<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\Base64Url;
use Attendly\Support\ClientIpResolver;
use Attendly\Support\Flash;
use Attendly\Support\RateLimiter;
use Attendly\Support\SessionAuth;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PasskeyController
{
    private Repository $repository;
    private int $timeoutSeconds;
    private int $maxPerUser;
    private int $reauthTtlSeconds;
    private string $rpId;
    private string $rpName;
    /** @var string[] */
    private array $allowedOrigins;
    /** @var string[] */
    private array $allowedFormats;

    public function __construct(?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->timeoutSeconds = $this->sanitizeInt($_ENV['PASSKEY_TIMEOUT_SECONDS'] ?? 60, 60, 10, 300);
        $this->maxPerUser = $this->sanitizeInt($_ENV['PASSKEY_MAX_PER_USER'] ?? 10, 10, 1, 20);
        $this->reauthTtlSeconds = $this->sanitizeInt($_ENV['PASSKEY_REAUTH_SECONDS'] ?? 900, 900, 60, 3600);
        $this->rpName = trim((string)($_ENV['APP_BRAND_NAME'] ?? 'Attendly'));
        $this->rpId = $this->resolveRpId();
        $this->allowedOrigins = $this->resolveAllowedOrigins();
        $this->allowedFormats = $this->resolveAllowedFormats();
    }

    public function registrationOptions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getCurrentUser($request);
        if ($user === null) {
            return $this->json($response, ['error' => 'not_authenticated'], 401);
        }
        if (!$this->hasRecentAuthentication()) {
            SessionAuth::clear();
            return $this->json($response, ['error' => 'reauth_required'], 401);
        }

        $currentCount = $this->repository->countPasskeysByUser($user['id']);
        if ($currentCount >= $this->maxPerUser) {
            return $this->json($response, ['error' => 'limit_reached', 'message' => '登録できるパスキーの上限に達しました。'], 400);
        }

        if (!$this->allowRateLimit($request, [
            ["passkey_register_user:{$user['id']}", 10, 3600],
        ])) {
            return $this->json($response, ['error' => 'rate_limited'], 429);
        }

        try {
            $webauthn = $this->createWebAuthn();
        } catch (\Throwable $e) {
            error_log('[passkey] WebAuthn init failed');
            return $this->json($response, ['error' => 'unsupported'], 500);
        }

        $userId = (string)$user['id'];
        $userName = $user['email'] ?? '';
        if ($userName === '') {
            $userName = 'user-' . $userId;
        }
        $userDisplay = $userName;
        if (!empty($user['display_name']) && is_string($user['display_name'])) {
            $display = trim($user['display_name']);
            if ($display !== '') {
                $userDisplay = $display;
            }
        }
        $exclude = [];
        foreach ($this->repository->listPasskeysByUser($user['id']) as $passkey) {
            $binaryId = Base64Url::decode($passkey['credential_id']);
            if ($binaryId !== null) {
                $exclude[] = $binaryId;
            }
        }

        try {
            $args = $webauthn->getCreateArgs(
                $userId,
                $userName,
                $userDisplay,
                $this->timeoutSeconds,
                'required',
                'required',
                null,
                $exclude
            );
        } catch (\Throwable $e) {
            error_log('[passkey] Failed to build registration options');
            return $this->json($response, ['error' => 'failed_to_prepare'], 500);
        }

        $challenge = $webauthn->getChallenge();
        $challengeBin = $challenge instanceof ByteBuffer ? $challenge->getBinaryString() : (string)$challenge;
        $challengeEncoded = Base64Url::encode($challengeBin);
        SessionAuth::setPendingPasskeyRegistration($user['id'], $challengeEncoded);

        return $this->json($response, ['publicKey' => $args->publicKey]);
    }

    public function registrationVerify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getCurrentUser($request);
        if ($user === null) {
            return $this->json($response, ['error' => 'not_authenticated'], 401);
        }
        if (!$this->hasRecentAuthentication()) {
            SessionAuth::clear();
            return $this->json($response, ['error' => 'reauth_required'], 401);
        }

        $pending = SessionAuth::getPendingPasskeyRegistration();
        if ($pending === null || $pending['user_id'] !== $user['id']) {
            return $this->json($response, ['error' => 'invalid_session'], 400);
        }
        SessionAuth::clearPendingPasskeyRegistration();

        if (!$this->allowRateLimit($request, [
            ["passkey_register_verify_user:{$user['id']}", 20, 3600],
        ])) {
            return $this->json($response, ['error' => 'rate_limited'], 429);
        }

        $payload = $this->getJsonBody($request);
        if ($payload === []) {
            return $this->json($response, ['error' => 'invalid_payload'], 400);
        }

        $credentialId = $this->normalizeString($payload['id'] ?? $payload['rawId'] ?? '');
        $responseData = is_array($payload['response'] ?? null) ? $payload['response'] : [];
        $clientDataJson = $this->normalizeString($responseData['clientDataJSON'] ?? '');
        $attestationObject = $this->normalizeString($responseData['attestationObject'] ?? '');

        if ($credentialId === '' || $clientDataJson === '' || $attestationObject === '') {
            return $this->json($response, ['error' => 'invalid_payload'], 400);
        }

        $clientDataBinary = Base64Url::decode($clientDataJson);
        $attestationBinary = Base64Url::decode($attestationObject);
        $challengeBinary = Base64Url::decode($pending['challenge']);
        if ($clientDataBinary === null || $attestationBinary === null || $challengeBinary === null) {
            return $this->json($response, ['error' => 'invalid_payload'], 400);
        }

        if (!$this->isOriginAllowedFromClientData($clientDataBinary)) {
            return $this->json($response, ['error' => 'invalid_origin'], 400);
        }

        try {
            $webauthn = $this->createWebAuthn();
            $result = $webauthn->processCreate(
                $clientDataBinary,
                $attestationBinary,
                $challengeBinary,
                true,
                true,
                true,
                false
            );
        } catch (WebAuthnException $e) {
            return $this->json($response, ['error' => 'verification_failed'], 400);
        } catch (\Throwable $e) {
            error_log('[passkey] registration verify failed');
            return $this->json($response, ['error' => 'verification_failed'], 400);
        }

        $binaryId = $result->credentialId ?? null;
        if (!is_string($binaryId) || $binaryId === '') {
            return $this->json($response, ['error' => 'invalid_credential'], 400);
        }

        $publicKey = $result->credentialPublicKey ?? null;
        if (!is_string($publicKey) || $publicKey === '') {
            return $this->json($response, ['error' => 'invalid_credential'], 400);
        }

        $label = $this->sanitizeLabel($payload['label'] ?? null);
        $transports = $this->sanitizeTransports($payload['transports'] ?? null);
        $encodedId = Base64Url::encode($binaryId);
        if ($credentialId !== '' && !hash_equals($encodedId, $credentialId)) {
            return $this->json($response, ['error' => 'invalid_credential'], 400);
        }
        $userHandle = Base64Url::encode((string)$user['id']);
        $signCount = $webauthn->getSignatureCounter() ?? 0;

        try {
            $this->repository->createPasskey(
                $user['id'],
                $encodedId,
                $publicKey,
                $signCount,
                $userHandle,
                $label,
                $transports
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->json($response, ['error' => 'already_registered'], 400);
            }
            error_log('[passkey] failed to store passkey');
            return $this->json($response, ['error' => 'failed_to_store'], 500);
        } catch (\Throwable $e) {
            error_log('[passkey] failed to store passkey');
            return $this->json($response, ['error' => 'failed_to_store'], 500);
        }

        Flash::add('success', 'パスキーを登録しました。');
        return $this->json($response, ['ok' => true]);
    }

    public function loginOptions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->getJsonBody($request);
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $allowCredentials = [];
        $allowIds = [];

        if ($email !== '' && mb_strlen($email, 'UTF-8') <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = $this->repository->findUserByEmail($email);
            if ($user === null || ($user['status'] !== null && $user['status'] !== 'active')) {
                return $this->json($response, ['error' => 'not_available'], 400);
            }
            $role = $user['role'] ?? null;
            $allowedRoles = ['admin', 'tenant_admin', 'employee'];
            if ($role !== null && !in_array($role, $allowedRoles, true)) {
                return $this->json($response, ['error' => 'not_available'], 400);
            }
            $lockedUntil = $user['locked_until'] ?? null;
            if ($lockedUntil instanceof \DateTimeImmutable && $lockedUntil > AppTime::now()) {
                return $this->json($response, ['error' => 'not_available'], 400);
            }
            $passkeys = $this->repository->listPasskeysByUser((int)$user['id']);
            if ($passkeys === []) {
                return $this->json($response, ['error' => 'not_available'], 400);
            }
            foreach ($passkeys as $passkey) {
                $binaryId = Base64Url::decode($passkey['credential_id']);
                if ($binaryId !== null) {
                    $allowCredentials[] = $binaryId;
                    $allowIds[] = $passkey['credential_id'];
                }
            }
        }

        if (!$this->allowRateLimit($request, [])) {
            return $this->json($response, ['error' => 'rate_limited'], 429);
        }

        try {
            $webauthn = $this->createWebAuthn();
            $args = $webauthn->getGetArgs(
                $allowCredentials,
                $this->timeoutSeconds,
                true,
                true,
                true,
                true,
                true,
                'required'
            );
        } catch (\Throwable $e) {
            error_log('[passkey] Failed to build login options');
            return $this->json($response, ['error' => 'failed_to_prepare'], 500);
        }

        $challenge = $webauthn->getChallenge();
        $challengeBin = $challenge instanceof ByteBuffer ? $challenge->getBinaryString() : (string)$challenge;
        $challengeEncoded = Base64Url::encode($challengeBin);
        SessionAuth::setPendingPasskeyLogin($challengeEncoded, $allowIds);

        return $this->json($response, ['publicKey' => $args->publicKey]);
    }

    public function loginVerify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pending = SessionAuth::getPendingPasskeyLogin();
        if ($pending === null) {
            return $this->json($response, ['error' => 'invalid_session'], 400);
        }
        SessionAuth::clearPendingPasskeyLogin();

        $payload = $this->getJsonBody($request);
        if ($payload === []) {
            return $this->json($response, ['error' => 'invalid_payload'], 400);
        }

        $credentialId = $this->normalizeString($payload['id'] ?? $payload['rawId'] ?? '');
        $responseData = is_array($payload['response'] ?? null) ? $payload['response'] : [];
        $clientDataJson = $this->normalizeString($responseData['clientDataJSON'] ?? '');
        $authenticatorData = $this->normalizeString($responseData['authenticatorData'] ?? '');
        $signature = $this->normalizeString($responseData['signature'] ?? '');
        $userHandle = $this->normalizeString($responseData['userHandle'] ?? '');

        if ($credentialId === '' || $clientDataJson === '' || $authenticatorData === '' || $signature === '') {
            return $this->json($response, ['error' => 'invalid_payload'], 400);
        }

        if ($pending['allow_ids'] !== [] && !in_array($credentialId, $pending['allow_ids'], true)) {
            return $this->json($response, ['error' => 'invalid_credential'], 400);
        }

        $passkey = $this->repository->findPasskeyByCredentialId($credentialId);
        if ($passkey === null) {
            return $this->json($response, ['error' => 'invalid_credential'], 400);
        }

        $user = $this->repository->findUserById($passkey['user_id']);
        if ($user === null || ($user['status'] !== null && $user['status'] !== 'active')) {
            return $this->json($response, ['error' => 'inactive_user'], 400);
        }
        $lockedUntil = $user['locked_until'] ?? null;
        if ($lockedUntil instanceof \DateTimeImmutable && $lockedUntil > AppTime::now()) {
            return $this->json($response, ['error' => 'inactive_user'], 400);
        }
        $allowedRoles = ['admin', 'tenant_admin', 'employee'];
        $role = $user['role'] ?? null;
        if ($role !== null && !in_array($role, $allowedRoles, true)) {
            return $this->json($response, ['error' => 'inactive_user'], 400);
        }

        if ($userHandle !== '' && $passkey['user_handle'] !== '' && $userHandle !== $passkey['user_handle']) {
            return $this->json($response, ['error' => 'invalid_user'], 400);
        }

        $clientDataBinary = Base64Url::decode($clientDataJson);
        $authenticatorBinary = Base64Url::decode($authenticatorData);
        $signatureBinary = Base64Url::decode($signature);
        $challengeBinary = Base64Url::decode($pending['challenge']);

        if ($clientDataBinary === null || $authenticatorBinary === null || $signatureBinary === null || $challengeBinary === null) {
            return $this->json($response, ['error' => 'invalid_payload'], 400);
        }

        if (!$this->isOriginAllowedFromClientData($clientDataBinary)) {
            return $this->json($response, ['error' => 'invalid_origin'], 400);
        }

        if (!$this->allowRateLimit($request, [])) {
            return $this->json($response, ['error' => 'rate_limited'], 429);
        }

        try {
            $webauthn = $this->createWebAuthn();
            $webauthn->processGet(
                $clientDataBinary,
                $authenticatorBinary,
                $signatureBinary,
                $passkey['public_key'],
                $challengeBinary,
                $passkey['sign_count'],
                true,
                true
            );
        } catch (WebAuthnException $e) {
            return $this->json($response, ['error' => 'verification_failed'], 400);
        } catch (\Throwable $e) {
            error_log('[passkey] login verify failed');
            return $this->json($response, ['error' => 'verification_failed'], 400);
        }

        $newCount = $webauthn->getSignatureCounter();
        try {
            $this->repository->touchPasskeyUsed($passkey['id'], $newCount);
        } catch (\Throwable) {
            error_log('[passkey] failed to update passkey sign_count');
        }

        try {
            $this->repository->resetLoginFailures((int)$user['id']);
        } catch (\Throwable) {
            // ignore reset failures
        }

        $loginResponse = $this->completeLogin($request, $response, [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'tenant_id' => $user['tenant_id'],
        ]);
        return $this->json($loginResponse, ['ok' => true, 'redirect' => '/dashboard']);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->getCurrentUser($request);
        if ($user === null) {
            Flash::add('error', '再度ログインしてください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        if (!$this->hasRecentAuthentication()) {
            SessionAuth::clear();
            Flash::add('error', 'セキュリティ保護のため、再度ログインしてから操作を実行してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $id = isset($args['id']) ? (int)$args['id'] : 0;
        if ($id <= 0) {
            Flash::add('error', 'パスキーを特定できませんでした。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        try {
            $deleted = $this->repository->deletePasskeyById($user['id'], $id);
        } catch (\Throwable) {
            Flash::add('error', 'パスキーの削除に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        if (!$deleted) {
            Flash::add('error', 'パスキーを削除できませんでした。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        Flash::add('success', 'パスキーを削除しました。');
        return $response->withStatus(303)->withHeader('Location', '/account');
    }

    private function getCurrentUser(ServerRequestInterface $request): ?array
    {
        $current = $request->getAttribute('currentUser');
        if (!is_array($current) || empty($current['id'])) {
            return null;
        }
        return [
            'id' => (int)$current['id'],
            'email' => (string)($current['email'] ?? ''),
            'role' => $current['role'] ?? null,
            'tenant_id' => isset($current['tenant_id']) ? (int)$current['tenant_id'] : null,
            'display_name' => $current['name'] ?? null,
        ];
    }

    private function completeLogin(ServerRequestInterface $request, ResponseInterface $response, array $user): ResponseInterface
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $sessionKey = bin2hex(random_bytes(32));
        SessionAuth::setSessionKey($sessionKey);
        SessionAuth::setUser([
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'] ?? null,
            'tenant_id' => $user['tenant_id'] ?? null,
        ]);
        SessionAuth::clearPendingMfa();

        $loginIp = null;
        try {
            $loginIp = ClientIpResolver::resolve($request);
        } catch (\Throwable) {
            $loginIp = null;
        }
        $loginUa = $this->sanitizeUserAgent((string)$request->getHeaderLine('User-Agent'));

        $sessionHash = hash('sha256', $sessionKey);
        try {
            $this->repository->upsertUserActiveSession((int)$user['id'], $sessionHash, $loginIp, $loginUa);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                error_log('[auth] user_active_sessions table missing; concurrent login control is disabled until schema is applied');
            } else {
                error_log('[auth] failed to upsert user_active_sessions on login');
            }
        } catch (\Throwable) {
            error_log('[auth] failed to upsert user_active_sessions on login');
        }

        Flash::add('success', 'パスキーでログインしました。');
        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    private function sanitizeUserAgent(string $ua): ?string
    {
        $ua = preg_replace('/[\r\n]/', ' ', $ua) ?? '';
        $ua = trim($ua);
        if ($ua === '') {
            return null;
        }
        if (mb_strlen($ua, 'UTF-8') > 512) {
            $ua = mb_substr($ua, 0, 512, 'UTF-8');
        }
        return $ua;
    }

    private function hasRecentAuthentication(): bool
    {
        return SessionAuth::hasRecentAuthentication($this->reauthTtlSeconds);
    }

    private function createWebAuthn(): WebAuthn
    {
        return new WebAuthn($this->rpName, $this->rpId, $this->allowedFormats, true);
    }

    private function resolveAllowedFormats(): array
    {
        $raw = trim((string)($_ENV['PASSKEY_ALLOWED_FORMATS'] ?? 'none'));
        if ($raw === '') {
            return ['none'];
        }
        $formats = array_filter(array_map('trim', explode(',', $raw)));
        $formats = array_map('strtolower', $formats);
        return $formats !== [] ? $formats : ['none'];
    }

    private function resolveRpId(): string
    {
        $override = trim((string)($_ENV['PASSKEY_RP_ID'] ?? ''));
        if ($override !== '') {
            return $override;
        }
        $baseUrl = trim((string)($_ENV['APP_BASE_URL'] ?? ''));
        if ($baseUrl !== '') {
            $parts = parse_url($baseUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                return (string)$parts['host'];
            }
        }
        return 'localhost';
    }

    /**
     * @return string[]
     */
    private function resolveAllowedOrigins(): array
    {
        $raw = trim((string)($_ENV['PASSKEY_ALLOWED_ORIGINS'] ?? ''));
        if ($raw !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        $single = trim((string)($_ENV['PASSKEY_ORIGIN'] ?? ''));
        if ($single !== '') {
            return [$single];
        }
        $baseUrl = trim((string)($_ENV['APP_BASE_URL'] ?? ''));
        if ($baseUrl !== '') {
            $origin = $this->originFromBaseUrl($baseUrl);
            if ($origin !== null) {
                return [$origin];
            }
        }
        return [];
    }

    private function originFromBaseUrl(string $baseUrl): ?string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    private function isOriginAllowedFromClientData(string $clientDataJson): bool
    {
        if ($this->allowedOrigins === []) {
            return true;
        }
        $decoded = json_decode($clientDataJson, true);
        if (!is_array($decoded)) {
            return false;
        }
        $origin = $decoded['origin'] ?? '';
        if (!is_string($origin) || $origin === '') {
            return false;
        }
        foreach ($this->allowedOrigins as $allowed) {
            if (hash_equals($allowed, $origin)) {
                return true;
            }
        }
        return false;
    }

    private function sanitizeLabel(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $label = trim($value);
        if ($label === '') {
            return null;
        }
        if (preg_match('/[\r\n]/', $label)) {
            return null;
        }
        if (mb_strlen($label, 'UTF-8') > 64) {
            $label = mb_substr($label, 0, 64, 'UTF-8');
        }
        return $label;
    }

    /**
     * @return string[]|null
     */
    private function sanitizeTransports(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $allowed = ['usb', 'nfc', 'ble', 'hybrid', 'internal'];
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = strtolower(trim($item));
            if ($item !== '' && in_array($item, $allowed, true) && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result === [] ? null : $result;
    }

    private function getJsonBody(ServerRequestInterface $request): array
    {
        $raw = (string)$request->getBody()->getContents();
        if ($raw === '') {
            $parsed = $request->getParsedBody();
            return is_array($parsed) ? $parsed : [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeString(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }

    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function sanitizeInt(int|string $value, int $default, int $min, int $max): int
    {
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false) {
            return $default;
        }
        return max($min, min($max, (int)$intVal));
    }

    private function allowRateLimit(ServerRequestInterface $request, array $limits): bool
    {
        $keys = $limits;
        try {
            $ip = ClientIpResolver::resolve($request);
            $keys[] = ["passkey_ip:{$ip}", 60, 600];
        } catch (\RuntimeException) {
            // IPが取れなくても固定の枠で継続
            $keys[] = ['passkey_ip:unknown', 20, 600];
        }
        foreach ($keys as [$key, $max, $window]) {
            if (!RateLimiter::allow($key, $max, $window)) {
                return false;
            }
        }
        return true;
    }
}
