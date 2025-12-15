<!doctype html>
<html lang="ja">
<head>
  <?php
    $brand = $brandName ?? ($_ENV['APP_BRAND_NAME'] ?? 'Attendly');
    if (empty($csrf)) {
        throw new \RuntimeException('CSRF token is required for authenticated layout');
    }
    $csrfToken = $csrf;
    $isAuthed = !empty($currentUser);
    $role = $isAuthed && is_array($currentUser) ? (string)($currentUser['role'] ?? '') : '';
    $tenantId = $isAuthed && is_array($currentUser) ? ($currentUser['tenant_id'] ?? null) : null;
    $isPlatform = $isAuthed && $role === 'admin' && $tenantId === null;
    $isTenantAdmin = $isAuthed && in_array($role, ['admin', 'tenant_admin'], true) && $tenantId !== null;
    $displayName = '';
    if ($isAuthed && is_array($currentUser)) {
        $displayName = trim((string)($currentUser['name'] ?? ''));
        if ($displayName === '') {
            $last = trim((string)($currentUser['last_name'] ?? ''));
            $first = trim((string)($currentUser['first_name'] ?? ''));
            $displayName = trim($last . ' ' . $first);
        }
        if ($displayName === '') {
            $displayName = trim((string)($currentUser['email'] ?? ''));
        }
    }
  ?>
  <?php include __DIR__ . '/_partials/head.php'; ?>
</head>
<body>
  <header class="app-header">
    <div class="app-header__brand">
      <?php $homeLink = $isAuthed ? '/dashboard' : '/login'; ?>
      <h1 class="app-header__title">
        <a href="<?= $e($homeLink) ?>" class="app-header__home-link"><?= $e($brand) ?></a>
      </h1>
    </div>
    <button class="menu-toggle" type="button" aria-label="メニューを開く" aria-expanded="false" aria-controls="primary-nav">
      <span class="menu-toggle__bar"></span>
      <span class="menu-toggle__bar"></span>
      <span class="menu-toggle__bar"></span>
    </button>
    <nav class="app-header__nav" id="primary-nav">
      <a href="/web" class="nav-link">ホーム</a>
      <?php if ($isAuthed): ?>
        <a href="/dashboard" class="nav-link">ダッシュボード</a>
        <?php if ($isPlatform): ?>
          <a href="/platform/tenants" class="nav-link">テナント管理</a>
        <?php elseif ($isTenantAdmin): ?>
          <a href="/admin/role-codes" class="nav-link">ロールコード管理</a>
          <a href="/admin/timesheets/export" class="nav-link">勤怠エクスポート</a>
          <a href="/admin/payslips/send" class="nav-link">給与明細送信</a>
        <?php else: ?>
          <a href="/payrolls" class="nav-link">給与明細</a>
        <?php endif; ?>
        <a href="/account" class="nav-link">アカウント設定</a>
        <a href="/settings/mfa" class="nav-link">MFA設定</a>
        <span class="nav-user"><?= $e($displayName) ?></span>
        <form id="logout-form" method="post" action="/logout" class="nav-form">
          <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
          <button type="submit" class="btn secondary">ログアウト</button>
        </form>
      <?php else: ?>
        <a href="/register" class="nav-link">従業員登録</a>
        <a href="/password/reset" class="nav-link">パスワードリセット</a>
        <a href="/login" class="btn primary">ログイン</a>
      <?php endif; ?>
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
