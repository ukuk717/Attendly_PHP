<div class="page-header">
  <h2>勤怠エクスポート</h2>
  <p class="form-note">期間と従業員を指定して勤怠データを署名URL経由でダウンロードします。</p>
</div>

<section class="card">
  <form method="post" action="/admin/timesheets/export" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="form-field">
      <label for="start_date">開始日</label>
      <input type="date" name="start_date" id="start_date" required>
    </div>
    <div class="form-field">
      <label for="end_date">終了日</label>
      <input type="date" name="end_date" id="end_date" required>
    </div>
    <div class="form-field">
      <label for="user_id">従業員（任意）</label>
      <select name="user_id" id="user_id">
        <option value="">全員</option>
        <?php foreach ($employees ?? [] as $emp): ?>
          <?php
            $name = trim(($emp['last_name'] ?? '') . ' ' . ($emp['first_name'] ?? ''));
            $email = $emp['email'] ?? '';
            if ($name !== '' && $email !== '') {
              $label = "{$name} ({$email})";
            } elseif ($name !== '') {
              $label = $name;
            } elseif ($email !== '') {
              $label = $email;
            } else {
              continue;
            }
          ?>
          <option value="<?= $e((string)$emp['id']) ?>"><?= $e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-field">
      <label for="format">出力形式</label>
      <select name="format" id="format" required>
        <option value="excel" selected>Excel</option>
        <option value="pdf">PDF</option>
      </select>
    </div>
    <button type="submit" class="btn primary">署名URLでダウンロード</button>
  </form>
</section>
