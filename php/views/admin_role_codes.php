<div class="page-header">
  <h2>ロールコード管理</h2>
  <p class="form-note">ロールコードの発行・一覧・無効化を行います。</p>
</div>

<section class="card">
  <h3>新規発行</h3>
  <form method="post" action="/admin/role-codes" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="form-field">
      <label for="max_uses">利用上限（任意）</label>
      <input type="number" name="max_uses" id="max_uses" min="1" max="100000" inputmode="numeric">
    </div>
    <div class="form-field">
      <label for="expires_at">有効期限（任意）</label>
      <input type="date" name="expires_at" id="expires_at">
    </div>
    <button type="submit" class="btn primary">発行する</button>
  </form>
</section>

<section class="card">
  <h3>発行済みコード</h3>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>コード</th>
          <th>使用数</th>
          <th>上限</th>
          <th>有効期限</th>
          <th>状態</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="7">発行済みのロールコードはありません。</td></tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= $e((string)$item['id']) ?></td>
              <td class="mono"><?= $e($item['code']) ?></td>
              <td><?= $e((string)$item['usage_count']) ?></td>
              <td><?= $e($item['max_uses'] === null ? '—' : (string)$item['max_uses']) ?></td>
              <td><?= $item['expires_at'] ? $e($item['expires_at']->format('Y-m-d')) : $e('—') ?></td>
              <td><?= $e($item['is_disabled'] ? '無効' : '有効') ?></td>
              <td>
                <?php if (!$item['is_disabled']): ?>
                  <form method="post" action="/admin/role-codes/<?= $e((string)$item['id']) ?>/disable" class="inline-form" data-confirm-message="無効化しますか？">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <button type="submit" class="btn secondary">無効化</button>
                  </form>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
