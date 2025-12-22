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
  <?php if (!empty($isPlatformAdmin)): ?>
    <p class="form-note">現在のパスワードを確認し、新しいパスワードを設定します。<?= $e((string)($platformMinPasswordLength ?? 12)) ?> 文字以上で、英字（大文字・小文字）・数字・記号を必ず含めてください。</p>
  <?php else: ?>
    <p class="form-note">現在のパスワードを確認し、新しいパスワードを設定します。<?= $e((string)($minPasswordLength ?? 8)) ?> 文字以上で入力してください。</p>
  <?php endif; ?>
  <form method="post" action="/account/password" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <input type="text" name="username" autocomplete="username" value="<?= $e((string)($userEmail ?? '')) ?>" class="visually-hidden" aria-hidden="true" tabindex="-1">
    <label class="form-field">
      <span>現在のパスワード</span>
      <input type="password" name="currentPassword" autocomplete="current-password" maxlength="<?= $e((string)($maxPasswordLength ?? 256)) ?>" required>
    </label>
    <label class="form-field">
      <span>新しいパスワード</span>
      <input
        type="password"
        name="newPassword"
        autocomplete="new-password"
        minlength="<?= $e((string)(!empty($isPlatformAdmin) ? ($platformMinPasswordLength ?? 12) : ($minPasswordLength ?? 8))) ?>"
        maxlength="<?= $e((string)($maxPasswordLength ?? 256)) ?>"
        required
      >
    </label>
    <label class="form-field">
      <span>新しいパスワード（確認）</span>
      <input
        type="password"
        name="newPasswordConfirmation"
        autocomplete="new-password"
        minlength="<?= $e((string)(!empty($isPlatformAdmin) ? ($platformMinPasswordLength ?? 12) : ($minPasswordLength ?? 8))) ?>"
        maxlength="<?= $e((string)($maxPasswordLength ?? 256)) ?>"
        required
      >
    </label>
    <button type="submit" class="btn primary">パスワードを変更</button>
  </form>
</section>

<section class="card">
  <h3>パスキー</h3>
  <p class="form-note">パスキーでログインすると多要素認証は不要になります。対応端末・ブラウザでご利用ください。</p>
  <p class="form-note">注意: 共有端末ではパスキーを登録しないでください。同一端末で複数アカウントのパスキー運用は想定していません。</p>
  <div class="form">
    <label class="form-field" for="passkey-label">
      <span>表示名（任意）</span>
      <input type="text" id="passkey-label" maxlength="64" placeholder="例: 仕事用PC">
    </label>
    <input type="hidden" id="passkey-csrf" value="<?= $e($csrf) ?>">
    <button type="button" class="btn secondary" data-passkey-register>パスキーを登録</button>
    <p class="passkey-status" data-passkey-status></p>
  </div>

  <?php if (empty($passkeys ?? [])): ?>
    <p class="form-note">登録済みのパスキーはありません。</p>
  <?php else: ?>
    <ul class="passkey-list">
      <?php foreach ($passkeys as $passkey): ?>
        <li class="passkey-item">
          <div class="passkey-meta">
            <div class="passkey-name"><?= $e($passkey['name'] ?? 'パスキー') ?></div>
            <div class="passkey-sub">登録: <?= $e($passkey['created_at']) ?></div>
            <?php if (!empty($passkey['last_used_at'])): ?>
              <div class="passkey-sub">最終使用: <?= $e($passkey['last_used_at']) ?></div>
            <?php else: ?>
              <div class="passkey-sub">最終使用: 未使用</div>
            <?php endif; ?>
            <?php if (!empty($passkey['transports']) && is_array($passkey['transports'])): ?>
              <div class="passkey-sub">方式: <?= $e(implode(', ', $passkey['transports'])) ?></div>
            <?php endif; ?>
          </div>
          <form method="post" action="/passkeys/<?= $e((string)$passkey['id']) ?>/delete" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="btn danger" onclick="return confirm('このパスキーを削除しますか？');">削除</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card">
  <h3>ログインセッション</h3>
  <p class="form-note">ログイン端末と日時を確認できます（IPは表示せず、サーバーログに記録されます）。</p>
  <?php if (empty($loginSessions ?? [])): ?>
    <p class="form-note">セッション情報がありません。</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>ログイン日時</th>
            <th>端末</th>
            <th>状態</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($loginSessions as $session): ?>
            <?php
              $isCurrent = !empty($session['is_current']);
              $isRevoked = !empty($session['revoked_at']);
              $status = $isCurrent ? '現在の端末' : ($isRevoked ? '失効済み' : '有効');
              $ua = trim((string)($session['user_agent'] ?? ''));
              if ($ua === '') {
                  $ua = '不明';
              }
            ?>
            <tr>
              <td><?= $e((string)($session['login_at'] ?? '')) ?></td>
              <td><?= $e($ua) ?></td>
              <td><?= $e($status) ?></td>
              <td>
                <?php if (!$isCurrent && !$isRevoked): ?>
                  <form method="post" action="/account/sessions/<?= $e((string)($session['id'] ?? 0)) ?>/revoke" class="form-inline">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <button type="submit" class="btn secondary" onclick="return confirm('このセッションを失効しますか？');">失効</button>
                  </form>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
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

<script src="/passkeys.js" defer></script>
