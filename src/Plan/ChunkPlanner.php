<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Plan;

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\AudioInfo;
use IndianOpinion\AudioToPost\Chunk;
use IndianOpinion\AudioToPost\ChunkPlan;
use IndianOpinion\AudioToPost\PlanException;

/**
 * Plans chunks in milliseconds (cases 20 to 26). A Chunker then snaps the
 * boundaries to frames and fills in byte ranges.
 */
final class ChunkPlanner
{
    public const SAFETY = 0.95;
    public const MIN_CHUNK_MS = 60_000;
    public const MIN_TAIL_MS = 30_000;

    /** Lowest plausible bitrate per format, for the upper-bound duration (case 28). */
    public const LOWEST_BITRATE = [
        'mp3' => 32_000,
        'wav' => 64_000,
        'ogg' => 16_000,
        'webm' => 16_000,
        'm4a' => 24_000,
        'flac' => 96_000,
    ];

    public const CANNOT_SPLIT = 'This file is too long for the configured provider and this format cannot be split. Convert it to MP3 or WAV and try again.';

    /**
     * Duration used for planning: measured, or the upper bound from size.
     */
    public static function planningDuration(AudioInfo $info): int
    {
        if ($info->durationMs !== null) {
            return $info->durationMs;
        }
        $rate = self::LOWEST_BITRATE[$info->format] ?? 16_000;

        return (int) ceil($info->sizeBytes * 8 * 1000 / $rate);
    }

    /**
     * @throws PlanException
     */
    public function plan(AudioInfo $info, AdapterCapabilities $caps, int $chunkSeconds = 600, int $overlapSeconds = 10, bool $chunkable = false): ChunkPlan
    {
        if (!in_array($info->format, $caps->formats, true)) {
            throw new PlanException('unsupported_format', sprintf('The configured provider does not accept %s files.', $info->format));
        }
        $d = self::planningDuration($info);
        $fitsBytes = $info->sizeBytes <= $caps->maxBytes;
        $fitsTime = $d <= $caps->maxSeconds * 1000;
        if ($fitsBytes && $fitsTime) {
            return new ChunkPlan($info->format, [new Chunk(0, 0, $d, 0, $info->sizeBytes)]);
        }
        if ($caps->longAudio) {
            return new ChunkPlan($info->format, [new Chunk(0, 0, $d, 0, $info->sizeBytes)], true);
        }
        if (!$chunkable || $info->durationMs === null) {
            throw new PlanException('cannot_split_format', self::CANNOT_SPLIT);
        }
        $bitrate = max(1, (int) $info->averageBitrate());
        $bytesMs = (int) floor($caps->maxBytes * 8 * 1000 / $bitrate);
        $hard = (int) floor(min($caps->maxSeconds * 1000, $bytesMs) * self::SAFETY);
        $ov = max(0, min(30, $overlapSeconds)) * 1000;
        $len = min(max(60, $chunkSeconds) * 1000, $hard - $ov);
        if ($len < self::MIN_CHUNK_MS) {
            throw new PlanException('limits_too_small', 'The provider limits are too small to make chunks of at least 60 seconds.');
        }

        $n = (int) ceil($d / $len);
        $starts = [];
        for ($i = 0; $i < $n; $i++) {
            $starts[] = $i * $len;
        }
        $tail = $d - ($n - 1) * $len;
        if ($n > 1 && $tail < self::MIN_TAIL_MS) {
            if ($len + $tail + $ov <= $hard) {
                array_pop($starts);
            } else {
                $starts[$n - 1] = $starts[$n - 2] + intdiv($len + $tail, 2);
            }
        }

        return new ChunkPlan($info->format, self::chunksFrom(array_values($starts), $d, $ov));
    }

    /**
     * @param list<int> $starts
     *
     * @return list<Chunk>
     */
    public static function chunksFrom(array $starts, int $d, int $ov): array
    {
        $chunks = [];
        $last = count($starts) - 1;
        foreach ($starts as $i => $s) {
            $end = $i === $last ? $d : min($d, $starts[$i + 1] + $ov);
            $chunks[] = new Chunk($i, $s, $end, 0, 0, $i > 0 ? min($ov, max(0, $chunks[$i - 1]->endMs - $s)) : 0, $i < $last ? $end - $starts[$i + 1] : 0);
        }

        return $chunks;
    }

    /**
     * Split one chunk in two halves with the same overlap (case 38).
     *
     * @return list<Chunk>
     */
    public static function halve(Chunk $c, int $overlapMs): array
    {
        $mid = $c->startMs + intdiv($c->lengthMs(), 2);

        return [
            new Chunk($c->index, $c->startMs, min($c->endMs, $mid + $overlapMs), 0, 0, $c->overlapBeforeMs, min($c->endMs, $mid + $overlapMs) - $mid),
            new Chunk($c->index + 1, $mid, $c->endMs, 0, 0, min($c->endMs, $mid + $overlapMs) - $mid, $c->overlapAfterMs),
        ];
    }

    /**
     * A filtered plan is accepted only if it still covers the recording
     * without gaps and no chunk breaks the adapter limits (filter
     * `audiopost_chunk_plan`).
     */
    public static function isValid(ChunkPlan $plan, AdapterCapabilities $caps, int $durationMs): bool
    {
        if ($plan->chunks === []) {
            return false;
        }
        $prevEnd = 0;
        foreach ($plan->chunks as $i => $c) {
            if ($c->index !== $i || $c->startMs > $prevEnd || $c->endMs <= $c->startMs) {
                return false;
            }
            if (!$plan->native && count($plan->chunks) > 1 && $c->lengthMs() > (int) floor($caps->maxSeconds * 1000)) {
                return false;
            }
            $prevEnd = max($prevEnd, $c->endMs);
        }

        return $prevEnd >= $durationMs;
    }
}
