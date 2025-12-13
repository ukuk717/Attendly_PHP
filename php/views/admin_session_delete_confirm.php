<?php
declare(strict_types=1);

$rawQueryString = is_string($queryString ?? null) ? (string)$queryString : '';
$rawQueryString = trim($rawQueryString);
$safeQueryString = '';
if ($rawQueryString !== '') {
  $safeQueryString = ($rawQueryString[0] === '?' || $rawQueryString[0] === '&')
    ? $rawQueryString
    : '?' . ltrim($rawQueryString, '?&');
}
?>
<div class="page-header">
  <h2>勤務記録削除の確認</h2>
  <p class="form-note">
    対象従業員:
    <strong><?= $e((string)($employee['username'] ?? '')) ?></strong>
    <?php if (!empty($employee['email'])): ?>
      <span class="muted">(<?= $e((string)$employee['email']) ?>)</span>
    <?php endif; ?>
  </p>
</div>

<section class="card">
  <p>以下の勤務記録を削除します。よろしいですか？</p>
  <ul>
    <li>開始: <strong><?= $e((string)($session['startDisplay'] ?? '')) ?></strong></li>
    <li>終了: <strong><?= $e((string)($session['endDisplay'] ?? '')) ?></strong></li>
  </ul>

  <div class="session-actions" style="margin-top: 12px;">
    <form
      method="post"
      action="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions/<?= $e((string)($session['id'] ?? 0)) ?>/delete<?= $e($safeQueryString) ?>"
      class="form-inline"
    >
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="confirmed" value="yes">
      <button type="submit" class="btn danger">削除する</button>
    </form>
    <a class="btn secondary" href="<?= $e((string)($redirectUrl ?? '/dashboard')) ?>">キャンセル</a>
  </div>
</section>
