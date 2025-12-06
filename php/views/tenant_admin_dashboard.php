<div class="page-header">
  <h2>テナント管理ダッシュボード</h2>
  <p class="form-note">タイムゾーン: <?= $e($metrics['timezone'] ?? 'Asia/Tokyo') ?></p>
</div>

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
