<div class="page-header">
  <h2>お知らせ管理</h2>
  <p class="form-note">メンテナンス/障害/機能更新/その他のお知らせを管理します。</p>
</div>

<section class="card">
  <div class="card-header">
    <h3>お知らせ一覧</h3>
    <a href="/platform/announcements/new" class="btn primary">新規作成</a>
  </div>
  <form method="get" action="/platform/announcements" class="form form-inline">
    <label class="form-field">
      <span>ステータス</span>
      <select name="status">
        <option value="" <?= empty($status) ? 'selected' : '' ?>>すべて</option>
        <option value="draft" <?= ($status ?? '') === 'draft' ? 'selected' : '' ?>>下書き</option>
        <option value="published" <?= ($status ?? '') === 'published' ? 'selected' : '' ?>>公開中</option>
        <option value="archived" <?= ($status ?? '') === 'archived' ? 'selected' : '' ?>>アーカイブ</option>
      </select>
    </label>
    <button type="submit" class="btn secondary">絞り込み</button>
  </form>

  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>タイトル</th>
          <th>種別</th>
          <th>ステータス</th>
          <th>公開開始</th>
          <th>公開終了</th>
          <th>更新日時</th>
          <th>ログイン表示</th>
          <th>固定</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($announcements)): ?>
          <tr><td colspan="9">お知らせがありません。</td></tr>
        <?php else: ?>
          <?php foreach ($announcements as $announcement): ?>
            <tr>
              <td><?= $e($announcement['title']) ?></td>
              <td><?= $e($announcement['type']) ?></td>
              <td><?= $e($announcement['status']) ?></td>
              <td><?= $e($announcement['publish_start_display']) ?></td>
              <td><?= $e($announcement['publish_end_display']) ?></td>
              <td><?= $e($announcement['updated_display']) ?></td>
              <td><?= !empty($announcement['show_on_login']) ? 'ON' : 'OFF' ?></td>
              <td><?= !empty($announcement['is_pinned']) ? 'ON' : 'OFF' ?></td>
              <td class="table-actions">
                <a href="/platform/announcements/<?= $e((string)$announcement['id']) ?>/edit" class="btn secondary">編集</a>
                <form method="post" action="/platform/announcements/<?= $e((string)$announcement['id']) ?>/archive" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="btn danger">アーカイブ</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($pagination) && ($pagination['hasPrev'] ?? false || $pagination['hasNext'] ?? false)): ?>
    <div class="pagination">
      <?php if (!empty($pagination['hasPrev'])): ?>
        <a class="btn secondary" href="/platform/announcements?page=<?= $e((string)($pagination['page'] - 1)) ?>&status=<?= $e((string)($status ?? '')) ?>">前へ</a>
      <?php endif; ?>
      <span class="form-note">ページ <?= $e((string)$pagination['page']) ?></span>
      <?php if (!empty($pagination['hasNext'])): ?>
        <a class="btn secondary" href="/platform/announcements?page=<?= $e((string)($pagination['page'] + 1)) ?>&status=<?= $e((string)($status ?? '')) ?>">次へ</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
