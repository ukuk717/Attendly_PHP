# RateLimiter 設定メモ（PHP 版）

## ドライバ
- `RATE_LIMIT_DRIVER` で `apcu` / `file` / `memory` を選択（未指定 `auto` だと APCu 優先、不可なら file）。
- `file` ドライバは `storage/ratelimiter.json` を単一ファイルでロック更新。`RATE_LIMIT_FILE_PATH` で場所を変更可。
- `memory` はプロセスローカル（PHP-FPM マルチワーカーでは共有されないため本番では非推奨）。

## キーと TTL
- 固定ウィンドウ方式（`window_start` + `count` + `ttl` を保持）。APCu/file いずれも TTL は秒単位。
- 現在のキー例:  
  - `pwd_reset_ip:{ip}` / `pwd_reset_ip_email:{ip}:{email}`  
  - `pwd_reset_update_ip:{ip}` / `pwd_reset_update:{ip}:{tokenHash}`  
  - `register_verify_ip:{ip}` / `register_verify_ip_email:{ip}:{email}`  
  - `register_verify_resend_ip:{ip}` / `register_verify_resend:{ip}:{email}`
- `RateLimiter::allow($key, $maxAttempts, $windowSeconds)` の引数 TTL がそのキーの `ttl` として記録される。

## 推奨設定（ロリポップ標準プラン）
- モジュール版では APCu が無効なケースが多いため、`RATE_LIMIT_DRIVER=file` を既定とし、`storage/ratelimiter.json`（`RATE_LIMIT_FILE_PATH` 未指定時）を共有ストアにする。
- APCu を有効化できる場合は `RATE_LIMIT_DRIVER=apcu` + `apc.enable_cli=1` で CLI からも同一キーを参照可能。APCu が無効でも `auto` 設定で file に自動フォールバックする。
- 簡易確認（CLI、apc.enable_cli=0）では `cli-test` キーに対し 30 秒間 2 回まで許可 → 3 回目で拒否、かつ file ストアが生成されることを確認済み。

## ファイル数・サイズ対策
- `RATE_LIMIT_MAX_KEYS`（デフォルト 5000）を超えた場合、最も古いエントリから削除。`ttl` を過ぎたキーは読み込み時にクリーンアップ。
- ストレージ配下で完結するためロリポップ標準プランでも利用可。APCu 無効時のフォールバックとして CLI/Web 共通のストアを確保。

## 運用メモ
- APCu 利用時は `apc.enable_cli=1` を設定すると CLI スクリプトでも同一ストアを参照可能。
- ファイルパスを変える場合はアクセス権限に注意し、`storage/` 配下と同等の書き込み権限を確保する。
- file ドライバの簡易検証例（CLI 用）:
  ```bash
  @'
  <?php
  require __DIR__ . "/php/vendor/autoload.php";
  require __DIR__ . "/php/src/bootstrap.php";
  use Attendly\Support\RateLimiter;
  $_ENV["RATE_LIMIT_DRIVER"] = "file";
  $_ENV["RATE_LIMIT_FILE_PATH"] = __DIR__ . "/php/storage/ratelimiter_cli_test.json";
  @unlink($_ENV["RATE_LIMIT_FILE_PATH"]);
  for ($i = 1; $i <= 3; $i++) {
      echo ($i . ":" . (RateLimiter::allow("cli-test", 2, 30) ? "allow" : "deny") . PHP_EOL);
  }
  '@ | php
  ```
