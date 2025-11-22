<!doctype html>
<html lang="ja">
<head>
  <?php
    $brand = $brandName ?? ($_ENV['APP_BRAND_NAME'] ?? 'Attendly');
  ?>
  <?php include __DIR__ . '/_partials/head.php'; ?>
</head>
<body>
  <header class="app-header">
    <div class="app-header__brand">
      <?php $homeLink = !empty($currentUser) ? '/dashboard' : '/login'; ?>
      <h1 class="app-header__title"><a href="<?= $e($homeLink) ?>" class="app-header__home-link"><?= $e($brand) ?></a></h1>
    </div>
    <nav class="app-header__nav">
      <a href="/web">Home</a>
      <a href="/dashboard">Dashboard</a>
      <a href="/web/form">Form Sample</a>
      <a href="/register">Register</a>
    </nav>
    <div class="app-header__actions">
      <?php if (!empty($currentUser)): ?>
        <span class="app-header__user"><?= $e($currentUser['email'] ?? '') ?></span>
        <form id="logout-form" method="post" action="/logout" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf ?? '') ?>">
          <button type="submit" class="btn">ログアウト</button>
        </form>
      <?php else: ?>
        <a href="/login" class="btn">ログイン</a>
      <?php endif; ?>
    </div>
  </header>

  <main class="container">
    <?php if (!empty($flashes)): ?>
      <?php foreach ($flashes as $flash): ?>
        <?php
          $type = $flash['type'] ?? 'info';
          $allowedTypes = ['success', 'error', 'info'];
          if (!in_array($type, $allowedTypes, true)) {
              $type = 'info';
          }
        ?>
        <div class="flash <?= $e($type) ?>"><?= $e($flash['message'] ?? '') ?></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?= $content ?>
  </main>
</body>
</html>
