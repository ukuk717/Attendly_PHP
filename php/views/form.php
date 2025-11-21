<h1>CSRF & Flash Sample</h1>

<form method="post" action="/web/form">
  <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
  <label for="name">名前</label>
  <input type="text" id="name" name="name" placeholder="例: 山田太郎">
  <button type="submit">送信</button>
</form>

<p><a href="/web">ステータスページへ戻る</a></p>
