<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

use IndianOpinion\AudioToPost\Adapter\ChunkResult;
use IndianOpinion\AudioToPost\Text\Normalizer;

/**
 * Joins chunk results into one transcript (cases 44 to 49).
 *
 * - Times are shifted to the recording and clamped to its duration.
 * - Speaker labels, which restart in every request, are mapped across
 *   each overlap by shared speaking time, greedily and one to one.
 * - Inside each overlap a cut point near the middle is moved to the
 *   nearest gap between words (or segments); text before it comes from
 *   the earlier chunk and after it from the later one. When one long
 *   segment spans the overlap, the two texts are joined on their longest
 *   common run of words; without a run of three words both are kept and
 *   flagged as a seam. No word is dropped silently.
 */
final class Stitcher
{
    public function __construct(
        private readonly int $minSharedMs = 1000,
        private readonly float $lowConfidence = 0.5,
        private readonly int $minCommonRun = 3,
    ) {
    }

    /**
     * @param list<Chunk>             $chunks
     * @param array<int, ChunkResult> $results by chunk index
     *
     * @return array{transcript: Transcript, mappings: list<array{chunk: int, label: string, speaker: string, confidence: float, low: bool}>, seams: int}
     */
    public function stitch(array $chunks, array $results, int $durationMs): array
    {
        $coarse = false;
        $diarised = false;
        /** @var list<list<Segment>> $perChunk */
        $perChunk = [];
        foreach ($chunks as $chunk) {
            $res = $results[$chunk->index] ?? new ChunkResult([]);
            $segs = [];
            if (!$res->timed || $res->segments === []) {
                if (trim($res->text) !== '') {
                    $coarse = $coarse || !$res->timed || $res->segments === [];
                    $segs[] = new Segment('', $chunk->startMs, min($durationMs, $chunk->endMs), '', trim($res->text), [], null, $res->language, $chunk->index);
                }
            } else {
                foreach ($res->segments as $s) {
                    if (trim($s->text) === '') {
                        continue;
                    }
                    $words = array_map(static fn (Word $w): Word => $w->shifted($chunk->startMs, $durationMs), $s->words);
                    $segs[] = $s->with([
                        'startMs' => min($durationMs, $s->startMs + $chunk->startMs),
                        'endMs' => min($durationMs, $s->endMs + $chunk->startMs),
                        'words' => $words,
                        'chunk' => $chunk->index,
                        'language' => $s->language ?? $res->language,
                    ]);
                }
            }
            foreach ($segs as $s) {
                if ($s->speaker !== '') {
                    $diarised = true;
                }
            }
            usort($segs, static fn (Segment $a, Segment $b): int => $a->startMs <=> $b->startMs);
            $perChunk[] = $segs;
        }

        // Speaker mapping: rewrite labels to provisional global keys "g<n>".
        $mappings = [];
        $next = 0;
        foreach ($chunks as $k => $chunk) {
            $segs = $perChunk[$k];
            $labels = [];
            foreach ($segs as $s) {
                $labels[$s->speaker] = true;
            }
            $map = [];
            $conf = [];
            if ($k > 0 && $diarised) {
                $ws = $chunk->startMs;
                $we = $chunks[$k - 1]->endMs;
                $shared = [];
                $speech = [];
                foreach ($segs as $b) {
                    $speech[$b->speaker] = ($speech[$b->speaker] ?? 0) + self::overlap($b->startMs, $b->endMs, $ws, $we);
                    foreach ($perChunk[$k - 1] as $a) {
                        $ms = self::overlap(max($a->startMs, $b->startMs), min($a->endMs, $b->endMs), $ws, $we);
                        if ($ms > 0) {
                            $key = $a->speaker . "\0" . $b->speaker;
                            $shared[$key] = ($shared[$key] ?? 0) + $ms;
                        }
                    }
                }
                arsort($shared);
                $usedA = [];
                foreach ($shared as $pair => $ms) {
                    [$a, $b] = explode("\0", (string) $pair, 2);
                    // Segments of chunk k-1 already carry global keys.
                    if ($ms <= $this->minSharedMs || isset($usedA[$a]) || isset($map[$b])) {
                        continue;
                    }
                    $usedA[$a] = true;
                    $map[$b] = $a;
                    $conf[$b] = ($speech[$b] ?? 0) > 0 ? $ms / $speech[$b] : 0.0;
                }
            }
            foreach (array_keys($labels) as $label) {
                $label = (string) $label;
                if (!isset($map[$label])) {
                    $map[$label] = 'g' . $next++;
                }
            }
            foreach ($segs as $i => $s) {
                $low = isset($conf[$s->speaker]) && $conf[$s->speaker] < $this->lowConfidence;
                $seg = $s->with(['speaker' => $map[$s->speaker]]);
                $segs[$i] = $low ? $seg->withFlag(Segment::FLAG_LOW_CONFIDENCE_SPEAKER) : $seg;
            }
            foreach ($conf as $label => $c) {
                $mappings[] = ['chunk' => $chunk->index, 'label' => (string) $label, 'speaker' => $map[(string) $label], 'confidence' => round($c, 3), 'low' => $c < $this->lowConfidence];
            }
            $perChunk[$k] = $segs;
        }

        // Seams.
        $seams = 0;
        for ($k = 1, $n = count($chunks); $k < $n; $k++) {
            $ws = $chunks[$k]->startMs;
            $we = $chunks[$k - 1]->endMs;
            if ($we <= $ws) {
                continue;
            }
            [$perChunk[$k - 1], $perChunk[$k], $seam] = $this->seam($perChunk[$k - 1], $perChunk[$k], $ws, $we);
            $seams += $seam ? 1 : 0;
        }

        $all = [];
        foreach ($perChunk as $segs) {
            foreach ($segs as $s) {
                $all[] = $s;
            }
        }
        usort($all, static fn (Segment $a, Segment $b): int => $a->startMs <=> $b->startMs);

        // Global speakers in order of first speech.
        $order = [];
        foreach ($all as $s) {
            if (!isset($order[$s->speaker])) {
                $order[$s->speaker] = 'S' . (count($order) + 1);
            }
        }
        if (!$diarised) {
            $order = array_fill_keys(array_keys($order), 'S1');
        }
        $speakers = [];
        foreach (array_unique(array_values($order)) as $id) {
            $speakers[] = new Speaker($id, 'Speaker ' . substr($id, 1));
        }
        $langs = [];
        $out = [];
        foreach ($all as $i => $s) {
            $out[] = $s->with(['id' => Segment::idFor($i + 1), 'speaker' => $order[$s->speaker]]);
            if ($s->language !== null && $s->language !== '') {
                $langs[$s->language] = ($langs[$s->language] ?? 0) + 1;
            }
        }
        foreach ($mappings as $i => $m) {
            $mappings[$i]['speaker'] = $order[$m['speaker']] ?? $m['speaker'];
        }
        arsort($langs);
        $language = $langs === [] ? '' : (string) array_key_first($langs);

        $transcript = new Transcript(
            $durationMs,
            $language,
            $coarse ? Transcript::TIMESTAMPS_COARSE : Transcript::TIMESTAMPS_FINE,
            $diarised ? Transcript::SPEAKERS_DIARISED : Transcript::SPEAKERS_NONE,
            $speakers,
            $out,
        );

        return ['transcript' => $transcript, 'mappings' => $mappings, 'seams' => $seams];
    }

    /**
     * @param list<Segment> $prev
     * @param list<Segment> $next
     *
     * @return array{0: list<Segment>, 1: list<Segment>, 2: bool}
     */
    private function seam(array $prev, array $next, int $ws, int $we): array
    {
        $mid = intdiv($ws + $we, 2);
        $cut = $this->wordCut($prev, $next, $ws, $we, $mid);
        if ($cut !== null) {
            return [self::keepWords($prev, static fn (int $t): bool => $t < $cut), self::keepWords($next, static fn (int $t): bool => $t >= $cut), false];
        }
        $prevBounds = [];
        foreach ($prev as $i => $s) {
            if ($s->words === [] && $s->endMs > $ws && $s->endMs < $we && isset($prev[$i + 1])) {
                $prevBounds[] = $s->endMs;
            }
        }
        $nextBounds = [];
        foreach ($next as $i => $s) {
            if ($s->words === [] && $s->startMs > $ws && $s->startMs < $we && $i > 0) {
                $nextBounds[] = $s->startMs;
            }
        }
        if ($prevBounds !== [] && $nextBounds !== []) {
            $cp = self::nearest($prevBounds, $mid);
            $cn = self::nearest($nextBounds, $cp);

            return [
                array_values(array_filter($prev, static fn (Segment $s): bool => $s->startMs < $cp)),
                array_values(array_filter($next, static fn (Segment $s): bool => $s->startMs >= $cn)),
                false,
            ];
        }

        return $this->textJoin($prev, $next, $ws, $we);
    }

    /**
     * Find a cut time in a gap between words near the middle of the overlap.
     *
     * @param list<Segment> $prev
     * @param list<Segment> $next
     */
    private function wordCut(array $prev, array $next, int $ws, int $we, int $mid): ?int
    {
        $collect = static function (array $segs) use ($ws, $we): array {
            $words = [];
            foreach ($segs as $s) {
                foreach ($s->words as $w) {
                    $words[] = $w;
                }
            }
            usort($words, static fn (Word $a, Word $b): int => $a->startMs <=> $b->startMs);
            $gaps = [];
            for ($i = 0, $n = count($words); $i + 1 < $n; $i++) {
                $g = intdiv($words[$i]->endMs + $words[$i + 1]->startMs, 2);
                if ($g >= $ws && $g <= $we && $words[$i + 1]->startMs >= $words[$i]->endMs) {
                    $gaps[] = $g;
                }
            }

            return [$words, $gaps];
        };
        [$pw, $pg] = $collect($prev);
        [$nw, $ng] = $collect($next);
        $inWindow = static fn (array $words): bool => array_filter($words, static fn (Word $w): bool => $w->endMs > $ws && $w->startMs < $we) !== [];
        if (!$inWindow($pw) && !$inWindow($nw)) {
            return null;
        }
        // Prefer gaps both chunks agree on, then the earlier chunk's gaps.
        $both = array_values(array_filter($pg, static function (int $g) use ($nw): bool {
            foreach ($nw as $w) {
                if ($w->startMs < $g && $w->endMs > $g) {
                    return false;
                }
            }

            return true;
        }));
        $pool = $both !== [] ? $both : ($pg !== [] ? $pg : $ng);

        return $pool === [] ? null : self::nearest($pool, $mid);
    }

    /**
     * Keep the words whose start passes the test and rebuild cut segments.
     *
     * @param list<Segment>        $segs
     * @param callable(int): bool  $keep
     *
     * @return list<Segment>
     */
    private static function keepWords(array $segs, callable $keep): array
    {
        $out = [];
        foreach ($segs as $s) {
            if ($s->words === []) {
                if ($keep($s->startMs)) {
                    $out[] = $s;
                }
                continue;
            }
            $kept = array_values(array_filter($s->words, static fn (Word $w): bool => $keep($w->startMs)));
            if ($kept === []) {
                continue;
            }
            if (count($kept) === count($s->words)) {
                $out[] = $s;
                continue;
            }
            $out[] = $s->with([
                'words' => $kept,
                'text' => implode(' ', array_map(static fn (Word $w): string => $w->text, $kept)),
                'startMs' => $kept[0]->startMs,
                'endMs' => $kept[count($kept) - 1]->endMs,
            ]);
        }

        return $out;
    }

    /**
     * @param list<Segment> $prev
     * @param list<Segment> $next
     *
     * @return array{0: list<Segment>, 1: list<Segment>, 2: bool}
     */
    private function textJoin(array $prev, array $next, int $ws, int $we): array
    {
        $pi = null;
        foreach ($prev as $i => $s) {
            if ($s->endMs > $ws && $s->startMs < $we) {
                $pi = $i;
            }
        }
        $ni = null;
        foreach ($next as $i => $s) {
            if ($s->endMs > $ws && $s->startMs < $we) {
                $ni = $i;
                break;
            }
        }
        if ($pi === null || $ni === null) {
            return [$prev, $next, false];
        }
        $p = $prev[$pi];
        $n = $next[$ni];
        $pt = self::tokens($p->text);
        $nt = self::tokens($n->text);
        // Only the tail of the earlier text and the head of the later one
        // can hold the overlap.
        $pOff = max(0, count($pt) - 400);
        $pt = array_slice($pt, $pOff);
        $nt = array_slice($nt, 0, 400);
        [$len, $pEnd, $nEnd] = self::longestRun($pt, $nt);
        if ($len < $this->minCommonRun) {
            $prev[$pi] = $p->withFlag(Segment::FLAG_SEAM);
            $next[$ni] = $n->withFlag(Segment::FLAG_SEAM);

            return [array_values($prev), array_values($next), true];
        }
        $pOrig = preg_split('/\s+/u', trim($p->text)) ?: [];
        $nOrig = preg_split('/\s+/u', trim($n->text)) ?: [];
        $keepP = array_slice($pOrig, 0, $pt[$pEnd]['i'] + 1);
        $keepN = array_slice($nOrig, $nt[$nEnd]['i'] + 1);
        $joined = trim(implode(' ', $keepP) . ' ' . implode(' ', $keepN));
        $prev[$pi] = $p->with(['text' => $joined, 'endMs' => max($p->endMs, $n->endMs), 'words' => []]);
        unset($next[$ni]);

        return [array_values($prev), array_values($next), false];
    }

    /**
     * @return list<array{w: string, i: int}>
     */
    private static function tokens(string $text): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $i => $tok) {
            $n = Normalizer::normalise($tok);
            if ($n !== '') {
                $out[] = ['w' => $n, 'i' => $i];
            }
        }

        return $out;
    }

    /**
     * Longest common contiguous run of tokens.
     *
     * @param list<array{w: string, i: int}> $a
     * @param list<array{w: string, i: int}> $b
     *
     * @return array{0: int, 1: int, 2: int} length, last index in $a, last index in $b
     */
    private static function longestRun(array $a, array $b): array
    {
        $best = [0, -1, -1];
        $prevRow = array_fill(0, count($b) + 1, 0);
        foreach ($a as $i => $ta) {
            $row = [0];
            foreach ($b as $j => $tb) {
                $v = $ta['w'] === $tb['w'] ? $prevRow[$j] + 1 : 0;
                $row[] = $v;
                if ($v > $best[0]) {
                    $best = [$v, $i, $j];
                }
            }
            $prevRow = $row;
        }

        return $best;
    }

    /** @param list<int> $points */
    private static function nearest(array $points, int $target): int
    {
        $best = $points[0];
        foreach ($points as $p) {
            if (abs($p - $target) < abs($best - $target)) {
                $best = $p;
            }
        }

        return $best;
    }

    private static function overlap(int $a, int $b, int $ws, int $we): int
    {
        return max(0, min($b, $we) - max($a, $ws));
    }
}
