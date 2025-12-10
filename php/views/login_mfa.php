<div class="page-header">
  <h2>多要素認証</h2>
  <?php if (!empty($email)): ?>
    <p class="form-note">対象アカウント: <code><?= $e($email) ?></code></p>
  <?php endif; ?>
</div>

<section class="card">
  <?php if (!empty($totpAvailable)): ?>
    <div class="mfa-login-panel">
      <h3>認証アプリのコード</h3>
      <?php if (!empty($totpState['isLocked'])): ?>
        <p class="form-note error">認証アプリはロック中です。時間をおいて再試行してください。<?= !empty($totpState['lockUntilDisplay']) ? '解除予定: '.$e($totpState['lockUntilDisplay']) : '' ?></p>
      <?php endif; ?>
      <form method="post" action="/login/mfa" class="form">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="authMode" value="totp">
        <label class="form-field">
          <span>認証コード</span>
          <input
            type="text"
            name="token"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="<?= $e((string)($_ENV['MFA_TOTP_DIGITS'] ?? 6)) ?>"
            autocomplete="one-time-code"
            <?= !empty($totpState['isLocked']) ? 'disabled' : 'required' ?>
          >
        </label>
        <label class="form-checkbox">
          <input type="checkbox" name="remember_device" value="on" <?= !empty($totpState['isLocked']) ? 'disabled' : '' ?>>
          <span><?= $e((string)($_ENV['MFA_TRUST_TTL_DAYS'] ?? 30)) ?>日間このデバイスを信頼する（共有端末では選択しないでください）</span>
        </label>
        <button type="submit" class="btn primary" <?= !empty($totpState['isLocked']) ? 'disabled' : '' ?>>認証する</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if (!empty($email)): ?>
    <?php $state = $emailState ?? ['hasChallenge' => false, 'expiresAtDisplay' => null, 'isLocked' => false, 'resendWaitSeconds' => 0]; ?>
    <div class="mfa-login-panel">
      <h3>メールワンタイムコード</h3>
      <?php if (!empty($state['isLocked'])): ?>
        <p class="form-note error">試行回数が上限に達しました。時間をおいて再試行してください。</p>
      <?php endif; ?>
      <p class="form-note">送信先: <code><?= $e($email) ?></code></p>
      <form method="post" action="/login/mfa/email/send" class="form form-inline">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button
          type="submit"
          class="btn secondary"
          data-resend-button
          data-countdown-seconds="<?= $e((string)max(0, (int)($state['resendWaitSeconds'] ?? 0))) ?>"
          data-locked="<?= !empty($state['isLocked']) ? 'true' : 'false' ?>"
          <?= (!empty($state['isLocked']) || (($state['resendWaitSeconds'] ?? 0) > 0)) ? 'disabled' : '' ?>
        >
          コードを送信
          <span
            class="muted"
            id="emailResendCountdown"
            data-countdown-seconds="<?= $e((string)max(0, (int)($state['resendWaitSeconds'] ?? 0))) ?>"
            aria-live="polite"
          >
            <?php if (!empty($state['resendWaitSeconds'])): ?>
              (<?= $e((string)$state['resendWaitSeconds']) ?>秒後)
            <?php endif; ?>
          </span>
        </button>
      </form>

      <?php if (!empty($state['hasChallenge'])): ?>
        <p class="form-note">有効期限: <?= $e($state['expiresAtDisplay'] ?? '未送信') ?></p>
      <?php endif; ?>

      <form method="post" action="/login/mfa" class="form">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="authMode" value="email">
        <label class="form-field">
          <span>確認コード</span>
          <input
            type="text"
            name="token"
            inputmode="numeric"
            pattern="[0-9]{<?= $e((string)($otpLength ?? 6)) ?>}"
            maxlength="<?= $e((string)($otpLength ?? 6)) ?>"
            autocomplete="one-time-code"
            <?= !empty($state['isLocked']) ? 'disabled' : 'required' ?>
          >
        </label>
        <label class="form-checkbox">
          <input type="checkbox" name="remember_device" value="on" <?= !empty($state['isLocked']) ? 'disabled' : '' ?>>
          <span><?= $e((string)($_ENV['MFA_TRUST_TTL_DAYS'] ?? 30)) ?>日間このデバイスを信頼する（共有端末では選択しないでください）</span>
        </label>
        <button type="submit" class="btn primary" <?= !empty($state['isLocked']) ? 'disabled' : '' ?>>認証する</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if (!empty($hasRecovery)): ?>
    <div class="mfa-login-panel">
      <h3>バックアップコードを使用</h3>
      <form method="post" action="/login/mfa" class="form">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="authMode" value="backup">
        <label class="form-field">
          <span>バックアップコード（1回限り）</span>
          <input
            type="text"
            name="backupCode"
            inputmode="text"
            maxlength="16"
            autocomplete="one-time-code"
            placeholder="例: ABCDE-FGHIJ"
            required
          >
        </label>
        <p class="form-note">
          認証アプリやメールを利用できない場合のみ使用してください。コードは使用後に失効します。
        </p>
        <button type="submit" class="btn secondary">バックアップコードで認証</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="form-links">
    <a class="link" href="/login/mfa/cancel">ログインをやり直す</a>
  </div>
</section>

<script>
  (function () {
    const resendButton = document.querySelector('[data-resend-button]');
    const countdownEl = document.getElementById('emailResendCountdown');
    if (!resendButton || !countdownEl) {
      return;
    }
    let remaining = Number.parseInt(countdownEl.getAttribute('data-countdown-seconds'), 10);
    const isLocked = resendButton.getAttribute('data-locked') === 'true';
    if (!Number.isFinite(remaining) || remaining <= 0) {
      return;
    }
    const updateCountdown = () => {
      if (remaining <= 0) {
        countdownEl.textContent = '';
        if (!isLocked) {
          resendButton.disabled = false;
        }
        clearInterval(timerId);
        return;
      }
      countdownEl.textContent = `(${remaining}秒後)`;
      remaining -= 1;
    };
    resendButton.disabled = true;
    updateCountdown();
    const timerId = setInterval(updateCountdown, 1000);
  })();
</script>
