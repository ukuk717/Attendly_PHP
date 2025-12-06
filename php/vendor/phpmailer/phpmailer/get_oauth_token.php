<?php

// このサンプルスクリプトは本番配布から除外します。
// センシティブな OAuth クライアント情報を扱うため、誤って公開ディレクトリに配置された場合の悪用を防ぐ目的で無効化しています。
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'This helper is disabled for security reasons. Generate OAuth tokens in a dedicated, locked-down environment.';
exit;
