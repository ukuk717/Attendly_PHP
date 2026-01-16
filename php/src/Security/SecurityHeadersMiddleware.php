<?php

declare(strict_types=1);

namespace Attendly\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        // Only append CSP if not already set to avoid overriding later customization.
        if (!$response->hasHeader('Content-Security-Policy')) {
            $csp = [
                "default-src 'self'",
                "img-src 'self' data:",
                "style-src 'self' 'unsafe-inline'",
            ];
            $recaptchaEnabled = filter_var($_ENV['RECAPTCHA_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
            if ($recaptchaEnabled) {
                $csp[] = "script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/";
                $csp[] = "frame-src https://www.google.com/recaptcha/";
            }
            $headers['Content-Security-Policy'] = implode('; ', $csp) . ';';
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
