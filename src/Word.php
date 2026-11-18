<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * One timed word inside a segment.
 */
final class Word
{
    public function __construct(
        public readonly int $startMs,
        public readonly int $endMs,
        public readonly string $text,
        public readonly ?float $confidence = null,
    ) {
    }

    public function shifted(int $offsetMs, ?int $clampMs = null): self
    {
        $start = $this->startMs + $offsetMs;
        $end = $this->endMs + $offsetMs;
        if ($clampMs !== null) {
            $start = min($start, $clampMs);
            $end = min($end, $clampMs);
        }

        return new self(max(0, $start), max(0, $end), $this->text, $this->confidence);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['start_ms' => $this->startMs, 'end_ms' => $this->endMs, 'text' => $this->text];
        if ($this->confidence !== null) {
            $out['confidence'] = $this->confidence;
        }

        return $out;
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $conf = $data['confidence'] ?? null;

        return new self(
            self::int($data['start_ms'] ?? 0),
            self::int($data['end_ms'] ?? 0),
            is_string($data['text'] ?? null) ? $data['text'] : '',
            is_int($conf) || is_float($conf) ? (float) $conf : null,
        );
    }

    private static function int(mixed $v): int
    {
        return is_int($v) ? $v : (is_float($v) || (is_string($v) && is_numeric($v)) ? (int) $v : 0);
    }
}
