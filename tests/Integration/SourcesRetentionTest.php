<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Integration;

use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Tests\Support\AudioFixtures;
use IndianOpinion\AudioToPost\Tests\Support\WpTestCase;
use IndianOpinion\AudioToPost\WordPress\PrivateStorage;
use IndianOpinion\AudioToPost\WordPress\Rest;
use IndianOpinion\AudioToPost\WordPress\Uninstaller;

final class SourcesRetentionTest extends WpTestCase
{
    private const FEED = 'https://pod.example/feed.xml';

    private function feed(): string
    {
        return '<?xml version="1.0"?><rss version="2.0" xmlns:podcast="https://podcastindex.org/namespace/1.0"><channel><title>Show</title>'
            . '<item><title>Ep 1</title><guid>g1</guid><pubDate>Mon, 02 Nov 2026 10:00:00 +0000</pubDate><enclosure url="https://pod.example/ep1.mp3" length="1000" type="audio/mpeg"/>'
            . '<podcast:transcript url="https://pod.example/ep1.vtt" type="text/vtt"/></item></channel></rss>';
    }

    /** T4: a published WebVTT transcript is a free route with no adapter call. */
    public function testFeedTranscriptShortcut(): void
    {
        $this->http([
            'GET ' . self::FEED => self::ok($this->feed()),
            'GET https://pod.example/ep1.vtt' => self::ok("WEBVTT\n\n00:00:01.000 --> 00:00:03.000\n<v Host>Welcome back\n"),
        ]);
        $eps = $this->p->runs()->feedEpisodes(self::FEED);
        self::assertSame('https://pod.example/ep1.vtt', $eps[0]['transcript_url']);
        $run = $this->p->runs()->create(['kind' => 'feed_episode', 'feed_url' => self::FEED, 'guid' => 'g1', 'episode_title' => 'Ep 1', 'enclosure_url' => $eps[0]['enclosure_url'], 'transcript_url' => $eps[0]['transcript_url'], 'transcript_type' => 'vtt', 'use_transcript' => true], ['mode' => 'none'], 1);
        $this->drain();
        $run = $this->runOf($run->id);
        self::assertSame(RunState::TRANSCRIPT_READY, $run->state);
        self::assertSame([], $this->fake->calls);
        self::assertSame('Ep 1', $run->title());
        self::assertSame('Welcome back', $this->p->repo()->transcript($run->id)?->segments[0]->text);
        // A broken link says so and offers the audio route.
        $this->http(['GET ' . self::FEED => self::ok($this->feed())]);
        $bad = $this->p->runs()->create(['kind' => 'feed_episode', 'guid' => 'g1', 'enclosure_url' => 'https://pod.example/ep1.mp3', 'transcript_url' => 'https://pod.example/ep1.vtt', 'use_transcript' => true], ['mode' => 'none'], 1);
        $this->drain();
        self::assertSame('transcript_unavailable', $this->runOf($bad->id)->errorCode);
    }

    /** T12: an HTML page fails not_audio; an RSS body pasted as audio switches to the picker. */
    public function testUrlSourceSniffing(): void
    {
        $this->http([
            'GET https://pod.example/page' => self::ok('<html><head></head><body>Episode</body></html>', ['content-type' => 'audio/mpeg']),
            'GET ' . self::FEED => self::ok($this->feed(), ['content-type' => 'audio/mpeg']),
        ]);
        $a = $this->p->runs()->create(['kind' => 'url', 'url' => 'https://pod.example/page'], ['rights_confirmed' => true], 1);
        $b = $this->p->runs()->create(['kind' => 'url', 'url' => self::FEED], ['rights_confirmed' => true], 1);
        $this->drain();
        self::assertSame('not_audio', $this->runOf($a->id)->errorCode);
        self::assertStringContainsString('looks like a web page', (string) $this->runOf($a->id)->errorMessage);
        self::assertSame('feed_url', $this->runOf($b->id)->errorCode);
        foreach (\WPStub::$requests as $r) {
            self::assertTrue($r['args']['reject_unsafe_urls']);
            self::assertSame(0, $r['args']['redirection']);
        }
    }

    /** T13 (runner): 503 then 200 succeeds on a scheduled retry. */
    public function testFetchRetryInRunner(): void
    {
        $mp3 = (string) file_get_contents(AudioFixtures::mp3(5.0)['path']);
        $n = 0;
        $this->http(['GET https://pod.example/a.mp3' => static function () use (&$n, $mp3): array {
            $n++;

            return $n === 1 ? ['response' => ['code' => 503], 'body' => ''] : ['response' => ['code' => 200], 'headers' => ['content-type' => 'audio/mpeg'], 'body' => $mp3];
        }]);
        $run = $this->p->runs()->create(['kind' => 'url', 'url' => 'https://pod.example/a.mp3'], ['rights_confirmed' => true], 1);
        $this->p->runner()->tick(60.0);
        self::assertSame(RunState::FETCHING, $this->runOf($run->id)->state);
        $this->p->clock()->advance(60);
        $this->p->runner()->tick(60.0);
        self::assertSame(RunState::AWAITING_CONFIRMATION, $this->runOf($run->id)->state);
    }

    /** T80: retention of audio copies. */
    public function testAudioRetention(): void
    {
        $this->settings(['keep_recording_hours' => 48, 'keep_failed_hours' => 168]);
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $file = $this->p->files()->path((string) $this->p->repo()->meta($run->id, 'file'));
        $this->p->clock()->advance(30 * 86400);
        $this->p->runner()->housekeeping();
        $this->p->runner()->sweep();
        self::assertFileExists($file, 'never deleted while the run is not final');
        $this->drain();
        $this->p->clock()->advance(47 * 3600);
        $this->p->runner()->tick(10.0);
        self::assertFileExists($file);
        $this->p->clock()->advance(3601);
        $this->p->runner()->tick(10.0);
        self::assertFileDoesNotExist($file);
        $deleted = \WPStub::fired('audiopost_recording_deleted');
        self::assertCount(1, $deleted);
        self::assertSame($run->id, $deleted[0][0]?->id);
        self::assertSame('keep_recording_hours=48', $deleted[0][3]);

        $this->settings(['keep_recording_hours' => 0]);
        $this->fake->failNext('refusal');
        $failed = $this->confirmedRun(AudioFixtures::mp3(6.0)['path']);
        $ff = $this->p->files()->path((string) $this->p->repo()->meta($failed->id, 'file'));
        $this->drain();
        self::assertSame(RunState::FAILED, $this->runOf($failed->id)->state);
        $this->p->clock()->advance(100 * 3600);
        $this->p->runner()->tick(10.0);
        self::assertFileExists($ff, 'a failed run keeps its copy for keep_failed_hours');
        $this->p->runs()->cancel($failed->id);
        $this->p->runs()->delete($failed->id);
        self::assertFileExists($ff, 'deleting a run deletes no audio early');
        $this->p->clock()->advance(69 * 3600);
        $this->p->runner()->tick(10.0);
        self::assertFileDoesNotExist($ff);
    }

    /** T85: each deletion logs size, time and cause and fires the action; failures are retried; purge skips files not due. */
    public function testDeletionsAreVisible(): void
    {
        $this->settings(['keep_recording_hours' => 1]);
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $this->drain();
        $fileId = (string) $this->p->repo()->meta($run->id, 'file');
        $dry = $this->p->files()->sweep(static function (): void {
        }, 1, true);
        self::assertSame([], $dry, 'purge --dry-run lists nothing that is not due');
        $this->p->clock()->advance(3601);
        $lines = [];
        $fired = 0;
        $repo = $this->p->repo();
        add_action('audiopost_recording_deleted', static function () use (&$fired): void {
            $fired++;
        });
        $done = $this->p->files()->sweep(static function (int $r, string $l) use (&$lines): void {
            $lines[] = $l;
        }, 1, false, [$repo, 'get']);
        self::assertSame($fileId, $done[0]['id']);
        self::assertStringContainsString('bytes at', $lines[0]);
        self::assertStringContainsString('keep_recording_hours=1', $lines[0]);
        self::assertSame(1, $fired);
        // A file that cannot be deleted is retried and shown.
        $this->settings(['keep_recording_hours' => 0]);
        $other = $this->confirmedRun(AudioFixtures::mp3(6.0)['path']);
        $this->drain();
        self::assertNotEmpty(array_filter($this->logLines($other->id), static fn (string $l): bool => str_starts_with($l, 'deleted audio file')));
    }

    /** T85: a deletion that fails is logged, kept in the registry with a failure count, and retried until it works. */
    public function testFailedDeletionIsRetried(): void
    {
        $fail = 2;
        $attempts = [];
        $this->p->set('files', new PrivateStorage($this->p->clock(), null, static function (string $path) use (&$fail, &$attempts): bool {
            $attempts[] = $path;
            if ($fail > 0) {
                $fail--;

                return false;
            }

            return !is_file($path) || unlink($path);
        }));
        $this->settings(['keep_recording_hours' => 1]);
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $this->drain();
        $fileId = (string) $this->p->repo()->meta($run->id, 'file');
        $file = $this->p->files()->path($fileId);
        $this->p->clock()->advance(3601);

        $this->p->runner()->sweep();
        self::assertFileExists($file);
        self::assertSame(1, $this->p->files()->registry()[$fileId]['failures']);
        self::assertSame([], \WPStub::fired('audiopost_recording_deleted'), 'no deletion is reported that did not happen');
        $this->p->runner()->daily();
        self::assertSame(2, $this->p->files()->registry()[$fileId]['failures']);
        $failures = array_filter($this->logLines($run->id), static fn (string $l): bool => str_starts_with($l, 'could not delete audio file ' . $fileId));
        self::assertCount(2, $failures);
        self::assertStringContainsString('keep_recording_hours=1', (string) reset($failures));

        $this->p->runner()->sweep();
        self::assertFileDoesNotExist($file);
        self::assertArrayNotHasKey($fileId, $this->p->files()->registry());
        self::assertCount(3, array_filter($attempts, static fn (string $p): bool => $p === $file));
        self::assertCount(1, \WPStub::fired('audiopost_recording_deleted'));
        self::assertNotEmpty(array_filter($this->logLines($run->id), static fn (string $l): bool => str_starts_with($l, 'deleted audio file ' . $fileId)));
    }

    /** Fail closed: when the private directory cannot be protected, nothing is written to it. */
    public function testUnprotectedDirectoryFailsClosed(): void
    {
        // A path under a regular file can never become a directory.
        file_put_contents($this->uploads . '/not-a-dir', 'x');
        $base = $this->uploads . '/not-a-dir/audio-to-post';
        $files = new PrivateStorage($this->p->clock(), $base);
        try {
            $files->dir();
            self::fail('expected storage_unwritable');
        } catch (\IndianOpinion\AudioToPost\StateException $e) {
            self::assertSame('storage_unwritable', $e->errorCode());
        }
    }

    /** T82: an unreferenced file is an orphan and is deleted only when due and older than 24 h. */
    public function testOrphans(): void
    {
        $dir = $this->p->files()->dir();
        $id = PrivateStorage::newId();
        file_put_contents($dir . '/' . $id, 'x');
        touch($dir . '/' . $id, $this->p->clock()->now());
        self::assertSame($id, $this->p->files()->orphans()[0]['id']);
        $this->p->clock()->advance(23 * 3600);
        $this->p->runner()->sweep();
        self::assertFileExists($dir . '/' . $id);
        $this->p->clock()->advance(3601);
        $this->p->runner()->sweep();
        self::assertFileDoesNotExist($dir . '/' . $id);
        self::assertNotEmpty(get_option('audiopost_orphan_log'));
    }

    /** T81: runs older than retention are deleted; drafts are kept. */
    public function testRunRetention(): void
    {
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path'], ['mode' => 'transcript']);
        $this->drain();
        $draft = $this->runOf($run->id)->draftIds[0];
        $this->p->clock()->advance(91 * 86400);
        $this->p->runner()->daily();
        self::assertNull($this->p->repo()->get($run->id));
        self::assertNotNull(get_post($draft));
    }

    /** T77 (REST): a stale revision gets 409; the original stays unchanged. */
    public function testRestEditsAndPermissions(): void
    {
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $this->drain();
        Rest::register();
        self::assertArrayHasKey('audio-to-post/v1/runs/(?P<id>\d+)/transcript', \WPStub::$routes);
        $req = new \WP_REST_Request('PATCH', '', ['id' => $run->id, 'revision' => 0, 'edits' => [['op' => 'rename_speaker', 'speaker' => 'S1', 'name' => 'Asha']]]);
        $ok = Rest::edit($req);
        self::assertInstanceOf(\WP_REST_Response::class, $ok);
        $stale = Rest::edit($req);
        self::assertInstanceOf(\WP_Error::class, $stale);
        self::assertSame(409, $stale->get_error_data()['status']);
        self::assertSame('', $this->p->repo()->transcript($run->id, true)?->speakers[0]->name);
        self::assertSame('Asha', $this->p->repo()->transcript($run->id)?->speakers[0]->name);
        \WPStub::$caps = ['upload_files', 'edit_posts'];
        \WPStub::$userId = 2;
        self::assertFalse(Rest::canActOnRun(new \WP_REST_Request('GET', '', ['id' => $run->id])));
        $created = Rest::create(new \WP_REST_Request('POST', '', ['source' => ['kind' => 'url', 'url' => 'https://ok.example/a.mp3'], 'options' => []]));
        self::assertSame('rights_not_confirmed', $created instanceof \WP_Error ? $created->get_error_code() : '');
    }

    /** T84: uninstall removes runs, options, usage, files and link meta; keeps drafts and attachments. */
    public function testUninstall(): void
    {
        $this->settings(['remove_recordings_on_uninstall' => true]);
        $path = AudioFixtures::mp3(5.0)['path'];
        $run = $this->confirmedRun($path, ['mode' => 'transcript']);
        $this->drain();
        $draft = $this->runOf($run->id)->draftIds[0];
        $att = $run->source->attachmentId();
        $log = $this->uploads . '/php-error.log';
        $before = ini_set('error_log', $log);
        try {
            Uninstaller::site();
        } finally {
            ini_set('error_log', (string) $before);
        }
        self::assertStringContainsString('under remove_recordings_on_uninstall', (string) file_get_contents($log), 'the uninstall removal is logged');
        self::assertNull(get_post($run->id));
        self::assertNotNull(get_post($draft));
        self::assertNotNull(get_post($att));
        self::assertSame('', get_post_meta($draft, '_audiopost_run_id', true));
        self::assertFalse(get_option('audiopost_settings'));
        self::assertSame([], $GLOBALS['wpdb']->usage);
        self::assertDirectoryDoesNotExist($this->uploads . '/audio-to-post');
        self::assertFalse(wp_next_scheduled('audiopost_tick'));
    }
}
