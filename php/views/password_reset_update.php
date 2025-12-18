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
          minlength="<?= $e((string)($minPasswordLength ?? 8)) ?>"
          maxlength="<?= $e((string)($maxPasswordLength ?? 256)) ?>"
          autocomplete="new-password"
        >
      </label>
      <button type="submit" class="btn primary">再設定する</button>
    </form>
    <p class="form-note">
      パスワードは <strong><?= $e((string)($minPasswordLength ?? 8)) ?> 文字以上</strong> で入力してください。
    </p>
    <div class="form-links">
      <a class="link" href="/login">ログイン画面へ戻る</a>
    </div>
  </section>
</div>
