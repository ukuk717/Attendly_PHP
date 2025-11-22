<section class="card auth-card">
  <h2>ログイン</h2>
  <form method="post" action="/login" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
    <label class="form-field" for="email">メールアドレス
      <input type="email" id="email" name="email" required autocomplete="email">
    </label>

    <label class="form-field" for="password">パスワード
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </label>

    <button type="submit" class="btn primary">ログイン</button>
  </form>
</section>
