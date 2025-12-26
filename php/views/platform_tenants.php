<div class="page-header">
  <h2>テナント管理</h2>
  <p class="form-note">
    プラットフォーム管理者として、テナントの作成・停止/再開、およびテナント管理者の二段階認証（TOTP）リセット/取消を行います。
  </p>
</div>

<?php if (!empty($generated) && is_array($generated)): ?>
  <section class="card">
    <h3>初期アカウント情報</h3>
    <p class="form-note">以下の情報は一度だけ表示されます。安全な手段で共有してください。</p>
    <div class="code-block small">
      <div>テナントUID: <?= $e((string)($generated['tenant_uid'] ?? '')) ?></div>
      <div>管理者メール: <?= $e((string)($generated['admin_email'] ?? '')) ?></div>
      <div>初期パスワード: <?= $e((string)($generated['initial_password'] ?? '')) ?></div>
    </div>
  </section>
<?php endif; ?>

<section class="card">
  <h3>テナント作成</h3>
  <form method="post" action="/platform/tenants/create" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label class="form-field">
      <span>テナント名</span>
      <input type="text" name="name" maxlength="255" required>
    </label>
    <label class="form-field">
      <span>連絡先メールアドレス</span>
      <input type="email" name="contactEmail" maxlength="254" autocomplete="email" required>
    </label>
    <label class="form-field">
      <span>管理者メールアドレス</span>
      <input type="email" name="adminEmail" maxlength="254" autocomplete="email" required>
    </label>
    <label class="form-field">
      <span>電話番号（任意）</span>
      <input type="tel" name="contactPhone" maxlength="32" autocomplete="tel">
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
          <th>登録日時</th>
          <th>ステータス</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tenants)): ?>
          <tr><td colspan="6">テナントはまだ登録されていません。</td></tr>
        <?php else: ?>
          <?php foreach ($tenants as $tenant): ?>
            <?php
              $tenantId = isset($tenant['id']) ? (int)$tenant['id'] : 0;
              $tenantName = trim((string)($tenant['name'] ?? ''));
              $tenantUid = trim((string)($tenant['tenant_uid'] ?? ''));
              $contact = trim((string)($tenant['contact_email'] ?? ''));
              $contactPhone = trim((string)($tenant['contact_phone'] ?? ''));
              $createdAt = trim((string)($tenant['created_at_display'] ?? ''));
              $status = (string)($tenant['status'] ?? 'active');
              $isActive = $status === 'active';
            ?>
            <tr>
              <td><?= $tenantName !== '' ? $e($tenantName) : '（名称未設定）' ?></td>
              <td><code><?= $e($tenantUid) ?></code></td>
              <td>
                <div class="user-cell">
                  <span class="user-email"><?= $contact !== '' ? $e($contact) : '未設定' ?></span>
                  <?php if ($contactPhone !== ''): ?>
                    <span class="user-meta">TEL: <?= $e($contactPhone) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td><?= $createdAt !== '' ? $e($createdAt) : '-' ?></td>
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
  <h3>テナント管理者（二段階認証リセット/取消）</h3>
  <p class="form-note">監査ログは暗号化して保存されます。</p>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>テナント</th>
          <th>連絡/管理者メール</th>
          <th>二段階認証</th>
          <th>リセット操作詳細</th>
          <th>理由</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tenantAdmins)): ?>
          <tr><td colspan="6">テナント管理者はまだ登録されていません。</td></tr>
        <?php else: ?>
          <?php foreach ($tenantAdmins as $admin): ?>
            <?php
              $adminId = isset($admin['id']) ? (int)$admin['id'] : 0;
              $lastReset = $admin['lastReset'] ?? null;
              $hasMfa = !empty($admin['hasMfa']);
              $phone = trim((string)($admin['phoneNumber'] ?? ''));
              $tenantLabel = trim((string)($admin['tenantName'] ?? ''));
              $tenantUid = trim((string)($admin['tenantUid'] ?? ''));
              $tenantContact = trim((string)($admin['tenantContactEmail'] ?? ''));
              $tenantContactPhone = trim((string)($admin['tenantContactPhone'] ?? ''));
              $tenantCreated = trim((string)($admin['tenantCreatedAt'] ?? ''));
              $adminEmail = trim((string)($admin['email'] ?? ''));
              $canRollback = is_array($lastReset) && !empty($lastReset['id']) && !isset($lastReset['rolledBackAtDisplay']);
            ?>
            <tr>
              <td>
                <div class="user-cell">
                  <span class="user-name"><?= $tenantLabel !== '' ? $e($tenantLabel) : '未設定' ?></span>
                  <?php if ($tenantUid !== ''): ?>
                    <span class="user-email"><code><?= $e($tenantUid) ?></code></span>
                  <?php endif; ?>
                  <?php if ($tenantCreated !== ''): ?>
                    <span class="user-meta">登録: <?= $e($tenantCreated) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="user-cell">
                  <span class="user-name">管理者: <?= $e((string)($admin['username'] ?? '')) ?></span>
                  <span class="user-email"><?= $adminEmail !== '' ? $e($adminEmail) : '-' ?></span>
                  <?php if ($tenantContact !== '' && $tenantContact !== $adminEmail): ?>
                    <span class="user-meta">連絡先: <?= $e($tenantContact) ?></span>
                  <?php endif; ?>
                  <?php if ($tenantContactPhone !== ''): ?>
                    <span class="user-meta">連絡先TEL: <?= $e($tenantContactPhone) ?></span>
                  <?php endif; ?>
                  <?php if ($phone !== ''): ?>
                    <span class="user-meta">管理者TEL: <?= $e($phone) ?></span>
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
                  <div>最終リセット: <?= $e((string)($lastReset['createdAtDisplay'] ?? '-')) ?></div>
                  <?php if (!empty($lastReset['rolledBackAtDisplay'])): ?>
                    <div class="muted">
                      取消: <?= $e((string)$lastReset['rolledBackAtDisplay']) ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!is_array($lastReset)): ?>
                  <span>-</span>
                <?php else: ?>
                  <div>理由: <?= $e((string)($lastReset['reason'] ?? '未設定')) ?></div>
                  <?php if (!empty($lastReset['rolledBackAtDisplay'])): ?>
                    <div class="muted">取消理由: <?= $e((string)($lastReset['rollbackReason'] ?? '理由未記入')) ?></div>
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
                      <div class="form-note">本人確認（いずれか2点一致）</div>
                      <input
                        type="email"
                        name="verifyEmail"
                        class="input-compact"
                        placeholder="メールアドレス"
                        maxlength="254"
                      >
                      <input
                        type="text"
                        name="verifyPhoneLast4"
                        class="input-compact"
                        placeholder="電話番号下4桁"
                        maxlength="4"
                        inputmode="numeric"
                      >
                      <input
                        type="text"
                        name="verifyRegisteredAt"
                        class="input-compact"
                        placeholder="登録日時 (YYYY-MM-DD)"
                        maxlength="19"
                      >
                      <input
                        type="text"
                        name="verifyTenantUid"
                        class="input-compact"
                        placeholder="テナントUID"
                        maxlength="64"
                      >
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
                        data-confirm-message="テナント管理者の二段階認証をリセットします。よろしいですか？"
                      >
                        二段階認証リセット
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
                        <div class="form-note">本人確認（いずれか2点一致）</div>
                        <input
                          type="email"
                          name="verifyEmail"
                          class="input-compact"
                          placeholder="メールアドレス"
                          maxlength="254"
                        >
                        <input
                          type="text"
                          name="verifyPhoneLast4"
                          class="input-compact"
                          placeholder="電話番号下4桁"
                          maxlength="4"
                          inputmode="numeric"
                        >
                        <input
                          type="text"
                          name="verifyRegisteredAt"
                          class="input-compact"
                          placeholder="登録日時 (YYYY-MM-DD)"
                          maxlength="19"
                        >
                        <input
                          type="text"
                          name="verifyTenantUid"
                          class="input-compact"
                          placeholder="テナントUID"
                          maxlength="64"
                        >
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
                          data-confirm-message="直前の二段階認証リセットを取り消します。よろしいですか？"
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
