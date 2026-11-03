<?php

namespace App\Services\Email;

/** The mail server host and port come from the user: keep them from pointing at the platform's own network (SSRF). */
class EmailHostGuard
{
    /** @throws EmailException */
    public static function assert(string $host, int $port, string $protocol): void
    {
        $host = strtolower(trim($host));
        if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new EmailException(__('Invalid mail server address.'));
        }

        $allowed = (array) config("email.{$protocol}_ports", []);
        if ($allowed && !in_array($port, $allowed, true)) {
            throw new EmailException(__('Port :port is not allowed. Use one of: :ports.', ['port' => $port, 'ports' => implode(', ', $allowed)]));
        }

        if (config('email.allow_private_hosts')) {
            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            throw new EmailException(__('Could not find the mail server :host.', ['host' => $host]));
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new EmailException(__('This mail server address is not allowed.'));
            }
        }
    }
}
