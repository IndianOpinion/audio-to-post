<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Http;

/**
 * HttpClient over the WordPress HTTP API. Redirects are off: the
 * Downloader follows them by hand so every hop is checked (case 9).
 */
final class WordPressHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $options['timeout'] ?? 30,
            'redirection' => 0,
            'reject_unsafe_urls' => (bool) ($options['safe'] ?? false),
            'user-agent' => 'audio-to-post/' . (defined('AUDIOPOST_VERSION') ? (string) constant('AUDIOPOST_VERSION') : '0') . '; ' . home_url('/'),
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        if (isset($options['stream_to'])) {
            $args['stream'] = true;
            $args['filename'] = $options['stream_to'];
        }
        if (isset($options['limit_bytes'])) {
            $args['limit_response_size'] = $options['limit_bytes'];
        }
        $res = ($options['safe'] ?? false) ? wp_safe_remote_request($url, $args) : wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            $msg = $res->get_error_message();
            throw new TransportError($msg, stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false);
        }
        $headers = [];
        $raw = wp_remote_retrieve_headers($res);
        if (is_object($raw) && method_exists($raw, 'getAll')) {
            $raw = $raw->getAll();
        }
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                $headers[strtolower((string) $k)] = is_array($v) ? (string) end($v) : (string) $v;
            }
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($res),
            $headers,
            isset($options['stream_to']) ? '' : (string) wp_remote_retrieve_body($res),
            $options['stream_to'] ?? null,
        );
    }
}
