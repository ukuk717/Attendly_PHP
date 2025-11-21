<h1>Attendly PHP Skeleton</h1>
<p class="meta">環境: <?= $e($env ?? 'local') ?></p>
<p class="meta">PHP: <?= $e($php ?? '') ?></p>
<p class="meta">タイムゾーン: <?= $e($timezone ?? '') ?></p>
<p class="meta">CSRF Token: <code><?= $e($csrf ?? '') ?></code></p>
<p>このページは Slim + プレーンPHPビューの疎通確認用です。</p>
<p>APIステータス確認は <code>/status</code> を利用してください。</p>
