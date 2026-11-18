<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Http;

/**
 * A plain HTTP response. Header names are lower case.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $file = null,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Seconds from a Retry-After header (delta seconds or an HTTP date).
     */
    public function retryAfter(int $now): ?int
    {
        $v = $this->header('retry-after');
        if ($v === null || trim($v) === '') {
            return null;
        }
        if (ctype_digit(trim($v))) {
            return (int) trim($v);
        }
        $t = strtotime($v);

        return $t === false ? null : max(0, $t - $now);
    }
}
