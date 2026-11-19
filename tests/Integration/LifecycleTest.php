<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Integration;

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\SourceException;
use IndianOpinion\AudioToPost\StateException;
use IndianOpinion\AudioToPost\Tests\Support\AudioFixtures;
use IndianOpinion\AudioToPost\Tests\Support\WpTestCase;

final class LifecycleTest extends WpTestCase
{
    /** T1 and T73: attachment copied to private storage; own runner completes. */
    public function testAttachmentRunCompletes(): void
    {
        $f = AudioFixtures::mp3(30.0);
        $run = $this->confirmedRun($f['path']);
        $fileId = (string) $this->p->repo()->meta($run->id, 'file');
        self::assertFileExists($this->p->files()->path($fileId));
        self::assertSame($run->source->attachmentId(), (int) $run->source->fields['attachment_id']);
        $this->drain();
        $run = $this->runOf($run->id);
        self::assertSame(RunState::TRANSCRIPT_READY, $run->state);
        self::assertCount(1, $this->fake->calls);
        self::assertNotNull($this->p->repo()->transcript($run->id));
        self::assertNotEmpty(\WPStub::fired('audiopost_transcript_ready'));
    }

    /** T6: an audio run without the rights tick is refused. */
    public function testRightsRequired(): void
    {
        $f = AudioFixtures::mp3(3.0);
        try {
            $this->p->runs()->create(['kind' => 'attachment', 'attachment_id' => $this->attachment($f['path'])], [], 1);
            self::fail('expected rights_not_confirmed');
        } catch (SourceException $e) {
            self::assertSame('rights_not_confirmed', $e->errorCode());
        }
        $err = audiopost_create_run(['kind' => 'url', 'url' => 'https://ok.example/a.mp3']);
        self::assertInstanceOf(\WP_Error::class, $err);
        self::assertSame(400, $err->get_error_data()['status']);
    }

    /** T1 and T5: only audio attachments and .vtt, .srt or .json transcripts are accepted. */
    public function testAttachmentTypesAreChecked(): void
    {
        $codes = [];
        $try = function (array $source) use (&$codes): void {
            try {
                $this->p->runs()->create($source, ['rights_confirmed' => true, 'mode' => 'none'], 1);
                $codes[] = 'none';
            } catch (\IndianOpinion\AudioToPost\AudioToPostException $e) {
                $codes[] = $e->errorCode();
            }
        };
        $png = AudioFixtures::bytes("\x89PNG\r\n", 100, 'png');
        $try(['kind' => 'attachment', 'attachment_id' => $this->attachment($png, 'image/png')]);
        $post = wp_insert_post(['post_type' => 'post', 'post_title' => 'not media', 'post_mime_type' => 'audio/mpeg']);
        $try(['kind' => 'attachment', 'attachment_id' => (int) $post]);
        $txt = AudioFixtures::dir() . '/t.txt';
        file_put_contents($txt, 'hello');
        $try(['kind' => 'transcript_file', 'transcript_attachment_id' => $this->attachment($txt, 'text/plain')]);
        $try(['kind' => 'transcript_file', 'transcript_attachment_id' => (int) $post]);
        self::assertSame(['not_audio', 'invalid_source', 'transcript_unreadable', 'invalid_source'], $codes);
    }

    /** T5 and T39: an SRT upload is free and skips confirmation when no draft is asked. */
    public function testTranscriptFileIsFree(): void
    {
        $srt = AudioFixtures::dir() . '/t.srt';
        file_put_contents($srt, "1\n00:00:01,000 --> 00:00:03,000\nHost: Hello\n\n2\n00:00:04,000 --> 00:00:06,000\nGuest: Hi there\n");
        $att = $this->attachment($srt, 'application/x-subrip');
        $run = $this->p->runs()->create(['kind' => 'transcript_file', 'transcript_attachment_id' => $att], ['mode' => 'none'], 1);
        $this->drain();
        $run = $this->runOf($run->id);
        self::assertSame(RunState::TRANSCRIPT_READY, $run->state);
        self::assertSame([], $this->fake->calls);
        self::assertSame([], $this->p->repo()->usage($run->id));
        self::assertCount(2, (array) $this->p->repo()->transcript($run->id)?->segments);
    }

    /** T7: the same bytes and settings offer reuse; reuse makes no call. */
    public function testDuplicateReuse(): void
    {
        $f = AudioFixtures::mp3(20.0);
        $first = $this->confirmedRun($f['path']);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($first->id)->state);
        $calls = count($this->fake->calls);
        $second = $this->audioRun($f['path']);
        self::assertSame($first->id, (int) $this->p->repo()->flag($second->id, 'duplicate_of'));
        $est = $this->p->runs()->estimate($second->id, false, true);
        self::assertSame(0, $est->billedSeconds);
        $this->p->runs()->confirm($second->id, $est->fingerprint, true, 1);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($second->id)->state);
        self::assertSame($calls, count($this->fake->calls));
    }

    /** T11: a 5-hour recording fails too_long before any call. */
    public function testTooLong(): void
    {
        \WPStub::$audioMeta = static fn (): array => ['length' => 5 * 3600];
        $run = $this->audioRun(AudioFixtures::bytes('fLaC', 5000, 'flac'), [], 'audio/flac');
        self::assertSame(RunState::FAILED, $run->state);
        self::assertSame('too_long', $run->errorCode);
        self::assertSame([], $this->fake->calls);
    }

    /** T16 and T19: unknown duration gives an upper bound; probing works with the switch on. */
    public function testUpperBoundAndProbeWithKillSwitch(): void
    {
        \IndianOpinion\AudioToPost\WordPress\KillSwitch::set(true, 'test');
        $run = $this->audioRun(AudioFixtures::bytes('fLaC', 40_000, 'flac'), [], 'audio/flac');
        self::assertSame(RunState::AWAITING_CONFIRMATION, $run->state);
        self::assertTrue($run->estimate?->upperBound);
        self::assertGreaterThan(0, $run->estimate?->billedSeconds);
    }

    /** T27 and T60: the plan is stored before the first call; a retry sends only missing chunks. */
    public function testPlanStoredAndRetrySendsOnlyMissing(): void
    {
        $this->settings(['chunk_seconds' => 60]);
        $this->fake->withCapabilities(new AdapterCapabilities(25_000_000, 90, ['mp3']));
        $f = AudioFixtures::mp3(200.0);
        $run = $this->confirmedRun($f['path']);
        self::assertGreaterThanOrEqual(3, $run->plan?->count());
        $this->fake->failNext(null, 'refusal');
        $this->drain();
        $run = $this->runOf($run->id);
        self::assertSame(RunState::FAILED, $run->state);
        self::assertSame('provider_refused', $run->errorCode);
        self::assertSame(1, $run->chunksDone, 'T42/T59: finished chunks are kept');
        $planBefore = $this->p->repo()->meta($run->id, 'plan');
        $this->p->runs()->retry($run->id);
        $this->drain();
        $run = $this->runOf($run->id);
        self::assertSame(RunState::TRANSCRIPT_READY, $run->state);
        self::assertSame($planBefore, $this->p->repo()->meta($run->id, 'plan'));
        $indexes = array_map(static fn ($r): int => $r->index, $this->fake->calls);
        self::assertSame(1, count(array_keys($indexes, 0, true)), 'chunk 0 sent once');
    }

    /** T32: changing a price after the estimate makes confirm fail. */
    public function testEstimateStale(): void
    {
        $run = $this->audioRun(AudioFixtures::mp3(5.0)['path']);
        $this->settings(['prices' => ['minute' => ['fake' => '0.5']], 'currency' => 'EUR']);
        try {
            $this->p->runs()->confirm($run->id, (string) $run->estimate?->fingerprint, false, 1);
            self::fail('expected estimate_stale');
        } catch (StateException $e) {
            self::assertSame('estimate_stale', $e->errorCode());
        }
        $fresh = $this->runOf($run->id)->estimate;
        self::assertNotNull($fresh?->price);
        $this->p->runs()->confirm($run->id, (string) $fresh?->fingerprint, false, 1);
        self::assertSame(RunState::QUEUED, $this->runOf($run->id)->state);
    }

    /** T34: all-empty chunks end in empty, minutes recorded, no draft. */
    public function testEmptyRecording(): void
    {
        $this->fake->empty = true;
        $run = $this->confirmedRun(AudioFixtures::mp3(10.0)['path'], ['mode' => 'transcript']);
        $this->drain();
        $run = $this->runOf($run->id);
        self::assertSame(RunState::EMPTY, $run->state);
        self::assertCount(1, $this->p->repo()->usage($run->id));
        self::assertSame([], $run->draftIds);
    }

    /** T35: crash before store re-sends once with the log line; after store, no re-send. */
    public function testInterruptedStore(): void
    {
        $run = $this->confirmedRun(AudioFixtures::mp3(10.0)['path']);
        $this->p->repo()->move($run->id, RunState::TRANSCRIBING);
        $this->p->repo()->setMeta($run->id, 'inflight_0', true);
        $this->drain();
        self::assertContains('chunk 0 re-sent after interrupted store', $this->logLines($run->id));
        self::assertCount(1, $this->fake->calls);
        $this->drain();
        self::assertCount(1, $this->fake->calls);
    }

    /** T36: two workers on one run: only one sends. */
    public function testLockStopsSecondWorker(): void
    {
        $run = $this->confirmedRun(AudioFixtures::mp3(10.0)['path']);
        $token = $this->p->locks()->acquire('run_' . $run->id);
        self::assertNotNull($token);
        self::assertNull($this->p->locks()->acquire('run_' . $run->id));
        $this->p->runner()->tick(60.0);
        self::assertSame([], $this->fake->calls);
        $this->p->locks()->release('run_' . $run->id, (string) $token);
        $this->p->runner()->tick(60.0);
        self::assertCount(1, $this->fake->calls);
        // An expired lock is taken over (case 58).
        $t2 = $this->p->locks()->acquire('x');
        $this->p->clock()->advance(901);
        self::assertNotNull($this->p->locks()->acquire('x'));
        self::assertNotNull($t2);
    }

    /** T37: 429 with Retry-After 30 schedules the retry at 30 s. */
    public function testRetryAfter(): void
    {
        $this->fake->retryAfter = 30;
        $this->fake->failNext('429');
        $run = $this->confirmedRun(AudioFixtures::mp3(10.0)['path']);
        $this->p->runner()->tick(60.0);
        $retry = $this->p->repo()->meta($run->id, 'retry_at');
        self::assertSame($this->p->clock()->now() + 30, $retry['0']);
        $this->p->runner()->tick(60.0);
        self::assertCount(1, $this->fake->calls, 'not before it is due');
        $this->p->clock()->advance(31);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);
    }

    /** T38: 413 splits the chunk and lowers the stored limit; under 60 s fails. */
    public function testPayloadTooLarge(): void
    {
        $f = AudioFixtures::mp3(150.0);
        $run = $this->confirmedRun($f['path']);
        self::assertSame(1, $run->plan?->count());
        $this->fake->failNext('413');
        $this->drain();
        $run = $this->runOf($run->id);
        self::assertSame(RunState::TRANSCRIPT_READY, $run->state);
        self::assertSame(2, $run->plan?->count());
        $limits = get_option('audiopost_adapter_limits');
        self::assertLessThan(25_000_000, $limits['fake']['max_bytes']);
        $small = $this->confirmedRun(AudioFixtures::mp3(40.0)['path']);
        $this->fake->failNext('413');
        $this->drain();
        $small = $this->runOf($small->id);
        self::assertSame(RunState::FAILED, $small->state);
        self::assertSame('provider_rejected_audio', $small->errorCode);
    }

    /** T40: unconfirmed after the TTL expires; the copy is kept until its due time. */
    public function testEstimateExpiry(): void
    {
        $this->settings(['keep_recording_hours' => 48]);
        $run = $this->audioRun(AudioFixtures::mp3(5.0)['path']);
        $this->p->clock()->advance(86401);
        $this->p->runner()->tick(10.0);
        self::assertSame(RunState::EXPIRED, $this->runOf($run->id)->state);
        $file = $this->p->files()->path((string) $this->p->repo()->meta($run->id, 'file'));
        self::assertFileExists($file);
        $this->p->clock()->advance(48 * 3600 + 1);
        $this->p->runner()->tick(10.0);
        self::assertFileDoesNotExist($file);
    }

    /** T41: 401 fails the run and pauses other runs on the adapter for 30 min with one notice. */
    public function testAuthErrorHoldsAdapter(): void
    {
        $a = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $b = $this->confirmedRun(AudioFixtures::mp3(6.0)['path']);
        $this->fake->failNext('401');
        $this->p->runner()->tick(60.0);
        self::assertSame('provider_auth', $this->runOf($a->id)->errorCode);
        self::assertSame(RunState::PAUSED, $this->runOf($b->id)->state);
        self::assertCount(1, $this->fake->calls);
        $notice = get_option('audiopost_notice_provider');
        self::assertSame('provider_auth', $notice['code']);
        $this->p->clock()->advance(1801);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($b->id)->state);
    }

    /** T43: the language hint is passed when supported; per-segment language stored. */
    public function testLanguageHint(): void
    {
        $this->fake->language = 'hi';
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path'], ['language' => 'hi']);
        $this->drain();
        self::assertSame('hi', $this->fake->calls[0]->language);
        $t = $this->p->repo()->transcript($run->id);
        self::assertSame('hi', $t?->language);
        self::assertSame('hi', $t?->segments[0]->language);
    }

    /** T57: deleting the attachment has no effect; deleting the private copy fails the run. */
    public function testSourceDeletedMidRun(): void
    {
        $f = AudioFixtures::mp3(10.0);
        $run = $this->confirmedRun($f['path']);
        unlink($f['path']);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);
        $g = AudioFixtures::mp3(11.0);
        $run2 = $this->confirmedRun($g['path']);
        unlink($this->p->files()->path((string) $this->p->repo()->meta($run2->id, 'file')));
        $this->drain();
        self::assertSame('source_copy_missing', $this->runOf($run2->id)->errorCode);
        // A retry fetches the attachment again.
        $this->p->runs()->retry($run2->id);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run2->id)->state);
    }

    /** T58: deactivate then reactivate resumes from the last stored chunk. */
    public function testDeactivateReactivate(): void
    {
        $this->settings(['chunk_seconds' => 60]);
        $this->fake->withCapabilities(new AdapterCapabilities(25_000_000, 90, ['mp3']));
        $run = $this->confirmedRun(AudioFixtures::mp3(200.0)['path']);
        $this->p->runner()->tick(60.0, 1);
        self::assertSame(1, $this->runOf($run->id)->chunksDone);
        \IndianOpinion\AudioToPost\WordPress\Hooks::deactivate();
        self::assertFalse(wp_next_scheduled('audiopost_tick'));
        \IndianOpinion\AudioToPost\WordPress\Hooks::activate();
        self::assertNotFalse(wp_next_scheduled('audiopost_tick'));
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);
        self::assertSame($this->runOf($run->id)->plan?->count(), count($this->fake->calls));
    }

    /** T61: cancel during a call: result discarded, usage recorded, copy kept until due. */
    public function testCancelDuringCall(): void
    {
        $this->settings(['keep_recording_hours' => 24]);
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $p = $this->p;
        $id = $run->id;
        $this->fake->during = static function () use ($p, $id): void {
            $p->runs()->cancel($id);
        };
        $this->p->runner()->tick(60.0);
        $run = $this->runOf($run->id);
        self::assertSame(RunState::CANCELLED, $run->state);
        self::assertNull($this->p->repo()->chunkResult($run->id, 0));
        self::assertCount(1, $this->p->repo()->usage($run->id));
        self::assertFileExists($this->p->files()->path((string) $this->p->repo()->meta($run->id, 'file')));
    }

    /** T74: the tick stops starting calls when the budget is short. */
    public function testBudget(): void
    {
        // No declared latency: 20 s is assumed per call.
        $this->fake->withCapabilities(new AdapterCapabilities(25_000_000, 1200, ['mp3']));
        $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $res = $this->p->runner()->tick(5.0);
        self::assertSame(0, $res['calls']);
        self::assertSame('budget', $res['stopped']);
    }
}
