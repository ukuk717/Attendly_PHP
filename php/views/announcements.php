<div class="page-header">
  <h2>お知らせ</h2>
  <p class="form-note">最新の通知を確認できます。</p>
</div>

<section class="card announcement-list-page">
  <?php if (empty($announcements)): ?>
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
          <h3 class="announcement-title"><?= $e($announcement['title'] ?? '') ?></h3>
          <div class="announcement-body"><?= $announcement['body_html'] ?? '' ?></div>
          <?php if (!$isRead): ?>
            <form method="post" action="/announcements/<?= $e((string)$announcement['id']) ?>/read" class="form-inline">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
              <input type="hidden" name="redirect" value="/announcements">
              <button type="submit" class="btn secondary">確認した</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!empty($pagination) && ($pagination['hasPrev'] ?? false || $pagination['hasNext'] ?? false)): ?>
    <div class="pagination">
      <?php if (!empty($pagination['hasPrev'])): ?>
        <a class="btn secondary" href="/announcements?page=<?= $e((string)($pagination['page'] - 1)) ?>">前へ</a>
      <?php endif; ?>
      <span class="form-note">ページ <?= $e((string)$pagination['page']) ?></span>
      <?php if (!empty($pagination['hasNext'])): ?>
        <a class="btn secondary" href="/announcements?page=<?= $e((string)($pagination['page'] + 1)) ?>">次へ</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
