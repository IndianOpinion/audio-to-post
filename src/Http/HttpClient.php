<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Http;

/**
 * The plugin's one door to the network. Production uses the WordPress
 * HTTP API; tests use a scripted client. No other code makes requests.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array{
     *   timeout?: int|float,
     *   safe?: bool,
     *   stream_to?: string,
     *   limit_bytes?: int,
     *   pin?: array{host: string, port: int, ips: list<string>},
     * } $options `safe` uses the safe-request API (reject unsafe URLs);
     *   `stream_to` writes the body to that file; `limit_bytes` stops
     *   reading after that many bytes; `pin` connects to the addresses the
     *   guard checked instead of resolving the host again. Redirects are
     *   never followed here.
     *
     * @throws TransportError on a connection error or timeout
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse;
}
