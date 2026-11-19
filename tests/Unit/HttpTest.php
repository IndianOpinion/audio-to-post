<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Unit;

use IndianOpinion\AudioToPost\Http\Downloader;
use IndianOpinion\AudioToPost\Http\FeedParser;
use IndianOpinion\AudioToPost\Http\HttpResponse;
use IndianOpinion\AudioToPost\Http\TransportError;
use IndianOpinion\AudioToPost\Http\UrlGuard;
use IndianOpinion\AudioToPost\SourceException;
use IndianOpinion\AudioToPost\Tests\Support\AudioFixtures;
use IndianOpinion\AudioToPost\Tests\Support\ScriptedHttp;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    private function guard(array $dns = []): UrlGuard
    {
        return new UrlGuard(static fn (string $h): array => $dns[$h] ?? ['93.184.216.34']);
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (SourceException $e) {
            return $e->errorCode();
        }

        return 'none';
    }

    protected function tearDown(): void
    {
        AudioFixtures::cleanup();
    }

    /** T8: loopback, private, link-local and reserved targets are refused. */
    public function testUnsafeUrlsAreRefused(): void
    {
        $g = $this->guard(['inside.example' => ['10.0.0.1'], 'meta.example' => ['169.254.169.254'], 'ok.example' => ['93.184.216.34'], 'v6.example' => ['::1']]);
        foreach (['http://127.0.0.1/a.mp3', 'http://10.0.0.1/a.mp3', 'http://169.254.169.254/latest', 'http://[::1]/a.mp3', 'https://inside.example/a.mp3', 'https://meta.example/a.mp3', 'https://v6.example/a.mp3', 'ftp://ok.example/a.mp3', 'http://localhost/a.mp3', 'http://[::ffff:127.0.0.1]/a.mp3', 'https://user:pw@ok.example/a.mp3', 'https://ok.example:22/a.mp3', 'http://100.64.0.1/a', 'http://[fd00::1]/a'] as $url) {
            self::assertSame('unsafe_url', $this->code(static fn () => $g->check($url)), $url);
        }
        self::assertSame('none', $this->code(static fn () => $g->check('https://ok.example/a.mp3')));
        $allow = new UrlGuard(static fn (): array => ['93.184.216.34'], ['example.org']);
        self::assertSame('none', $this->code(static fn () => $allow->check('https://cdn.example.org/a.mp3')));
        self::assertSame('unsafe_url', $this->code(static fn () => $allow->check('https://example.com/a.mp3')));
    }

    /** T9: the sixth redirect and https to http are refused. */
    public function testRedirects(): void
    {
        $http = new ScriptedHttp();
        for ($i = 0; $i < 6; $i++) {
            $http->on('GET https://ok.example/' . $i, new HttpResponse(302, ['location' => '/' . ($i + 1)], ''));
        }
        $http->on('GET https://ok.example/6', new HttpResponse(200, [], 'x'));
        $dl = new Downloader($http, $this->guard());
        self::assertSame('unsafe_url', $this->code(static fn () => $dl->text('https://ok.example/0', 100)));
        self::assertSame('none', $this->code(static fn () => $dl->text('https://ok.example/1', 100)), 'five redirects are fine');
        $http->on('GET https://ok.example/down', new HttpResponse(301, ['location' => 'http://ok.example/plain'], ''));
        self::assertSame('unsafe_url', $this->code(static fn () => $dl->text('https://ok.example/down', 100)));
        $http->on('GET https://ok.example/inside', new HttpResponse(302, ['location' => 'http://10.1.2.3/'], ''));
        self::assertSame('unsafe_url', $this->code(static fn () => $dl->text('https://ok.example/inside', 100)));
        foreach ($http->requests as $r) {
            self::assertTrue($r['options']['safe']);
        }
    }

    /** T10: Content-Length over the limit never downloads; a missing header aborts at the limit. */
    public function testSizeLimit(): void
    {
        $http = new ScriptedHttp();
        $http->on('HEAD https://ok.example/big.mp3', new HttpResponse(200, ['content-length' => '2000'], ''));
        $http->on('GET https://ok.example/big.mp3', new HttpResponse(200, [], str_repeat('a', 2000)));
        $dl = new Downloader($http, $this->guard());
        $head = $dl->head('https://ok.example/big.mp3');
        self::assertSame(2000, $head?->bytes);
        $dest = AudioFixtures::dir() . '/dl.bin';
        self::assertSame('too_large', $this->code(static fn () => $dl->download('https://ok.example/big.mp3', $dest, 1000)));
        self::assertFileDoesNotExist($dest);
        self::assertFileDoesNotExist($dest . '.part');
        $get = array_values(array_filter($http->requests, static fn (array $r): bool => $r['method'] === 'GET'));
        self::assertSame(1001, $get[0]['options']['limit_bytes']);
    }

    /** T13: 503 is retryable; 404 is not. */
    public function testDownloadFailures(): void
    {
        $http = new ScriptedHttp();
        $http->on('GET https://ok.example/flaky.mp3', new HttpResponse(503, [], ''), new HttpResponse(200, ['accept-ranges' => 'bytes'], 'data'));
        $http->on('GET https://ok.example/gone.mp3', new HttpResponse(404, [], ''));
        $http->on('GET https://ok.example/slow.mp3', new TransportError('Operation timed out', true));
        $dl = new Downloader($http, $this->guard());
        $dest = AudioFixtures::dir() . '/f.bin';
        self::assertSame('fetch_failed', $this->code(static fn () => $dl->download('https://ok.example/flaky.mp3', $dest, 100)));
        $res = $dl->download('https://ok.example/flaky.mp3', $dest, 100);
        self::assertSame(4, $res->bytes);
        self::assertTrue($res->acceptRanges);
        self::assertSame('source_unavailable', $this->code(static fn () => $dl->download('https://ok.example/gone.mp3', $dest, 100)));
        self::assertSame('fetch_failed', $this->code(static fn () => $dl->download('https://ok.example/slow.mp3', $dest, 100)));
    }

    public function testRangedResumeAppends(): void
    {
        $http = new ScriptedHttp();
        $http->on('GET https://ok.example/r.mp3', static function (string $m, string $u, array $h): HttpResponse {
            return isset($h['Range']) ? new HttpResponse(206, ['content-range' => 'bytes 6-10/11'], 'world') : new HttpResponse(200, [], 'hello world');
        });
        $dl = new Downloader($http, $this->guard());
        $dest = AudioFixtures::dir() . '/r.bin';
        file_put_contents($dest, 'hello ');
        $dl->download('https://ok.example/r.mp3', $dest, 100, 6);
        self::assertSame('hello world', file_get_contents($dest));
    }

    /** A 206 for a range other than the one asked for is never appended. */
    public function testWrongRangeIsNotAppended(): void
    {
        $http = new ScriptedHttp();
        $http->on('GET https://ok.example/r.mp3', new HttpResponse(206, ['content-range' => 'bytes 3-10/11'], 'lo world'));
        $http->on('GET https://ok.example/w.mp3', new HttpResponse(206, ['content-range' => 'bytes 0-10/11'], 'hello world'));
        $dl = new Downloader($http, $this->guard());
        $dest = AudioFixtures::dir() . '/r2.bin';
        file_put_contents($dest, 'hello ');
        self::assertSame('fetch_failed', $this->code(static fn () => $dl->download('https://ok.example/r.mp3', $dest, 100, 6)));
        self::assertSame('hello ', file_get_contents($dest), 'the partial copy is kept, not deleted');
        self::assertFileDoesNotExist($dest . '.part');
        // The whole file sent as a 206 from byte 0 replaces the partial copy.
        $dl->download('https://ok.example/w.mp3', $dest, 100, 6);
        self::assertSame('hello world', file_get_contents($dest));
    }

    /** T8 and T9: every hop is pinned to the addresses its own check resolved. */
    public function testEveryHopIsPinnedToCheckedAddresses(): void
    {
        $http = new ScriptedHttp();
        $http->on('GET https://a.example/x', new HttpResponse(302, ['location' => 'https://b.example:8080/y'], ''));
        $http->on('GET https://b.example:8080/y', new HttpResponse(200, [], 'ok'));
        $http->on('GET https://93.184.216.34/z', new HttpResponse(200, [], 'ok'));
        $dl = new Downloader($http, $this->guard(['a.example' => ['93.184.216.34'], 'b.example' => ['93.184.216.35', '2606:2800:220:1::1']]));
        $dl->text('https://a.example/x', 100);
        self::assertSame(['host' => 'a.example', 'port' => 443, 'ips' => ['93.184.216.34']], $http->requests[0]['options']['pin']);
        self::assertSame(['host' => 'b.example', 'port' => 8080, 'ips' => ['93.184.216.35', '2606:2800:220:1::1']], $http->requests[1]['options']['pin']);
        $dl->text('https://93.184.216.34/z', 100);
        self::assertArrayNotHasKey('pin', $http->requests[2]['options'], 'an address literal needs no pin');
    }

    /** T2: a 100 MB download streams to disk without holding the file in memory. */
    public function testStreamsLargeDownloadsToDisk(): void
    {
        $http = new class () implements \IndianOpinion\AudioToPost\Http\HttpClient {
            public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
            {
                if (isset($options['stream_to'])) {
                    $h = fopen((string) $options['stream_to'], 'wb');
                    $block = str_repeat("\0", 1048576);
                    for ($i = 0; $i < 100; $i++) {
                        fwrite($h, $block);
                    }
                    fclose($h);
                }

                return new HttpResponse(200, ['content-type' => 'audio/mpeg'], '');
            }
        };
        $dl = new Downloader($http, $this->guard());
        $dest = AudioFixtures::dir() . '/big.bin';
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $before = memory_get_usage();
        $res = $dl->download('https://ok.example/big.mp3', $dest, 200 * 1048576);
        self::assertSame(100 * 1048576, $res->bytes);
        self::assertLessThan(32 * 1048576, memory_get_peak_usage() - $before);
        unlink($dest);
    }

    /** T3: a feed with 80 items lists the newest 50 with title, date, duration and size. */
    public function testFeedListsNewestFifty(): void
    {
        $items = '';
        for ($i = 1; $i <= 80; $i++) {
            $items .= '<item><title>Episode ' . $i . '</title><guid>ep-' . $i . '</guid><pubDate>' . gmdate('D, d M Y H:i:s', 1_700_000_000 + $i * 86400) . ' +0000</pubDate>'
                . '<itunes:duration>' . ($i === 80 ? '01:02:03' : '1800') . '</itunes:duration><enclosure url="https://ok.example/' . $i . '.mp3" length="' . (1000 * $i) . '" type="audio/mpeg"/>'
                . ($i === 80 ? '<podcast:transcript url="https://ok.example/80.vtt" type="text/vtt"/>' : '') . '</item>';
        }
        $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:podcast="https://podcastindex.org/namespace/1.0"><channel><title>Show</title>' . $items . '</channel></rss>';
        $eps = FeedParser::episodes($xml);
        self::assertCount(50, $eps);
        self::assertSame('Episode 80', $eps[0]['title']);
        self::assertSame('Episode 31', $eps[49]['title']);
        self::assertSame(3723, $eps[0]['duration']);
        self::assertSame(80_000, $eps[0]['enclosure_size']);
        self::assertSame('vtt', $eps[0]['transcript_type']);
        self::assertNull($eps[1]['transcript_url']);
        self::assertNotSame('', $eps[0]['published']);
        $this->expectException(SourceException::class);
        FeedParser::episodes('<!DOCTYPE x [<!ENTITY a "b">]><rss/>');
    }
}
