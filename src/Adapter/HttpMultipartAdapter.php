<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Adapter;

use IndianOpinion\AudioToPost\Http\HttpClient;
use IndianOpinion\AudioToPost\Http\TransportError;
use IndianOpinion\AudioToPost\ProviderException;
use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Word;

/**
 * Generic adapter for the common synchronous "audio transcriptions"
 * request shape (id `http-multipart`): a multipart/form-data POST with
 * `file` and `model`, a bearer key, and a JSON reply holding `text` and,
 * when present, `segments` and `words` with times in seconds.
 *
 * Response keys are configurable as dotted paths, so a service that uses
 * the same request shape with other key names still works. The body is
 * built here and sent through the WordPress HTTP API; no SDK.
 */
final class HttpMultipartAdapter implements TranscriptionAdapter
{
    public const ID = 'http-multipart';

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config endpoint, model, key, max_bytes, max_seconds, response_format,
     *   extra_fields (list of [name, value]), diarisation, timeout, path_* and field_* keys
     */
    public function __construct(private readonly HttpClient $http, array $config)
    {
        $this->config = $config + self::defaults();
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'endpoint' => '',
            'model' => '',
            'key' => '',
            'max_bytes' => 25 * 1000 * 1000,
            'max_seconds' => 1200,
            'response_format' => 'verbose_json',
            'extra_fields' => [],
            'diarisation' => false,
            'timeout' => 300,
            'billing_rounding' => AdapterCapabilities::ROUND_NONE,
            'minimum_billable_seconds' => 0,
            'path_text' => 'text',
            'path_segments' => 'segments',
            'path_words' => 'words',
            'path_language' => 'language',
            'path_duration' => 'duration',
            'field_start' => 'start',
            'field_end' => 'end',
            'field_text' => 'text',
            'field_speaker' => 'speaker',
            'field_word' => 'word',
        ];
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Generic multipart transcription endpoint';
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            max(1, $this->int('max_bytes')),
            max(1, $this->int('max_seconds')),
            ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'webm'],
            diarisation: (bool) $this->config['diarisation'],
            wordTimestamps: $this->str('path_words') !== '',
            segmentTimestamps: $this->str('path_segments') !== '',
            languageHint: true,
            billingRounding: $this->str('billing_rounding'),
            minimumBillableSeconds: $this->int('minimum_billable_seconds'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->configurationProblems() === [];
    }

    public function configurationProblems(): array
    {
        $p = [];
        $endpoint = $this->str('endpoint');
        if ($endpoint === '') {
            $p[] = 'Set the endpoint URL.';
        } elseif (!preg_match('#^https://#i', $endpoint) && !preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#i', $endpoint)) {
            $p[] = 'The endpoint must use https (plain http is allowed for localhost only).';
        }
        if ($this->str('model') === '') {
            $p[] = 'Set the model name.';
        }
        if ($this->str('key') === '') {
            $p[] = 'Set the API key (constant AUDIOPOST_ADAPTER_KEY_HTTP_MULTIPART or the settings screen).';
        }

        return $p;
    }

    public function transcribe(ChunkRequest $request): ChunkResult
    {
        if (!$this->isConfigured()) {
            throw new ProviderException(ProviderException::AUTH, 'The transcription adapter is not configured.');
        }
        $audio = file_get_contents($request->path);
        if ($audio === false) {
            throw new ProviderException(ProviderException::REJECTED_AUDIO, 'The chunk file cannot be read.');
        }
        $boundary = '----audiopost' . bin2hex(random_bytes(12));
        $fields = [['model', $this->str('model')]];
        if ($this->str('response_format') !== '') {
            $fields[] = ['response_format', $this->str('response_format')];
        }
        if ($request->language !== null && $request->language !== '' && $request->language !== 'auto') {
            $fields[] = ['language', $request->language];
        }
        foreach (is_array($this->config['extra_fields']) ? $this->config['extra_fields'] : [] as $f) {
            if (is_array($f) && isset($f[0], $f[1]) && is_string($f[0]) && is_string($f[1]) && $f[0] !== '') {
                $fields[] = [$f[0], $f[1]];
            }
        }
        $body = self::multipart($boundary, $fields, 'chunk-' . $request->index . '.' . $request->format, self::mime($request->format), $audio);
        unset($audio);
        try {
            $res = $this->http->request('POST', $this->str('endpoint'), [
                'Authorization' => 'Bearer ' . $this->str('key'),
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'Accept' => 'application/json',
            ], $body, ['timeout' => $this->int('timeout')]);
        } catch (TransportError $e) {
            throw new ProviderException(ProviderException::TRANSIENT, $this->redact('Connection error: ' . $e->getMessage()), true);
        }
        if ($res->status < 200 || $res->status >= 300) {
            throw $this->error($res->status, $res->body, $res->retryAfter(time()));
        }
        $data = json_decode($res->body, true);
        if (!is_array($data)) {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'The provider reply is not JSON.', true, $res->status);
        }

        return $this->parse($data, $request, $res->status);
    }

    public function submit(ChunkRequest $request): string
    {
        throw new ProviderException(ProviderException::BAD_RESPONSE, 'This adapter is synchronous.');
    }

    public function poll(string $jobId): ?ChunkResult
    {
        throw new ProviderException(ProviderException::BAD_RESPONSE, 'This adapter is synchronous.');
    }

    /**
     * @param array<mixed> $data
     */
    public function parse(array $data, ChunkRequest $request, int $status = 200): ChunkResult
    {
        $text = self::dig($data, $this->str('path_text'));
        $text = is_string($text) ? $text : '';
        $lang = self::dig($data, $this->str('path_language'));
        $lang = is_string($lang) && $lang !== '' ? $lang : null;
        $duration = self::dig($data, $this->str('path_duration'));
        $billed = is_numeric($duration) ? (int) ceil((float) $duration) : null;
        $segments = [];
        $rawSegs = $this->str('path_segments') !== '' ? self::dig($data, $this->str('path_segments')) : null;
        $rawWords = $this->str('path_words') !== '' ? self::dig($data, $this->str('path_words')) : null;
        $words = is_array($rawWords) ? $this->words($rawWords) : [];
        if (is_array($rawSegs) && $rawSegs !== []) {
            foreach ($rawSegs as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $start = self::ms($s[$this->str('field_start')] ?? null);
                $end = self::ms($s[$this->str('field_end')] ?? null);
                if ($start === null || $end === null) {
                    continue;
                }
                $segWords = is_array($s['words'] ?? null) ? $this->words($s['words']) : array_values(array_filter(
                    $words,
                    static fn (Word $w): bool => $w->startMs >= $start && $w->startMs < $end,
                ));
                $speaker = $s[$this->str('field_speaker')] ?? '';
                $segments[] = new Segment(
                    '',
                    $start,
                    max($start, $end),
                    is_scalar($speaker) ? (string) $speaker : '',
                    trim(is_string($s[$this->str('field_text')] ?? null) ? $s[$this->str('field_text')] : ''),
                    $segWords,
                    null,
                    $lang,
                    $request->index,
                );
            }

            return new ChunkResult($segments, $text, $lang, $billed, $status, true);
        }
        if ($words !== []) {
            $segments[] = new Segment('', $words[0]->startMs, $words[count($words) - 1]->endMs, '', trim($text), $words, null, $lang, $request->index);

            return new ChunkResult($segments, $text, $lang, $billed, $status, true);
        }

        return new ChunkResult([], $text, $lang, $billed, $status, false);
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<Word>
     */
    private function words(array $raw): array
    {
        $out = [];
        foreach ($raw as $w) {
            if (!is_array($w)) {
                continue;
            }
            $s = self::ms($w[$this->str('field_start')] ?? null);
            $e = self::ms($w[$this->str('field_end')] ?? null);
            $t = $w[$this->str('field_word')] ?? ($w['text'] ?? null);
            if ($s === null || $e === null || !is_string($t)) {
                continue;
            }
            $out[] = new Word($s, max($s, $e), trim($t));
        }

        return $out;
    }

    private function error(int $status, string $body, ?int $retryAfter): ProviderException
    {
        $msg = 'HTTP ' . $status;
        $data = json_decode($body, true);
        $detail = '';
        if (is_array($data)) {
            $err = $data['error'] ?? null;
            if (is_array($err)) {
                $detail = trim((is_string($err['message'] ?? null) ? $err['message'] : '') . ' ' . (is_string($err['code'] ?? null) ? $err['code'] : '') . ' ' . (is_string($err['type'] ?? null) ? $err['type'] : ''));
            } elseif (is_string($err)) {
                $detail = $err;
            } elseif (is_string($data['message'] ?? null)) {
                $detail = $data['message'];
            }
        } else {
            $detail = substr(trim(strip_tags($body)), 0, 200);
        }
        $msg = $this->redact(trim($msg . ': ' . $detail, ': '));
        $lower = strtolower($detail);
        if (preg_match('/insufficient[_ ]quota|insufficient[_ ]credit|quota[_ ]exceeded|billing|balance/', $lower) === 1) {
            return new ProviderException(ProviderException::ACCOUNT, $msg, false, $status);
        }
        if (preg_match('/too large|maximum content size|file size|payload/', $lower) === 1 && $status !== 429) {
            return new ProviderException(ProviderException::TOO_LARGE, $msg, false, $status);
        }
        if (preg_match('/content[_ ]policy|refus|moderation|safety/', $lower) === 1) {
            return new ProviderException(ProviderException::REFUSED, $msg, false, $status);
        }

        return ProviderException::fromStatus($status, $msg, $retryAfter);
    }

    private function redact(string $message): string
    {
        $key = $this->str('key');

        return $key === '' ? $message : str_replace($key, '[redacted]', $message);
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     */
    public static function multipart(string $boundary, array $fields, string $fileName, string $mime, string $content): string
    {
        $eol = "\r\n";
        $body = '';
        foreach ($fields as [$name, $value]) {
            $body .= '--' . $boundary . $eol
                . 'Content-Disposition: form-data; name="' . self::quote($name) . '"' . $eol . $eol
                . $value . $eol;
        }
        $body .= '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="file"; filename="' . self::quote($fileName) . '"' . $eol
            . 'Content-Type: ' . $mime . $eol . $eol
            . $content . $eol
            . '--' . $boundary . '--' . $eol;

        return $body;
    }

    private static function quote(string $s): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '', ''], $s);
    }

    public static function mime(string $format): string
    {
        return match ($format) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'webm' => 'audio/webm',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<mixed> $data
     */
    public static function dig(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        $cur = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    private static function ms(mixed $seconds): ?int
    {
        return is_numeric($seconds) ? (int) round((float) $seconds * 1000) : null;
    }

    private function str(string $k): string
    {
        $v = $this->config[$k] ?? '';

        return is_scalar($v) ? (string) $v : '';
    }

    private function int(string $k): int
    {
        $v = $this->config[$k] ?? 0;

        return is_numeric($v) ? (int) $v : 0;
    }
}
