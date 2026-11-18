<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Transcript;

use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Transcript;

/**
 * Derives turns from segments (case 50). A turn is a run of consecutive
 * segments by one speaker; a gap longer than the turn gap inside a turn
 * starts a new paragraph, not a new turn.
 */
final class Turns
{
    /**
     * @return list<array{speaker: string, start_ms: int, end_ms: int, paragraphs: list<list<Segment>>}>
     */
    public static function build(Transcript $t, int $gapMs = 4000): array
    {
        $turns = [];
        $cur = null;
        $lastEnd = 0;
        foreach ($t->segments as $s) {
            if ($cur === null || $cur['speaker'] !== $s->speaker) {
                if ($cur !== null) {
                    $turns[] = $cur;
                }
                $cur = ['speaker' => $s->speaker, 'start_ms' => $s->startMs, 'end_ms' => $s->endMs, 'paragraphs' => [[$s]]];
            } else {
                if ($s->startMs - $lastEnd > $gapMs) {
                    $cur['paragraphs'][] = [$s];
                } else {
                    $cur['paragraphs'][count($cur['paragraphs']) - 1][] = $s;
                }
                $cur['end_ms'] = max($cur['end_ms'], $s->endMs);
            }
            $lastEnd = $s->endMs;
        }
        if ($cur !== null) {
            $turns[] = $cur;
        }

        return $turns;
    }
}
