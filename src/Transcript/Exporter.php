<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Transcript;

use IndianOpinion\AudioToPost\Transcript;

/**
 * Exports a transcript as JSON, WebVTT, SRT or plain text (case 78).
 */
final class Exporter
{
    public const FORMATS = ['json', 'vtt', 'srt', 'txt'];

    public static function export(Transcript $t, string $format, int $turnGapMs = 4000): string
    {
        return match ($format) {
            'json' => $t->toJson() . "\n",
            'vtt' => self::vtt($t),
            'srt' => self::srt($t),
            'txt' => self::txt($t, $turnGapMs),
            default => throw new \InvalidArgumentException('Unknown export format: ' . $format),
        };
    }

    public static function vtt(Transcript $t): string
    {
        $out = "WEBVTT\n\n";
        foreach ($t->segments as $s) {
            $name = str_replace(['>', "\n"], ['', ' '], $t->speakerDisplay($s->speaker));
            $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s->text);
            $out .= Timecode::full($s->startMs) . ' --> ' . Timecode::full($s->endMs) . "\n"
                . '<v ' . $name . '>' . $text . "\n\n";
        }

        return $out;
    }

    public static function srt(Transcript $t): string
    {
        $out = '';
        foreach ($t->segments as $i => $s) {
            $out .= ($i + 1) . "\n"
                . Timecode::full($s->startMs, ',') . ' --> ' . Timecode::full($s->endMs, ',') . "\n"
                . $t->speakerDisplay($s->speaker) . ': ' . $s->text . "\n\n";
        }

        return $out;
    }

    public static function txt(Transcript $t, int $turnGapMs = 4000): string
    {
        $out = [];
        foreach (Turns::build($t, $turnGapMs) as $turn) {
            $paras = [];
            foreach ($turn['paragraphs'] as $p) {
                $paras[] = implode(' ', array_map(static fn ($s): string => $s->text, $p));
            }
            $out[] = '[' . Timecode::hms($turn['start_ms']) . '] ' . $t->speakerDisplay($turn['speaker']) . ': ' . implode("\n", $paras);
        }

        return implode("\n\n", $out) . "\n";
    }

    public static function mime(string $format): string
    {
        return match ($format) {
            'json' => 'application/json',
            'vtt' => 'text/vtt',
            'srt' => 'application/x-subrip',
            default => 'text/plain',
        };
    }
}
