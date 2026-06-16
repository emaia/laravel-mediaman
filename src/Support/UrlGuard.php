<?php

namespace Emaia\MediaMan\Support;

use Emaia\MediaMan\Exceptions\UrlNotAllowed;

class UrlGuard
{
    private const array ALLOWED_SCHEMES = ['http', 'https'];

    private const array BLOCKED_HOSTS = ['localhost', 'localhost.'];

    private const array PRIVATE_IPV4_RANGES = [
        ['0.0.0.0', '0.255.255.255'],
        ['10.0.0.0', '10.255.255.255'],
        ['127.0.0.0', '127.255.255.255'],
        ['169.254.0.0', '169.254.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
    ];

    private const array BLOCKED_IPV4_SINGLE = [
        '255.255.255.255',
    ];

    public static function check(string $url): void
    {
        self::parseAndValidate($url);
    }

    /**
     * Validate a URL and return the resolved IP addresses.
     *
     * Use this instead of check() when you need to pin the resolved
     * IPs in a subsequent HTTP request to prevent DNS rebinding.
     *
     * @return array{host: string, port: int, ips: string[]}
     */
    public static function resolve(string $url): array
    {
        $parts = self::parseAndValidate($url);

        $host = strtolower($parts['host']);
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : (strtolower($parts['scheme']) === 'https' ? 443 : 80);

        $ips = [];

        // allow_private_hosts=true means the user has opted out of SSRF
        // protection; returning an empty ips array skips CURLOPT_RESOLVE
        // pinning downstream and lets curl resolve freely.
        if (! config('mediaman.url_sources.allow_private_hosts', false)) {
            $ips = self::resolveHost($host);
        }

        return [
            'host' => $host,
            'port' => $port,
            'ips' => $ips,
        ];
    }

    /**
     * @return array{scheme?: string, host: string, port?: int}
     */
    private static function parseAndValidate(string $url): array
    {
        $parts = @parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw UrlNotAllowed::noHost();
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;

        if ($scheme === null || ! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw UrlNotAllowed::forScheme($scheme ?? 'null');
        }

        $host = strtolower($parts['host']);

        if ($host === '') {
            throw UrlNotAllowed::noHost();
        }

        self::checkHost($host);

        return $parts;
    }

    private static function checkHost(string $host): void
    {
        if (in_array($host, self::BLOCKED_HOSTS, true) || str_ends_with($host, '.localhost')) {
            if (! config('mediaman.url_sources.allow_private_hosts', false)) {
                throw UrlNotAllowed::forHost($host);
            }

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            self::checkIPv4($host);

            return;
        }

        $ipv6Host = str_starts_with($host, '[') ? trim($host, '[]') : $host;

        if (filter_var($ipv6Host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            self::checkIPv6($ipv6Host);

            return;
        }

        if (config('mediaman.url_sources.allow_private_hosts', false)) {
            return;
        }

        $resolvedIps = self::resolveHost($host);

        foreach ($resolvedIps as $ip) {
            self::checkResolvedIp($ip);
        }
    }

    private static function checkIPv4(string $ip): void
    {
        if (config('mediaman.url_sources.allow_private_hosts', false)) {
            return;
        }

        if (in_array($ip, self::BLOCKED_IPV4_SINGLE, true)) {
            throw UrlNotAllowed::forPrivateIp($ip);
        }

        $ipLong = ip2long($ip);

        foreach (self::PRIVATE_IPV4_RANGES as $range) {
            $start = ip2long($range[0]);
            $end = ip2long($range[1]);

            if ($ipLong >= $start && $ipLong <= $end) {
                throw UrlNotAllowed::forPrivateIp($ip);
            }
        }
    }

    private static function checkIPv6(string $ip): void
    {
        if (config('mediaman.url_sources.allow_private_hosts', false)) {
            return;
        }

        $binary = inet_pton($ip);

        if ($binary === false) {
            return;
        }

        // ::/128 (unspecified)
        if ($binary === inet_pton('::')) {
            throw UrlNotAllowed::forPrivateIp($ip);
        }

        // ::1/128 (loopback)
        if ($binary === inet_pton('::1')) {
            throw UrlNotAllowed::forPrivateIp($ip);
        }

        // ::ffff:x.x.x.x/96 (IPv4-mapped)
        if (self::isIPv4Mapped($binary)) {
            $ipv4 = long2ip(unpack('N', substr($binary, 12, 4))[1]);

            self::checkIPv4($ipv4);

            return;
        }

        $firstByte = ord($binary[0]);

        // fc00::/7 (Unique Local Addresses)
        if (($firstByte & 0xFE) === 0xFC) {
            throw UrlNotAllowed::forPrivateIp($ip);
        }

        // fe80::/10 (link-local)
        $secondByte = ord($binary[1]);

        if ($firstByte === 0xFE && ($secondByte & 0xC0) === 0x80) {
            throw UrlNotAllowed::forPrivateIp($ip);
        }

        // 2002::/16 (6to4 tunnel)
        if ($firstByte === 0x20 && $secondByte === 0x02) {
            throw UrlNotAllowed::forPrivateIp($ip);
        }

        // 2001::/32 (Teredo tunnel)
        if ($firstByte === 0x20 && $secondByte === 0x01 && ord($binary[2]) === 0x00 && ord($binary[3]) === 0x00) {
            throw UrlNotAllowed::forPrivateIp($ip);
        }
    }

    private static function isIPv4Mapped(string $binary): bool
    {
        return strlen($binary) === 16
            && ord($binary[10]) === 0xFF
            && ord($binary[11]) === 0xFF
            && substr($binary, 0, 10) === str_repeat("\x00", 10);
    }

    /**
     * @return string[]
     */
    private static function resolveHost(string $host): array
    {
        $ips = [];

        $ipv4List = @gethostbynamel($host);

        if ($ipv4List !== false) {
            foreach ($ipv4List as $ip) {
                $ips[] = $ip;
            }
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);

        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    private static function checkResolvedIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            self::checkIPv4($ip);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            self::checkIPv6($ip);
        }
    }
}
