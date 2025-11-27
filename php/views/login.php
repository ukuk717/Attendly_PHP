<div class="auth-container">
  <section class="card auth-card">
    <h2>ログイン</h2>
    <form method="post" action="/login" class="form">
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

      <div class="form-actions">
        <button type="submit" class="btn primary">ログイン</button>
        <a class="btn secondary" href="/password/reset">パスワードをお忘れの方</a>
      </div>
    </form>
    <div class="form-links">
      <a class="link" href="/register">従業員登録へ</a>
    </div>
  </section>
</div>
