<h1>従業員アカウント登録</h1>
<p class="meta">事前にテナント管理者から発行されたロールコードが必要です。</p>
<p class="meta">テナントによっては登録前にメールへ送信される 6 桁の確認コードが必要です。</p>
<?php if (!empty($roleCodeValue)): ?>
  <p class="meta">共有リンクからアクセスしたため、ロールコードが自動入力されています。</p>
<?php endif; ?>

<form method="post" action="/register">
  <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">

  <label for="roleCode">ロールコード</label>
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

  <div class="form-field" style="display:flex; gap:1rem; flex-wrap:wrap;">
    <div style="flex:1; min-width:140px;">
      <label for="lastName">姓</label>
      <input type="text" id="lastName" name="lastName" required autocomplete="family-name">
    </div>
    <div style="flex:1; min-width:140px;">
      <label for="firstName">名</label>
      <input type="text" id="firstName" name="firstName" required autocomplete="given-name">
    </div>
  </div>

  <label for="email">メールアドレス</label>
  <input type="email" id="email" name="email" required autocomplete="email">

  <label for="verificationCode">確認コード（6桁・必要な場合のみ）</label>
  <input
    type="text"
    id="verificationCode"
    name="verificationCode"
    inputmode="numeric"
    pattern="[0-9]{6}"
    maxlength="6"
    autocomplete="one-time-code"
  >

  <label for="password">パスワード</label>
  <input
    type="password"
    id="password"
    name="password"
    required
    minlength="<?= $e((string)($minPasswordLength ?? 12)) ?>"
    autocomplete="new-password"
  >

  <button type="submit">登録する</button>
</form>

<p class="meta">パスワードは <strong><?= $e((string)($minPasswordLength ?? 12)) ?> 文字以上</strong> で、英字・数字・記号を必ず含めてください。</p>
<p><a href="/login">ログイン画面へ戻る</a></p>
