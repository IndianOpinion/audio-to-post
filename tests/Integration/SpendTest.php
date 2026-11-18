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

    /** T71: spend-meter deny pauses; reservations are always closed; value 2 means absent. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSpendMeter(): void
    {
        define('SPEND_METER_API', 1);
        $decisions = [['allowed' => false, 'reason' => 'budget'], ['allowed' => true, 'reservation_id' => 'r1'], ['allowed' => true, 'reservation_id' => 'r2']];
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
        $this->p->clock()->advance(901);
        $this->fake->failNext('refusal');
        $this->drain();
        self::assertSame(['r1', 'provider_refused'], \WPStub::fired('spend_meter_release')[0]);
        $this->p->runs()->retry($run->id);
        $this->drain();
        $records = \WPStub::fired('spend_meter_record');
        self::assertCount(1, $records);
        self::assertSame('r2', $records[0][0]['reservation_id']);
    }

    /** T72: gen-queue present: one job per chunk with the key; null falls back. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testGenQueue(): void
    {
        define('GEN_QUEUE_VERSION', '0.1.0');
        $jobs = [];
        add_filter('gen_queue_enqueue_job', static function ($v, array $job) use (&$jobs) {
            $jobs[] = $job;

            return count($jobs);
        }, 10, 2);
        $this->settings(['chunk_seconds' => 60]);
        $this->fake->withCapabilities(new AdapterCapabilities(25_000_000, 90, ['mp3'], typicalSecondsPerAudioMinute: 0.0));
        $run = $this->confirmedRun(AudioFixtures::mp3(150.0)['path']);
        $n = (int) $run->plan?->count();
        $this->p->runner()->tick(60.0);
        self::assertCount($n, $jobs);
        self::assertSame($run->id . ':0', $jobs[0]['idempotency_key']);
        self::assertSame('audio-to-post.chunk', $jobs[0]['type']);
        self::assertSame([], $this->fake->calls, 'the plugin runner stays idle');
        foreach ($jobs as $job) {
            $out = $this->p->runner()->handleJob($job);
            self::assertCount(1, $out['usage']);
        }
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($run->id)->state);

        remove_all_filters('gen_queue_enqueue_job');
        add_filter('gen_queue_enqueue_job', static fn () => null, 10, 2);
        $other = $this->confirmedRun(AudioFixtures::mp3(20.0)['path']);
        $this->drain();
        self::assertSame(RunState::TRANSCRIPT_READY, $this->runOf($other->id)->state);
        self::assertTrue((bool) $this->p->repo()->flag($other->id, 'gen_queue_fallback'));
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
