<section class="card auth-card">
  <h2>メールアドレスの確認</h2>
  <p class="form-note">
    <code><?= $e($email ?? '') ?></code> に 6 桁の確認コードを送信しました。10 分以内に入力して登録を完了してください。
  </p>
  <form method="post" action="/register/verify" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
    <input type="hidden" name="email" value="<?= $e($email ?? '') ?>">
    <label class="form-field">
      <span>確認コード</span>
      <input type="text" name="token" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
    </label>
    <button type="submit" class="btn primary">登録を完了する</button>
  </form>
  <div class="form-links">
    <form method="post" action="/register/verify/resend" class="form-inline">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
      <input type="hidden" name="email" value="<?= $e($email ?? '') ?>">
      <button type="submit" class="btn secondary">コードを再送</button>
    </form>
    <form method="post" action="/register/verify/cancel" class="form-inline">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
      <button type="submit" class="btn link">登録をやり直す</button>
    </form>
  </div>
</section>
