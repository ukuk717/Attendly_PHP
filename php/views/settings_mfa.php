<div class="page-header">
  <h2>2FA設定</h2>
  <p class="form-note">認証アプリ（OTP）とバックアップコードを設定してください。</p>
</div>

<section class="card">
  <h3>認証アプリ（OTP）</h3>
  <?php if (!empty($totpVerified)): ?>
    <p class="form-note success">認証アプリは有効です。</p>
    <form method="post" action="/settings/mfa/totp/disable" class="form" data-confirm-message="認証アプリを無効化します。よろしいですか？">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <label class="form-checkbox">
        <input type="checkbox" name="confirmed" value="yes" required>
        <span>認証アプリを無効化する（バックアップコードと信頼済みデバイスも無効化されます）</span>
      </label>
      <button type="submit" class="btn danger">認証アプリを無効化</button>
    </form>
  <?php else: ?>
    <?php if (!empty($totpSetupHidden)): ?>
      <p class="form-note">
        セキュリティのため、登録用QRコードとシークレットの表示は一度のみです。
        もう一度表示するには、セットアップをやり直してください。
      </p>
      <form method="post" action="/settings/mfa/totp/setup/reset" class="form" data-confirm-message="認証アプリのセットアップをやり直します。よろしいですか？">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn secondary">セットアップをやり直す</button>
      </form>
    <?php elseif (!empty($pendingSecret)): ?>
      <p class="form-note">認証アプリでQRコードを読み取り、<?= $e($totpDigits) ?>桁コードで確認してください。</p>
      <div class="mfa-setup">
        <div class="mfa-setup__qr">
          <?php if (!empty($totpQrSrc)): ?>
            <img src="<?= $e((string)$totpQrSrc) ?>" alt="認証アプリ登録用QRコード">
          <?php else: ?>
            <p class="form-note error">QRコードを生成できませんでした。下の情報を手動で登録してください。</p>
          <?php endif; ?>
        </div>
        <div class="mfa-setup__details">
          <div>
            <p class="form-note">シークレット</p>
            <div class="code-block"><?= $e((string)$pendingSecret) ?></div>
          </div>
          <?php if (!empty($totpUri)): ?>
            <div>
              <p class="form-note">登録用URI</p>
              <div class="code-block small"><?= $e((string)$totpUri) ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" action="/settings/mfa/totp/verify" class="form mfa-verify-form">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <label class="form-field">
          <span>認証コード</span>
          <input type="text" name="token" inputmode="numeric" pattern="[0-9]*" maxlength="<?= $totpDigits ?>" required>
        </label>
        <button type="submit" class="btn primary">認証アプリを有効化</button>
      </form>
    <?php else: ?>
      <p class="form-note error">セットアップ用のシークレットが見つかりませんでした。ページを再読み込みしてください。</p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="card">
  <h3>バックアップコード</h3>
  <?php if (!empty($newRecoveryCodes)): ?>
    <p class="form-note">以下のコードを安全な場所に保存してください。今回のみ表示されます。</p>
    <ul class="code-list">
      <?php foreach ($newRecoveryCodes as $code): ?>
        <li><code><?= $e($code) ?></code></li>
      <?php endforeach; ?>
    </ul>
  <?php elseif (!empty($hasRecoveryCodes)): ?>
    <p class="form-note">バックアップコードは発行済みです。紛失した場合に再発行してください。</p>
  <?php else: ?>
    <p class="form-note">バックアップコードはまだ発行されていません。</p>
  <?php endif; ?>
  <form method="post" action="/settings/mfa/recovery-codes/regenerate" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <button
      type="submit"
      class="btn secondary"
      <?= empty($totpVerified) ? 'disabled' : '' ?>
      data-confirm-message="<?= !empty($hasRecoveryCodes) ? 'バックアップコードを再発行します。よろしいですか？' : 'バックアップコードを発行します。よろしいですか？' ?>"
    >
      バックアップコードを<?= empty($hasRecoveryCodes) ? '発行' : '再発行' ?>
    </button>
  </form>
</section>

<section class="card">
  <h3>信頼済みデバイス</h3>
  <p class="form-note">現在のアカウントに紐づく信頼済みデバイスをすべて無効化できます。不明な端末を使用した場合や紛失時に実行してください。</p>
  <form method="post" action="/settings/mfa/trusted-devices/revoke" class="form" data-confirm-message="信頼済みデバイスをすべて無効化します。よろしいですか？">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn danger">信頼済みデバイスをすべて無効化</button>
  </form>
</section>
