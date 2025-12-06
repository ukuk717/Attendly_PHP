# OTP 運用ガイド（メール確認コード）

## 方針
- OTP の発行/検証は `EmailOtpService` で行い、コードは SHA-256 ハッシュで保存する。本番 (`APP_ENV=production|prod`) ではレスポンスに平文コードを含めない。
- メール本文以外への OTP 記録は禁止。ログは `storage/mail.log` のハッシュ化された宛先/件名のみで、本文は常に省略される。
- 再送・検証にはレートリミットを必須とし、キーは以下で運用する（いずれも固定ウィンドウ方式）:
  - `register_verify_ip:{ip}` / `register_verify_ip_email:{ip}:{email}`
  - `register_verify_resend_ip:{ip}` / `register_verify_resend:{ip}:{email}`

## 環境設定
- ステージング: `MAIL_TRANSPORT=log` で実送信を抑止し、`php/storage/mail.log` のみ確認する。
- 本番送信: `MAIL_TRANSPORT=smtp`（もしくは `sendmail`）。`MAIL_AUTH_TYPE` は `login|plain|cram-md5` のみを許可し、XOAUTH2 は使用しない。
- 暗号化: `MAIL_ENCRYPTION=tls`（587/STARTTLS）または `MAIL_ENCRYPTION=ssl`（465/SMTPS）。465 利用時は暗黙 TLS として扱う。
- 本番では `APP_ENV` を `production` に設定し、`EMAIL_OTP_*`（TTL/試行上限/ロック秒）を環境に合わせて調整する。

## 運用チェック
- `/register` → `/register/verify` → `/login` の一連フローで、画面や flash メッセージに OTP が出力されないことを確認する。
- `mail.log` には宛先/件名ハッシュのみが出力され、本文・コードが残らないことを確認する（不要になったログはローテーションする）。
- 再送・検証が連続した場合は HTTP 429/303 で抑制されることを手動で確認し、RateLimiter のドライバ設定が期待通りか `docs/rate_limiter.md` に沿って点検する。
