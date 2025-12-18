<?php
  $tzName = is_string($timezone ?? null) && $timezone !== '' ? (string)$timezone : 'Asia/Tokyo';
  try {
    $tz = new DateTimeZone($tzName);
  } catch (Exception $e) {
    $tz = new DateTimeZone('Asia/Tokyo');
  }
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
  <h2>休憩区間の編集</h2>
  <p class="form-note">
    対象従業員:
    <strong><?= $e((string)($employee['username'] ?? '')) ?></strong>
    <?php if (!empty($employee['email'])): ?>
      <span class="muted">(<?= $e((string)$employee['email']) ?>)</span>
    <?php endif; ?>
  </p>
  <p class="form-note">
    勤務記録: 開始 <strong><?= $e((string)($session['startDisplay'] ?? '')) ?></strong>
    / 終了 <strong><?= $e((string)($session['endDisplay'] ?? '')) ?></strong>
  </p>
  <p class="form-note">
    <a class="link" href="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions<?= $e($safeQueryString) ?>">勤務記録訂正へ戻る</a>
  </p>
</div>

<section class="card">
  <h3>休憩区間の追加</h3>
  <form method="post" action="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions/<?= $e((string)($session['id'] ?? 0)) ?>/breaks/add<?= $e($safeQueryString) ?>" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">

    <div class="form-field-inline">
      <label class="form-field">
        <span>種別</span>
        <select name="breakType">
          <option value="rest">通常休憩</option>
          <option value="other">その他</option>
        </select>
      </label>
      <label class="form-field form-checkbox" style="align-self:flex-end;">
        <input type="checkbox" name="isCompensated" value="on">
        <span>賃金補償</span>
      </label>
    </div>

    <div class="form-field-inline">
      <label class="form-field">
        <span>開始日時</span>
        <input type="datetime-local" name="startTime" required min="2000-01-01T00:00" max="2100-12-31T23:59">
      </label>
      <label class="form-field">
        <span>終了日時</span>
        <input type="datetime-local" name="endTime" min="2000-01-01T00:00" max="2100-12-31T23:59" <?= !empty($session['isOpen']) ? '' : 'required' ?>>
      </label>
    </div>

    <label class="form-field">
      <span>メモ（任意）</span>
      <input type="text" name="note" maxlength="255">
    </label>

    <button type="submit" class="btn primary">追加する</button>
    <?php if (!empty($session['isOpen'])): ?>
      <p class="form-note">勤務記録が「記録中」の場合、終了日時を空にすると「休憩中」として扱われます。</p>
    <?php endif; ?>
  </form>
</section>

<section class="card">
  <h3>休憩区間一覧</h3>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>種別</th>
          <th>賃金補償</th>
          <th>開始</th>
          <th>終了</th>
          <th>メモ</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($breaks)): ?>
          <tr><td colspan="6">休憩区間はありません。</td></tr>
        <?php else: ?>
          <?php foreach ($breaks as $b): ?>
            <?php
              $startInput = '';
              $endInput = '';
              try {
                if (isset($b['start_time']) && $b['start_time'] instanceof DateTimeInterface) {
                  $startInput = $b['start_time']->setTimezone($tz)->format('Y-m-d\\TH:i');
                }
                if (isset($b['end_time']) && $b['end_time'] instanceof DateTimeInterface) {
                  $endInput = $b['end_time']->setTimezone($tz)->format('Y-m-d\\TH:i');
                }
              } catch (Exception $e) {
                $startInput = '';
                $endInput = '';
              }
              $breakType = strtolower(trim((string)($b['break_type'] ?? 'rest')));
              if (!in_array($breakType, ['rest', 'other'], true)) {
                $breakType = 'rest';
              }
              $isComp = !empty($b['is_compensated']);
              $note = (string)($b['note'] ?? '');
            ?>
            <tr>
              <td>
                <select name="breakType" form="break-form-<?= $e((string)$b['id']) ?>">
                  <option value="rest" <?= $breakType === 'rest' ? 'selected' : '' ?>>通常休憩</option>
                  <option value="other" <?= $breakType === 'other' ? 'selected' : '' ?>>その他</option>
                </select>
              </td>
              <td>
                <label class="form-checkbox">
                  <input type="checkbox" name="isCompensated" value="on" form="break-form-<?= $e((string)$b['id']) ?>" <?= $isComp ? 'checked' : '' ?>>
                  <span>補償</span>
                </label>
              </td>
              <td>
                <input
                  type="datetime-local"
                  name="startTime"
                  value="<?= $e($startInput) ?>"
                  required
                  min="2000-01-01T00:00"
                  max="2100-12-31T23:59"
                  form="break-form-<?= $e((string)$b['id']) ?>"
                >
              </td>
              <td>
                <input
                  type="datetime-local"
                  name="endTime"
                  value="<?= $e($endInput) ?>"
                  min="2000-01-01T00:00"
                  max="2100-12-31T23:59"
                  <?= !empty($session['isOpen']) ? '' : 'required' ?>
                  form="break-form-<?= $e((string)$b['id']) ?>"
                >
              </td>
              <td>
                <input
                  type="text"
                  name="note"
                  maxlength="255"
                  value="<?= $e($note) ?>"
                  form="break-form-<?= $e((string)$b['id']) ?>"
                >
              </td>
              <td>
                <div class="session-actions">
                  <button type="submit" class="btn primary" form="break-form-<?= $e((string)$b['id']) ?>">保存</button>
                  <form method="post" action="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions/<?= $e((string)($session['id'] ?? 0)) ?>/breaks/<?= $e((string)$b['id']) ?>/delete<?= $e($safeQueryString) ?>" class="inline-form" data-confirm-message="この休憩区間を削除しますか？">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <button type="submit" class="btn danger">削除</button>
                  </form>
                </div>
              </td>
            </tr>
            <form
              id="break-form-<?= $e((string)$b['id']) ?>"
              method="post"
              action="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions/<?= $e((string)($session['id'] ?? 0)) ?>/breaks/<?= $e((string)$b['id']) ?>/update<?= $e($safeQueryString) ?>"
            >
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

