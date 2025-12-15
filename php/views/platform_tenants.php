<div class="page-header">
  <h2>テナント管理（MFAリセット/取消）</h2>
  <p class="form-note">
    テナント管理者の多要素認証（TOTP）をリセットし、必要に応じて直前のリセットを取り消します。
    監査ログは暗号化して保存されます。
  </p>
</div>

<section class="card">
  <h3>テナント管理者</h3>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>テナント管理者</th>
          <th>テナント</th>
          <th>MFA</th>
          <th>最終リセット</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tenantAdmins)): ?>
          <tr><td colspan="5">テナント管理者はまだ登録されていません。</td></tr>
        <?php else: ?>
          <?php foreach ($tenantAdmins as $admin): ?>
            <?php
              $adminId = isset($admin['id']) ? (int)$admin['id'] : 0;
              $lastReset = $admin['lastReset'] ?? null;
              $hasMfa = !empty($admin['hasMfa']);
              $phone = trim((string)($admin['phoneNumber'] ?? ''));
              $tenantLabel = trim((string)($admin['tenantName'] ?? ''));
              $tenantUid = trim((string)($admin['tenantUid'] ?? ''));
              $canRollback = is_array($lastReset) && !empty($lastReset['id']) && !isset($lastReset['rolledBackAtDisplay']);
            ?>
            <tr>
              <td>
                <div class="user-cell">
                  <span class="user-name"><?= $e((string)($admin['username'] ?? '')) ?></span>
                  <span class="user-email"><?= $e((string)($admin['email'] ?? '')) ?></span>
                  <?php if ($phone !== ''): ?>
                    <span class="user-meta">TEL: <?= $e($phone) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="user-cell">
                  <span class="user-name"><?= $tenantLabel !== '' ? $e($tenantLabel) : '未設定' ?></span>
                  <?php if ($tenantUid !== ''): ?>
                    <span class="user-email"><code><?= $e($tenantUid) ?></code></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($hasMfa): ?>
                  <span class="status-badge success">設定済み</span>
                <?php else: ?>
                  <span class="status-badge warning">未設定</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!is_array($lastReset)): ?>
                  <span>リセット履歴はありません。</span>
                <?php else: ?>
                  <div>最終リセット: <?= $e((string)($lastReset['createdAtDisplay'] ?? '---')) ?></div>
                  <div class="muted">理由: <?= $e((string)($lastReset['reason'] ?? '---')) ?></div>
                  <?php if (!empty($lastReset['rolledBackAtDisplay'])): ?>
                    <div class="muted">
                      取消: <?= $e((string)$lastReset['rolledBackAtDisplay']) ?>
                      （<?= $e((string)($lastReset['rollbackReason'] ?? '理由未記入')) ?>）
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <div class="table-actions">
                  <?php if ($adminId > 0): ?>
                    <form
                      method="post"
                      action="/platform/tenant-admins/<?= $e((string)$adminId) ?>/mfa/reset"
                      class="form-inline table-action-form"
                    >
                      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                      <input
                        type="text"
                        name="reason"
                        class="input-compact"
                        placeholder="理由（必須）"
                        required
                        maxlength="200"
                      >
                      <label class="form-checkbox" style="margin-top:0;">
                        <input type="checkbox" name="confirmed" value="yes" required>
                        <span>確認</span>
                      </label>
                      <button
                        type="submit"
                        class="btn danger"
                        data-confirm-message="テナント管理者の2FAをリセットします。よろしいですか？"
                      >
                        2FAリセット
                      </button>
                    </form>

                    <?php if ($canRollback): ?>
                      <form
                        method="post"
                        action="/platform/tenant-admins/<?= $e((string)$adminId) ?>/mfa/rollback"
                        class="form-inline table-action-form"
                      >
                        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="logId" value="<?= $e((string)$lastReset['id']) ?>">
                        <input
                          type="text"
                          name="rollbackReason"
                          class="input-compact"
                          placeholder="取消理由（必須）"
                          required
                          maxlength="200"
                        >
                        <button
                          type="submit"
                          class="btn secondary"
                          data-confirm-message="直前の2FAリセットを取り消します。よろしいですか？"
                        >
                          直前のリセットを取消
                        </button>
                      </form>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="muted">操作不可（ID不明）</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php
  $pagination = $pagination ?? null;
  $page = is_array($pagination) && isset($pagination['page']) ? (int)$pagination['page'] : 1;
  $hasPrev = is_array($pagination) && !empty($pagination['hasPrev']);
  $hasNext = is_array($pagination) && !empty($pagination['hasNext']);
?>
<?php if ($hasPrev || $hasNext): ?>
  <div class="table-actions" style="justify-content:flex-end; margin-top:12px;">
    <?php if ($hasPrev): ?>
      <a class="btn secondary" href="/platform/tenants?page=<?= $e((string)max(1, $page - 1)) ?>">前へ</a>
    <?php endif; ?>
    <?php if ($hasNext): ?>
      <a class="btn secondary" href="/platform/tenants?page=<?= $e((string)($page + 1)) ?>">次へ</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
