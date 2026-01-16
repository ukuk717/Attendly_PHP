<div class="auth-container">
  <section class="card auth-card">
    <h2>ログイン</h2>
    <?php
      $recaptchaEnabled = filter_var($_ENV['RECAPTCHA_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
      $recaptchaSiteKey = trim((string)($_ENV['RECAPTCHA_SITE_KEY'] ?? ''));
    ?>
    <form method="post" action="/login" class="form" data-recaptcha-enabled="<?= $recaptchaEnabled && $recaptchaSiteKey !== '' ? '1' : '0' ?>" data-recaptcha-site-key="<?= $e($recaptchaSiteKey) ?>">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
      <label class="form-field" for="email">メールアドレス
        <input
          type="email"
          id="email"
          name="email"
          required
          autocomplete="email"
          maxlength="254"
          inputmode="email"
        >
      </label>

      <label class="form-field" for="password">パスワード
        <input
          type="password"
          id="password"
          name="password"
          required
          autocomplete="current-password"
          maxlength="128"
        >
      </label>

      <input type="hidden" name="g-recaptcha-response" value="">

      <div class="form-actions">
        <button type="submit" class="btn primary">ログイン</button>
        <a class="btn secondary" href="/password/reset">パスワードをお忘れの方</a>
      </div>
    </form>
    <div class="passkey-login">
      <p class="form-note">パスキー対応端末では、パスワードなしでログインできます。</p>
      <input type="hidden" id="passkey-csrf" value="<?= $e($csrf ?? '') ?>">
      <button type="button" class="btn secondary" data-passkey-login>パスキーでログイン</button>
      <p class="passkey-status" data-passkey-status></p>
      <p class="form-note">注意: 共有端末ではパスキーを登録しないでください。同一端末で複数アカウントのパスキー運用は想定していません。</p>
    </div>
    <div class="form-links">
      <a class="link" href="/register">従業員登録へ</a>
    </div>
  </section>
</div>

<script src="/passkeys.js" defer></script>
<?php if ($recaptchaEnabled && $recaptchaSiteKey !== ''): ?>
  <script src="https://www.google.com/recaptcha/api.js?render=<?= $e($recaptchaSiteKey) ?>" defer></script>
  <script src="/recaptcha_login.js" defer></script>
<?php endif; ?>
