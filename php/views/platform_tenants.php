<div class="page-header">
  <h2>テナント管理</h2>
  <p class="form-note">
    プラットフォーム管理者として、テナントの作成・停止/再開、およびテナント管理者のMFA（TOTP）リセット/取消を行います。
  </p>
</div>

<section class="card">
  <h3>テナント作成</h3>
  <form method="post" action="/platform/tenants/create" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label class="form-field">
      <span>テナント名</span>
      <input type="text" name="name" maxlength="255" required>
    </label>
    <label class="form-field">
      <span>連絡先メールアドレス（任意）</span>
      <input type="email" name="contactEmail" maxlength="254" autocomplete="email">
    </label>
    <label class="form-checkbox" style="margin-top:0;">
      <input type="checkbox" name="confirmed" value="yes" required>
      <span>確認</span>
    </label>
    <button type="submit" class="btn primary" data-confirm-message="テナントを作成します。よろしいですか？">作成する</button>
  </form>
</section>

<section class="card">
  <h3>テナント一覧</h3>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>テナント</th>
          <th>UID</th>
          <th>連絡先</th>
          <th>ステータス</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tenants)): ?>
          <tr><td colspan="5">テナントはまだ登録されていません。</td></tr>
        <?php else: ?>
          <?php foreach ($tenants as $tenant): ?>
            <?php
              $tenantId = isset($tenant['id']) ? (int)$tenant['id'] : 0;
              $tenantName = trim((string)($tenant['name'] ?? ''));
              $tenantUid = trim((string)($tenant['tenant_uid'] ?? ''));
              $contact = trim((string)($tenant['contact_email'] ?? ''));
              $status = (string)($tenant['status'] ?? 'active');
              $isActive = $status === 'active';
            ?>
            <tr>
              <td><?= $tenantName !== '' ? $e($tenantName) : '（名称未設定）' ?></td>
              <td><code><?= $e($tenantUid) ?></code></td>
              <td><?= $contact !== '' ? $e($contact) : '—' ?></td>
              <td>
                <?php if ($isActive): ?>
                  <span class="status-badge success">稼働中</span>
                <?php else: ?>
                  <span class="status-badge warning">停止中</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($tenantId > 0): ?>
                  <form method="post" action="/platform/tenants/<?= $e((string)$tenantId) ?>/status" class="form-inline table-action-form">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                    <label class="form-checkbox" style="margin-top:0;">
                      <input type="checkbox" name="confirmed" value="yes" required>
                      <span>確認</span>
                    </label>
                    <button
                      type="submit"
                      class="btn <?= $isActive ? 'danger' : 'secondary' ?>"
                      data-confirm-message="<?= $isActive ? 'テナントを停止します。よろしいですか？' : 'テナントを再開します。よろしいですか？' ?>"
                    >
                      <?= $isActive ? '停止' : '再開' ?>
                    </button>
                  </form>
                <?php else: ?>
                  <span class="muted">操作不可（ID不明）</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php
  $tenantsPagination = $tenantsPagination ?? null;
  $adminsPagination = $adminsPagination ?? null;

  $tenantsPage = is_array($tenantsPagination) && isset($tenantsPagination['page']) ? (int)$tenantsPagination['page'] : 1;
  $tenantsHasPrev = is_array($tenantsPagination) && !empty($tenantsPagination['hasPrev']);
  $tenantsHasNext = is_array($tenantsPagination) && !empty($tenantsPagination['hasNext']);

  $adminsPage = is_array($adminsPagination) && isset($adminsPagination['page']) ? (int)$adminsPagination['page'] : 1;
  $adminsHasPrev = is_array($adminsPagination) && !empty($adminsPagination['hasPrev']);
  $adminsHasNext = is_array($adminsPagination) && !empty($adminsPagination['hasNext']);
?>
<?php if ($tenantsHasPrev || $tenantsHasNext): ?>
  <div class="table-actions" style="justify-content:flex-end; margin-top:12px;">
    <?php if ($tenantsHasPrev): ?>
      <a class="btn secondary" href="/platform/tenants?tenants_page=<?= $e((string)max(1, $tenantsPage - 1)) ?>&admins_page=<?= $e((string)$adminsPage) ?>">前へ</a>
    <?php endif; ?>
    <?php if ($tenantsHasNext): ?>
      <a class="btn secondary" href="/platform/tenants?tenants_page=<?= $e((string)($tenantsPage + 1)) ?>&admins_page=<?= $e((string)$adminsPage) ?>">次へ</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<section class="card">
  <h3>テナント管理者（MFAリセット/取消）</h3>
  <p class="form-note">監査ログは暗号化して保存されます。</p>
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

<?php if ($adminsHasPrev || $adminsHasNext): ?>
  <div class="table-actions" style="justify-content:flex-end; margin-top:12px;">
    <?php if ($adminsHasPrev): ?>
      <a class="btn secondary" href="/platform/tenants?tenants_page=<?= $e((string)$tenantsPage) ?>&admins_page=<?= $e((string)max(1, $adminsPage - 1)) ?>">前へ</a>
    <?php endif; ?>
    <?php if ($adminsHasNext): ?>
      <a class="btn secondary" href="/platform/tenants?tenants_page=<?= $e((string)$tenantsPage) ?>&admins_page=<?= $e((string)($adminsPage + 1)) ?>">次へ</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
