<?php
  $announcements = is_array($announcementBox ?? null) ? $announcementBox : [];
  $redirectPath = is_string($currentPath ?? null) ? $currentPath : '/dashboard';
  $redirectPath = $redirectPath !== '' && str_starts_with($redirectPath, '/') ? $redirectPath : '/dashboard';
?>
<section class="card announcement-box">
  <div class="card-header">
    <h3>お知らせ</h3>
    <a href="/announcements" class="card-link">一覧を見る</a>
  </div>
  <?php if ($announcements === []): ?>
    <p class="form-note">現在お知らせはありません。</p>
  <?php else: ?>
    <ul class="announcement-list">
      <?php foreach ($announcements as $announcement): ?>
        <?php
          $typeClass = match ($announcement['type'] ?? '') {
            'maintenance' => 'maintenance',
            'outage' => 'outage',
            'feature' => 'feature',
            default => 'other',
          };
          $isRead = !empty($announcement['is_read']);
        ?>
        <li class="announcement-item <?= $isRead ? 'is-read' : 'is-unread' ?>">
          <div class="announcement-meta">
            <span class="announcement-type <?= $e($typeClass) ?>">
              <?= $e($announcement['type_label'] ?? 'その他') ?>
            </span>
            <?php if (!empty($announcement['is_pinned'])): ?>
              <span class="announcement-pin">固定</span>
            <?php endif; ?>
            <?php if (!empty($announcement['published_at'])): ?>
              <span class="announcement-date"><?= $e($announcement['published_at']) ?></span>
            <?php endif; ?>
            <?php if (!$isRead): ?>
              <span class="announcement-unread">未読</span>
            <?php endif; ?>
          </div>
          <h4 class="announcement-title"><?= $e($announcement['title'] ?? '') ?></h4>
          <div class="announcement-body"><?= $announcement['body_html'] ?? '' ?></div>
          <?php if (!$isRead): ?>
            <form method="post" action="/announcements/<?= $e((string)$announcement['id']) ?>/read" class="form-inline">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
              <input type="hidden" name="redirect" value="<?= $e($redirectPath) ?>">
              <button type="submit" class="btn secondary">確認した</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
