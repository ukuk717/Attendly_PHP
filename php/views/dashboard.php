<div class="page-header">
  <h2>勤怠ダッシュボード</h2>
  <p class="form-note">タイムゾーン: <?= $e($timezone ?? 'Asia/Tokyo') ?></p>
</div>

<section class="card">
  <h3>ワンクリック打刻</h3>
  <p class="status-row">
    現在の状態:
    <?php if (!empty($openSession)): ?>
      <strong class="status in-progress">勤務中（開始: <?= $e($openSession['start_time']->setTimezone(new DateTimeZone($timezone ?? 'Asia/Tokyo'))->format('Y-m-d H:i')) ?>）</strong>
    <?php else: ?>
      <strong class="status idle">待機中</strong>
    <?php endif; ?>
  </p>
  <form method="post" action="/work-sessions/punch" class="form-inline">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn primary">
      <?php if (!empty($openSession)): ?>
        勤務終了を記録
      <?php else: ?>
        勤務開始を記録
      <?php endif; ?>
    </button>
  </form>
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
