<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Adapter;

use IndianOpinion\AudioToPost\Segment;

/**
 * What a provider returned for one chunk. Segment times are relative to
 * the chunk start; speaker labels are as the provider gave them, or empty.
 */
final class ChunkResult
{
    /**
     * @param list<Segment> $segments
     */
    public function __construct(
        public readonly array $segments,
        public readonly string $text = '',
        public readonly ?string $language = null,
        public readonly ?int $billedSeconds = null,
        public readonly int $httpStatus = 200,
        public readonly bool $timed = true,
    ) {
    }

    public function isEmpty(): bool
    {
        if (trim($this->text) !== '') {
            return false;
        }
        foreach ($this->segments as $s) {
            if (trim($s->text) !== '') {
                return false;
            }
        }

        return true;
    }

    public function diarised(): bool
    {
        foreach ($this->segments as $s) {
            if ($s->speaker !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'segments' => array_map(static fn (Segment $s): array => $s->toArray(), $this->segments),
            'text' => $this->text,
            'language' => $this->language,
            'billed_seconds' => $this->billedSeconds,
            'http_status' => $this->httpStatus,
            'timed' => $this->timed,
        ];
    }

    /** @param array<mixed> $d */
    public static function fromArray(array $d): self
    {
        $segs = [];
        foreach (is_array($d['segments'] ?? null) ? $d['segments'] : [] as $s) {
            if (is_array($s)) {
                $segs[] = Segment::fromArray($s);
            }
        }

        return new self(
            $segs,
            is_string($d['text'] ?? null) ? $d['text'] : '',
            is_string($d['language'] ?? null) ? $d['language'] : null,
            is_numeric($d['billed_seconds'] ?? null) ? (int) $d['billed_seconds'] : null,
            is_numeric($d['http_status'] ?? null) ? (int) $d['http_status'] : 200,
            (bool) ($d['timed'] ?? true),
        );
    }
}
