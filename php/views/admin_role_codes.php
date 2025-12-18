<div class="page-header">
  <h2>ロールコード管理</h2>
  <p class="form-note">ロールコードの発行・一覧・無効化を行います。</p>
</div>

<section class="card">
  <h3>新規発行</h3>
  <form method="post" action="/admin/role-codes" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="form-field">
      <label for="employment_type">雇用区分（任意）</label>
      <select name="employment_type" id="employment_type">
        <option value="">未指定</option>
        <option value="part_time">アルバイト/パート</option>
        <option value="full_time">社員</option>
      </select>
      <p class="form-note">従業員登録時に自動で雇用区分が付与されます（後から変更も可能）。</p>
    </div>
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
          <th>雇用区分</th>
          <th>コード</th>
          <th>共有リンク</th>
          <th>QR</th>
          <th>使用数</th>
          <th>上限</th>
          <th>有効期限</th>
          <th>状態</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="10">発行済みのロールコードはありません。</td></tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <?php
              $employmentType = (string)($item['employment_type'] ?? '');
              $employmentLabel = '未指定';
              if ($employmentType === 'part_time') {
                $employmentLabel = 'アルバイト/パート';
              } elseif ($employmentType === 'full_time') {
                $employmentLabel = '社員';
              }
              $base = isset($baseUrl) ? rtrim((string)$baseUrl, '/') : '';
              $shareUrl = $base !== '' ? ($base . '/register?roleCode=' . rawurlencode((string)$item['code'])) : ('/register?roleCode=' . rawurlencode((string)$item['code']));
            ?>
            <tr>
              <td><?= $e((string)$item['id']) ?></td>
              <td><?= $e($employmentLabel) ?></td>
              <td class="mono"><?= $e($item['code']) ?></td>
              <td>
                <div class="form-inline" style="gap:6px; flex-wrap:wrap;">
                  <input type="text" value="<?= $e($shareUrl) ?>" readonly style="min-width:260px;">
                  <button type="button" class="btn" data-copy-text="<?= $e($shareUrl) ?>">コピー</button>
                  <a class="btn secondary" href="<?= $e($shareUrl) ?>" target="_blank" rel="noopener">開く</a>
                </div>
              </td>
              <td>
                <a class="btn" href="/admin/role-codes/<?= $e((string)$item['id']) ?>/qr">ダウンロード</a>
              </td>
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
