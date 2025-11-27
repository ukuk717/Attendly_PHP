<div class="auth-container">
  <section class="card auth-card">
    <h2>パスワードリセット</h2>
    <p class="form-note">
      登録済みのメールアドレスを入力すると、リセット用リンクを送信します。メールが届かない場合は数分待ってから再度お試しください。
    </p>
    <form method="post" action="/password/reset" class="form">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
      <label class="form-field" for="email">
        <span>メールアドレス</span>
        <input type="email" id="email" name="email" required autocomplete="email" maxlength="254" inputmode="email">
      </label>
      <button type="submit" class="btn primary">リセットリンクを送信</button>
    </form>
    <div class="form-links">
      <a class="link" href="/login">ログイン画面へ戻る</a>
    </div>
  </section>
</div>
