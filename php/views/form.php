<section class="card">
  <h2>CSRF & Flash サンプル</h2>
  <p class="form-note">CSRF トークン付きフォームのサンプルです。送信すると Flash メッセージで結果を表示します。</p>
  <form method="post" action="/web/form" class="form">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
    <label class="form-field" for="name">
      <span>名前</span>
      <input
        type="text"
        id="name"
        name="name"
        placeholder="例: 山田太郎"
        autocomplete="name"
        maxlength="64"
        required
      >
    </label>
    <div class="form-actions">
      <button type="submit" class="btn primary">送信する</button>
      <a href="/web" class="btn secondary">環境ステータスへ戻る</a>
    </div>
  </form>
</section>
