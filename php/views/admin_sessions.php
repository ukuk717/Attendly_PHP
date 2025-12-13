<div class="page-header">
  <h2>勤務記録訂正</h2>
  <p class="form-note">
    対象従業員:
    <strong><?= $e((string)($employee['username'] ?? '')) ?></strong>
    <?php if (!empty($employee['email'])): ?>
      <span class="muted">(<?= $e((string)$employee['email']) ?>)</span>
    <?php endif; ?>
  </p>
</div>

<section class="card">
  <form method="get" action="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions" class="form form-inline">
    <label>
      <span>年</span>
      <input type="number" name="year" value="<?= $e((string)($targetYear ?? '')) ?>" min="2000" max="2100">
    </label>
    <label>
      <span>月</span>
      <input type="number" name="month" value="<?= $e((string)($targetMonth ?? '')) ?>" min="1" max="12">
    </label>
    <button type="submit" class="btn">表示</button>
  </form>
  <p>
    表示中の月合計:
    <strong class="highlight-sm"><?= $e((string)($monthlySummary['formattedTotal'] ?? '0分')) ?></strong>
  </p>
</section>

<section class="card">
  <h3>勤務記録の追加</h3>
  <form method="post" action="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions<?= $e((string)($queryString ?? '')) ?>" class="form form-inline session-add-form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label class="form-field">
      <span>開始日時</span>
      <input type="datetime-local" name="startTime" required min="2000-01-01T00:00" max="2100-12-31T23:59">
    </label>
    <label class="form-field">
      <span>終了日時</span>
      <input type="datetime-local" name="endTime" required min="2000-01-01T00:00" max="2100-12-31T23:59">
    </label>
    <button type="submit" class="btn primary">記録を追加</button>
  </form>
</section>

<section class="card">
  <h3>勤務記録一覧</h3>
  <?php
    $rawQueryString = is_string($queryString ?? null) ? (string)$queryString : '';
    $rawQueryString = trim($rawQueryString);
    $safeQueryString = '';
    if ($rawQueryString !== '') {
      $safeQueryString = ($rawQueryString[0] === '?' || $rawQueryString[0] === '&')
        ? $rawQueryString
        : '?' . ltrim($rawQueryString, '?&');
    }
  ?>
  <div class="table-responsive">
    <table class="table session-table">
      <thead>
        <tr>
          <th id="session-start-heading">開始</th>
          <th id="session-end-heading">終了</th>
          <th>合計</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sessions)): ?>
          <tr><td colspan="4">対象期間の勤務記録がありません。</td></tr>
        <?php else: ?>
          <?php foreach ($sessions as $session): ?>
            <tr>
              <td>
                <input
                  type="datetime-local"
                  name="startTime"
                  value="<?= $e((string)$session['startInput']) ?>"
                  required
                  min="2000-01-01T00:00"
                  max="2100-12-31T23:59"
                  form="session-form-<?= $e((string)$session['id']) ?>"
                  aria-labelledby="session-start-heading"
                >
              </td>
              <td>
                <input
                  type="datetime-local"
                  name="endTime"
                  value="<?= $e((string)$session['endInput']) ?>"
                  required
                  min="2000-01-01T00:00"
                  max="2100-12-31T23:59"
                  form="session-form-<?= $e((string)$session['id']) ?>"
                  aria-labelledby="session-end-heading"
                >
              <td><?= $e((string)$session['formattedMinutes']) ?></td>
              <td>
                <div class="session-actions">
                  <button type="submit" class="btn primary" form="session-form-<?= $e((string)$session['id']) ?>">
                    保存
                  </button>
                  <button
                    type="submit"
                    class="btn danger"
                    form="session-form-<?= $e((string)$session['id']) ?>"
                    formaction="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions/<?= $e((string)$session['id']) ?>/delete<?= $e($safeQueryString) ?>"
                    formnovalidate
                    data-confirm-message="選択した勤務記録を削除します。よろしいですか？"
                    data-confirm-field="confirmed"
                    data-confirm-value="yes"
                  >
                    削除
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($sessions)): ?>
    <?php foreach ($sessions as $session): ?>
      <form
        id="session-form-<?= $e((string)$session['id']) ?>"
        method="post"
        action="/admin/employees/<?= $e((string)($employee['id'] ?? 0)) ?>/sessions/<?= $e((string)$session['id']) ?>/update<?= $e($safeQueryString) ?>"
      >
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      </form>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
