<?php
  $otpLen = isset($emailOtpLength) && is_numeric($emailOtpLength) ? (int)$emailOtpLength : 6;
  $otpLen = max(4, min(10, $otpLen));
?>
<div class="auth-container">
  <section class="card auth-card">
    <h2>従業員アカウント登録</h2>
    <p class="form-note">
      事前にテナント管理者から発行されたロールコードが必要です。半角英数字（A-Z, 0-9）のみ入力可能です。
    </p>
    <p class="form-note">
      テナントによっては、登録後にメールアドレスへ送信される <?= $e((string)$otpLen) ?> 桁の確認コードを入力して登録を完了します。
    </p>
    <?php if (!empty($roleCodeValue)): ?>
      <p class="form-note">共有リンクからアクセスしたため、ロールコードが自動入力されています。</p>
    <?php endif; ?>

    <form method="post" action="/register" class="form">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">

      <label class="form-field" for="roleCode">ロールコード
        <input
          type="text"
          id="roleCode"
          name="roleCode"
          maxlength="32"
          required
          autocomplete="off"
          pattern="[A-Za-z0-9]+"
          title="英数字のみ利用できます"
          value="<?= $e($roleCodeValue ?? '') ?>"
        >
      </label>

      <div class="form-field-inline register-name-inline">
        <label class="form-field" for="lastName">
          <span>姓</span>
          <input type="text" id="lastName" name="lastName" required autocomplete="family-name" maxlength="64">
        </label>
        <label class="form-field" for="firstName">
          <span>名</span>
          <input type="text" id="firstName" name="firstName" required autocomplete="given-name" maxlength="64">
        </label>
      </div>

      <label class="form-field" for="email">メールアドレス
        <input type="email" id="email" name="email" required autocomplete="email" maxlength="254">
      </label>

      <label class="form-field" for="password">パスワード
        <input
          type="password"
          id="password"
          name="password"
          required
          minlength="<?= $e((string)($minPasswordLength ?? 12)) ?>"
          autocomplete="new-password"
          maxlength="128"
        >
      </label>

      <button type="submit" class="btn primary">登録する</button>
    </form>

    <p class="form-note">パスワードは <strong><?= $e((string)($minPasswordLength ?? 12)) ?> 文字以上</strong> で、英字・数字・記号を含めてください。</p>
    <div class="form-links">
      <a class="link" href="/login">ログイン画面へ戻る</a>
    </div>
  </section>
</div>
