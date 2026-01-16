<div class="page-header">
  <h2>勤怠ダッシュボード</h2>
  <p class="form-note">タイムゾーン: <?= $e($timezone ?? 'Asia/Tokyo') ?></p>
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

<section class="card">
  <h3>ワンクリック打刻</h3>
  <p class="status-row">
    現在の状態:
    <?php 
      $formattedStartTime = 'N/A';
      $formattedBreakStartTime = 'N/A';
      try {
        if (isset($openSession['start_time']) && $openSession['start_time'] instanceof DateTimeInterface) {
          $tz = new DateTimeZone($timezone ?? 'Asia/Tokyo');
          $formattedStartTime = $openSession['start_time']->setTimezone($tz)->format('Y-m-d H:i');
        }
        if (isset($openBreak['start_time']) && $openBreak['start_time'] instanceof DateTimeInterface) {
          $tz = new DateTimeZone($timezone ?? 'Asia/Tokyo');
          $formattedBreakStartTime = $openBreak['start_time']->setTimezone($tz)->format('Y-m-d H:i');
        }
      } catch (Exception $e) {
        $formattedStartTime = '表示エラー';
        $formattedBreakStartTime = '表示エラー';
      }
    ?>
    <?php if (!empty($openSession)): ?>
      <?php if (!empty($openBreak)): ?>
        <strong class="status in-progress">休憩中（休憩開始: <?= $e($formattedBreakStartTime) ?> / 勤務開始: <?= $e($formattedStartTime) ?>）</strong>
      <?php else: ?>
        <strong class="status in-progress">勤務中（開始: <?= $e($formattedStartTime) ?>）</strong>
      <?php endif; ?>
    <?php else: ?>
      <strong class="status idle">待機中</strong>
    <?php endif; ?>
  </p>
  <form method="post" action="/work-sessions/punch" class="form-inline" style="gap: 8px;">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn primary">
      <?php if (!empty($openSession)): ?>
        勤務終了を記録
      <?php else: ?>
        勤務開始を記録
      <?php endif; ?>
    </button>
  </form>

  <?php if (!empty($breaksEnabled)): ?>
    <?php if (!empty($openSession)): ?>
      <form method="post" action="<?= !empty($openBreak) ? '/work-sessions/break/end' : '/work-sessions/break/start' ?>" class="form-inline" style="margin-top: 10px;">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn secondary">
          <?php if (!empty($openBreak)): ?>
            休憩終了を記録
          <?php else: ?>
            休憩開始を記録
          <?php endif; ?>
        </button>
      </form>
      <p class="form-note" style="margin-top: 8px;">勤務中のみ休憩の開始/終了を記録できます。</p>
    <?php else: ?>
      <p class="form-note" style="margin-top: 10px;">休憩の記録は勤務開始後に利用できます。</p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="card highlight-card">
  <div class="metric">
    <p class="metric-label">今月の合計勤務時間</p>
    <p class="metric-value"><?= $e($monthlyTotal ?? '0分') ?></p>
  </div>
</section>

<section class="card">
  <h3>最近30日間の日別集計</h3>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>日付</th>
          <th>勤務時間</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($dailySummary)): ?>
          <tr><td colspan="2">記録がありません。</td></tr>
        <?php else: ?>
          <?php foreach ($dailySummary as $row): ?>
            <tr>
              <td><?= $e($row['date']) ?></td>
              <td><?= $e($row['formatted']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <h3>最近の打刻履歴</h3>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>開始</th>
          <th>終了</th>
          <th>勤務時間</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentSessions)): ?>
          <tr><td colspan="3">履歴がありません。</td></tr>
        <?php else: ?>
          <?php foreach ($recentSessions as $session): ?>
            <tr>
              <td><?= $e($session['start']) ?></td>
              <td><?= $e($session['end']) ?></td>
              <td><?= $e($session['duration']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<script src="/passkeys.js" defer></script>
