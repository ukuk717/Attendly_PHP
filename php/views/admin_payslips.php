<div class="page-header">
  <h2>給与明細管理</h2>
  <p class="form-note">送信済み給与明細の一覧・再送・ダウンロードリンクの発行ができます。</p>
  <p><a class="btn link" href="/admin/payslips/send">給与明細を送信する</a></p>
</div>

<section class="card">
  <form method="get" action="/admin/payslips" class="form-inline" style="gap: 8px; align-items: flex-end;">
    <div class="form-field" style="min-width: 280px;">
      <label for="employee_id">従業員で絞り込み（任意）</label>
      <select name="employee_id" id="employee_id">
        <option value="">すべて</option>
        <?php foreach (($employeeOptions ?? []) as $opt): ?>
          <?php $selected = (int)($selectedEmployeeId ?? 0) === (int)($opt['id'] ?? 0); ?>
          <option value="<?= $e((string)$opt['id']) ?>" <?= $selected ? 'selected' : '' ?>><?= $e((string)($opt['label'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn secondary">適用</button>
  </form>
</section>

<section class="card">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th class="table-text-nowrap">支給日</th>
          <th class="table-text-nowrap">送信日時</th>
          <th>従業員</th>
          <th>ファイル</th>
          <th class="table-text-nowrap">受領</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="6">明細がありません。</td></tr>
        <?php else: ?>
          <?php foreach ($items as $row): ?>
            <tr>
              <td class="table-text-nowrap"><?= $e((string)($row['sent_on'] ?? '')) ?></td>
              <td class="table-text-nowrap"><?= $e((string)($row['sent_at'] ?? '')) ?></td>
              <td><?= $e((string)($row['employee_label'] ?? '')) ?></td>
              <td>
                <?= $e((string)($row['file_name'] ?? '')) ?>
                <?php if (!empty($row['file_size'])): ?>
                  <span class="muted">(<?= $e((string)number_format((int)$row['file_size'])) ?> bytes)</span>
                <?php endif; ?>
              </td>
              <td class="table-text-nowrap">
                <?php if (!empty($row['downloaded_at'])): ?>
                  <?= $e((string)$row['downloaded_at']) ?>
                <?php else: ?>
                  <span class="muted">未確認</span>
                <?php endif; ?>
              </td>
              <td>
                <a class="btn link" href="/admin/payslips/<?= $e((string)$row['id']) ?>/download">ダウンロードリンクを開く</a>
                <form method="post" action="/admin/payslips/<?= $e((string)$row['id']) ?>/resend" class="form-inline" style="display:inline-flex; gap: 6px; margin-left: 6px;">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="return_to" value="<?= $e((string)($returnTo ?? '/admin/payslips')) ?>">
                  <button type="submit" class="btn secondary" onclick="return confirm('給与明細の案内メールを再送します。よろしいですか？');">再送</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($pagination)): ?>
    <div class="form-inline" style="justify-content: space-between; margin-top: 12px;">
      <div class="muted">
        <?php
          $total = (int)($pagination['total'] ?? 0);
          $page = (int)($pagination['page'] ?? 1);
          $limit = (int)($pagination['limit'] ?? 50);
          $from = $total > 0 ? (($page - 1) * $limit + 1) : 0;
          $to = min($total, $page * $limit);
        ?>
        <?= $e((string)$from) ?>〜<?= $e((string)$to) ?> / <?= $e((string)$total) ?> 件
      </div>
      <div class="form-inline" style="gap: 8px;">
        <?php if (!empty($pagination['hasPrev'])): ?>
          <a class="btn link" href="<?= $e((string)$pagination['prevUrl']) ?>">前へ</a>
        <?php endif; ?>
        <?php if (!empty($pagination['hasNext'])): ?>
          <a class="btn link" href="<?= $e((string)$pagination['nextUrl']) ?>">次へ</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
