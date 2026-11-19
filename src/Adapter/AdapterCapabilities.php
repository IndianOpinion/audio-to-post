<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Adapter;

/**
 * What an adapter accepts and returns (section 5.3).
 */
final class AdapterCapabilities
{
    public const ROUND_NONE = 'none';
    public const ROUND_SECOND = 'second';
    public const ROUND_MINUTE = 'minute';

    /**
     * @param list<string> $formats container names: mp3, wav, ogg, flac, m4a, webm
     */
    public function __construct(
        public readonly int $maxBytes,
        public readonly int $maxSeconds,
        public readonly array $formats,
        public readonly bool $diarisation = false,
        public readonly bool $wordTimestamps = false,
        public readonly bool $segmentTimestamps = true,
        public readonly bool $languageHint = false,
        public readonly bool $async = false,
        public readonly bool $longAudio = false,
        public readonly string $billingRounding = self::ROUND_NONE,
        public readonly int $minimumBillableSeconds = 0,
        public readonly ?float $typicalSecondsPerAudioMinute = null,
        public readonly bool $free = false,
    ) {
    }

    /** Lower the byte and second limits; never raise them (case 20). */
    public function clamped(?int $maxBytes, ?int $maxSeconds): self
    {
        return new self(
            $maxBytes !== null && $maxBytes > 0 ? min($this->maxBytes, $maxBytes) : $this->maxBytes,
            $maxSeconds !== null && $maxSeconds > 0 ? min($this->maxSeconds, $maxSeconds) : $this->maxSeconds,
            $this->formats,
            $this->diarisation,
            $this->wordTimestamps,
            $this->segmentTimestamps,
            $this->languageHint,
            $this->async,
            $this->longAudio,
            $this->billingRounding,
            $this->minimumBillableSeconds,
            $this->typicalSecondsPerAudioMinute,
            $this->free,
        );
    }

    /** Billed seconds for one request of the given length (case 28). */
    public function billedSeconds(int $lengthMs): int
    {
        $s = $lengthMs / 1000;
        $billed = match ($this->billingRounding) {
            self::ROUND_SECOND => (int) ceil($s),
            self::ROUND_MINUTE => (int) (ceil($s / 60) * 60),
            default => (int) ceil($s),
        };

        return max($billed, $this->minimumBillableSeconds);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
