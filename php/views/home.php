<section class="card highlight-card">
  <h2>環境ステータス</h2>
  <div class="definition-list">
    <div>
      <dt>環境</dt>
      <dd><?= $e($env ?? 'local') ?></dd>
    </div>
    <div>
      <dt>PHP</dt>
      <dd><?= $e($php ?? '') ?></dd>
    </div>
    <div>
      <dt>タイムゾーン</dt>
      <dd><?= $e($timezone ?? '') ?></dd>
    </div>
    <div>
      <dt>CSRF トークン</dt>
      <dd class="code-large"><?= $csrf ? '発行済み' : '未生成' ?></dd>
    </div>
    <?php if (!empty($currentUser)): ?>
      <div>
        <dt>ログイン中</dt>
        <dd><?= $e($currentUser['email'] ?? '') ?></dd>
      </div>
    <?php endif; ?>
  </div>
  <p class="form-note">Slim + プレーンPHPビューの疎通確認ページです。API ステータスは <code>/status</code> で JSON を返します。</p>
</section>

<section class="card">
  <h2>サンプルと動作確認</h2>
  <p class="form-note">CSRF/Flash のサンプルフォームや主要フローへの導線です。既存環境の導線に合わせ、フォーム・カードの見た目を共通クラスで揃えています。</p>
  <div class="form-actions">
    <a class="btn primary" href="/web/form">CSRF/Flash サンプル</a>
    <a class="btn secondary" href="/login">ログイン</a>
    <a class="btn secondary" href="/register">従業員登録</a>
    <a class="btn secondary" href="/password/reset">パスワードリセット</a>
  </div>
</section>
