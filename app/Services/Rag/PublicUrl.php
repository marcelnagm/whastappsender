<?php

namespace App\Services\Rag;

/** The crawler only talks to public web servers, never to the server's own network (SSRF). */
class PublicUrl
{
    public static function allowed(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        if (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)) {
            return false;
        }
        $ip = filter_var($p['host'], FILTER_VALIDATE_IP) ? $p['host'] : gethostbyname($p['host']);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
