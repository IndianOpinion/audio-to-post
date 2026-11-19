<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Unit;

use IndianOpinion\AudioToPost\Adapter\ChunkRequest;
use IndianOpinion\AudioToPost\Adapter\ChunkResult;
use IndianOpinion\AudioToPost\Adapter\FakeAdapter;
use IndianOpinion\AudioToPost\Chunk;
use IndianOpinion\AudioToPost\Plan\ChunkPlanner;
use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Stitcher;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\Transcript\Turns;
use PHPUnit\Framework\TestCase;

final class StitcherTest extends TestCase
{
    /**
     * @param list<int> $starts
     *
     * @return array{0: list<Chunk>, 1: array<int, ChunkResult>}
     */
    private static function hear(FakeAdapter $fake, array $starts, int $duration, int $overlap = 10_000): array
    {
        $chunks = ChunkPlanner::chunksFrom($starts, $duration, $overlap);
        $results = [];
        foreach ($chunks as $c) {
            $results[$c->index] = $fake->transcribe(new ChunkRequest('/dev/null', 'mp3', $c->startMs, $c->lengthMs(), null, 'r', $c->index));
        }

        return [$chunks, $results];
    }

    /** @return list<string> */
    private static function words(Transcript $t): array
    {
        $out = [];
        foreach ($t->segments as $s) {
            foreach (preg_split('/\s+/', trim($s->text)) ?: [] as $w) {
                $out[] = $w;
            }
        }

        return $out;
    }

    /** T44: labels permuted per chunk are mapped back to consistent speakers. */
    public function testSpeakersAreConsistentAcrossChunks(): void
    {
        $fake = new FakeAdapter(turnWords: 20, speakers: 2);
        [$chunks, $results] = self::hear($fake, [0, 60_000, 120_000], 180_000);
        self::assertNotSame($results[0]->segments[0]->speaker, $results[1]->segments[0]->speaker === $results[0]->segments[0]->speaker ? 'x' : $results[0]->segments[0]->speaker);
        $out = (new Stitcher())->stitch($chunks, $results, 180_000);
        $t = $out['transcript'];
        self::assertCount(2, $t->speakers);
        foreach ($t->segments as $s) {
            $first = (int) substr(explode(' ', $s->text)[0], 1);
            $expected = intdiv($first - 1, 20) % 2 === 0 ? 'S1' : 'S2';
            self::assertSame($expected, $s->speaker, $s->id . ' ' . $s->text);
        }
        foreach ($out['mappings'] as $m) {
            self::assertFalse($m['low']);
        }
    }

    /** T44: a mapping under 0.5 confidence is flagged. */
    public function testWeakMappingIsFlagged(): void
    {
        $chunks = [new Chunk(0, 0, 70_000), new Chunk(1, 60_000, 120_000)];
        $seg = static fn (int $s, int $e, string $spk, string $text, int $chunk): Segment => new Segment('', $s, $e, $spk, $text, [], null, null, $chunk);
        $results = [
            // Chunk 0 hears A only for the first 3 s of the overlap ...
            0 => new ChunkResult([$seg(0, 63_000, 'A', 'one two three four five six', 0)]),
            // ... while chunk 1 hears X over the whole overlap.
            1 => new ChunkResult([$seg(0, 10_000, 'X', 'four five six', 1), $seg(10_000, 60_000, 'X', 'seven eight nine ten', 1)]),
        ];
        $out = (new Stitcher())->stitch($chunks, $results, 120_000);
        $low = array_values(array_filter($out['mappings'], static fn (array $m): bool => $m['low']));
        self::assertNotEmpty($low);
        $flagged = array_filter($out['transcript']->segments, static fn (Segment $s): bool => $s->hasFlag(Segment::FLAG_LOW_CONFIDENCE_SPEAKER));
        self::assertNotEmpty($flagged);
    }

    /** T45: segment times from chunk 3 are shifted by its start. */
    public function testTimesAreGlobal(): void
    {
        $fake = new FakeAdapter();
        [$chunks, $results] = self::hear($fake, [0, 60_000, 120_000, 180_000], 240_000);
        $local = $results[3]->segments[count($results[3]->segments) - 1];
        $out = (new Stitcher())->stitch($chunks, $results, 240_000)['transcript'];
        $found = null;
        foreach ($out->segments as $s) {
            if ($s->text === $local->text) {
                $found = $s;
            }
        }
        self::assertNotNull($found);
        self::assertSame($local->startMs + 180_000, $found->startMs);
        self::assertSame($local->words[0]->startMs + 180_000, $found->words[0]->startMs);
        $prev = -1;
        foreach ($out->segments as $s) {
            self::assertGreaterThanOrEqual($prev, $s->startMs);
            self::assertLessThanOrEqual(240_000, $s->endMs);
            $prev = $s->startMs;
        }
    }

    /** T46: with word times there are no duplicated or missing words at the seams. */
    public function testSeamWithWordTimes(): void
    {
        $fake = new FakeAdapter(wordEveryMs: 400, wordLengthMs: 300);
        [$chunks, $results] = self::hear($fake, [0, 61_100, 122_300], 200_000);
        $t = (new Stitcher())->stitch($chunks, $results, 200_000)['transcript'];
        self::assertSame($fake->scriptTexts(200_000), self::words($t));
    }

    /** T46: a seam inside one long segment joins on a common run of words. */
    public function testSeamInsideOneLongSegmentJoinsOnCommonRun(): void
    {
        $chunks = [new Chunk(0, 0, 70_000), new Chunk(1, 60_000, 130_000)];
        $results = [
            0 => new ChunkResult([new Segment('', 0, 70_000, 'A', 'alpha beta gamma delta, epsilon zeta eta theta')]),
            1 => new ChunkResult([new Segment('', 0, 70_000, 'A', 'Delta epsilon zeta eta theta iota kappa')]),
        ];
        $t = (new Stitcher())->stitch($chunks, $results, 130_000)['transcript'];
        self::assertCount(1, $t->segments);
        self::assertSame('alpha beta gamma delta, epsilon zeta eta theta iota kappa', $t->segments[0]->text);
        self::assertSame(130_000, $t->segments[0]->endMs);
    }

    /** T46: without a common run both texts are kept with a seam marker. */
    public function testSeamWithoutCommonRunKeepsBoth(): void
    {
        $chunks = [new Chunk(0, 0, 70_000), new Chunk(1, 60_000, 130_000)];
        $results = [
            0 => new ChunkResult([new Segment('', 0, 70_000, 'A', 'alpha beta gamma')]),
            1 => new ChunkResult([new Segment('', 0, 70_000, 'A', 'one two three four')]),
        ];
        $out = (new Stitcher())->stitch($chunks, $results, 130_000);
        self::assertSame(1, $out['seams']);
        self::assertCount(2, $out['transcript']->segments);
        foreach ($out['transcript']->segments as $s) {
            self::assertTrue($s->hasFlag(Segment::FLAG_SEAM));
        }
    }

    /** T47: a text-only adapter gives chunk-level segments and the coarse flag. */
    public function testTextOnlyIsCoarse(): void
    {
        $fake = new FakeAdapter(timing: 'none');
        [$chunks, $results] = self::hear($fake, [0, 60_000], 100_000);
        $t = (new Stitcher())->stitch($chunks, $results, 100_000)['transcript'];
        self::assertSame(Transcript::TIMESTAMPS_COARSE, $t->timestamps);
        self::assertSame(0, $t->segments[0]->startMs);
        self::assertSame($fake->scriptTexts(100_000), self::words($t));
    }

    /** T48: a non-diarising adapter gives one speaker and the none flag. */
    public function testNoSpeakers(): void
    {
        $fake = new FakeAdapter(diarise: false);
        [$chunks, $results] = self::hear($fake, [0, 60_000], 100_000);
        $t = (new Stitcher())->stitch($chunks, $results, 100_000)['transcript'];
        self::assertSame(Transcript::SPEAKERS_NONE, $t->speakersFlag);
        self::assertCount(1, $t->speakers);
        self::assertSame('Speaker 1', $t->speakers[0]->label);
        foreach ($t->segments as $s) {
            self::assertSame('S1', $s->speaker);
        }
    }

    /** T49: stitched segments carry every field. */
    public function testSegmentShape(): void
    {
        $fake = new FakeAdapter();
        [$chunks, $results] = self::hear($fake, [0], 30_000, 0);
        $t = (new Stitcher())->stitch($chunks, $results, 30_000)['transcript'];
        $a = $t->segments[0]->toArray();
        self::assertSame('s0001', $a['id']);
        foreach (['start_ms', 'end_ms', 'speaker', 'text', 'words', 'confidence', 'language', 'chunk', 'flags'] as $k) {
            self::assertArrayHasKey($k, $a);
        }
        self::assertSame(['start_ms', 'end_ms', 'text', 'confidence'], array_keys($a['words'][0] + ['confidence' => null]));
        self::assertSame('en', $t->language);
    }

    /** T50: a gap over 4 s starts a paragraph, not a turn. */
    public function testTurnsAndParagraphs(): void
    {
        $t = new Transcript(60_000, 'en', 'fine', 'diarised', [], [
            new Segment('s0001', 0, 2000, 'S1', 'a'),
            new Segment('s0002', 2500, 4000, 'S1', 'b'),
            new Segment('s0003', 9000, 10_000, 'S1', 'c'),
            new Segment('s0004', 10_500, 11_000, 'S2', 'd'),
        ]);
        $turns = Turns::build($t, 4000);
        self::assertCount(2, $turns);
        self::assertCount(2, $turns[0]['paragraphs']);
        self::assertCount(2, $turns[0]['paragraphs'][0]);
    }

    /** T34 (pure part): all-empty chunks give an empty transcript. */
    public function testEmptyResults(): void
    {
        $fake = new FakeAdapter(empty: true);
        [$chunks, $results] = self::hear($fake, [0, 60_000], 100_000);
        self::assertTrue((new Stitcher())->stitch($chunks, $results, 100_000)['transcript']->isEmpty());
    }
}
