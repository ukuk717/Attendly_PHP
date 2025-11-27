<div class="auth-container">
  <section class="card auth-card">
    <h2>パスワード再設定</h2>
    <form method="post" action="/password/reset/<?= $e($token ?? '') ?>" class="form">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
      <label class="form-field" for="password">
        <span>新しいパスワード</span>
        <input
          type="password"
          id="password"
          name="password"
          required
          minlength="<?= $e((string)($minPasswordLength ?? 12)) ?>"
          maxlength="128"
          pattern="(?=.*[A-Za-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{<?= $e((string)($minPasswordLength ?? 12)) ?>,128}"
          autocomplete="new-password"
        >
      </label>
      <button type="submit" class="btn primary">再設定する</button>
    </form>
    <p class="form-note">
      パスワードは <strong><?= $e((string)($minPasswordLength ?? 12)) ?> 文字以上</strong> で、英字・数字・記号を必ず含めてください。
    </p>
    <div class="form-links">
      <a class="link" href="/login">ログイン画面へ戻る</a>
    </div>
  </section>
</div>
