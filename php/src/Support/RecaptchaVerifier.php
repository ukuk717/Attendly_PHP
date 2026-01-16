<?php

declare(strict_types=1);

namespace Attendly\Support;

final class RecaptchaVerifier
{
    private string $secretKey;
    private float $minScore;
    private string $siteVerifyUrl;
    private bool $relaxedMode;

    public function __construct(?string $secretKey = null, ?float $minScore = null)
    {
        $this->secretKey = $secretKey ?? trim((string)($_ENV['RECAPTCHA_SECRET_KEY'] ?? ''));
        $this->minScore = $minScore ?? (float)($_ENV['RECAPTCHA_MIN_SCORE'] ?? 0.5);
        $this->siteVerifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
        $this->relaxedMode = filter_var($_ENV['RECAPTCHA_RELAXED_TEST_KEYS'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{success:bool,score:float,action:string,error_codes:array}
     */
    public function verify(string $token, ?string $remoteIp, string $expectedAction): array
    {
        $token = trim($token);
        if ($token === '' || $this->secretKey === '') {
            return [
                'success' => false,
                'score' => 0.0,
                'action' => '',
                'error_codes' => ['missing-input'],
            ];
        }

        $payload = [
            'secret' => $this->secretKey,
            'response' => $token,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $responseBody = $this->postForm($payload);
        if ($responseBody === null) {
            return [
                'success' => false,
                'score' => 0.0,
                'action' => '',
                'error_codes' => ['verify-failed'],
            ];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'score' => 0.0,
                'action' => '',
                'error_codes' => ['invalid-json'],
            ];
        }

        $success = !empty($decoded['success']);
        $score = isset($decoded['score']) ? (float)$decoded['score'] : 0.0;
        $action = isset($decoded['action']) ? (string)$decoded['action'] : '';
        $errors = isset($decoded['error-codes']) && is_array($decoded['error-codes'])
            ? $decoded['error-codes']
            : [];

        if (!$this->relaxedMode) {
            if ($expectedAction !== '' && $action !== $expectedAction) {
                $success = false;
                $errors[] = 'action-mismatch';
            }
            if ($score < $this->minScore) {
                $success = false;
                $errors[] = 'low-score';
            }
        }

        return [
            'success' => $success,
            'score' => $score,
            'action' => $action,
            'error_codes' => $errors,
        ];
    }

    private function postForm(array $payload): ?string
    {
        $content = http_build_query($payload);

        if (function_exists('curl_init')) {
            $ch = curl_init($this->siteVerifyUrl);
            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $result = curl_exec($ch);
                curl_close($ch);
                if (is_string($result)) {
                    return $result;
                }
            }
        }

        $headers = "Content-Type: application/x-www-form-urlencoded\r\n" .
            "Content-Length: " . strlen($content) . "\r\n";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $content,
                'timeout' => 5,
            ],
        ]);

        $result = @file_get_contents($this->siteVerifyUrl, false, $context);
        if ($result !== false) {
            return $result;
        }
        return null;
    }
}
