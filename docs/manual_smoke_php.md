## PHP版 手動スモーク手順

### 前提
- この手順は手動確認用。
- 以降の手順はローカルを想定（ロリポップ本番は `migrate_php.md` を優先）。

### 1) 初期セットアップ
1. `php/` ディレクトリで Composer 依存を用意（`vendor/` はGit管理しない）。
2. `php/.env.example` を参考に `php/.env` を作成し、DB設定と以下を設定:
   - `MIN_PASSWORD_LENGTH`（既定: 8）
   - `MAX_PASSWORD_LENGTH`（既定: 256）
   - `PASSWORD_PEPPER`（本番は必須。長いランダム文字列）
   - `LOGIN_MAX_FAILURES` / `LOGIN_LOCK_SECONDS`
   - `MFA_TRUST_TTL_DAYS`（MFA信頼済みデバイスの有効期限。既定: 30）
   - `TENANT_DATA_ENCRYPTION_KEY`（本番は必須。テナント情報の暗号化キー）
   - `MFA_RESET_LOG_ENCRYPTION_KEY`（本番は必須。MFAリセット監査ログの暗号化キー）
   - `EXPORT_RETENTION_DAYS` / `EXPORT_MAX_FILES` / `EXPORT_MAX_TOTAL_MB`（エクスポートファイル保持設定）
   - `PAYROLL_RETENTION_DAYS`（給与明細保持期間。既定は無効）

### 2) DBスキーマ適用
- MySQL 8.0 に `docs/php_mysql_schema.sql` を適用。
  - 重要: `user_active_sessions`（同時ログイン制御）、`user_login_sessions`（ログイン履歴）、`signed_downloads`（署名URL）、`tenant_admin_mfa_reset_logs`（監査ログ）、`user_passkeys`（パスキー）が含まれる。

### 3) 起動
- `php/` で `php -S localhost:8000 -t public public/router.php`（ルーティング用。`/login` などのパスも動作）
  ~~- Windows 環境で `http://localhost:8000` が別プロセス（IPv6 `::1`）に割り当てられている場合があるため、ブラウザも `http://127.0.0.1:8000` で開いてください。~~
- `http://localhost:8000/health` が `ok` を返すこと

### 4) 認証・アカウント系
- ログイン: `/login`
  - 正常ログイン後に `/dashboard` へ遷移すること
  - 試行回数制限: 誤ったパスワードを繰り返すとロックされること（`LOGIN_MAX_FAILURES`/`LOGIN_LOCK_SECONDS`）
- パスワード変更: `/account`
  - 変更後に再ログイン要求が発生しても破綻しないこと
- パスワードリセット: `/password/reset`
  - `EMAIL_OTP_DEBUG_LOG=true`（非productionのみ）でメール送信をログで追えること

### 5) パスキー（WebAuthn）
- 登録: `/account`
  - HTTPS（または localhost）で「パスキーを登録」が完了すること
  - 登録後に一覧へ表示され、削除できること
- ログイン: `/login`
  - 「パスキーでログイン」から `/dashboard` に遷移すること
  - パスキー経由のログインでは `/login/mfa` が要求されないこと

### 6) MFA
- ログインMFA: `/login/mfa`
  - Email OTP / TOTP / バックアップコードの導線が動くこと
  - 信頼済みデバイス「この端末を信頼する」で次回MFAがスキップされること（期限は `MFA_TRUST_TTL_DAYS`）
- 設定: `/settings/mfa`
  - TOTP QRが表示され、検証成功で「設定済み」になること
  - QR/シークレットが再表示されないこと（必要ならリセット導線で再生成）
  - 認証アプリ無効化が動作し、関連データが削除されること

### 7) 同時ログイン制御（単一セッション）
1. 端末Aでログイン（MFAがある場合は完了）
2. 端末Bで同一アカウントにログイン
3. 端末Aで任意ページへ遷移した際にログアウトされ、Flashに「時刻/IP/端末情報」が表示されること

### 8) プラットフォーム管理（`role=platform_admin` かつ `tenant_id` が `NULL`）
- `/platform/tenants`
  - テナント作成（確認チェック必須）が成功すること
  - テナント停止/再開（確認チェック必須）が成功すること
  - テナント管理者MFAリセット/取消が成功すること（本人確認2点一致、監査ログ暗号化キーの設定に注意）

### 9) 勤怠エクスポート / 給与明細
- 勤怠エクスポート: `/admin/timesheets/export`
  - 期間指定で Excel（.xlsx） / PDF が署名URL経由でダウンロードできること（同一期間でもファイル生成が衝突しないこと）
  - 休憩(分)・実労働(分)・時間（x時間xx分）が含まれること（勤務中のレコードは「記録中」表示になること）
  - PDFは氏名が「姓/名」順で表示され、合計時間の行があること
- 給与明細送信（テナント管理者）: `/admin/payslips/send`
  - 送信が成功し、従業員側の一覧に反映されること
- 給与明細（従業員）: `/payrolls`
  - 一覧が表示され、署名URLでダウンロードできること

### 10) ストレージクリーンアップ（cron）
- エクスポートファイル（`php/storage/exports`）の世代管理:
  - `cd php && php scripts/cleanup_storage.php`
  - `EXPORT_RETENTION_DAYS` / `EXPORT_MAX_FILES` / `EXPORT_MAX_TOTAL_MB` で制御
- 給与明細ファイル（`php/storage/payslips`）の削除/アーカイブは運用設計が必要なため既定は無効:
  - 有効化する場合は `PAYROLL_RETENTION_DAYS`（例: 365）を設定し、同コマンドをcronで実行
