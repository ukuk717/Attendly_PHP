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

## ファイル数・サイズ対策
- `RATE_LIMIT_MAX_KEYS`（デフォルト 5000）を超えた場合、最も古いエントリから削除。`ttl` を過ぎたキーは読み込み時にクリーンアップ。
- ストレージ配下で完結するためロリポップ標準プランでも利用可。APCu 無効時のフォールバックとして CLI/Web 共通のストアを確保。

## 運用メモ
- APCu 利用時は `apc.enable_cli=1` を設定すると CLI スクリプトでも同一ストアを参照可能。
- ファイルパスを変える場合はアクセス権限に注意し、`storage/` 配下と同等の書き込み権限を確保する。
