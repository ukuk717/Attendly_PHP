<?php

declare(strict_types=1);

namespace Attendly\Support;

use Psr\Http\Message\ServerRequestInterface;

final class ClientIpResolver
{
    public static function resolve(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $trusted = filter_var($_ENV['TRUST_PROXY'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $trustedProxies = [];
        if (!empty($_ENV['TRUSTED_PROXIES'])) {
            $trustedProxies = array_filter(array_map('trim', explode(',', (string)$_ENV['TRUSTED_PROXIES'])));
        }

        if ($trusted && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            $remoteAddr = $server['REMOTE_ADDR'] ?? '';
            if ($remoteAddr !== '' && in_array($remoteAddr, $trustedProxies, true)) {
                $forwarded = array_map('trim', explode(',', (string)$server['HTTP_X_FORWARDED_FOR']));
                $candidate = $forwarded[0] ?? '';
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $candidate;
                }
            }
        }

        if (!empty($server['REMOTE_ADDR']) && filter_var((string)$server['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return (string)$server['REMOTE_ADDR'];
        }

        throw new \RuntimeException('Unable to resolve client IP address');
    }
}
