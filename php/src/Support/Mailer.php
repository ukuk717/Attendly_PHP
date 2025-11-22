<?php

declare(strict_types=1);

namespace Attendly\Support;

use RuntimeException;

/**
 * シンプルなメール送信ラッパー。
 * - まずファイルにロギング（storage/mail.log）を行い、後続で SMTP 実装に差し替え可能な構造。
 * - 本番で mail() が利用可能な場合は mail() も試行するが、失敗しても例外は投げず警告ログに留める。
 */
final class Mailer
{
    private string $fromAddress;
    private string $fromName;
    private string $logPath;

    public function __construct(?string $fromAddress = null, ?string $fromName = null)
    {
        $rawAddress = $fromAddress ?: ($_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@example.com');
        $rawName = $fromName ?: ($_ENV['MAIL_FROM_NAME'] ?? 'Attendly');
        $this->fromAddress = $this->sanitizeAddress($rawAddress);
        $this->fromName = $this->sanitizeHeader($rawName);
        $storage = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($storage)) {
            if (!mkdir($storage, 0775, true) && !is_dir($storage)) {
                throw new RuntimeException('メールログ用の storage ディレクトリを作成できませんでした。');
            }
        }
        $this->logPath = $storage . '/mail.log';
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

        // mail() は環境依存。失敗しても例外を出さずにログだけ残す。
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$this->sanitizeHeader($v)}";
        }
        $headerString = implode("\r\n", $headerLines);
        $sent = @mail($sanitizedTo, $subject, $body, $headerString);
        if (!$sent) {
            $this->logWarning("mail() 送信に失敗: to={$sanitizedTo}, subject={$subject}");
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

    /** @param array<string,string> $headers */
    private function logMessage(string $to, string $subject, array $headers): void
    {
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
            throw new RuntimeException('メールログへの書き込みに失敗しました。');
        }
    }

    private function logWarning(string $message): void
    {
        $line = sprintf("[%s] WARN %s\n", gmdate(DATE_ATOM), $message);
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
