<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * One transcript segment (case 49).
 *
 * In a chunk result, times are relative to the chunk start and `speaker` is
 * the label the provider gave. In a stitched transcript, times are global
 * and `speaker` is a global speaker id such as `S1`.
 */
final class Segment
{
    public const FLAG_SEAM = 'seam';
    public const FLAG_LOW_CONFIDENCE_SPEAKER = 'low_confidence_speaker';

    /**
     * @param list<Word>           $words
     * @param list<string>         $flags
     * @param array<string, mixed> $extra unknown keys kept from an import
     */
    public function __construct(
        public readonly string $id,
        public readonly int $startMs,
        public readonly int $endMs,
        public readonly string $speaker,
        public readonly string $text,
        public readonly array $words = [],
        public readonly ?float $confidence = null,
        public readonly ?string $language = null,
        public readonly int $chunk = 0,
        public readonly array $flags = [],
        public readonly array $extra = [],
    ) {
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $v = static fn (string $k, mixed $d): mixed => array_key_exists($k, $changes) ? $changes[$k] : $d;

        /** @var list<Word> $words */
        $words = $v('words', $this->words);
        /** @var list<string> $flags */
        $flags = $v('flags', $this->flags);
        /** @var ?float $confidence */
        $confidence = $v('confidence', $this->confidence);
        /** @var ?string $language */
        $language = $v('language', $this->language);

        return new self(
            (string) $v('id', $this->id),
            (int) $v('startMs', $this->startMs),
            (int) $v('endMs', $this->endMs),
            (string) $v('speaker', $this->speaker),
            (string) $v('text', $this->text),
            $words,
            $confidence,
            $language,
            (int) $v('chunk', $this->chunk),
            $flags,
            $this->extra,
        );
    }

    public function withFlag(string $flag): self
    {
        if (in_array($flag, $this->flags, true)) {
            return $this;
        }

        return $this->with(['flags' => array_merge($this->flags, [$flag])]);
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    public function durationMs(): int
    {
        return max(0, $this->endMs - $this->startMs);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = $this->extra;
        $out['id'] = $this->id;
        $out['start_ms'] = $this->startMs;
        $out['end_ms'] = $this->endMs;
        $out['speaker'] = $this->speaker;
        $out['text'] = $this->text;
        if ($this->language !== null) {
            $out['language'] = $this->language;
        }
        if ($this->confidence !== null) {
            $out['confidence'] = $this->confidence;
        }
        $out['chunk'] = $this->chunk;
        $out['flags'] = $this->flags;
        if ($this->words !== []) {
            $out['words'] = array_map(static fn (Word $w): array => $w->toArray(), $this->words);
        }

        return $out;
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $known = ['id', 'start_ms', 'end_ms', 'speaker', 'text', 'language', 'confidence', 'chunk', 'flags', 'words'];
        $extra = [];
        foreach ($data as $k => $val) {
            if (is_string($k) && !in_array($k, $known, true)) {
                $extra[$k] = $val;
            }
        }
        $words = [];
        foreach (is_array($data['words'] ?? null) ? $data['words'] : [] as $w) {
            if (is_array($w)) {
                $words[] = Word::fromArray($w);
            }
        }
        $flags = [];
        foreach (is_array($data['flags'] ?? null) ? $data['flags'] : [] as $f) {
            if (is_string($f)) {
                $flags[] = $f;
            }
        }
        $conf = $data['confidence'] ?? null;
        $int = static fn (mixed $x): int => is_int($x) ? $x : (is_numeric($x) ? (int) $x : 0);

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            $int($data['start_ms'] ?? 0),
            $int($data['end_ms'] ?? 0),
            is_string($data['speaker'] ?? null) ? $data['speaker'] : '',
            is_string($data['text'] ?? null) ? $data['text'] : '',
            $words,
            is_int($conf) || is_float($conf) ? (float) $conf : null,
            is_string($data['language'] ?? null) ? $data['language'] : null,
            $int($data['chunk'] ?? 0),
            $flags,
            $extra,
        );
    }

    public static function idFor(int $index): string
    {
        return 's' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }
}
