# PHP/CGI への移行計画（ロリポップ標準プラン・モジュール設定固定を考慮）

## 前提・制約
- すべてUTF-8を使用すること。BOMをつけることも不可。純粋なUTF-8のみを使用する。
- 使用可: PHP CGI 版 / PHP モジュール版（Apache プロセス）、cron。常駐 Node は不可。
- モジュール版は php.ini を編集できず、固定値は以下（変更不可）:
  - session.auto_start=0, session.use_trans_sid=0, session.use_only_cookies=1
  - opcache.enable=0, opcache.enable_cli=0（OPcache 必須なら CGI 版を選ぶ）
  - default_charset=UTF-8, short_open_tag=0, allow_url_fopen=1, allow_url_include=0
  - upload_max_filesize=100M, post_max_size=100M
  - display_errors=0, error_reporting=22527, variables_order=GPCS
- ストレージ: 合算 450GB（ファイル・メール・DB）、ファイル容量 400GB、ファイル数上限 500,000。
- MySQL 前提。セッション/ジョブは PHP で再実装。

## 環境選択ポリシー
- OPcache や php.ini 調整（session.gc_maxlifetime など）が必要なら CGI 版を選択し、専用 php.ini を用意する。
- モジュール版のまま運用する場合:
  - OPcache 無効を前提にパフォーマンスを見積もる（キャッシュ層なし、プロセス常駐のみ）。
  - php.ini が変えられないので、クッキー属性・TTL・GC は `ini_set` で上書きできる範囲 + アプリ/cron で補う。
  - upload/post は 100M 上限固定。超過するワークフローは分割/外部ストレージなどの代替を設計。

## アーキテクチャ方針
- Web: PHP（Slim/Lumen 等の軽量 FW またはフロントコントローラ）。提供形態（mod_php/CGI）に合わせる。
- テンプレート: EJS → PHP テンプレート（標準 PHP/Blade/Twig 等）。
- セッション: PHP セッション（files または MySQL）。TTL と同時ログイン管理はアプリ層。`cookie_secure/httponly/samesite` をヘッダで明示。
- DB: MySQL（utf8mb4）。現行 knex スキーマを Phinx/Laravel Migration 等へ移植。
- バッチ: cron + PHP CLI（期限切れデータ削除、証明書監視など）。
- メール: ロリポップの sendmail/SMTP、または外部 SMTP。

## 容量とファイル数対策
- ログとバックアップ、アップロード保管場所を分離し、日次/週次で圧縮・削除。世代数は 7〜14 を目安にし、400GB/50万ファイルに余裕を持たせる。
- セッションを files 保存にする場合は専用ディレクトリに隔離し、cron で古いファイルを削除（`find -mmin` 相当）してファイル数を抑制。
- アップロードは 100M を超えない前提で UI/バリデーションを実装。超える場合は分割アップロードまたは外部ストレージを検討。

## 移行ステップ（詳細）
1) 機能棚卸しと要件確定  
   - 認証/管理/MFA/勤怠/エクスポート/設定/バッチを洗い出し。  
   - セッション要件（TTL/同時ログイン/クッキー属性）と MFA ロックアウト条件を確定。  
   - OPcache が不要であるか、CGI 版へ切り替える必要があるかを決定。

2) データモデル移行  
   - knex マイグレーションから MySQL スキーマを抽出し、PHP 用マイグレーションに書き換え。  
   - 既存データを MySQL に移行（SQLite `.dump` など）。datetime/boolean/auto_increment の差異を補正。

3) 認証・セッション  
   - パスワード: bcrypt（`password_hash`/`PASSWORD_BCRYPT`）で互換。  
   - セッション: handler=files なら専用ディレクトリ + cron GC。handler=MySQL なら専用テーブル + TTL カラム + cron 削除。  
   - php.ini 固定値を前提に、`ini_set` で可能な範囲を補強し、クッキー属性はアプリで強制送出。

4) MFA（TOTP/メール OTP）  
   - TOTP: spomky-labs/otphp 等で登録・検証。シークレットは既存カラムを流用。  
   - メール OTP: 発行/検証/再送制御/失敗カウントを PHP で実装し、sendmail/SMTP で送信。  
   - 失敗ロックやクールダウンを現行仕様に合わせて再現。

5) ルーティング/テンプレート  
   - `/auth`, `/users`, `/tenants`, `/work_sessions`, `/payrolls`, `/mfa` 等を PHP で再構築。  
   - EJS ビューを PHP テンプレートへ変換し、CSRF トークン埋め込みと flash メッセージを移行。  
   - アップロードは `$_FILES` で受け、バリデーション・保存先・容量上限（100M）を設定。

6) ビジネスロジック  
   - 打刻重複検知・時間計算・集計をサービス層に実装。  
   - 権限チェック（テナント管理者/一般ユーザー）を再現。  
   - エクスポートは PHPSpreadsheet 等で Excel/CSV を生成。

7) バッチ・保守ジョブ（cron）  
   - 期限切れデータ削除: PHP CLI スクリプトを日次実行。  
   - バックアップ: `mysqldump` を日次取得し、7〜14 世代ローテーション（容量・ファイル数を常時監視）。  
   - ログローテーション: 日次/週次で圧縮し、所定世代で削除。  
   - （任意）証明書更新監視ジョブ。

8) 環境変数/設定  
   - phpdotenv 等で `.env` を読み込む。主要項目: `APP_BASE_URL`, `APP_TIMEZONE`, `ALLOWED_HOSTS`, `DB_*`, `SESSION_SECRET`, `SESSION_TTL_SECONDS`, `DATA_RETENTION_YEARS`, `MFA_RESET_LOG_ENCRYPTION_KEY` 等。  
   - モジュール版では php.ini 固定を前提に、`ini_set`/HTTP ヘッダで補うべき設定（cookie params, cache headers 等）を明文化。

9) テストとリハーサル  
   - PHPUnit でユニット/機能テスト。  
   - ステージングでログイン→打刻→エクスポート→MFA→パスワードリセットを手動確認。  
   - 軽負荷テスト（同時 10〜20 セッション）でレスポンスとメモリ/プロセス数を確認。

10) 本番切替  
    - 最新データを MySQL に再インポート。  
    - DNS/ドメインを PHP 実行環境へ向け、HTTPS を有効化。  
    - 旧 Node 環境を一定期間 Read Only 待機とし、ロールバック手順を保持。

## リスクと対応
- OPcache 無効: パフォーマンス劣化を前提にし、必要なら CGI 版 + OPcache や別環境を検討。  
- php.ini 固定: セッション TTL/GC などは cron + アプリで代替。必須設定が変えられない場合は CGI 版へ切替。  
- ストレージ/ファイル数: ログ/バックアップ/アップロードのローテーションを徹底し、容量・ファイル数の監視を自動化。  
- 工数: API/画面/MFA/バッチの全面移植。仕様差はテストで吸収。  
- セキュリティ: CSRF/XSS/SQLi 対策、HTTPS 強制、セッション固定化防止を PHP で再実装。  
- 互換性: bcrypt コスト/タイムゾーン/日付処理を合わせ、既存アカウントでのログインを必ず検証。
