<?php
  $modalAnnouncements = is_array($announcementModal ?? null) ? $announcementModal : [];
  if ($modalAnnouncements === []) {
      return;
  }
  $redirectPath = is_string($currentPath ?? null) ? $currentPath : '/dashboard';
  $redirectPath = $redirectPath !== '' && str_starts_with($redirectPath, '/') ? $redirectPath : '/dashboard';
?>
<div class="modal announcement-modal is-active" data-announcement-modal aria-hidden="false">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__content" role="dialog" aria-modal="true" aria-labelledby="announcement-modal-title">
    <div class="modal__header">
      <h2 id="announcement-modal-title">お知らせ</h2>
      <button type="button" class="modal__close" aria-label="閉じる" data-modal-close>×</button>
    </div>
    <div class="modal__body">
      <ul class="announcement-list modal-list">
        <?php foreach ($modalAnnouncements as $announcement): ?>
          <?php
            $typeClass = match ($announcement['type'] ?? '') {
              'maintenance' => 'maintenance',
              'outage' => 'outage',
              'feature' => 'feature',
              default => 'other',
            };
          ?>
          <li class="announcement-item is-unread">
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
            </div>
            <h4 class="announcement-title"><?= $e($announcement['title'] ?? '') ?></h4>
            <div class="announcement-body"><?= $announcement['body_html'] ?? '' ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="modal__footer">
      <form method="post" action="/announcements/mark-all-read" class="form-inline">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
        <input type="hidden" name="redirect" value="<?= $e($redirectPath) ?>">
        <?php foreach ($modalAnnouncements as $announcement): ?>
          <input type="hidden" name="announcement_ids[]" value="<?= $e((string)$announcement['id']) ?>">
        <?php endforeach; ?>
        <button type="submit" class="btn primary">まとめて確認</button>
      </form>
      <button type="button" class="btn secondary" data-modal-close>閉じる</button>
    </div>
  </div>
</div>
