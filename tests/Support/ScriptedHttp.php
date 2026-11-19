<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Support;

use IndianOpinion\AudioToPost\Http\HttpClient;
use IndianOpinion\AudioToPost\Http\HttpResponse;
use IndianOpinion\AudioToPost\Http\TransportError;

/**
 * An HttpClient that answers from a queue per URL. Entries are
 * HttpResponse objects, TransportError instances, or callables.
 */
final class ScriptedHttp implements HttpClient
{
    /** @var array<string, list<HttpResponse|TransportError|callable>> */
    private array $queue = [];
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, options: array<string, mixed>}> */
    public array $requests = [];

    public function on(string $methodAndUrl, HttpResponse|TransportError|callable ...$responses): self
    {
        foreach ($responses as $r) {
            $this->queue[$methodAndUrl][] = $r;
        }

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
        $key = $method . ' ' . $url;
        if (!isset($this->queue[$key]) || $this->queue[$key] === []) {
            return new HttpResponse(404, [], '');
        }
        $r = count($this->queue[$key]) > 1 ? array_shift($this->queue[$key]) : $this->queue[$key][0];
        if (is_callable($r)) {
            $r = $r($method, $url, $headers, $body, $options);
        }
        if ($r instanceof TransportError) {
            throw $r;
        }
        if (isset($options['stream_to'])) {
            $data = $r->body;
            if (isset($options['limit_bytes'])) {
                $data = substr($data, 0, (int) $options['limit_bytes']);
            }
            file_put_contents((string) $options['stream_to'], $data);

            return new HttpResponse($r->status, $r->headers, '', (string) $options['stream_to']);
        }

        return $r;
    }
}
