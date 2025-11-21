-- ローカル開発/テスト用の簡易シードデータ。
-- 実行前に .env で指定したローカルDB (attendly_local / attendly_test など) に接続してください。

-- 1) テナント作成
INSERT INTO tenants (tenant_uid, name, contact_email, status, created_at)
VALUES ('local-demo', 'Local Demo Tenant', 'admin@example.com', 'active', '2025-11-22 00:00:00');

-- 2) ユーザー作成 (パスワード: TestPass123!)
-- ハッシュは `php -r "echo password_hash('TestPass123!', PASSWORD_BCRYPT), PHP_EOL;"` で生成済み。
INSERT INTO users (
  tenant_id, username, email, password_hash, role,
  must_change_password, failed_attempts, created_at, status
) VALUES (
  (SELECT id FROM tenants WHERE tenant_uid='local-demo'),
  'admin',
  'admin@example.com',
  '$2y$12$IZIOV8wRZ28KC797L5LMouFyRVXzgj.RiU6g3xpPF0Q2DIGuQFwOO',
  'platform',
  0,
  0,
  '2025-11-22 00:00:00',
  'active'
);
