<?php
  $otpLen = isset($emailOtpLength) ? (int)$emailOtpLength : 6;
  $otpLen = max(4, min(10, $otpLen));
?>
<div class="auth-container">
  <section class="card auth-card">
    <h2>メールアドレスの確認</h2>
    <p class="form-note">
      <code class="code-large"><?= $e($email ?? '') ?></code> に <?= $e((string)$otpLen) ?> 桁の確認コードを送信しました。10 分以内に入力して登録を完了してください。
    </p>
    <form method="post" action="/register/verify" class="form">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
      <input type="hidden" name="email" value="<?= $e($email ?? '') ?>">
      <label class="form-field" for="token">
        <span>確認コード</span>
        <input type="text" id="token" name="token" inputmode="numeric" pattern="[0-9]{<?= $e((string)$otpLen) ?>}" maxlength="<?= $e((string)$otpLen) ?>" required autocomplete="one-time-code">
      </label>
      <button type="submit" class="btn primary">登録を完了する</button>
    </form>
    <div class="form-actions form-inline">
      <form method="post" action="/register/verify/resend" class="nav-form">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
        <input type="hidden" name="email" value="<?= $e($email ?? '') ?>">
        <button type="submit" class="btn secondary">コードを再送</button>
      </form>
      <form method="post" action="/register/verify/cancel" class="nav-form">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
        <button type="submit" class="btn link">登録をやり直す</button>
      </form>
    </div>
  </section>
</div>
