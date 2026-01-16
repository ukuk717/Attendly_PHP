<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlatformAnnouncementsController
{
    private Repository $repository;
    private int $perPage;

    public function __construct(private View $view, ?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->perPage = 20;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $platform = $this->requirePlatformUser($request);
        $query = $request->getQueryParams();
        $page = $this->sanitizePage($query['page'] ?? 1);
        $status = $this->sanitizeStatus($query['status'] ?? '');

        $offset = ($page - 1) * $this->perPage;
        $total = $this->repository->countAnnouncementsForPlatform($status);
        $rows = $this->repository->listAnnouncementsForPlatform($this->perPage, $offset, $status);

        $announcements = array_map(static function (array $row): array {
            $start = $row['publish_start_at'] instanceof \DateTimeInterface
                ? $row['publish_start_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : '未設定';
            $end = $row['publish_end_at'] instanceof \DateTimeInterface
                ? $row['publish_end_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : '未設定';
            $updated = $row['updated_at'] instanceof \DateTimeInterface
                ? $row['updated_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                : '未設定';
            return [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'type' => (string)$row['type'],
                'status' => (string)$row['status'],
                'show_on_login' => !empty($row['show_on_login']),
                'is_pinned' => !empty($row['is_pinned']),
                'publish_start_display' => $start,
                'publish_end_display' => $end,
                'updated_display' => $updated,
            ];
        }, $rows);

        $pagination = [
            'page' => $page,
            'limit' => $this->perPage,
            'total' => $total,
            'hasPrev' => $page > 1,
            'hasNext' => ($offset + $this->perPage) < $total,
        ];

        $html = $this->view->renderWithLayout('platform_announcements', [
            'title' => 'お知らせ管理',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'platformUser' => $platform,
            'announcements' => $announcements,
            'pagination' => $pagination,
            'status' => $status,
        ], 'platform_layout');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function showCreate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $platform = $this->requirePlatformUser($request);
        $html = $this->view->renderWithLayout('platform_announcement_form', [
            'title' => 'お知らせ作成',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'platformUser' => $platform,
            'announcement' => null,
        ], 'platform_layout');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $platform = $this->requirePlatformUser($request);
        $body = (array)$request->getParsedBody();
        $validated = $this->validateInput($body);
        if (isset($validated['error'])) {
            Flash::add('error', $validated['error']);
            return $response->withStatus(303)->withHeader('Location', '/platform/announcements/new');
        }
        $payload = $validated['data'];
        $payload['created_by'] = $platform['id'];
        $this->repository->createAnnouncement($payload);
        Flash::add('success', 'お知らせを作成しました。');
        return $response->withStatus(303)->withHeader('Location', '/platform/announcements');
    }

    public function showEdit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $platform = $this->requirePlatformUser($request);
        $announcementId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($announcementId <= 0) {
            Flash::add('error', '対象のお知らせが見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/announcements');
        }
        $announcement = $this->repository->findAnnouncementById($announcementId);
        if ($announcement === null) {
            Flash::add('error', '対象のお知らせが見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/announcements');
        }

        $html = $this->view->renderWithLayout('platform_announcement_form', [
            'title' => 'お知らせ編集',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'platformUser' => $platform,
            'announcement' => $announcement,
        ], 'platform_layout');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requirePlatformUser($request);
        $announcementId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($announcementId <= 0) {
            Flash::add('error', '対象のお知らせが見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/announcements');
        }

        $body = (array)$request->getParsedBody();
        $validated = $this->validateInput($body);
        if (isset($validated['error'])) {
            Flash::add('error', $validated['error']);
            return $response->withStatus(303)->withHeader('Location', '/platform/announcements/' . $announcementId . '/edit');
        }
        $payload = $validated['data'];
        $this->repository->updateAnnouncement($announcementId, $payload);
        Flash::add('success', 'お知らせを更新しました。');
        return $response->withStatus(303)->withHeader('Location', '/platform/announcements');
    }

    public function archive(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requirePlatformUser($request);
        $announcementId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($announcementId <= 0) {
            Flash::add('error', '対象のお知らせが見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/platform/announcements');
        }
        $this->repository->archiveAnnouncement($announcementId);
        Flash::add('success', 'お知らせをアーカイブしました。');
        return $response->withStatus(303)->withHeader('Location', '/platform/announcements');
    }

    /**
     * @return array{id:int,email:string,name:?string}
     */
    private function requirePlatformUser(ServerRequestInterface $request): array
    {
        $sessionUser = $request->getAttribute('currentUser');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            throw new \RuntimeException('認証が必要です。');
        }
        if (!in_array(($sessionUser['role'] ?? null), ['platform_admin', 'admin'], true)) {
            throw new \RuntimeException('権限がありません。');
        }
        if (array_key_exists('tenant_id', $sessionUser) && $sessionUser['tenant_id'] !== null) {
            throw new \RuntimeException('プラットフォーム管理者ではありません。');
        }
        return [
            'id' => (int)$sessionUser['id'],
            'email' => (string)($sessionUser['email'] ?? ''),
            'name' => isset($sessionUser['name']) ? (string)$sessionUser['name'] : null,
        ];
    }

    private function sanitizePage(mixed $value): int
    {
        $page = (int)$value;
        if ($page < 1) {
            $page = 1;
        }
        return $page;
    }

    private function sanitizeStatus(mixed $value): ?string
    {
        $status = is_string($value) ? trim($value) : '';
        if ($status === '') {
            return null;
        }
        $allowed = ['draft', 'published', 'archived'];
        return in_array($status, $allowed, true) ? $status : null;
    }

    /**
     * @return array{data?:array<string,mixed>,error?:string}
     */
    private function validateInput(array $data): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255 || preg_match('/[\r\n]/', $title)) {
            return ['error' => 'タイトルは255文字以内で入力してください。'];
        }
        $body = trim((string)($data['body'] ?? ''));
        if ($body === '' || mb_strlen($body, 'UTF-8') > 10000) {
            return ['error' => '本文は1万文字以内で入力してください。'];
        }
        $type = (string)($data['type'] ?? '');
        $allowedTypes = ['maintenance', 'outage', 'feature', 'other'];
        if (!in_array($type, $allowedTypes, true)) {
            return ['error' => '種別が不正です。'];
        }
        $status = (string)($data['status'] ?? '');
        $allowedStatus = ['draft', 'published', 'archived'];
        if (!in_array($status, $allowedStatus, true)) {
            return ['error' => 'ステータスが不正です。'];
        }
        $showOnLogin = !empty($data['show_on_login']);
        $isPinned = !empty($data['is_pinned']);

        $publishStart = $this->parseDateTimeInput($data['publish_start_at'] ?? null);
        $publishEnd = $this->parseDateTimeInput($data['publish_end_at'] ?? null);
        if ($publishStart !== null && $publishEnd !== null && $publishEnd < $publishStart) {
            return ['error' => '公開終了日時は公開開始日時以降に設定してください。'];
        }

        return [
            'data' => [
                'title' => $title,
                'body' => $body,
                'type' => $type,
                'status' => $status,
                'show_on_login' => $showOnLogin,
                'is_pinned' => $isPinned,
                'publish_start_at' => $publishStart,
                'publish_end_at' => $publishEnd,
            ],
        ];
    }

    private function parseDateTimeInput(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, AppTime::timezone());
        } catch (\Throwable) {
            return null;
        }
    }
}
