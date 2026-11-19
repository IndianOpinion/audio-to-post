<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Unit;

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\AudioInfo;
use IndianOpinion\AudioToPost\ChunkPlan;
use IndianOpinion\AudioToPost\Estimate\EstimateCalculator;
use IndianOpinion\AudioToPost\Estimate\Money;
use IndianOpinion\AudioToPost\Estimate\TokenRatios;
use IndianOpinion\AudioToPost\Plan\ChunkPlanner;
use IndianOpinion\AudioToPost\PlanException;
use PHPUnit\Framework\TestCase;

final class PlanEstimateTest extends TestCase
{
    private static function caps(int $bytes = 25_000_000, int $seconds = 1200, bool $long = false, string $round = 'none', array $formats = ['mp3', 'wav', 'flac']): AdapterCapabilities
    {
        return new AdapterCapabilities($bytes, $seconds, $formats, longAudio: $long, billingRounding: $round);
    }

    private static function mp3(int $ms, int $bitrate = 64000): AudioInfo
    {
        return new AudioInfo('mp3', intdiv($ms * $bitrate, 8000), $ms, true, 'mp3', 44100, 2, $bitrate);
    }

    /** T20: an operator limit above the adapter's limit is clamped. */
    public function testOperatorLimitIsClamped(): void
    {
        $c = self::caps()->clamped(100_000_000, 99_999);
        self::assertSame(25_000_000, $c->maxBytes);
        self::assertSame(1200, $c->maxSeconds);
        $lower = self::caps()->clamped(10_000_000, 600);
        self::assertSame(10_000_000, $lower->maxBytes);
        self::assertSame(600, $lower->maxSeconds);
    }

    /** T21: a short file is one chunk with no overlap. */
    public function testShortFileIsOneChunk(): void
    {
        $plan = (new ChunkPlanner())->plan(self::mp3(300_000), self::caps(), 600, 10, true);
        self::assertSame(1, $plan->count());
        self::assertSame(0, $plan->chunks[0]->overlapBeforeMs + $plan->chunks[0]->overlapAfterMs);
        self::assertSame(300_000, $plan->chunks[0]->lengthMs());
    }

    /** T23: a 61-minute file with 10-minute chunks never ends on a tail under 30 s. */
    public function testNoShortTail(): void
    {
        foreach ([61 * 60_000, 60 * 60_000 + 20_000, 60 * 60_000 + 1_000, 59 * 60_000 + 59_000] as $ms) {
            $plan = (new ChunkPlanner())->plan(self::mp3($ms), self::caps(), 600, 10, true);
            $last = $plan->chunks[$plan->count() - 1];
            self::assertGreaterThanOrEqual(30_000, $last->lengthMs(), "duration $ms");
            self::assertSame($ms, $last->endMs);
            foreach ($plan->chunks as $c) {
                self::assertLessThanOrEqual((int) (1200 * 1000 * 0.95), $c->lengthMs());
            }
        }
        // A tail that cannot be merged is balanced with the chunk before it.
        $tight = (new ChunkPlanner())->plan(self::mp3(2 * 600_000 + 10_000), self::caps(25_000_000, 640), 600, 10, true);
        $n = $tight->count();
        self::assertGreaterThanOrEqual(30_000, $tight->chunks[$n - 1]->lengthMs());
    }

    /** T24: overlap seconds appear in billed seconds. */
    public function testOverlapIsBilled(): void
    {
        $info = self::mp3(1_500_000);
        $plan = (new ChunkPlanner())->plan($info, self::caps(), 600, 10, true);
        self::assertSame(3, $plan->count());
        self::assertSame(10_000, $plan->chunks[1]->overlapBeforeMs);
        $est = $this->estimate(['plan' => $plan, 'caps' => self::caps(), 'speech_ms' => 1_500_000]);
        self::assertSame(1500 + 20, $est->billedSeconds);
    }

    /** T25: an oversize format that cannot be split fails with no call. */
    public function testOversizeUnchunkableFails(): void
    {
        $flac = new AudioInfo('flac', 300_000_000, 3_600_000, false);
        try {
            (new ChunkPlanner())->plan($flac, self::caps(), 600, 10, false);
            self::fail('expected cannot_split_format');
        } catch (PlanException $e) {
            self::assertSame('cannot_split_format', $e->errorCode());
            self::assertStringContainsString('Convert it to MP3 or WAV', $e->getMessage());
        }
    }

    /** T26: a long-audio adapter gets one chunk. */
    public function testLongAudioAdapterGetsOneChunk(): void
    {
        $plan = (new ChunkPlanner())->plan(new AudioInfo('flac', 300_000_000, 3_600_000, false), self::caps(25_000_000, 1200, true), 600, 10, false);
        self::assertSame(1, $plan->count());
        self::assertTrue($plan->native);
    }

    public function testBytesLimitShortensChunks(): void
    {
        // 25 MB at 320 kbit/s is about 625 s; with the 5 % margin chunks stay under 594 s.
        $plan = (new ChunkPlanner())->plan(self::mp3(3_600_000, 320_000), self::caps(25_000_000, 1500), 900, 10, true);
        foreach ($plan->chunks as $c) {
            self::assertLessThanOrEqual(593_750, $c->lengthMs());
        }
    }

    /** T28: billed seconds include per-chunk rounding to the minute when declared. */
    public function testPerChunkRounding(): void
    {
        $plan = new ChunkPlan('mp3', ChunkPlanner::chunksFrom([0, 590_000], 1_000_000, 10_000));
        $est = $this->estimate(['plan' => $plan, 'caps' => self::caps(25_000_000, 1200, false, 'minute'), 'speech_ms' => 1_000_000]);
        // 600 s (10 min) and 410 s (rounded to 7 min = 420 s).
        self::assertSame(600 + 420, $est->billedSeconds);
        $min = new AdapterCapabilities(1, 1, ['mp3'], billingRounding: 'second', minimumBillableSeconds: 15);
        self::assertSame(15, $min->billedSeconds(3_200));
    }

    /** T16 and T28: unknown duration gives an upper bound from size. */
    public function testUpperBoundFromSize(): void
    {
        $info = new AudioInfo('mp3', 4_000_000, null, false);
        self::assertSame(1_000_000, ChunkPlanner::planningDuration($info));
        $plan = (new ChunkPlanner())->plan($info, self::caps(), 600, 10, true);
        self::assertSame(1_000_000, $plan->chunks[0]->endMs);
    }

    /** T29: the token estimate follows the formula; two-pass adds both passes. */
    public function testTokenFormula(): void
    {
        $t = EstimateCalculator::draftTokens(self::input(['speech_ms' => 600_000, 'mode' => 'article', 'prompt_chars' => 4000]));
        // 10 min x 150 wpm = 1500 words x 1.3 = 1950 tokens, + 1000 overhead, + 10 %.
        self::assertSame((int) ceil((1950 + 1000) * 1.1), $t['in']);
        self::assertSame(2000, $t['out']);
        self::assertSame(1, $t['passes']);
        $long = EstimateCalculator::draftTokens(self::input(['speech_ms' => 4 * 3_600_000, 'mode' => 'article', 'single_pass_max_tokens' => 10_000]));
        self::assertGreaterThan(2, $long['passes']);
        $blocks = $long['passes'] - 1;
        self::assertSame(24, $blocks);
        self::assertSame($blocks * 600 + 2000, $long['out']);
        self::assertGreaterThan($long['transcript_tokens'], $long['in']);
        self::assertSame(0, EstimateCalculator::draftTokens(self::input(['mode' => 'transcript']))['in']);
    }

    public function testRatiosByScript(): void
    {
        self::assertSame(1.3, TokenRatios::ratio('en'));
        self::assertSame(1.3, TokenRatios::ratio('auto'));
        self::assertSame(2.5, TokenRatios::ratio('hi-IN'));
        self::assertSame(2.5, TokenRatios::ratio('ur'));
        self::assertSame(1.0, TokenRatios::ratio('ja'));
        self::assertSame(1.9, TokenRatios::ratio('ta', ['ta' => 1.9]));
        self::assertSame(1.6, TokenRatios::ratio('fr', ['latin' => 1.6]));
        self::assertSame(TokenRatios::INDIC, TokenRatios::scriptOfText('यह एक परीक्षण है'));
        $cjk = EstimateCalculator::draftTokens(self::input(['speech_ms' => 60_000, 'mode' => 'article', 'language' => 'zh', 'prompt_chars' => 0]));
        self::assertSame(250, $cjk['units']);
    }

    /** T30: no prices, partial prices and full prices. */
    public function testPriceDisplay(): void
    {
        $plan = new ChunkPlan('mp3', ChunkPlanner::chunksFrom([0], 600_000, 0));
        $none = $this->estimate(['plan' => $plan, 'caps' => self::caps(), 'speech_ms' => 600_000, 'mode' => 'article']);
        self::assertNull($none->price);
        self::assertSame('price not configured', $none->priceLine());
        $partial = $this->estimate(['plan' => $plan, 'caps' => self::caps(), 'speech_ms' => 600_000, 'mode' => 'article', 'price_per_minute' => '0.01']);
        self::assertNull($partial->price);
        $full = $this->estimate(['plan' => $plan, 'caps' => self::caps(), 'speech_ms' => 600_000, 'mode' => 'article', 'price_per_minute' => '0.01', 'price_input' => '1', 'price_output' => '2', 'currency' => 'EUR']);
        self::assertNotNull($full->price);
        self::assertSame('0.1000', $full->price['transcription']);
        self::assertStringContainsString('estimate, not a quote', $full->priceLine());
        self::assertStringContainsString('EUR', $full->priceLine());
        $transcriptOnly = $this->estimate(['plan' => $plan, 'caps' => self::caps(), 'speech_ms' => 600_000, 'mode' => 'none', 'price_per_minute' => '0.01']);
        self::assertSame('0.1000', $transcriptOnly->price['total'] ?? null);
    }

    public function testMoney(): void
    {
        self::assertSame(1_500_000, Money::parse('1.5'));
        self::assertNull(Money::parse('1,5'));
        self::assertNull(Money::parse(''));
        self::assertSame('0.0060', Money::format(6000));
        self::assertSame(3000, Money::times(6000, 30, 60));
    }

    public function testFingerprintChangesWithPrice(): void
    {
        $plan = new ChunkPlan('mp3', ChunkPlanner::chunksFrom([0], 600_000, 0));
        $a = $this->estimate(['plan' => $plan, 'caps' => self::caps(), 'speech_ms' => 600_000, 'price_per_minute' => '0.01']);
        $b = $this->estimate(['plan' => $plan, 'caps' => self::caps(), 'speech_ms' => 600_000, 'price_per_minute' => '0.02']);
        $c = $this->estimate(['plan' => $plan, 'caps' => self::caps(), 'speech_ms' => 600_000, 'price_per_minute' => '0.01']);
        self::assertNotSame($a->fingerprint, $b->fingerprint);
        self::assertSame($a->fingerprint, $c->fingerprint);
    }

    /** @param array<string, mixed> $over */
    private static function input(array $over): array
    {
        return $over + [
            'plan' => null,
            'caps' => null,
            'speech_ms' => 0,
            'duration_exact' => true,
            'upper_bound' => false,
            'mode' => 'none',
            'language' => 'en',
            'known_units' => null,
            'words_per_minute' => 150,
            'characters_per_minute' => 250,
            'ratio_overrides' => [],
            'prompt_chars' => 2000,
            'notes_prompt_chars' => 2000,
            'max_output_tokens' => 2000,
            'notes_max_output_tokens' => 600,
            'single_pass_max_tokens' => 60_000,
            'block_words' => 1500,
            'price_per_minute' => null,
            'price_input' => null,
            'price_output' => null,
            'currency' => '',
            'free_transcription' => false,
            'fingerprint_material' => [],
        ];
    }

    /** @param array<string, mixed> $over */
    private function estimate(array $over): \IndianOpinion\AudioToPost\Estimate
    {
        return (new EstimateCalculator())->estimate(self::input($over));
    }
}
