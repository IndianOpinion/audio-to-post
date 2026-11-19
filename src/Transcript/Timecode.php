<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Transcript;

/**
 * Formats and parses time codes.
 */
final class Timecode
{
    /** `hh:mm:ss`, or `mm:ss` under one hour (case 69). */
    public static function short(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $sec) : sprintf('%02d:%02d', $m, $sec);
    }

    /** Always `hh:mm:ss`. */
    public static function hms(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);

        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }

    /** `hh:mm:ss.mmm` (WebVTT) or `hh:mm:ss,mmm` (SRT). */
    public static function full(int $ms, string $sep = '.'): string
    {
        $ms = max(0, $ms);

        return self::hms($ms) . $sep . sprintf('%03d', $ms % 1000);
    }

    /** Parse `[hh:]mm:ss[.,]mmm`; null when malformed. */
    public static function parse(string $s): ?int
    {
        if (preg_match('/^(?:(\d+):)?([0-5]?\d):([0-5]\d)[.,](\d{1,3})$/', trim($s), $m) !== 1) {
            return null;
        }
        $frac = (int) str_pad($m[4], 3, '0');

        return (((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3]) * 1000 + $frac;
    }
}
