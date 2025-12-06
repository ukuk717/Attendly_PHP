<?php

declare(strict_types=1);

namespace Attendly\Support;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * シンプルなメール送信ラッパー。
 * - まずファイルにロギング（storage/mail.log）を行い、実送信は transport ごとに切り替える。
 * - transport: log/mail/sendmail/smtp（env: MAIL_TRANSPORT）。
 * - 送信失敗時は例外を握りつぶし、ログに WARN を残して呼び出し側を止めない。
 */
final class Mailer
{
    private string $fromAddress;
    private string $fromName;
    private string $logPath;
    private string $transport;
    private int $logMaxBytes;
    private ?string $host;
    private int $port;
    private ?string $username;
    private ?string $password;
    private ?string $encryption;
    private int $timeout;
    private string $sendmailPath;
    private ?string $authType;

    public function __construct(?string $fromAddress = null, ?string $fromName = null)
    {
        $rawAddress = $fromAddress ?: ($_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@example.com');
        $rawName = $fromName ?: ($_ENV['MAIL_FROM_NAME'] ?? 'Attendly');
        $this->fromAddress = $this->sanitizeAddress($rawAddress);
        $this->fromName = $this->sanitizeHeader($rawName);
        $transport = strtolower((string)($_ENV['MAIL_TRANSPORT'] ?? 'log'));
        $this->transport = in_array($transport, ['log', 'mail', 'sendmail', 'smtp'], true) ? $transport : 'log';
        $storage = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($storage)) {
            if (!mkdir($storage, 0775, true) && !is_dir($storage)) {
                throw new RuntimeException('メールログ用の storage ディレクトリを作成できませんでした。');
            }
        }
        $this->logPath = $storage . '/mail.log';
        $logMaxKb = (int)($_ENV['MAIL_LOG_MAX_KB'] ?? 512);
        $this->logMaxBytes = max(64 * 1024, $logMaxKb * 1024); // 下限64KB
        $this->host = trim((string)($_ENV['MAIL_HOST'] ?? 'localhost')) ?: 'localhost';
        $this->port = max(1, (int)($_ENV['MAIL_PORT'] ?? 25));
        $this->username = $_ENV['MAIL_USERNAME'] ?? null;
        $this->password = $_ENV['MAIL_PASSWORD'] ?? null;
        $encryption = strtolower((string)($_ENV['MAIL_ENCRYPTION'] ?? ''));
        $this->encryption = in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null;
        $this->timeout = max(5, (int)($_ENV['MAIL_TIMEOUT'] ?? 15));
        $this->sendmailPath = trim((string)($_ENV['MAIL_SENDMAIL_PATH'] ?? '/usr/sbin/sendmail -t -i'));
        $rawAuthType = strtolower((string)($_ENV['MAIL_AUTH_TYPE'] ?? ''));
        $allowedAuth = ['login', 'plain', 'cram-md5'];
        $this->authType = in_array($rawAuthType, $allowedAuth, true) ? $rawAuthType : null;
    }

    /**
     * @param array<string,string> $headers
     */
    public function send(string $to, string $subject, string $body, array $headers = []): void
    {
        $sanitizedTo = $this->sanitizeAddress($to);
        $subject = $this->sanitizeHeader($subject);
        $headers = array_merge([
            'From' => $this->formatFrom(),
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], $headers);

        $this->logMessage($sanitizedTo, $subject, $headers);

        if ($this->transport === 'log') {
            $this->logWarning('MAIL_TRANSPORT=log のため実送信をスキップしました。');
            return;
        }

        try {
            $this->sendViaMailer($sanitizedTo, $subject, $body, $headers);
        } catch (\Throwable $e) {
            $this->logWarning("メール送信に失敗したためログのみに記録: {$e->getMessage()}");
        }
    }

    private function sanitizeAddress(string $address): string
    {
        $address = trim($address);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('無効なメールアドレスです。');
        }
        return $address;
    }

    private function sanitizeHeader(string $value): string
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new RuntimeException('ヘッダーに改行を含めることはできません。');
        }
        return $value;
    }

    private function formatFrom(): string
    {
        $name = $this->fromName;
        if ($name === '') {
            return $this->fromAddress;
        }
        return sprintf('%s <%s>', $name, $this->fromAddress);
    }

    /**
     * @param array<string,string> $headers
     */
    private function sendViaMailer(string $to, string $subject, string $body, array $headers): void
    {
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'UTF-8';
        $mailer->Encoding = 'base64';
        $mailer->isHTML(false);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->setFrom($this->fromAddress, $this->fromName);
        $mailer->addAddress($to);
        $mailer->Timeout = $this->timeout;

        if ($this->transport === 'smtp') {
            $mailer->isSMTP();
            $mailer->Host = (string)$this->host;
            $mailer->Port = $this->port > 0 ? $this->port : 25;
            $mailer->SMTPAuth = $this->username !== null && $this->username !== '';
            if ($mailer->SMTPAuth) {
                $mailer->Username = (string)$this->username;
                $mailer->Password = (string)$this->password;
                // OAuth 認証は利用せず、許可された機構のみ選択
                if ($this->authType !== null) {
                    $mailer->AuthType = strtoupper($this->authType);
                } else {
                    $mailer->AuthType = 'LOGIN';
                }
            }
            if ($this->encryption !== null) {
                $mailer->SMTPSecure = $this->encryption === 'ssl'
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($mailer->Port === 465) {
                // smtps:// の既定ポートでは暗黙 TLS を強制
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
        } elseif ($this->transport === 'sendmail') {
            $mailer->isSendmail();
            $mailer->Sendmail = $this->sendmailPath;
        } else {
            $mailer->isMail();
        }

        foreach ($headers as $name => $value) {
            $normalized = strtolower($name);
            if ($normalized === 'from') {
                continue; // setFrom で上書き済み
            }
            if ($normalized === 'reply-to') {
                $mailer->addReplyTo($this->sanitizeAddress($value));
                continue;
            }
            if ($normalized === 'cc') {
                $mailer->addCC($this->sanitizeAddress($value));
                continue;
            }
            if ($normalized === 'bcc') {
                $mailer->addBCC($this->sanitizeAddress($value));
                continue;
            }
            $mailer->addCustomHeader($this->sanitizeHeader($name), $this->sanitizeHeader($value));
        }

        try {
            $mailer->send();
        } catch (MailException $e) {
            throw new RuntimeException('PHPMailer 送信エラー: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->scrubSensitiveMailerState($mailer);
        }
    }

    /** @param array<string,string> $headers */
    private function logMessage(string $to, string $subject, array $headers): void
    {
        $this->rotateLogIfNeeded();
        $toHash = substr(hash('sha256', $to), 0, 12);
        $subjectHash = substr(hash('sha256', $subject), 0, 12);
        $lines = [
            '---- MAIL ----',
            'Date: ' . gmdate(DATE_ATOM),
            'To(hash): ' . $toHash,
            'Subject(hash): ' . $subjectHash,
            'Headers: ' . json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Body: (omitted)',
            '--------------',
            '',
        ];
        $result = file_put_contents($this->logPath, implode("\n", $lines), FILE_APPEND | LOCK_EX);
        if ($result === false) {
            throw new RuntimeException('メールログの書き込みに失敗しました。');
        }
    }

    private function logWarning(string $message): void
    {
        $line = sprintf("[%s] WARN %s\n", gmdate(DATE_ATOM), $message);
        $result = file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            throw new RuntimeException('メールログの書き込みに失敗しました。');
        }
    }

    private function rotateLogIfNeeded(): void
    {
        if (!is_file($this->logPath)) {
            return;
        }
        $size = filesize($this->logPath);
        if ($size === false || $size <= $this->logMaxBytes) {
            return;
        }
        $backup = $this->logPath . '.bak';
        @rename($this->logPath, $backup);
    }

    private function scrubSensitiveMailerState(PHPMailer $mailer): void
    {
        // 認証情報がダンプや例外で漏れないよう、送信後に破棄する
        if (property_exists($mailer, 'Password')) {
            $mailer->Password = null;
        }
        if (property_exists($mailer, 'AuthType')) {
            $mailer->AuthType = '';
        }
    }
}
