<h1>ログイン</h1>
<form method="post" action="/login">
  <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
  <label for="email">メールアドレス</label>
  <input type="email" id="email" name="email" required autocomplete="email">

  <label for="password">パスワード</label>
  <input type="password" id="password" name="password" required autocomplete="current-password">

  <button type="submit">ログイン</button>
</form>
