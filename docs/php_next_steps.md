# PHP移行作業メモ（次にやること）

# 移行のゴール
- Node.js/Lambda環境で当初実装していた環境のPHP版の構築。
- デザインや機能の仕様はなるべく変えることなく、あくまで**新規プロジェクト**ではなく、**環境の移行**であることを忘れない。

## いま完了していること
- `php/` に PHP CGI 用の骨格を追加（`.env` 読み込み、セッション初期化）。Slim を導入し、`/health` と `/status` ルートを Slim で提供。
- MySQL 8 用 DDL は `docs/php_mysql_schema.sql` に集約済み。`DATETIME(3)` で UTC 前提、最新カラムまで反映済み。
- CGI 用の php.ini テンプレを `docs/php.ini.example` に追加。OPcache 有効化と Cookie 属性を明示（PHP 8.3/8.4 両対応）。
- DB 接続のスケルトン（PDO）を追加し、`/status` で接続可否を返すようにした。
- プレーンPHPビューのレンダラーとサンプルページ（`/web`）を用意し、テンプレート移植の足場を作成。
- Slim 用の簡易 CSRF ミドルウェアを追加し、X-CSRF-Token または form フィールド `csrf_token` を検証する仕組みを組み込み。
- Flash メッセージ機構を追加し、CSRF付きフォームのサンプル `/web/form` を実装（POST → flash → リダイレクト）。
- レイアウト機構（`renderWithLayout` + `views/layout.php`）を追加し、`/web` と `/web/form` をレイアウト経由で描画するように変更。テンプレート移植時の共通枠を用意。
- Host ヘッダ検証ミドルウェアを追加（ALLOWED_HOSTS 環境変数で制御）。
- セキュリティヘッダ（CSP/SAMEORIGIN/nosniff/Referrer-Policy）を付与するミドルウェアを追加。
- 認証ルートの足場（`/login` GET/POST, `/logout` POST）を追加し、ビュー `views/login.php` を用意。認証処理は未実装で、後続の移植で DB/セッション連携を行う前提。
- 認証サービス/セッション管理/保護ミドルウェアの骨組みを追加（AuthService は DB からユーザー取得＆bcrypt検証し失敗回数を更新、Repository を導入、SessionAuth でセッション保存、RequireAuthMiddleware を用意）。ダッシュボード `/dashboard` はログイン必須のサンプルとして追加。
- `.env.example` をローカル完結の設定に変更し、ローカル/テスト用 DB サンプル（attendly_local, attendly_test）を追記。
- ローカル用シードSQL `docs/db_seed_local.sql` を追加（テナント `local-demo` とユーザー `admin@example.com` / パスワード `TestPass123!`）。bcryptハッシュはスクリプト内に記載。
- シードSQLの日付を MySQL DATETIME 形式（`YYYY-MM-DD HH:MM:SS`）に修正。
- ユーザー情報をビューに渡すミドルウェアを追加し、レイアウトにログイン状態（メール表示/ログアウトボタン）を表示するナビを組み込んだ。`/dashboard` でログイン中のメールを表示。
- ログイン済みで `/login` にアクセスした場合は `/dashboard` にリダイレクトするように変更し、ログイン成功時の遷移先も `/dashboard` へ統一。
- `/whoami`（JSON）を追加し、現在のセッションユーザーを確認できるようにした。
- 従業員アカウント登録フォーム（/register）の足場を追加。ロールコード・氏名・メール・確認コード（6桁任意）・パスワード入力を実装し、未実装部分は Flash 通知で案内。`MIN_PASSWORD_LENGTH` で長さを制御。

## これから実施する手順
1. 依存インストール  
   ```bash
   cd php
   composer install
   ```
2. DB 作成と初期化（MySQL 8.0）  
   ```sql
   CREATE DATABASE attendly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'attendly_app'@'%' IDENTIFIED BY 'your-strong-password';
   GRANT ALL PRIVILEGES ON attendly.* TO 'attendly_app'@'%';
   FLUSH PRIVILEGES;
   ```
   ```bash
   mysql -h <host> -P 3306 -u attendly_app -p attendly < docs/php_mysql_schema.sql
   ```
3. 環境変数設定（`php/.env` 作成）  
   - `php/.env.example` をコピーして DB 接続情報・APP_BASE_URL・APP_ENV を埋める。
4. php.ini 配置（CGI 利用時）  
   - `docs/php.ini.example` を参考に、ロリポップの CGI 用 php.ini を設置。`session.save_path` を専用ディレクトリにし、`session.cookie_secure=1` を本番で有効化。
5. 動作確認（ローカル）  
   ```bash
   cd php
   php -S localhost:8000 -t public
   curl http://localhost:8000/health
   # ブラウザで http://localhost:8000/web と http://localhost:8000/web/form を確認
   ```
6. アプリ実装の次ステップ  
- Slim のルートを拡充し、認証/MFA/勤怠/給与/管理のハンドラを実装。EJS テンプレートを PHP ビューへ移植。  
- テンプレートエンジン（Blade/Twig/プレーンPHP）の選定と CSRF/flash の組み込み。  
- バッチ（データ保持/バックアップなど）を PHP CLI + cron へ移植。  
- Jest は実行不可のため、PHP 側はスモーク/手動シナリオでの検証計画を用意。
