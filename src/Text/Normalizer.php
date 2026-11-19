<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Text;

/**
 * Text normalisation for quote checks and seam joins (cases 46 and 66):
 * Unicode NFC, case folding, curly and straight quotes unified,
 * punctuation removed, whitespace collapsed.
 *
 * NFC needs ext-intl. Without it the text is compared as given, which is
 * still correct for text that is already NFC (most provider output is).
 */
final class Normalizer
{
    public static function normalise(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($n)) {
                $text = $n;
            }
        }
        $text = mb_convert_case($text, MB_CASE_FOLD, 'UTF-8');
        $text = strtr($text, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
            "\u{00AB}" => '"', "\u{00BB}" => '"', "\u{2032}" => "'", "\u{2033}" => '"',
        ]);
        // Remove punctuation and symbols but keep letters, combining marks
        // (Indic vowel signs and viramas are \p{M}) and digits.
        $text = (string) preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        $n = self::normalise($text);

        return $n === '' ? [] : explode(' ', $n);
    }

    /**
     * Count words, or characters for scripts written without spaces.
     */
    public static function countUnits(string $text, bool $characters = false): int
    {
        if ($characters) {
            return (int) preg_match_all('/[\p{L}\p{N}]/u', $text);
        }

        return count(self::words($text));
    }
}
