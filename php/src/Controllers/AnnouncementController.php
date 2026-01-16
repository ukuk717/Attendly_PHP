<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Services\AnnouncementService;
use Attendly\Support\Flash;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AnnouncementController
{
    private View $view;
    private AnnouncementService $service;

    public function __construct(?View $view = null, ?AnnouncementService $service = null)
    {
        $this->view = $view ?? new View(dirname(__DIR__, 2) . '/views');
        $this->service = $service ?? new AnnouncementService();
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $query = $request->getQueryParams();
        $page = $this->sanitizePage($query['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $total = $this->service->countForUser((int)$user['id']);
        $rows = $this->service->listForUser((int)$user['id'], $limit, $offset);

        $pagination = [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasPrev' => $page > 1,
            'hasNext' => ($offset + $limit) < $total,
        ];

        $html = $this->view->renderWithLayout('announcements', [
            'title' => 'お知らせ',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'currentPath' => $request->getUri()->getPath(),
            'announcements' => $rows,
            'pagination' => $pagination,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $announcementId = isset($args['id']) ? (int)$args['id'] : 0;
        if ($announcementId <= 0) {
            Flash::add('error', '対象のお知らせが見つかりません。');
            return $response->withStatus(303)->withHeader('Location', '/announcements');
        }

        $this->service->markRead((int)$user['id'], $announcementId);
        Flash::add('success', '確認済みに更新しました。');

        $body = (array)$request->getParsedBody();
        $redirect = $this->sanitizeRedirect($body['redirect'] ?? '/announcements');
        return $response->withStatus(303)->withHeader('Location', $redirect);
    }

    public function markAllRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $body = (array)$request->getParsedBody();
        $ids = $this->sanitizeIdList($body['announcement_ids'] ?? []);
        $this->service->markReadBulk((int)$user['id'], $ids);
        Flash::add('success', 'お知らせを確認済みに更新しました。');

        $redirect = $this->sanitizeRedirect($body['redirect'] ?? '/announcements');
        return $response->withStatus(303)->withHeader('Location', $redirect);
    }

    private function sanitizePage(mixed $value): int
    {
        $page = (int)$value;
        if ($page < 1) {
            $page = 1;
        }
        return $page;
    }

    private function sanitizeRedirect(mixed $value): string
    {
        if (!is_string($value)) {
            return '/announcements';
        }
        $value = trim($value);
        if ($value === '' || !str_starts_with($value, '/')) {
            return '/announcements';
        }
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            return '/announcements';
        }
        return $value;
    }

    /**
     * @return int[]
     */
    private function sanitizeIdList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
