# PHP移行計画（ロリポップ！スタンダード）

## ホスティング確認と実行モード
- 公式仕様: PHP 8.3（CGI版）、8.4（CGI/モジュール版）、MySQL 8.0、phpMyAdmin、cron あり。SSH はスタンダード以上で利用可。  
- モジュール版は `opcache.enable=0` など `migrate_php.md` 記載の固定値で php.ini を編集不可。性能とセッション/OPcache調整のため **PHP 8.4 CGI + カスタム php.ini** を前提とし、`docs/php.ini.example` を配置して有効化する。
- ストレージ上限 450GB / ファイル数 50万 を踏まえ、アップロード・ログ・セッション保存先を分離し cron でローテーションする。

## データモデル / DDL（MySQL8）
- 既存 Knex マイグレーションを MySQL 用に落とした DDL を `docs/php_mysql_schema.sql` に追加。主な変換:
  - 文字列で保持していた日時は `DATETIME(3)`（UTC）へ型変換、`payroll_records.sent_on` は `DATE`。  
  - `tenants.require_employee_email_verification` など最新カラムも反映。  
  - `email_otp_requests` / MFA 系テーブルも含め、全 FK とインデックスを定義。  
  - DB セッションを使う場合のサンプル `php_sessions` テーブルをコメントで記載。
- 既存データ投入時は ISO8601 文字列を `STR_TO_DATE(..., '%Y-%m-%dT%H:%i:%s.%fZ')` などで UTC に変換しつつ流し込む。ID/ユニークキーは維持する。

## 機能インベントリ（Node/EJS 実装ベース）
- 認証: `/login`（TOTP/メールOTP 併用、ロック/再送制御）、`/logout`。  
- 新規登録: `/register` → `/register/verify`（再送/キャンセル含む）、ロールコード消費。  
- アカウント: `/account` でメール・氏名・パスワード変更、`/password/reset` 系フロー。  
- 従業員: `/employee` で打刻開始/終了/修正、`/employee/payrolls` で給与明細確認/DL。  
- 管理: `/platform/tenants` でテナント作成/状態更新/登録設定、テナント管理者 UI で勤怠修正・給与アップロード・ユーザー管理・ロールコード発行/無効化。  
- MFA: TOTP 登録/復旧コード/信頼済みデバイス、メールOTP の発行・検証・ロックアウト。  
- バッチ: `scripts/purgeExpiredData.js` で勤怠・給与の保持期間超過データをアーカイブ→削除（関連ファイルも移動）。PHP CLI + cron に移植する。

## これからの実装ステップ
1. PHP アプリ骨格: `public/index.php` フロントコントローラ、PSR-4 オートロード、`vlucas/phpdotenv` で `.env` を読む。軽量 FW（Slim/Lumen 等）を選択し、CSRF・flash 相当の仕組みを用意。  
2. データ層: `docs/php_mysql_schema.sql` を MySQL8 に適用。Phinx/Laravel Migrations 等に移植し、既存 SQLite/PostgreSQL のデータを UTC `DATETIME(3)` へ変換してインポートする。  
3. セッション: `docs/php.ini.example` に基づき cookie 属性/TTL を固定。ファイル保存なら専用ディレクトリ + cron GC、DB 保存なら `php_sessions` テーブル＋ `session_set_save_handler` で TTL/ロックアウト情報を管理。  
4. 認証/MFA: bcrypt(`password_hash`) と TOTP/メール OTP を PHP で再実装。メール送信は sendmail/SMTP を抽象化し再送間隔・最大試行・ロックアウトを DB で検証する。  
5. 画面移植: EJS を PHP テンプレートに置換（CSRF トークン/flash を踏襲）。アップロードは 100M 上限、`$_FILES` チェックと保存先分離を徹底。  
6. バッチ: cron でデータ保持ジョブ・mysqldump ローテーション・ログ圧縮・証明書監視を PHP CLI 化し、容量/ファイル数に余裕を持たせる。  
7. 検証: Jest は利用不可のため、PHP 側はスモーク CLI とステージングでの手動シナリオ（ログイン→打刻→給与DL→MFA→パスワードリセット）を重点確認する。  

## 参考ファイル
- PHP DDL: `docs/php_mysql_schema.sql`
- php.ini テンプレ: `docs/php.ini.example`
- 既存バッチ実装（移植対象）: `scripts/purgeExpiredData.js`
