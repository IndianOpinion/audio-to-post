<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Transcript;

use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Speaker;
use IndianOpinion\AudioToPost\StateException;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\Word;

/**
 * Editor operations on a transcript (case 77):
 *
 * - `rename_speaker`: speaker, name
 * - `merge_speakers`: from, into
 * - `reassign`: segment, speaker (a new id such as `S9` adds a speaker)
 * - `split`: segment, word_index (the new segment starts at that word)
 * - `text`: segment, text
 *
 * Segment ids are renumbered after a split.
 */
final class Editor
{
    /**
     * @param list<array<string, mixed>> $edits
     *
     * @throws StateException invalid_edit
     */
    public static function apply(Transcript $t, array $edits): Transcript
    {
        foreach ($edits as $e) {
            $op = is_string($e['op'] ?? null) ? $e['op'] : '';
            $t = match ($op) {
                'rename_speaker' => self::rename($t, self::s($e, 'speaker'), self::s($e, 'name')),
                'merge_speakers' => self::merge($t, self::s($e, 'from'), self::s($e, 'into')),
                'reassign' => self::reassign($t, self::s($e, 'segment'), self::s($e, 'speaker')),
                'split' => self::split($t, self::s($e, 'segment'), is_numeric($e['word_index'] ?? null) ? (int) $e['word_index'] : -1),
                'text' => self::text($t, self::s($e, 'segment'), self::s($e, 'text')),
                default => throw new StateException('invalid_edit', 'Unknown edit operation.'),
            };
        }

        return $t;
    }

    /** @param array<string, mixed> $e */
    private static function s(array $e, string $k): string
    {
        return is_string($e[$k] ?? null) ? trim($e[$k]) : '';
    }

    private static function rename(Transcript $t, string $id, string $name): Transcript
    {
        if ($t->speaker($id) === null) {
            throw new StateException('invalid_edit', 'Unknown speaker.');
        }
        $name = mb_substr(strip_tags($name), 0, 100);
        $speakers = array_map(static fn (Speaker $s): Speaker => $s->id === $id ? $s->withName($name) : $s, $t->speakers);

        return $t->withSpeakers($speakers);
    }

    private static function merge(Transcript $t, string $from, string $into): Transcript
    {
        if ($t->speaker($from) === null || $t->speaker($into) === null || $from === $into) {
            throw new StateException('invalid_edit', 'Pick two different speakers to merge.');
        }
        $segs = array_map(static fn (Segment $s): Segment => $s->speaker === $from ? $s->with(['speaker' => $into]) : $s, $t->segments);
        $speakers = array_values(array_filter($t->speakers, static fn (Speaker $s): bool => $s->id !== $from));

        return $t->withSegments($segs)->withSpeakers($speakers, Transcript::SPEAKERS_EDITED);
    }

    private static function reassign(Transcript $t, string $segment, string $speaker): Transcript
    {
        if ($t->segment($segment) === null || preg_match('/^S\d{1,4}$/', $speaker) !== 1) {
            throw new StateException('invalid_edit', 'Unknown segment or speaker.');
        }
        $speakers = $t->speakers;
        if ($t->speaker($speaker) === null) {
            $speakers[] = new Speaker($speaker, 'Speaker ' . substr($speaker, 1));
        }
        $segs = array_map(static fn (Segment $s): Segment => $s->id === $segment ? $s->with(['speaker' => $speaker]) : $s, $t->segments);

        return $t->withSegments($segs)->withSpeakers($speakers, Transcript::SPEAKERS_EDITED);
    }

    private static function split(Transcript $t, string $segment, int $at): Transcript
    {
        $i = $t->segmentIndex($segment);
        if ($i === null) {
            throw new StateException('invalid_edit', 'Unknown segment.');
        }
        $s = $t->segments[$i];
        $tokens = preg_split('/\s+/u', trim($s->text)) ?: [];
        if ($at <= 0 || $at >= count($tokens)) {
            throw new StateException('invalid_edit', 'Split at a word inside the segment.');
        }
        if (count($s->words) === count($tokens)) {
            $cut = $s->words[$at]->startMs;
            $w1 = array_slice($s->words, 0, $at);
            $w2 = array_slice($s->words, $at);
        } else {
            $cut = $s->startMs + (int) round($s->durationMs() * $at / count($tokens));
            $w1 = array_values(array_filter($s->words, static fn (Word $w): bool => $w->startMs < $cut));
            $w2 = array_values(array_filter($s->words, static fn (Word $w): bool => $w->startMs >= $cut));
        }
        $a = $s->with(['text' => implode(' ', array_slice($tokens, 0, $at)), 'endMs' => $cut, 'words' => $w1]);
        $b = $s->with(['text' => implode(' ', array_slice($tokens, $at)), 'startMs' => $cut, 'words' => $w2]);
        $segs = $t->segments;
        array_splice($segs, $i, 1, [$a, $b]);
        $renumbered = [];
        foreach ($segs as $n => $seg) {
            $renumbered[] = $seg->with(['id' => Segment::idFor($n + 1)]);
        }

        return $t->withSegments($renumbered);
    }

    private static function text(Transcript $t, string $segment, string $text): Transcript
    {
        if ($t->segment($segment) === null || $text === '') {
            throw new StateException('invalid_edit', 'Unknown segment or empty text.');
        }
        $text = strip_tags($text);
        $segs = array_map(static fn (Segment $s): Segment => $s->id === $segment ? $s->with(['text' => $text, 'words' => self::wordsMatch($s, $text) ? $s->words : []]) : $s, $t->segments);

        return $t->withSegments($segs);
    }

    private static function wordsMatch(Segment $s, string $text): bool
    {
        return count(preg_split('/\s+/u', trim($text)) ?: []) === count($s->words);
    }
}
