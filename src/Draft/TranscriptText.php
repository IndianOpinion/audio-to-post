<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Draft;

use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Text\Normalizer;
use IndianOpinion\AudioToPost\Transcript;

/**
 * Renders a transcript for a prompt: one line per segment, segment id
 * then speaker label. No times are given to the model (lesson 4).
 */
final class TranscriptText
{
    /**
     * @param list<Segment>|null $segments defaults to the whole transcript
     */
    public static function lines(Transcript $t, ?array $segments = null): string
    {
        $out = [];
        foreach ($segments ?? $t->segments as $s) {
            // One line per segment: text that holds line breaks cannot
            // forge another segment id or speaker line in the prompt.
            $out[] = '[' . $s->id . '] ' . self::oneLine($t->speakerDisplay($s->speaker)) . ': ' . self::oneLine($s->text);
        }

        return implode("\n", $out);
    }

    /**
     * Blocks of about $words words, cut on segment boundaries (ruling on
     * open question 5: blocks of about 1500 words).
     *
     * @return list<list<Segment>>
     */
    public static function blocks(Transcript $t, int $words = 1500, bool $characters = false): array
    {
        $blocks = [];
        $cur = [];
        $count = 0;
        foreach ($t->segments as $s) {
            $n = Normalizer::countUnits($s->text, $characters);
            if ($cur !== [] && $count + $n > $words) {
                $blocks[] = $cur;
                $cur = [];
                $count = 0;
            }
            $cur[] = $s;
            $count += $n;
        }
        if ($cur !== []) {
            $blocks[] = $cur;
        }

        return $blocks;
    }

    public static function units(Transcript $t, bool $characters = false): int
    {
        $n = 0;
        foreach ($t->segments as $s) {
            $n += Normalizer::countUnits($s->text, $characters);
        }

        return $n;
    }

    public static function speakerList(Transcript $t): string
    {
        return implode(', ', array_map(static fn ($s): string => $s->display(), $t->speakers));
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\r\n\x{2028}\x{2029}]+/u', ' ', $text));
    }
}
