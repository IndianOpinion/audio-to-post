<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * One planned chunk (case 27). Times are milliseconds within the
 * recording; bytes are a half-open range [byteStart, byteEnd) in the
 * private copy. The overlap fields say how much of this chunk is shared
 * with the previous and the next chunk.
 */
final class Chunk
{
    public function __construct(
        public readonly int $index,
        public readonly int $startMs,
        public readonly int $endMs,
        public readonly int $byteStart = 0,
        public readonly int $byteEnd = 0,
        public readonly int $overlapBeforeMs = 0,
        public readonly int $overlapAfterMs = 0,
    ) {
    }

    public function lengthMs(): int
    {
        return max(0, $this->endMs - $this->startMs);
    }

    public function withIndex(int $index): self
    {
        return new self($index, $this->startMs, $this->endMs, $this->byteStart, $this->byteEnd, $this->overlapBeforeMs, $this->overlapAfterMs);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'start_ms' => $this->startMs,
            'end_ms' => $this->endMs,
            'byte_start' => $this->byteStart,
            'byte_end' => $this->byteEnd,
            'overlap_before_ms' => $this->overlapBeforeMs,
            'overlap_after_ms' => $this->overlapAfterMs,
        ];
    }

    /** @param array<mixed> $d */
    public static function fromArray(array $d): self
    {
        $i = static fn (string $k): int => is_numeric($d[$k] ?? null) ? (int) $d[$k] : 0;

        return new self($i('index'), $i('start_ms'), $i('end_ms'), $i('byte_start'), $i('byte_end'), $i('overlap_before_ms'), $i('overlap_after_ms'));
    }
}
