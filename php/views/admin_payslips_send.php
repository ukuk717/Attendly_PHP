<div class="page-header">
  <h2>給与明細送信</h2>
  <p class="form-note">従業員に給与明細の通知メールを送信します。</p>
</div>

<section class="card">
  <form method="post" action="/admin/payslips/send" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="form-field">
      <label for="employee_id">従業員</label>
      <select name="employee_id" id="employee_id" required>
        <option value="">選択してください</option>
        <?php foreach ($employees as $emp): ?>
          <?php
            $name = trim(($emp['last_name'] ?? '') . ' ' . ($emp['first_name'] ?? ''));
            $email = $emp['email'] ?? '';
            $label = $name !== '' ? "{$name} ({$email})" : $email;
          ?>
          <option value="<?= $e((string)$emp['id']) ?>"><?= $e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-field">
      <label for="sent_on">支給日</label>
      <input type="date" name="sent_on" id="sent_on" required>
    </div>
    <div class="form-field">
      <label for="net_amount">支給額（任意、円）</label>
      <input type="number" name="net_amount" id="net_amount" step="1" min="0" inputmode="numeric">
    </div>
    <div class="form-field">
      <label for="summary">概要</label>
      <textarea name="summary" id="summary" rows="4" required maxlength="500"></textarea>
    </div>
    <button type="submit" class="btn primary">送信する</button>
  </form>
</section>
