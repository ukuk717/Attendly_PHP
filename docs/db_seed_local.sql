-- ローカル開発/テスト用の簡易シードデータ。
-- 実行前に .env で指定したローカルDB (attendly_local / attendly_test など) に接続してください。

-- 1) テナント作成
INSERT INTO tenants (tenant_uid, name, contact_email, contact_phone, status, created_at)
VALUES ('local-demo', 'Local Demo Tenant', 'admin@example.com', NULL, 'active', '2025-11-22 00:00:00');

-- 2) ユーザー作成
-- プラットフォーム管理者: plat@form.com / Platform1234!
-- テナント管理者: admin@example.com / TestPass123!
-- 従業員: test@test.com / testuser_1234
-- ハッシュは `php -r "echo password_hash('PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"` で生成済み。
INSERT INTO users (
  tenant_id, username, email, password_hash, role,
  must_change_password, failed_attempts, created_at, status
) VALUES (
  NULL,
  'platform_admin',
  'plat@form.com',
  '$2y$12$IcpzPIxHn7opWADmdUilUeCk3VYP2GaijOusqjgfzoweobsvoXoBy',
  'platform_admin',
  0,
  0,
  '2025-11-22 00:00:00',
  'active'
);

INSERT INTO users (
  tenant_id, username, email, password_hash, role,
  must_change_password, failed_attempts, created_at, status
) VALUES (
  (SELECT id FROM tenants WHERE tenant_uid='local-demo'),
  'admin',
  'admin@example.com',
  '$2y$12$IZIOV8wRZ28KC797L5LMouFyRVXzgj.RiU6g3xpPF0Q2DIGuQFwOO',
  'tenant_admin',
  0,
  0,
  '2025-11-22 00:00:00',
  'active'
);

INSERT INTO users (
  tenant_id, username, email, password_hash, role,
  must_change_password, failed_attempts, created_at, status
) VALUES (
  (SELECT id FROM tenants WHERE tenant_uid='local-demo'),
  'employee',
  'test@test.com',
  '$2y$12$cmn.LTsK5fmMQtQgQZh9Jep21rJdvuUcQGaaECJaruFxHBrhuYBB.',
  'employee',
  0,
  0,
  '2025-11-22 00:00:00',
  'active'
);
