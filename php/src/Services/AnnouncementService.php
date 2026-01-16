<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\MarkdownRenderer;
use DateTimeInterface;

final class AnnouncementService
{
    private Repository $repository;
    private MarkdownRenderer $renderer;

    public function __construct(?Repository $repository = null, ?MarkdownRenderer $renderer = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->renderer = $renderer ?? new MarkdownRenderer();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listForUser(int $userId, int $limit, int $offset = 0): array
    {
        $rows = $this->repository->listActiveAnnouncementsForUser($userId, $limit, $offset);
        return $this->formatAnnouncementRows($rows);
    }

    public function countForUser(int $userId): int
    {
        return $this->repository->countActiveAnnouncementsForUser($userId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listForLoginModal(int $userId, int $limit): array
    {
        $rows = $this->repository->listLoginAnnouncementsForUser($userId, $limit);
        return $this->formatAnnouncementRows($rows);
    }

    public function markRead(int $userId, int $announcementId): void
    {
        $this->repository->markAnnouncementRead($userId, $announcementId, AppTime::now());
    }

    /**
     * @param int[] $announcementIds
     */
    public function markReadBulk(int $userId, array $announcementIds): void
    {
        $ids = array_values(array_unique(array_filter($announcementIds, static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        $this->repository->markAnnouncementsRead($userId, $ids, AppTime::now());
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function formatAnnouncementRows(array $rows): array
    {
        $formatted = [];
        foreach ($rows as $row) {
            $publishedAt = $row['publish_start_at'] ?? $row['created_at'] ?? null;
            $publishedLabel = $this->formatDate($publishedAt);
            $formatted[] = [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'type' => (string)$row['type'],
                'type_label' => $this->labelType((string)$row['type']),
                'is_pinned' => !empty($row['is_pinned']),
                'show_on_login' => !empty($row['show_on_login']),
                'is_read' => !empty($row['read_at']),
                'published_at' => $publishedLabel,
                'body_html' => $this->renderer->render((string)$row['body']),
            ];
        }
        return $formatted;
    }

    private function formatDate(DateTimeInterface|string|null $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->setTimezone(AppTime::timezone())->format('Y-m-d');
        }
        if (is_string($value) && $value !== '') {
            $dt = AppTime::fromStorage($value);
            if ($dt instanceof DateTimeInterface) {
                return $dt->format('Y-m-d');
            }
        }
        return '';
    }

    private function labelType(string $type): string
    {
        return match ($type) {
            'maintenance' => 'メンテナンス',
            'outage' => '障害',
            'feature' => '機能更新',
            default => 'その他',
        };
    }
}
