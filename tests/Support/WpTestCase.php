<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Support;

use IndianOpinion\AudioToPost\Adapter\FakeAdapter;
use IndianOpinion\AudioToPost\Draft\LlmJsonCaller;
use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\WordPress\Debug;
use IndianOpinion\AudioToPost\WordPress\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests against the WordPress stub layer. Every test gets
 * fresh WordPress state, a frozen clock, a private uploads directory, the
 * fake adapter selected and no network.
 */
abstract class WpTestCase extends TestCase
{
    protected Plugin $p;
    protected FakeAdapter $fake;
    protected string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        \WPStub::reset();
        Plugin::reset();
        Debug::reset();
        LlmJsonCaller::$assumeAvailable = null;
        $this->uploads = sys_get_temp_dir() . '/audiopost-uploads-' . bin2hex(random_bytes(4));
        mkdir($this->uploads, 0777, true);
        \WPStub::$uploads = $this->uploads;
        $this->p = Plugin::instance();
        $this->p->clock()->set(1_800_000_000);
        $this->p->setResolver(static fn (string $host): array => ['93.184.216.34']);
        $this->p->runner()->setSleeper(static function (float $s): void {
        });
        $this->fake = new FakeAdapter();
        $this->p->adapters()->register($this->fake);
        update_option(Settings::OPTION, ['adapter' => 'fake', 'default_mode' => 'none']);
        $this->p->settings()->flush();
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->uploads);
        AudioFixtures::cleanup();
        parent::tearDown();
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        $this->p->settings()->save($values);
    }

    /** Register an audio file as a Media Library attachment. */
    protected function attachment(string $path, string $mime = 'audio/mpeg'): int
    {
        return wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => basename($path), 'post_status' => 'inherit'], $path);
    }

    /**
     * Create a run from an attachment and prepare it (fetch, probe, estimate).
     *
     * @param array<string, mixed> $options
     */
    protected function audioRun(string $path, array $options = [], string $mime = 'audio/mpeg'): Run
    {
        $att = $this->attachment($path, $mime);
        $run = $this->p->runs()->create(['kind' => 'attachment', 'attachment_id' => $att], $options + ['rights_confirmed' => true], 1);
        $this->p->runs()->prepare($run->id);

        return $this->runOf($run->id);
    }

    protected function runOf(int $id): Run
    {
        $r = $this->p->repo()->get($id);
        self::assertNotNull($r);

        return $r;
    }

    /** Create, prepare and confirm. */
    protected function confirmedRun(string $path, array $options = []): Run
    {
        $run = $this->audioRun($path, $options);
        self::assertSame(RunState::AWAITING_CONFIRMATION, $run->state, (string) $run->errorMessage);
        $this->p->runs()->confirm($run->id, (string) $run->estimate?->fingerprint, false, 1);

        return $this->runOf($run->id);
    }

    /** Run ticks until nothing changes or the limit is hit. */
    protected function drain(int $ticks = 20): void
    {
        for ($i = 0; $i < $ticks; $i++) {
            $res = $this->p->runner()->tick(600.0);
            if ($res['runs'] === 0) {
                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function logLines(int $runId): array
    {
        return array_map(static fn (array $l): string => $l['line'], $this->p->repo()->logs($runId));
    }

    /**
     * An HTTP handler for the stub: a map of URL to response arrays or
     * callables.
     *
     * @param array<string, array<string, mixed>|callable> $routes
     */
    protected function http(array $routes): void
    {
        \WPStub::$http = static function (string $url, string $method, array $args) use ($routes) {
            $r = $routes[$method . ' ' . $url] ?? $routes[$url] ?? null;
            if ($r === null) {
                return ['response' => ['code' => 404], 'headers' => [], 'body' => ''];
            }
            if (is_callable($r)) {
                $r = $r($url, $method, $args);
            }
            if ($r instanceof \WP_Error) {
                return $r;
            }
            if (isset($args['filename']) && isset($r['body'])) {
                $body = (string) $r['body'];
                if (isset($args['limit_response_size'])) {
                    $body = substr($body, 0, (int) $args['limit_response_size']);
                }
                file_put_contents($args['filename'], $body);
                $r['body'] = '';
            }

            return $r + ['headers' => [], 'body' => ''];
        };
    }

    /** @param array<string, string> $headers */
    protected static function ok(string $body, array $headers = [], int $code = 200): array
    {
        return ['response' => ['code' => $code], 'headers' => $headers, 'body' => $body];
    }
}
