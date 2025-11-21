<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title ?? 'Attendly') ?></title>
  <style>
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 2rem; color: #222; }
    h1 { margin-bottom: 0.5rem; }
    .meta { color: #555; margin: 0.25rem 0; }
    code { background: #f5f5f5; padding: 0.1rem 0.25rem; border-radius: 4px; }
    form { margin-top: 1rem; }
    label { display: block; margin-bottom: 0.5rem; }
    input[type="text"], input[type="email"], input[type="password"] { padding: 0.4rem; width: 100%; max-width: 260px; }
    button { padding: 0.5rem 1rem; margin-top: 0.5rem; }
    .flash { padding: 0.5rem 0.75rem; border-radius: 4px; margin-bottom: 0.5rem; }
    .flash.success { background: #e6f4ea; color: #1f7a3d; }
    .flash.error { background: #fdecea; color: #c33; }
    .flash.info { background: #e8f0fe; color: #2952cc; }
  </style>
</head>
<body>
  <header style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <div>
      <a href="/web">Home</a>
      <span style="margin-left:0.5rem;"></span>
      <a href="/dashboard">Dashboard</a>
      <span style="margin-left:0.5rem;"></span>
      <a href="/web/form">Form Sample</a>
    </div>
    <div>
      <?php if (!empty($currentUser)): ?>
        <span style="color:#555; margin-right:0.5rem;"><?= $e($currentUser['email'] ?? '') ?></span>
        <form id="logout-form" method="post" action="/logout" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
          <button type="submit">ログアウト</button>
        </form>
      <?php else: ?>
        <a href="/login">ログイン</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if (!empty($flashes)): ?>
    <?php foreach ($flashes as $flash): ?>
      <div class="flash <?= $e($flash['type'] ?? '') ?>"><?= $e($flash['message'] ?? '') ?></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?= $content ?>
</body>
</html>
