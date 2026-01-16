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
<div class="page-header">
  <h2>テナント管理ダッシュボード</h2>
  <p class="form-note">タイムゾーン: <?= $e($metrics['timezone'] ?? 'Asia/Tokyo') ?></p>
</div>

<?php
  $passkeyReco = is_array($passkeyRecommendation ?? null) ? $passkeyRecommendation : [];
  $passkeyUserId = (int)($passkeyReco['userId'] ?? 0);
  $passkeyHas = !empty($passkeyReco['hasPasskey']);
?>
<?php if ($passkeyUserId > 0 && !$passkeyHas): ?>
  <section
    class="card highlight-card passkey-recommendation"
    data-passkey-recommendation
    data-user-id="<?= $e((string)$passkeyUserId) ?>"
    data-has-passkey="<?= $passkeyHas ? '1' : '0' ?>"
  >
    <h3>パスキーの利用をおすすめします</h3>
    <p class="form-note">パスキーならパスワード入力や二段階認証が不要になり、より安全にログインできます。</p>
    <p class="form-note">共有端末では登録しないでください。</p>
    <div class="form-actions">
      <a class="btn primary" href="/account#passkeys">パスキーを登録する</a>
      <button type="button" class="btn secondary" data-passkey-dismiss>あとで</button>
    </div>
  </section>
<?php endif; ?>

<?php if (isset($announcementBox)): ?>
  <?php include __DIR__ . '/_partials/announcements_box.php'; ?>
<?php endif; ?>

<section class="card metric-grid">
  <div class="metric">
    <p class="metric-label">有効な従業員</p>
    <p class="metric-value"><?= $e((string)($metrics['active_employees'] ?? 0)) ?> 名</p>
  </div>
  <div class="metric">
    <p class="metric-label">稼働中のセッション</p>
    <p class="metric-value"><?= $e((string)($metrics['open_sessions'] ?? 0)) ?> 件</p>
  </div>
</section>

<script src="/passkeys.js" defer></script>

<section class="card">
  <header class="section-header">
    <h3>月次サマリー</h3>
    <?php if (!empty($tenantId)): ?>
      <p class="section-subtitle">テナントID: <code><?= $e((string)$tenantId) ?></code></p>
    <?php endif; ?>
  </header>
  <form method="get" action="/dashboard" class="form form-inline">
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

  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>従業員</th>
          <th>月合計</th>
          <th>警告</th>
          <th>勤務記録訂正</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($monthlySummary)): ?>
          <tr><td colspan="4">従業員が登録されていません。</td></tr>
        <?php else: ?>
          <?php foreach ($monthlySummary as $row): ?>
            <?php $u = $row['user'] ?? []; ?>
            <?php $shortageDays = (int)($row['breakShortageDays'] ?? 0); ?>
            <?php $edgeDays = (int)($row['edgeBreakWarningDays'] ?? 0); ?>
            <tr>
              <td>
                <div class="user-cell">
                  <span class="user-name"><?= $e((string)($u['username'] ?? '')) ?></span>
                  <?php if (!empty($u['email'])): ?>
                    <span class="user-email"><?= $e((string)$u['email']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="table-text-nowrap"><?= $e((string)($row['formattedTotal'] ?? '0分')) ?></td>
              <td class="table-text-nowrap">
                <?php if ($shortageDays > 0): ?>
                  <div><span class="status-badge warning">休憩不足</span> <span class="muted"><?= $e((string)$shortageDays) ?>日</span></div>
                <?php endif; ?>
                <?php if ($edgeDays > 0): ?>
                  <div><span class="status-badge warning">要注意</span> <span class="muted"><?= $e((string)$edgeDays) ?>日</span></div>
                <?php endif; ?>
                <?php if ($shortageDays === 0 && $edgeDays === 0): ?>
                  -
                <?php endif; ?>
              </td>
              <td>
                <a class="btn secondary" href="/admin/employees/<?= $e((string)($u['id'] ?? 0)) ?>/sessions<?= $e($safeQueryString) ?>">
                  勤務記録訂正
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <h3>従業員アカウント管理</h3>
  <p class="form-note">
    退職・解約後も最大 <strong><?= $e((string)($retentionYears ?? 5)) ?>年</strong> は勤怠・給与データが保持され、期間経過後は自動でアーカイブされます。
  </p>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th class="table-text-nowrap">従業員</th>
          <th>メール</th>
          <th>雇用区分</th>
          <th>状態</th>
          <th class="table-text-nowrap">アカウント操作</th>
          <th class="table-text-nowrap">二段階認証</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($employeesActive)): ?>
          <tr><td colspan="6">有効な従業員がいません。</td></tr>
        <?php else: ?>
          <?php foreach ($employeesActive as $employee): ?>
            <?php $email = trim((string)($employee['email'] ?? '')); ?>
            <?php $employmentType = (string)($employee['employment_type'] ?? ''); ?>
            <tr>
              <td><?= $e((string)$employee['username']) ?></td>
              <td><?= $email !== '' ? $e($email) : '未設定' ?></td>
              <td>
                <form method="post" action="/admin/employees/<?= $e((string)$employee['id']) ?>/employment-type" class="form-inline">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <select name="employment_type">
                    <option value="" <?= $employmentType === '' ? 'selected' : '' ?>>未指定</option>
                    <option value="part_time" <?= $employmentType === 'part_time' ? 'selected' : '' ?>>アルバイト/パート</option>
                    <option value="full_time" <?= $employmentType === 'full_time' ? 'selected' : '' ?>>社員</option>
                  </select>
                  <button type="submit" class="btn compact">保存</button>
                </form>
              </td>
              <td><span class="status-badge success">有効</span></td>
              <td>
                <form
                  method="post"
                  action="/admin/employees/<?= $e((string)$employee['id']) ?>/status"
                  class="form-inline"
                  data-confirm-message="従業員「<?= $e((string)$employee['username']) ?>」のアカウントを無効化します。よろしいですか？"
                >
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="action" value="deactivate">
                  <button type="submit" class="btn danger">無効化</button>
                </form>
              </td>
              <td>
                <form
                  method="post"
                  action="/admin/employees/<?= $e((string)$employee['id']) ?>/mfa/reset"
                  class="form-inline"
                  data-confirm-message="従業員「<?= $e((string)$employee['username']) ?>」の二段階認証をリセットします。この操作は、従業員がログインできない場合にのみ実行してください。"
                >
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="btn secondary">二段階認証リセット</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <h4>無効化済み従業員</h4>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th class="table-text-nowrap">従業員</th>
          <th>メール</th>
          <th>雇用区分</th>
          <th class="table-text-nowrap">最終更新</th>
          <th class="table-text-nowrap">アカウント操作</th>
          <th class="table-text-nowrap">二段階認証</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($employeesInactive)): ?>
          <tr><td colspan="6">無効化された従業員はいません。</td></tr>
        <?php else: ?>
          <?php foreach ($employeesInactive as $employee): ?>
            <?php $email = trim((string)($employee['email'] ?? '')); ?>
            <?php $deactivated = trim((string)($employee['deactivatedAtDisplay'] ?? '')); ?>
            <?php $employmentType = (string)($employee['employment_type'] ?? ''); ?>
            <tr>
              <td><?= $e((string)$employee['username']) ?></td>
              <td><?= $email !== '' ? $e($email) : '未設定' ?></td>
              <td>
                <form method="post" action="/admin/employees/<?= $e((string)$employee['id']) ?>/employment-type" class="form-inline">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <select name="employment_type">
                    <option value="" <?= $employmentType === '' ? 'selected' : '' ?>>未指定</option>
                    <option value="part_time" <?= $employmentType === 'part_time' ? 'selected' : '' ?>>アルバイト/パート</option>
                    <option value="full_time" <?= $employmentType === 'full_time' ? 'selected' : '' ?>>社員</option>
                  </select>
                  <button type="submit" class="btn compact">保存</button>
                </form>
              </td>
              <td class="table-text-nowrap"><?= $deactivated !== '' ? $e($deactivated) : '-' ?></td>
              <td>
                <form method="post" action="/admin/employees/<?= $e((string)$employee['id']) ?>/status" class="form-inline">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="action" value="activate">
                  <button type="submit" class="btn secondary">再有効化</button>
                </form>
              </td>
              <td>
                <form
                  method="post"
                  action="/admin/employees/<?= $e((string)$employee['id']) ?>/mfa/reset"
                  class="form-inline"
                  data-confirm-message="従業員「<?= $e((string)$employee['username']) ?>」の二段階認証をリセットします。この操作は、従業員がログインできない場合にのみ実行してください。"
                >
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="btn secondary">二段階認証リセット</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card" id="tenant-settings">
  <h3>テナント設定</h3>
  <form method="post" action="/admin/settings/email-verification" class="form-inline">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label class="form-checkbox">
      <input
        type="checkbox"
        name="requireEmployeeEmailVerification"
        value="on"
        <?= !empty($tenantSettings['requireEmailVerification']) ? 'checked' : '' ?>
      >
      <span>従業員登録時にメールアドレスの確認コードを必須にする</span>
    </label>
  <p class="form-note">
    有効にすると、従業員がアカウント登録時にメールアドレスへ送信される確認コードの入力が必要になります。
    認証が成功しない限り、アカウント作成は行われません。ロールコードの利用回数は確認完了後にのみ消費されます。
  </p>
    <button type="submit" class="btn secondary">設定を保存</button>
  </form>
</section>

<section class="card">
  <h3>最近の打刻</h3>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>従業員</th>
          <th>開始</th>
          <th>終了</th>
          <th>勤務時間</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($metrics['recent_sessions'])): ?>
          <tr><td colspan="4">打刻履歴がありません。</td></tr>
        <?php else: ?>
          <?php foreach ($metrics['recent_sessions'] as $row): ?>
            <tr>
              <td><?= $e($row['user_label']) ?></td>
              <td><?= $e($row['start']) ?></td>
              <td><?= $e($row['end']) ?></td>
              <td><?= $e($row['duration']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <h3>最近の給与明細送信</h3>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>支給日</th>
          <th>送信日時</th>
          <th>ファイル名</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($metrics['recent_payrolls'])): ?>
          <tr><td colspan="3">給与明細の履歴がありません。</td></tr>
        <?php else: ?>
          <?php foreach ($metrics['recent_payrolls'] as $row): ?>
            <tr>
              <td><?= $e($row['sent_on']) ?></td>
              <td><?= $e($row['sent_at']) ?></td>
              <td><?= $e($row['file_name']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
