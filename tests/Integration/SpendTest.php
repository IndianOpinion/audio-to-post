<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Integration;

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\Adapter\FakeAdapter;
use IndianOpinion\AudioToPost\CapException;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Tests\Support\AudioFixtures;
use IndianOpinion\AudioToPost\Tests\Support\WpTestCase;
use IndianOpinion\AudioToPost\WordPress\Cli;
use IndianOpinion\AudioToPost\WordPress\KillSwitch;

final class SpendTest extends WpTestCase
{
    /** T33: each check stops before the call, in order. */
    public function testChecksInOrder(): void
    {
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $order = [];
        add_filter('audiopost_can_spend', static function (bool $allow) use (&$order): bool {
            $order[] = 'filter';

            return $allow;
        });
        KillSwitch::set(true, 'test');
        $this->p->runner()->tick(60.0);
        self::assertSame('kill_switch', $this->runOf($run->id)->reason);
        self::assertSame([], $order, 'the switch is checked before anything else');
        KillSwitch::set(false, 'test');
        $this->p->runner()->tick(60.0, 0);
        $this->settings(['cap_minutes' => '0']);
        $this->p->runner()->tick(60.0);
        self::assertSame('daily_cap', $this->runOf($run->id)->reason);
        self::assertSame([], $order, 'the cap is checked before the filter');
        self::assertSame([], $this->fake->calls);
        $this->settings(['cap_minutes' => '']);
        $this->p->clock()->set($this->p->clock()->nextLocalMidnight() + 1);
        add_filter('audiopost_can_spend', static fn (): bool => false, 20);
        $this->p->runner()->tick(60.0);
        self::assertSame(['filter'], $order);
        self::assertSame('denied', $this->runOf($run->id)->reason);
        self::assertSame([], $this->fake->calls);
    }

    /** T54: a call in flight for one run counts against the cap for every other run until it is recorded. */
    public function testInFlightCallsHoldTheCap(): void
    {
        $this->settings(['cap_minutes' => '2']);
        $a = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $b = $this->confirmedRun(AudioFixtures::mp3(6.0)['path']);
        $intent = static fn (int $seconds): array => ['kind' => 'transcribe', 'seconds' => $seconds, 'tokens_in' => 0, 'tokens_out' => 0, 'money_micros' => null, 'provider' => 'fake', 'model' => ''];
        $guard = $this->p->guard();
        self::assertNull($guard->authorize($this->runOf($a->id), $intent(90)));
        try {
            $guard->authorize($this->runOf($b->id), $intent(60));
            self::fail('the second run must see the first run\'s call in flight');
        } catch (CapException $e) {
            self::assertSame('daily_cap', $e->errorCode());
        }
        // The same run asking again replaces its own hold instead of adding to it.
        self::assertNull($guard->authorize($this->runOf($a->id), $intent(90)));
        $guard->settle($a->id);
        self::assertNull($guard->authorize($this->runOf($b->id), $intent(60)));
        // A hold left behind by a crashed worker stops counting after the lock lifetime.
        $this->p->clock()->advance(\IndianOpinion\AudioToPost\WordPress\Lock::TTL + 1);
        self::assertNull($guard->authorize($this->runOf($a->id), $intent(90)));
    }

    /** T35: a usage row the table refused is kept, counted against the cap, and written later. */
    public function testLedgerWriteFailureIsBuffered(): void
    {
        $this->settings(['cap_minutes' => '10']);
        $GLOBALS['wpdb']->failInserts = true;
        $run = $this->confirmedRun(AudioFixtures::mp3(30.0)['path']);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);
        self::assertSame([], $GLOBALS['wpdb']->usage);
        self::assertSame('1', $this->p->guard()->today()['minutes']['used'], 'the buffered row still counts');
        self::assertNotEmpty(get_option('audiopost_usage_buffer'));
        $GLOBALS['wpdb']->failInserts = false;
        self::assertSame('1', $this->p->guard()->today()['minutes']['used']);
        self::assertCount(1, $GLOBALS['wpdb']->usage);
        self::assertFalse(get_option('audiopost_usage_buffer'));
    }

    /** T36: an expired lock is taken over by one process only; the old holder cannot release the new lock. */
    public function testExpiredLockTakeover(): void
    {
        $locks = $this->p->locks();
        $old = $locks->acquire('run_1');
        self::assertNotNull($old);
        self::assertNull($locks->acquire('run_1'));
        $this->p->clock()->advance(\IndianOpinion\AudioToPost\WordPress\Lock::TTL + 1);
        $new = $locks->acquire('run_1');
        self::assertNotNull($new);
        self::assertNull($locks->acquire('run_1'), 'a second taker loses');
        $locks->release('run_1', $old);
        self::assertTrue($locks->isHeld('run_1'));
        $locks->release('run_1', $new);
        self::assertFalse($locks->isHeld('run_1'));
    }

    /** T51 and T52: usage and remaining cap; zero blocks, empty removes, money ignored without prices. */
    public function testCaps(): void
    {
        $this->settings(['cap_minutes' => '10', 'cap_tokens' => '', 'cap_money' => '1']);
        $run = $this->confirmedRun(AudioFixtures::mp3(30.0)['path']);
        $this->drain();
        $today = $this->p->guard()->today();
        self::assertSame('1', $today['minutes']['used']);
        self::assertSame('9', $today['minutes']['left']);
        self::assertNull($today['tokens']['cap']);
        $caps = $this->p->guard()->caps();
        self::assertNull($caps['tokens']);
        // Money cap set but no prices: estimate money is null, so it never blocks.
        self::assertNull($this->p->guard()->checkEstimate($this->runOf($run->id)->estimate));
        $this->settings(['cap_tokens' => '0']);
        self::assertSame(0, $this->p->guard()->caps()['tokens']);
    }

    /** T53: over the remaining cap queues for tomorrow; over the whole cap fails. */
    public function testConfirmAgainstCaps(): void
    {
        $this->settings(['cap_minutes' => '2']);
        $a = $this->confirmedRun(AudioFixtures::mp3(70.0)['path']);
        $this->drain();
        $b = $this->audioRun(AudioFixtures::mp3(65.0)['path']);
        $res = $this->p->runs()->confirm($b->id, (string) $b->estimate?->fingerprint, false, 1);
        self::assertTrue($res['queued_for_tomorrow']);
        $this->p->runner()->tick(60.0);
        self::assertSame(RunState::QUEUED, $this->runOf($b->id)->state);
        $this->p->clock()->set($this->p->clock()->nextLocalMidnight() + 5);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($b->id)->state);
        $c = $this->audioRun(AudioFixtures::mp3(200.0)['path']);
        try {
            $this->p->runs()->confirm($c->id, (string) $c->estimate?->fingerprint, false, 1);
            self::fail('expected exceeds_daily_cap');
        } catch (CapException $e) {
            self::assertSame('exceeds_daily_cap', $e->errorCode());
        }
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($a->id)->state);
    }

    /** T54: cap reached mid-run pauses at a chunk boundary and resumes after local midnight. */
    public function testCapMidRun(): void
    {
        \WPStub::$timezone = 'Asia/Kolkata';
        $this->settings(['chunk_seconds' => 60, 'cap_minutes' => '3']);
        $this->fake->withCapabilities(new AdapterCapabilities(25_000_000, 90, ['mp3']));
        $run = $this->audioRun(AudioFixtures::mp3(150.0)['path']);
        $this->p->runs()->confirm($run->id, (string) $run->estimate?->fingerprint, false, 1);
        // Another run uses most of today's cap after the confirmation.
        $this->p->runner()->recordUsage(0, 'transcribe', 'fake', 'other', 100, 0, 0, null, 200, 1);
        $this->drain();
        $r = $this->runOf($run->id);
        self::assertSame(RunState::PAUSED, $r->state);
        self::assertSame('daily_cap', $r->reason);
        self::assertSame(1, $r->chunksDone);
        self::assertNotEmpty(\WPStub::fired('audiopost_cap_reached'));
        $midnight = $this->p->clock()->nextLocalMidnight();
        self::assertSame($midnight, (int) $this->p->repo()->meta($run->id, 'resume_at'));
        $this->p->clock()->set($midnight + 60);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);
    }

    /** T55: the constant cannot be overridden by the option; no call starts. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testConstantForcesSwitch(): void
    {
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        define('AUDIOPOST_DISABLED', true);
        KillSwitch::set(false, 'test');
        self::assertTrue(audiopost_is_disabled());
        $this->p->runner()->tick(60.0);
        self::assertSame([], $this->fake->calls);
        self::assertSame('kill_switch', $this->runOf($run->id)->reason);
    }

    /** T56: runs paused by the switch resume in order when it is turned off. */
    public function testResumeInOrder(): void
    {
        $a = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $b = $this->confirmedRun(AudioFixtures::mp3(6.0)['path']);
        KillSwitch::set(true, 'test');
        $this->p->runner()->tick(60.0);
        self::assertSame(RunState::PAUSED, $this->runOf($a->id)->state);
        self::assertSame(RunState::PAUSED, $this->runOf($b->id)->state);
        KillSwitch::set(false, 'test');
        self::assertCount(2, \WPStub::fired('audiopost_kill_switch_changed'));
        $this->drain();
        self::assertLessThan($this->fake->calls[1]->lengthMs, $this->fake->calls[0]->lengthMs);
    }

    /** T75: WP-CLI run exits 3 when disabled and 4 when capped. */
    public function testCliExitCodes(): void
    {
        $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $cli = new Cli();
        KillSwitch::set(true, 'test');
        self::assertSame(3, $this->exitCode(static fn () => $cli->run([], ['time' => '10'])));
        KillSwitch::set(false, 'test');
        $this->p->runner()->housekeeping();
        $this->settings(['cap_minutes' => '0']);
        self::assertSame(4, $this->exitCode(static fn () => $cli->run([], ['time' => '10'])));
        $this->settings(['cap_minutes' => '']);
        $this->p->clock()->set($this->p->clock()->nextLocalMidnight() + 1);
        self::assertSame(0, $this->exitCode(static fn () => $cli->run([], ['time' => '10'])));
        self::assertSame(1, $this->exitCode(static fn () => $cli->add(['/nope'], [])));
    }

    private function exitCode(callable $fn): int
    {
        try {
            $fn();
        } catch (\CliExit $e) {
            return $e->getCode();
        }

        return 0;
    }

    /** T71: spend-meter deny pauses; every attempt is recorded once; release only without a call. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSpendMeter(): void
    {
        define('SPEND_METER_API', 1);
        $decisions = [
            ['allowed' => false, 'reason' => 'cap_site_day', 'reservation_id' => null],
            ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'r1'],
            ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'r2'],
        ];
        $intents = [];
        add_filter('spend_meter_pre_spend', static function ($v, array $intent) use (&$decisions, &$intents) {
            $intents[] = $intent;

            return array_shift($decisions);
        }, 10, 2);
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $this->p->runner()->tick(60.0);
        self::assertSame('spend_meter', $this->runOf($run->id)->reason);
        self::assertSame('audio-to-post.transcribe', $intents[0]['purpose']);
        self::assertSame('audio-to-post', $intents[0]['caller']);
        self::assertSame('fake', $intents[0]['provider']);
        self::assertSame('fake', $intents[0]['model'], 'the model is required; the adapter id stands in');
        self::assertSame(0, $intents[0]['est_input_tokens']);
        self::assertArrayHasKey('est_units', $intents[0]);
        self::assertSame(['run_id' => (string) $run->id], $intents[0]['meta']);
        $this->p->clock()->advance(901);
        $this->fake->failNext('refusal');
        $this->drain();
        self::assertSame([], \WPStub::fired('spend_meter_release'), 'a call that reached the provider is recorded, not released');
        $records = \WPStub::fired('spend_meter_record');
        self::assertCount(1, $records);
        self::assertCount(1, $records[0], 'one call array');
        self::assertSame('r1', $records[0][0]['reservation_id']);
        self::assertSame('error_unbilled', $records[0][0]['status']);
        $this->p->runs()->retry($run->id);
        $this->drain();
        $records = \WPStub::fired('spend_meter_record');
        self::assertCount(2, $records);
        self::assertSame('r2', $records[1][0]['reservation_id']);
        self::assertSame('ok', $records[1][0]['status']);
        self::assertSame('audio-to-post.transcribe', $records[1][0]['purpose']);
        self::assertArrayNotHasKey('tokens_in', $records[1][0]);
    }

    /** T71: a null decision means no meter is listening; the plugin's own guards decide. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSpendMeterNullMeansAbsent(): void
    {
        define('SPEND_METER_API', 1);
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);
        self::assertCount(1, \WPStub::fired('spend_meter_record'));
        self::assertArrayNotHasKey('reservation_id', \WPStub::fired('spend_meter_record')[0][0]);
    }

    /** T71: a reservation not used for a call is released with its id only. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSpendMeterReleaseWhenCancelled(): void
    {
        define('SPEND_METER_API', 1);
        $run = $this->confirmedRun(AudioFixtures::mp3(5.0)['path']);
        add_filter('spend_meter_pre_spend', function ($v, array $intent) use ($run) {
            // The editor cancels while the check runs.
            $this->p->repo()->setMeta($run->id, 'cancel', true);

            return ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'r9'];
        }, 10, 2);
        $this->drain();
        self::assertSame([['r9']], \WPStub::fired('spend_meter_release'));
        self::assertSame([], \WPStub::fired('spend_meter_record'));
        self::assertSame([], $this->fake->calls);
    }

    /** T72: gen-queue present: one job per chunk through the documented filter and handler. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testGenQueue(): void
    {
        define('GEN_QUEUE_VERSION', '0.1.0');
        $jobs = [];
        add_filter('gen_queue_enqueue_job', static function ($v, string $type, array $payload, array $args) use (&$jobs) {
            $jobs[] = ['type' => $type, 'payload' => $payload, 'args' => $args];

            return count($jobs);
        }, 10, 4);
        require_once dirname(__DIR__) . '/Support/gen-queue-functions.php';
        \IndianOpinion\AudioToPost\WordPress\Hooks::genQueueTypes();
        self::assertSame('audio-to-post.chunk', $GLOBALS['gen_queue_registered'][0]['type']);
        self::assertIsCallable($GLOBALS['gen_queue_registered'][0]['handler']);
        self::assertSame(600, $GLOBALS['gen_queue_registered'][0]['args']['timeout']);
        $this->settings(['chunk_seconds' => 60]);
        $this->fake->withCapabilities(new AdapterCapabilities(25_000_000, 90, ['mp3'], typicalSecondsPerAudioMinute: 0.0));
        $run = $this->confirmedRun(AudioFixtures::mp3(150.0)['path']);
        $n = (int) $run->plan?->count();
        $this->p->runner()->tick(60.0);
        self::assertCount($n, $jobs);
        self::assertSame('audio-to-post.chunk', $jobs[0]['type']);
        self::assertSame(['run_id' => $run->id, 'chunk' => 0], $jobs[0]['payload']);
        self::assertSame('run' . $run->id . '-c0', $jobs[0]['args']['idempotency_key']);
        self::assertSame([], $this->fake->calls, 'the plugin runner stays idle');

        // gen-queue hands the handler a Job object and a JobContext.
        $ctx = new class () {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $costs = [];

            /** @param array<string, mixed> $usage */
            public function addCost(string $amount, array $usage = []): void
            {
                $this->costs[] = [$amount, $usage];
            }
        };
        foreach ($jobs as $j) {
            $job = new class ($j['type'], $j['payload']) {
                /** @param array<string, mixed> $payload */
                public function __construct(public string $type, public array $payload)
                {
                }
            };
            $out = $this->p->runner()->handleJob($job, $ctx);
            self::assertSame(1, $out['calls']);
        }
        self::assertCount($n, $ctx->costs);
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);

        remove_all_filters('gen_queue_enqueue_job');
        add_filter('gen_queue_enqueue_job', static fn () => null, 10, 4);
        $other = $this->confirmedRun(AudioFixtures::mp3(20.0)['path']);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($other->id)->state);
        self::assertTrue((bool) $this->p->repo()->flag($other->id, 'gen_queue_fallback'));
    }

    /** T72: a chunk job that cannot run yet (run locked) is handed back for a retry, not marked done. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testGenQueueRetriesWhenBusy(): void
    {
        define('GEN_QUEUE_VERSION', '0.1.0');
        add_filter('gen_queue_enqueue_job', static fn () => 7, 10, 4);
        $run = $this->confirmedRun(AudioFixtures::mp3(20.0)['path']);
        $this->p->runner()->tick(60.0);
        $token = $this->p->locks()->acquire('run_' . $run->id);
        self::assertNotNull($token);
        try {
            $this->p->runner()->handleJob(['type' => 'audio-to-post.chunk', 'payload' => ['run_id' => $run->id, 'chunk' => 0]]);
            self::fail('expected a retry signal');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('could not run yet', $e->getMessage());
        }
        self::assertSame([], $this->fake->calls);
    }

    /** T26, T31 and T76: async adapters poll for free and time out. */
    public function testAsyncAdapter(): void
    {
        $async = new FakeAdapter(new AdapterCapabilities(25_000_000, 60, ['mp3', 'flac'], async: true, longAudio: true, typicalSecondsPerAudioMinute: 0.0), pollsBeforeDone: 2);
        $this->p->adapters()->register($async);
        $run = $this->confirmedRun(AudioFixtures::mp3(90.0)['path']);
        self::assertSame(1, $run->plan?->count());
        self::assertTrue($run->plan?->native);
        $this->p->runner()->tick(60.0);
        for ($i = 0; $i < 4; $i++) {
            $this->p->clock()->advance(400);
            $this->p->runner()->tick(60.0);
        }
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);
        self::assertCount(1, $this->p->repo()->usage($run->id), 'polls are not counted');
        self::assertGreaterThanOrEqual(2, count($async->polls));

        $slow = $this->confirmedRun(AudioFixtures::mp3(91.0)['path']);
        $async->pollsBeforeDone = 1000;
        $this->p->runner()->tick(60.0);
        $this->p->clock()->advance(7 * 3600);
        $this->p->runner()->tick(60.0);
        self::assertSame('provider_timeout', $this->runOf($slow->id)->errorCode);
    }
}
