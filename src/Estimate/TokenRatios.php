<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Estimate;

/**
 * Default tokens-per-unit ratios by script. These are estimates for the
 * pre-run figure only; the operator tunes them from the estimate-against-
 * actual view on the run screen.
 *
 * - Latin script: 1.3 tokens per word.
 * - Indic and Arabic scripts: 2.5 tokens per word.
 * - Chinese, Japanese and Korean: 1.0 token per character (the unit is a
 *   character, not a word).
 */
final class TokenRatios
{
    public const LATIN = 'latin';
    public const INDIC = 'indic';
    public const ARABIC = 'arabic';
    public const CJK = 'cjk';

    /** @var array<string, float> */
    public const DEFAULTS = [self::LATIN => 1.3, self::INDIC => 2.5, self::ARABIC => 2.5, self::CJK => 1.0];

    private const INDIC_LANGS = ['hi', 'bn', 'ta', 'te', 'mr', 'gu', 'kn', 'ml', 'pa', 'or', 'as', 'ne', 'si', 'sa', 'kok', 'mai', 'doi', 'brx', 'mni', 'sat'];
    private const ARABIC_LANGS = ['ar', 'ur', 'fa', 'ps', 'sd', 'ug', 'ks', 'ckb'];
    private const CJK_LANGS = ['zh', 'ja', 'ko', 'yue'];

    /** Script family for a BCP 47 tag; Latin for `auto` and anything unknown. */
    public static function scriptFor(string $language): string
    {
        $primary = strtolower(explode('-', str_replace('_', '-', trim($language)))[0]);
        if (in_array($primary, self::INDIC_LANGS, true)) {
            return self::INDIC;
        }
        if (in_array($primary, self::ARABIC_LANGS, true)) {
            return self::ARABIC;
        }
        if (in_array($primary, self::CJK_LANGS, true)) {
            return self::CJK;
        }

        return self::LATIN;
    }

    /** Script family of a text sample, by its most common letters. */
    public static function scriptOfText(string $text): string
    {
        $sample = mb_substr($text, 0, 4000);
        $counts = [
            self::INDIC => preg_match_all('/[\x{0900}-\x{0DFF}]/u', $sample),
            self::ARABIC => preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $sample),
            self::CJK => preg_match_all('/[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $sample),
            self::LATIN => preg_match_all('/[A-Za-z\x{00C0}-\x{024F}]/u', $sample),
        ];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param array<string, float> $overrides per language tag or per script family
     */
    public static function ratio(string $language, array $overrides = [], ?string $script = null): float
    {
        $tag = strtolower(trim($language));
        if (isset($overrides[$tag]) && $overrides[$tag] > 0) {
            return $overrides[$tag];
        }
        $primary = explode('-', $tag)[0];
        if (isset($overrides[$primary]) && $overrides[$primary] > 0) {
            return $overrides[$primary];
        }
        $script ??= self::scriptFor($language);
        if (isset($overrides[$script]) && $overrides[$script] > 0) {
            return $overrides[$script];
        }

        return self::DEFAULTS[$script];
    }
}
