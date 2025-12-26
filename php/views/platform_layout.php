<!doctype html>
<html lang="ja">
<head>
  <?php
    $brand = $brandName ?? ($_ENV['APP_BRAND_NAME'] ?? 'Attendly');
    if (empty($csrf)) {
        throw new \RuntimeException('CSRF token is required for platform layout');
    }
    $csrfToken = $csrf;
    $displayName = '';
    if (!empty($platformUser) && is_array($platformUser)) {
        $name = trim((string)($platformUser['name'] ?? ''));
        if ($name !== '') {
            $displayName = $name;
        } else {
            $email = trim((string)($platformUser['email'] ?? ''));
            if ($email !== '' && str_contains($email, '@')) {
                [$local, $domain] = explode('@', $email, 2);
                $local = (string)$local;
                $domain = (string)$domain;
                $head = $local !== '' ? mb_substr($local, 0, 1, 'UTF-8') : '';
                $displayName = $head . '***@' . $domain;
            }
        }
    }
  ?>
  <?php include __DIR__ . '/_partials/head.php'; ?>
</head>
<body>
  <header class="app-header">
    <div class="app-header__brand">
      <h1 class="app-header__title">
        <a href="/platform/tenants" class="app-header__home-link"><?= $e($brand) ?></a>
      </h1>
      <span class="app-header__role">プラットフォーム管理者</span>
    </div>
    <button class="menu-toggle" type="button" aria-label="メニューを開く" aria-expanded="false" aria-controls="primary-nav">
      <span class="menu-toggle__bar"></span>
      <span class="menu-toggle__bar"></span>
      <span class="menu-toggle__bar"></span>
    </button>
    <nav class="app-header__nav" id="primary-nav">
      <a href="/platform/tenants" class="nav-link">テナント一覧</a>
      <a href="/account" class="nav-link">アカウント設定</a>
      <a href="/settings/mfa" class="nav-link">二段階認証設定</a>
      <?php if ($displayName !== ''): ?>
        <span class="nav-user"><?= $e($displayName) ?></span>
      <?php endif; ?>
      <form id="logout-form" method="post" action="/logout" class="nav-form">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <button type="submit" class="btn secondary">ログアウト</button>
      </form>
    </nav>
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
  <script src="/header.js" defer></script>
</body>
</html>
