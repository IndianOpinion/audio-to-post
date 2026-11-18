<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * What the probe learnt about a recording (case 17). No paid call is
 * needed to build it.
 */
final class AudioInfo
{
    public function __construct(
        public readonly string $format,
        public readonly int $sizeBytes,
        public readonly ?int $durationMs,
        public readonly bool $durationExact,
        public readonly ?string $codec = null,
        public readonly ?int $sampleRate = null,
        public readonly ?int $channels = null,
        public readonly ?int $bitrate = null,
        public readonly bool $variableBitrate = false,
        public readonly string $sha256 = '',
    ) {
    }

    public function durationKnown(): bool
    {
        return $this->durationMs !== null;
    }

    /** Average bits per second, measured when possible. */
    public function averageBitrate(): ?int
    {
        if ($this->durationMs !== null && $this->durationMs > 0 && $this->sizeBytes > 0) {
            return (int) round($this->sizeBytes * 8 * 1000 / $this->durationMs);
        }

        return $this->bitrate;
    }

    public function withSha256(string $sha): self
    {
        return new self($this->format, $this->sizeBytes, $this->durationMs, $this->durationExact, $this->codec, $this->sampleRate, $this->channels, $this->bitrate, $this->variableBitrate, $sha);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'codec' => $this->codec,
            'sample_rate' => $this->sampleRate,
            'channels' => $this->channels,
            'bitrate' => $this->bitrate,
            'variable_bitrate' => $this->variableBitrate,
            'duration_ms' => $this->durationMs,
            'duration_known' => $this->durationKnown(),
            'duration_exact' => $this->durationExact,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
        ];
    }

    /** @param array<mixed> $d */
    public static function fromArray(array $d): self
    {
        $int = static fn (mixed $v): ?int => is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);

        return new self(
            is_string($d['format'] ?? null) ? $d['format'] : 'unknown',
            $int($d['size_bytes'] ?? 0) ?? 0,
            $int($d['duration_ms'] ?? null),
            (bool) ($d['duration_exact'] ?? false),
            is_string($d['codec'] ?? null) ? $d['codec'] : null,
            $int($d['sample_rate'] ?? null),
            $int($d['channels'] ?? null),
            $int($d['bitrate'] ?? null),
            (bool) ($d['variable_bitrate'] ?? false),
            is_string($d['sha256'] ?? null) ? $d['sha256'] : '',
        );
    }
}
