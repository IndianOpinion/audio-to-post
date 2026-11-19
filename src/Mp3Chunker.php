<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

use IndianOpinion\AudioToPost\Audio\FormatDetector;
use IndianOpinion\AudioToPost\Audio\Mp3Reader;

/**
 * Splits MP3 on frame boundaries (case 22). Each chunk is plain MPEG
 * frames: it starts on a frame sync, has no ID3 tag and no Xing/Info
 * header that would describe the whole file.
 */
final class Mp3Chunker extends AbstractChunker
{
    public function supports(AudioInfo $info): bool
    {
        return $info->format === FormatDetector::MP3;
    }

    public function locate(string $path, AudioInfo $info, ChunkPlan $plan): ChunkPlan
    {
        $points = [];
        foreach ($plan->chunks as $c) {
            $points[] = $c->startMs;
            $points[] = $c->endMs;
        }
        $points = array_values(array_unique($points));
        sort($points);
        $scan = Mp3Reader::scan($path, $points, 60.0);
        if (!$scan['complete']) {
            // Boundaries past the point where the scan stopped would be
            // empty or wrong chunks that are still paid for.
            throw new AudioException('unreadable_audio', 'The MP3 frames could not all be read in time, so the file cannot be split safely.');
        }
        $map = [];
        foreach ($points as $i => $ms) {
            $map[$ms] = $scan['boundaries'][$i];
        }
        $snapped = [];
        $last = count($plan->chunks) - 1;
        foreach ($plan->chunks as $i => $c) {
            $s = $map[$c->startMs];
            $e = $i === $last ? ['offset' => $scan['data_end'], 'ms' => $scan['duration_ms']] : $map[$c->endMs];
            $snapped[] = ['start' => $s['ms'], 'end' => $e['ms'], 'bs' => $s['offset'], 'be' => $e['offset']];
        }

        return self::rebuild($plan, $snapped);
    }

    public function extract(string $path, Chunk $chunk, string $destination): void
    {
        $out = fopen($destination, 'wb');
        if ($out === false) {
            throw new AudioException('storage_unwritable', 'Cannot write a chunk file.');
        }
        try {
            self::copyRange($path, $chunk->byteStart, $chunk->byteEnd, $out);
        } finally {
            fclose($out);
        }
    }
}
