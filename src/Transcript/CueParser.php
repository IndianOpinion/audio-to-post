<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Transcript;

use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Speaker;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\TranscriptFormatException;

/**
 * Reads WebVTT (with or without `<v Name>` voice tags), SRT, and the
 * plugin's own JSON (case 79). Malformed cues are skipped and counted.
 * Files are parsed as text only.
 */
final class CueParser
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @return array{transcript: Transcript, skipped: int, format: string}
     *
     * @throws TranscriptFormatException
     */
    public static function parse(string $content, ?string $format = null): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new TranscriptFormatException('transcript_too_large', 'Transcript files are limited to 10 MB.');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $format ??= self::detect($content);
        if ($format === 'json') {
            $data = json_decode($content, true);
            if (is_array($data) && ($data['schema'] ?? null) !== Transcript::SCHEMA && is_array($data['segments'] ?? null)) {
                return self::podcastJson($data['segments']);
            }

            return ['transcript' => Transcript::fromJson($content), 'skipped' => 0, 'format' => 'json'];
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $blocks = preg_split('/\n{2,}/', trim($content)) ?: [];
        $cues = [];
        $skipped = 0;
        foreach ($blocks as $i => $block) {
            $lines = explode("\n", trim($block));
            if ($format === 'vtt' && $i === 0 && str_starts_with($lines[0], 'WEBVTT')) {
                continue;
            }
            if ($format === 'vtt' && preg_match('/^(NOTE|STYLE|REGION)\b/', $lines[0]) === 1) {
                continue;
            }
            $timeLine = null;
            foreach ($lines as $j => $line) {
                if (str_contains($line, '-->')) {
                    $timeLine = $j;
                    break;
                }
            }
            if ($timeLine === null) {
                $skipped++;
                continue;
            }
            $parts = explode('-->', $lines[$timeLine], 2);
            $start = Timecode::parse($parts[0]);
            $endRaw = preg_split('/\s+/', trim($parts[1] ?? '')) ?: [''];
            $end = Timecode::parse($endRaw[0]);
            $text = trim(implode("\n", array_slice($lines, $timeLine + 1)));
            if ($start === null || $end === null || $end < $start || $text === '') {
                $skipped++;
                continue;
            }
            $speaker = '';
            if ($format === 'vtt' && preg_match('/^<v(?:\.[^ >]+)?\s+([^>]+)>/u', $text, $m) === 1) {
                $speaker = trim($m[1]);
            }
            if ($format === 'vtt') {
                $text = html_entity_decode((string) preg_replace('/<[^>]*>/', '', $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (preg_match('/^(\p{Lu}[^:\n]{0,39}):\s+(.+)$/su', $text, $m) === 1) {
                $speaker = trim($m[1]);
                $text = $m[2];
            }
            $cues[] = ['start' => $start, 'end' => $end, 'speaker' => $speaker, 'text' => trim((string) preg_replace('/\s+/u', ' ', $text))];
        }
        if ($cues === []) {
            throw new TranscriptFormatException('transcript_unreadable', 'The file holds no readable cue.');
        }

        return ['transcript' => self::build($cues), 'skipped' => $skipped, 'format' => $format];
    }

    /**
     * The open Podcasting 2.0 JSON transcript shape: segments with
     * `startTime`, `endTime` (seconds), `body` and optional `speaker`.
     *
     * @param array<mixed> $segments
     *
     * @return array{transcript: Transcript, skipped: int, format: string}
     *
     * @throws TranscriptFormatException
     */
    private static function podcastJson(array $segments): array
    {
        $cues = [];
        $skipped = 0;
        foreach ($segments as $s) {
            if (!is_array($s) || !is_numeric($s['startTime'] ?? null) || !is_numeric($s['endTime'] ?? null) || !is_string($s['body'] ?? null) || trim($s['body']) === '') {
                $skipped++;
                continue;
            }
            $start = (int) round((float) $s['startTime'] * 1000);
            $end = (int) round((float) $s['endTime'] * 1000);
            if ($end < $start) {
                $skipped++;
                continue;
            }
            $cues[] = ['start' => $start, 'end' => $end, 'speaker' => is_string($s['speaker'] ?? null) ? trim($s['speaker']) : '', 'text' => trim($s['body'])];
        }
        if ($cues === []) {
            throw new TranscriptFormatException('transcript_unreadable', 'The file holds no readable cue.');
        }

        return ['transcript' => self::build($cues), 'skipped' => $skipped, 'format' => 'json'];
    }

    public static function detect(string $content): string
    {
        $t = ltrim($content);
        if (str_starts_with($t, 'WEBVTT')) {
            return 'vtt';
        }
        if (str_starts_with($t, '{')) {
            return 'json';
        }

        return 'srt';
    }

    /**
     * @param list<array{start: int, end: int, speaker: string, text: string}> $cues
     */
    private static function build(array $cues): Transcript
    {
        usort($cues, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $ids = [];
        $speakers = [];
        $segments = [];
        $diarised = false;
        foreach ($cues as $c) {
            $key = $c['speaker'];
            if ($key !== '') {
                $diarised = true;
            }
            if (!isset($ids[$key])) {
                $n = count($ids) + 1;
                $ids[$key] = 'S' . $n;
                $speakers[] = new Speaker('S' . $n, 'Speaker ' . $n, $key);
            }
            $segments[] = new Segment(Segment::idFor(count($segments) + 1), $c['start'], $c['end'], $ids[$key], $c['text']);
        }
        $duration = 0;
        foreach ($segments as $s) {
            $duration = max($duration, $s->endMs);
        }

        return new Transcript($duration, '', Transcript::TIMESTAMPS_FINE, $diarised ? Transcript::SPEAKERS_DIARISED : Transcript::SPEAKERS_NONE, $speakers, $segments);
    }
}
