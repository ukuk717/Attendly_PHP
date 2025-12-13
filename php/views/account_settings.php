<div class="page-header">
  <h2>アカウント設定</h2>
  <p class="form-note">プロフィール、パスワード、メールアドレスを管理します。</p>
</div>

<section class="card">
  <h3>プロフィール</h3>
  <form method="post" action="/account/profile" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label class="form-field">
      <span>姓</span>
      <input type="text" name="lastName" value="<?= $e($profile['last_name'] ?? '') ?>" maxlength="64" required>
    </label>
    <label class="form-field">
      <span>名</span>
      <input type="text" name="firstName" value="<?= $e($profile['first_name'] ?? '') ?>" maxlength="64" required>
    </label>
    <button type="submit" class="btn primary">プロフィールを保存</button>
  </form>
</section>

<section class="card">
  <h3>パスワード変更</h3>
  <p class="form-note">現在のパスワードを確認し、新しいパスワードを設定します。英字・数字・記号を含む <?= $minPasswordLength ?> 文字以上で入力してください。</p>
  <form method="post" action="/account/password" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label class="form-field">
      <span>現在のパスワード</span>
      <input type="password" name="currentPassword" autocomplete="current-password" maxlength="128" required>
    </label>
    <label class="form-field">
      <span>新しいパスワード</span>
      <input type="password" name="newPassword" autocomplete="new-password" minlength="<?= $e((string)$minPasswordLength) ?>" maxlength="128" required>
    </label>
    <label class="form-field">
      <span>新しいパスワード（確認）</span>
      <input type="password" name="newPasswordConfirmation" autocomplete="new-password" minlength="<?= $e((string)$minPasswordLength) ?>" maxlength="128" required>
    </label>
    <button type="submit" class="btn primary">パスワードを変更</button>
  </form>
</section>

<section class="card">
  <h3>メールアドレス変更</h3>
  <p class="form-note">新しいメールアドレスに確認コードを送信し、コードを入力すると変更が完了します。</p>
  <form method="post" action="/account/email/request" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label class="form-field">
      <span>新しいメールアドレス</span>
      <input type="email" name="email" value="<?= $e($pendingEmail ?? '') ?>" maxlength="254" autocomplete="email" required>
    </label>
    <button type="submit" class="btn secondary">確認コードを送信</button>
  </form>

  <?php if (!empty($pendingEmail)): ?>
    <div class="code-block small">
      <div>送信先: <?= $e($pendingEmail) ?></div>
      <?php if (!empty($pendingEmailExpiresAt)): ?>
        <div>有効期限: <?= $e($pendingEmailExpiresAt) ?></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($pendingEmailLocked)): ?>
      <p class="form-note error">試行回数の上限に達しています。しばらく待ってから再試行してください。</p>
    <?php else: ?>
    <form method="post" action="/account/email/verify" class="form inline-form">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <label class="form-field">
        <span>確認コード</span>
        <input type="text" name="token" inputmode="numeric" pattern="[0-9]*" maxlength="<?= $e((string)$otpLength) ?>" required>
      </label>
      <button type="submit" class="btn primary">メールアドレスを変更</button>
    </form>
  <?php endif; ?>
  <?php endif; ?>
</section>

