<div class="page-header">
  <h2>給与明細</h2>
  <p class="form-note">受信済みの明細をダウンロードできます。</p>
</div>

<section class="card">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th class="table-text-nowrap">支給日</th>
          <th class="table-text-nowrap">送信日時</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="3">まだ明細がありません。</td></tr>
        <?php else: ?>
          <?php foreach ($items as $row): ?>
            <tr>
              <td class="table-text-nowrap"><?= $e($row['sent_on']) ?></td>
              <td class="table-text-nowrap"><?= $e($row['sent_at']) ?></td>
              <td><a class="btn link" href="/payrolls/<?= $e((string)$row['id']) ?>/download">明細をダウンロード</a></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
