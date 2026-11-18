<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Http;

use IndianOpinion\AudioToPost\SourceException;

/**
 * Fetches editor-supplied URLs (cases 8 to 13).
 *
 * Every hop is checked by the UrlGuard and sent through the safe-request
 * API; at most five redirects are followed; https to http is refused.
 * Bodies stream to disk and stop at the size limit. Errors are typed:
 * `source_unavailable` (401, 403, 404, 410: never retried), `fetch_failed`
 * (timeouts, resets, other statuses: retried by the runner), `too_large`.
 */
final class Downloader
{
    public const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly HttpClient $http,
        private readonly UrlGuard $guard,
        private readonly int $timeout = 300,
    ) {
    }

    /**
     * A HEAD request for the cheap checks (size, type, ETag). Returns
     * null when the server does not answer HEAD usefully.
     */
    public function head(string $url): ?FetchResult
    {
        try {
            [$res, $final] = $this->follow('HEAD', $url, [], []);
        } catch (SourceException $e) {
            if ($e->errorCode() === 'unsafe_url') {
                throw $e;
            }

            return null;
        }
        if ($res->status < 200 || $res->status >= 300) {
            return null;
        }

        return self::result($res, $final, 0);
    }

    /**
     * Stream a URL to $dest. With $resumeFrom > 0 and a server that
     * accepts ranges, the missing tail is appended.
     *
     * @throws SourceException
     */
    public function download(string $url, string $dest, int $maxBytes, int $resumeFrom = 0): FetchResult
    {
        $part = $dest . '.part';
        $headers = $resumeFrom > 0 ? ['Range' => 'bytes=' . $resumeFrom . '-'] : [];
        try {
            [$res, $final] = $this->follow('GET', $url, $headers, ['stream_to' => $part, 'limit_bytes' => $maxBytes + 1, 'timeout' => $this->timeout]);
        } catch (SourceException $e) {
            self::remove($part);
            throw $e;
        }
        $len = $res->header('content-length');
        if ($res->status === 200 && $len !== null && ctype_digit($len) && (int) $len > $maxBytes) {
            self::remove($part);
            throw new SourceException('too_large', 'The file is larger than the size limit.');
        }
        if ($resumeFrom > 0 && $res->status === 206 && is_file($dest)) {
            $out = fopen($dest, 'ab');
            $in = fopen($part, 'rb');
            if ($out === false || $in === false) {
                throw new SourceException('storage_unwritable', 'Cannot append to the partial download.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            self::remove($part);
        } else {
            self::remove($dest);
            if (!rename($part, $dest)) {
                self::remove($part);
                throw new SourceException('storage_unwritable', 'Cannot move the download into place.');
            }
        }
        clearstatcache(true, $dest);
        $bytes = (int) filesize($dest);
        if ($bytes > $maxBytes) {
            self::remove($dest);
            throw new SourceException('too_large', 'The download passed the size limit and was stopped.');
        }

        return self::result($res, $final, $bytes);
    }

    /**
     * Fetch a small text resource (a feed or a transcript) into memory.
     *
     * @throws SourceException
     */
    public function text(string $url, int $maxBytes): FetchResult
    {
        [$res, $final] = $this->follow('GET', $url, [], ['limit_bytes' => $maxBytes + 1, 'timeout' => 60]);
        if (strlen($res->body) > $maxBytes) {
            throw new SourceException('too_large', 'The response is larger than the limit.');
        }

        return self::result($res, $final, strlen($res->body), $res->body);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     *
     * @return array{0: HttpResponse, 1: string}
     *
     * @throws SourceException
     */
    private function follow(string $method, string $url, array $headers, array $options): array
    {
        $current = $url;
        $previous = null;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->guard->check($current, $previous);
            try {
                /** @var array{timeout?: int|float, safe?: bool, stream_to?: string, limit_bytes?: int} $opts */
                $opts = ['safe' => true] + $options;
                $res = $this->http->request($method, $current, $headers, null, $opts);
            } catch (TransportError $e) {
                throw new SourceException('fetch_failed', ($e->timeout ? 'Timed out: ' : 'Connection error: ') . $e->getMessage(), $e);
            }
            if ($res->status >= 300 && $res->status < 400 && $res->header('location') !== null) {
                $previous = $current;
                $current = self::absolute((string) $res->header('location'), $current);
                continue;
            }
            if (in_array($res->status, [401, 403, 404, 410], true)) {
                throw new SourceException('source_unavailable', 'The server answered HTTP ' . $res->status . '.');
            }
            if ($res->status < 200 || $res->status >= 300) {
                throw new SourceException('fetch_failed', 'The server answered HTTP ' . $res->status . '.');
            }

            return [$res, $current];
        }
        throw new SourceException('unsafe_url', 'Too many redirects (more than ' . self::MAX_REDIRECTS . ').');
    }

    private static function result(HttpResponse $res, string $final, int $bytes, string $body = ''): FetchResult
    {
        return new FetchResult(
            $final,
            $res->status,
            $res->header('content-type'),
            $bytes > 0 ? $bytes : (ctype_digit((string) $res->header('content-length')) ? (int) $res->header('content-length') : 0),
            $res->header('etag'),
            $res->header('last-modified'),
            strtolower((string) $res->header('accept-ranges')) === 'bytes',
            $body,
        );
    }

    public static function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $b = parse_url($base);
        if (!is_array($b) || !isset($b['scheme'], $b['host'])) {
            return $location;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $b['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . $dir . $location;
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
