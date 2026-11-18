<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Audio;

use IndianOpinion\AudioToPost\AudioException;
use IndianOpinion\AudioToPost\AudioInfo;
use IndianOpinion\AudioToPost\SourceException;

/**
 * Measures a recording without any paid call (cases 15 to 19).
 *
 * The metadata reader is WordPress core's audio metadata function in
 * production (it wraps the getID3 library core bundles); tests pass a
 * closure. MP3 frames are then counted for an exact length and WAV
 * length comes from the data chunk.
 */
final class Prober
{
    /** @var (\Closure(string): (array<string, mixed>|null))|null */
    private ?\Closure $metadataReader;

    /**
     * @param (callable(string): (array<string, mixed>|null))|null $metadataReader
     */
    public function __construct(?callable $metadataReader = null, private readonly float $cpuSeconds = 10.0)
    {
        $this->metadataReader = $metadataReader === null ? null : \Closure::fromCallable($metadataReader);
    }

    /**
     * @throws AudioException  empty_audio, video_not_supported
     * @throws SourceException not_audio, feed_url
     */
    public function probe(string $path, ?string $contentType = null): AudioInfo
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) {
            throw new AudioException('source_copy_missing', 'The audio file is missing.');
        }
        if ($size === 0) {
            throw new AudioException('empty_audio', 'The file is empty.');
        }
        $format = FormatDetector::detect($path, $contentType);
        $meta = $this->metadataReader !== null ? ($this->metadataReader)($path) : null;
        $meta = is_array($meta) ? $meta : [];
        $coreMs = isset($meta['length']) && is_numeric($meta['length']) && (float) $meta['length'] > 0 ? (int) round((float) $meta['length'] * 1000) : null;
        $coreRate = isset($meta['sample_rate']) && is_numeric($meta['sample_rate']) ? (int) $meta['sample_rate'] : null;
        $coreChannels = isset($meta['channels']) && is_numeric($meta['channels']) ? (int) $meta['channels'] : null;
        $coreBitrate = isset($meta['bitrate']) && is_numeric($meta['bitrate']) ? (int) $meta['bitrate'] : null;
        $coreCodec = isset($meta['codec']) && is_string($meta['codec']) ? $meta['codec'] : null;
        $coreVbr = ($meta['bitrate_mode'] ?? '') === 'vbr';

        $info = match ($format) {
            FormatDetector::MP3 => $this->mp3($path, $size, $coreMs),
            FormatDetector::WAV => $this->wav($path, $size),
            default => new AudioInfo($format, $size, $coreMs, false, $coreCodec, $coreRate, $coreChannels, $coreBitrate, $coreVbr),
        };
        if ($info->durationMs !== null && $info->durationMs < 1000) {
            throw new AudioException('empty_audio', 'The recording is shorter than one second.');
        }
        $sha = hash_file('sha256', $path);

        return $info->withSha256($sha === false ? '' : $sha);
    }

    private function mp3(string $path, int $size, ?int $coreMs): AudioInfo
    {
        try {
            $scan = Mp3Reader::scan($path, [], $this->cpuSeconds);
        } catch (AudioException $e) {
            return new AudioInfo(FormatDetector::MP3, $size, null, false, 'mp3');
        }
        $durationMs = $scan['duration_ms'];
        $exact = $scan['complete'];
        if (!$exact) {
            $spf = $scan['version'] === 3 ? 1152 : 576;
            if ($scan['info_frames'] !== null && $scan['info_frames'] > 0) {
                $durationMs = intdiv($scan['info_frames'] * $spf * 1000, $scan['sample_rate']);
            } elseif ($coreMs !== null) {
                $durationMs = $coreMs;
            } else {
                $durationMs = intdiv($size * 8 * 1000, max(1, $scan['bitrate']));
            }
        }

        return new AudioInfo(
            FormatDetector::MP3,
            $size,
            $durationMs > 0 ? $durationMs : null,
            $exact && $durationMs > 0,
            'mp3',
            $scan['sample_rate'],
            $scan['channels'],
            $scan['bitrate'],
            $scan['variable'],
        );
    }

    private function wav(string $path, int $size): AudioInfo
    {
        try {
            $w = WavReader::read($path);
        } catch (AudioException $e) {
            return new AudioInfo(FormatDetector::WAV, $size, null, false, 'pcm');
        }
        $ms = $w['byte_rate'] > 0 ? intdiv($w['data_size'] * 1000, $w['byte_rate']) : null;

        return new AudioInfo(
            FormatDetector::WAV,
            $size,
            $ms,
            $ms !== null,
            $w['pcm'] ? 'pcm' : 'wav-' . $w['audio_format'],
            $w['sample_rate'],
            $w['channels'],
            $w['byte_rate'] * 8,
            false,
        );
    }
}
