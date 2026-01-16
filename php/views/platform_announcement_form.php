<?php
  $editing = is_array($announcement ?? null);
  $titleValue = $editing ? (string)($announcement['title'] ?? '') : '';
  $bodyValue = $editing ? (string)($announcement['body'] ?? '') : '';
  $typeValue = $editing ? (string)($announcement['type'] ?? 'other') : 'other';
  $statusValue = $editing ? (string)($announcement['status'] ?? 'draft') : 'draft';
  $showOnLogin = $editing ? !empty($announcement['show_on_login']) : false;
  $isPinned = $editing ? !empty($announcement['is_pinned']) : false;
  $startValue = '';
  $endValue = '';
  if ($editing && ($announcement['publish_start_at'] ?? null) instanceof DateTimeInterface) {
      $startValue = $announcement['publish_start_at']->setTimezone(Attendly\Support\AppTime::timezone())->format('Y-m-d\\TH:i');
  }
  if ($editing && ($announcement['publish_end_at'] ?? null) instanceof DateTimeInterface) {
      $endValue = $announcement['publish_end_at']->setTimezone(Attendly\Support\AppTime::timezone())->format('Y-m-d\\TH:i');
  }
?>
<div class="page-header">
  <h2><?= $editing ? 'お知らせ編集' : 'お知らせ作成' ?></h2>
  <p class="form-note">本文はMarkdown（太字/リンク/リストのみ）を利用できます。</p>
</div>

<section class="card">
  <form method="post" action="<?= $editing ? '/platform/announcements/' . $e((string)$announcement['id']) : '/platform/announcements' ?>" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label class="form-field">
      <span>タイトル</span>
      <input type="text" name="title" maxlength="255" value="<?= $e($titleValue) ?>" required>
    </label>
    <label class="form-field">
      <span>本文</span>
      <textarea name="body" rows="8" maxlength="10000" required><?= $e($bodyValue) ?></textarea>
      <small>太字（**bold**）、リンク（[text](https://...)）、リスト（- item / 1. item）のみ。</small>
    </label>
    <label class="form-field">
      <span>種別</span>
      <select name="type" required>
        <option value="maintenance" <?= $typeValue === 'maintenance' ? 'selected' : '' ?>>メンテナンス</option>
        <option value="outage" <?= $typeValue === 'outage' ? 'selected' : '' ?>>障害</option>
        <option value="feature" <?= $typeValue === 'feature' ? 'selected' : '' ?>>機能更新</option>
        <option value="other" <?= $typeValue === 'other' ? 'selected' : '' ?>>その他</option>
      </select>
    </label>
    <label class="form-field">
      <span>ステータス</span>
      <select name="status" required>
        <option value="draft" <?= $statusValue === 'draft' ? 'selected' : '' ?>>下書き</option>
        <option value="published" <?= $statusValue === 'published' ? 'selected' : '' ?>>公開中</option>
        <option value="archived" <?= $statusValue === 'archived' ? 'selected' : '' ?>>アーカイブ</option>
      </select>
    </label>
    <div class="form-field">
      <span>公開期間</span>
      <div class="form-inline">
        <input type="datetime-local" name="publish_start_at" value="<?= $e($startValue) ?>">
        <span>〜</span>
        <input type="datetime-local" name="publish_end_at" value="<?= $e($endValue) ?>">
      </div>
    </div>
    <label class="form-checkbox">
      <input type="checkbox" name="show_on_login" value="1" <?= $showOnLogin ? 'checked' : '' ?>>
      ログイン時に表示する
    </label>
    <label class="form-checkbox">
      <input type="checkbox" name="is_pinned" value="1" <?= $isPinned ? 'checked' : '' ?>>
      固定表示にする
    </label>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $editing ? '更新する' : '作成する' ?></button>
      <a href="/platform/announcements" class="btn secondary">戻る</a>
    </div>
  </form>
</section>
