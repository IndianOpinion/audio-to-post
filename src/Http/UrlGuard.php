<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Http;

use IndianOpinion\AudioToPost\SourceException;

/**
 * Checks editor-supplied URLs before every request and every redirect
 * hop (cases 8 and 9). Loopback, private, link-local, shared and reserved
 * addresses are refused after DNS resolution. The WordPress safe-request
 * API repeats its own check on the actual request.
 */
final class UrlGuard
{
    /** @var \Closure(string): list<string> */
    private \Closure $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver host to IP list
     * @param list<string>                          $allowedHosts optional allow-list
     */
    public function __construct(?callable $resolver = null, private readonly array $allowedHosts = [])
    {
        $this->resolver = $resolver === null ? \Closure::fromCallable([self::class, 'resolve']) : \Closure::fromCallable($resolver);
    }

    /**
     * Returns the public addresses the host resolved to, so the request
     * can be pinned to them (no second lookup that could answer
     * differently).
     *
     * @return list<string>
     *
     * @throws SourceException unsafe_url, source_unavailable
     */
    public function check(string $url, ?string $from = null): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new SourceException('unsafe_url', 'The link is not a valid http or https URL.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SourceException('unsafe_url', 'Only http and https links are accepted.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SourceException('unsafe_url', 'Links with a user name or password are not accepted.');
        }
        if ($from !== null && strtolower((string) parse_url($from, PHP_URL_SCHEME)) === 'https' && $scheme === 'http') {
            throw new SourceException('unsafe_url', 'A redirect from https to http was refused.');
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443, 8080], true)) {
            throw new SourceException('unsafe_url', 'Only the standard web ports are allowed.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($this->allowedHosts !== []) {
            $ok = false;
            foreach ($this->allowedHosts as $allowed) {
                $allowed = strtolower(trim($allowed));
                if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw new SourceException('unsafe_url', 'This host is not on the allow-list.');
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            if ($host === 'localhost' || str_ends_with($host, '.localhost') || !str_contains($host, '.')) {
                throw new SourceException('unsafe_url', 'Local host names are not allowed.');
            }
            $ips = ($this->resolver)($host);
            if ($ips === []) {
                throw new SourceException('source_unavailable', 'The host name could not be resolved.');
            }
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new SourceException('unsafe_url', 'The link points at a private or reserved address.');
            }
        }

        return array_values($ips);
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible addresses.
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) {
                $v4 = inet_ntop(substr($bin, 12));

                return $v4 !== false && self::isPublic($v4);
            }
            $first = ord($bin[0]);
            if (($first & 0xFE) === 0xFC || ($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) || $first === 0xFF || $bin === str_repeat("\0", 15) . "\1") {
                return false;
            }
            // 64:ff9b::/96 NAT64 and 2001:db8::/32 documentation.
            if (str_starts_with($bin, "\x00\x64\xFF\x9B") || str_starts_with($bin, "\x20\x01\x0D\xB8")) {
                return false;
            }

            return true;
        }
        $o = array_map('intval', explode('.', $ip));

        return !(
            $o[0] === 0 || $o[0] === 10 || $o[0] === 127 || $o[0] >= 224
            || ($o[0] === 100 && $o[1] >= 64 && $o[1] <= 127)
            || ($o[0] === 169 && $o[1] === 254)
            || ($o[0] === 172 && $o[1] >= 16 && $o[1] <= 31)
            || ($o[0] === 192 && $o[1] === 168)
            || ($o[0] === 192 && $o[1] === 0 && ($o[2] === 0 || $o[2] === 2))
            || ($o[0] === 198 && ($o[1] === 18 || $o[1] === 19))
            || ($o[0] === 198 && $o[1] === 51 && $o[2] === 100)
            || ($o[0] === 203 && $o[1] === 0 && $o[2] === 113)
        );
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        $ips = gethostbynamel($host);
        $ips = $ips === false ? [] : $ips;
        if (function_exists('dns_get_record')) {
            $v6 = dns_get_record($host, DNS_AAAA);
            foreach (is_array($v6) ? $v6 : [] as $r) {
                if (is_string($r['ipv6'] ?? null)) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
