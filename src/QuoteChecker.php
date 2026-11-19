<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

use IndianOpinion\AudioToPost\Text\Normalizer;

/**
 * Checks model quotes against the transcript (case 66).
 *
 * A quote passes when its normalised text appears in the normalised text
 * of the cited segment, or of that segment joined with its immediate
 * neighbours by the same speaker.
 */
final class QuoteChecker
{
    public const NOT_FOUND = 'quote not found in transcript';
    public const UNKNOWN_SEGMENT = 'quote cites an unknown segment';

    public function __construct(private readonly Transcript $transcript)
    {
    }

    /**
     * @return true|string true, or the reason the quote fails
     */
    public function check(string $quote, string $segmentId): bool|string
    {
        $i = $this->transcript->segmentIndex($segmentId);
        if ($i === null) {
            return self::UNKNOWN_SEGMENT;
        }
        $needle = Normalizer::normalise($quote);
        if ($needle === '') {
            return self::NOT_FOUND;
        }
        $segs = $this->transcript->segments;
        $seg = $segs[$i];
        $parts = [];
        if ($i > 0 && $segs[$i - 1]->speaker === $seg->speaker) {
            $parts[] = $segs[$i - 1]->text;
        }
        $parts[] = $seg->text;
        if (isset($segs[$i + 1]) && $segs[$i + 1]->speaker === $seg->speaker) {
            $parts[] = $segs[$i + 1]->text;
        }
        $hay = Normalizer::normalise(implode(' ', $parts));

        return str_contains(' ' . $hay . ' ', ' ' . $needle . ' ') ? true : self::NOT_FOUND;
    }
}
