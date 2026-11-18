<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

use IndianOpinion\AudioToPost\Audio\FormatDetector;
use IndianOpinion\AudioToPost\Audio\WavReader;

/**
 * Splits PCM WAV on sample-frame boundaries (case 22). Each chunk gets
 * its own RIFF, `fmt ` and `data` headers with the right sizes.
 */
final class WavChunker extends AbstractChunker
{
    /** @var array<string, array{channels: int, sample_rate: int, bits: int, block_align: int, data_offset: int, data_size: int, audio_format: int}> */
    private array $headers = [];

    public function supports(AudioInfo $info): bool
    {
        return $info->format === FormatDetector::WAV && $info->codec === 'pcm';
    }

    public function locate(string $path, AudioInfo $info, ChunkPlan $plan): ChunkPlan
    {
        $w = $this->wav($path);
        $toBytes = static function (int $ms) use ($w): int {
            $frames = intdiv($ms * $w['sample_rate'], 1000);

            return min($w['data_offset'] + $w['data_size'], $w['data_offset'] + $frames * $w['block_align']);
        };
        $toMs = static fn (int $bytes): int => intdiv(intdiv($bytes - $w['data_offset'], $w['block_align']) * 1000, max(1, $w['sample_rate']));
        $snapped = [];
        foreach ($plan->chunks as $c) {
            $bs = $toBytes($c->startMs);
            $be = $toBytes($c->endMs);
            $snapped[] = ['start' => $toMs($bs), 'end' => $toMs($be), 'bs' => $bs, 'be' => $be];
        }

        return self::rebuild($plan, $snapped);
    }

    public function extract(string $path, Chunk $chunk, string $destination): void
    {
        $w = $this->wav($path);
        $out = fopen($destination, 'wb');
        if ($out === false) {
            throw new AudioException('storage_unwritable', 'Cannot write a chunk file.');
        }
        try {
            $size = $chunk->byteEnd - $chunk->byteStart;
            fwrite($out, WavReader::header($w['channels'], $w['sample_rate'], $w['bits'], $size, $w['audio_format']));
            self::copyRange($path, $chunk->byteStart, $chunk->byteEnd, $out);
        } finally {
            fclose($out);
        }
    }

    /**
     * @return array{channels: int, sample_rate: int, bits: int, block_align: int, data_offset: int, data_size: int, audio_format: int}
     */
    private function wav(string $path): array
    {
        if (!isset($this->headers[$path])) {
            $w = WavReader::read($path);
            $this->headers[$path] = [
                'channels' => $w['channels'],
                'sample_rate' => $w['sample_rate'],
                'bits' => $w['bits'],
                'block_align' => $w['block_align'],
                'data_offset' => $w['data_offset'],
                'data_size' => $w['data_size'],
                'audio_format' => $w['audio_format'],
            ];
        }

        return $this->headers[$path];
    }
}
