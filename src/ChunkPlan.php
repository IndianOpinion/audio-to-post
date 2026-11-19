<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * The stored chunk plan for a run (cases 21 to 27).
 */
final class ChunkPlan
{
    /**
     * @param list<Chunk> $chunks
     */
    public function __construct(
        public readonly string $format,
        public readonly array $chunks,
        public readonly bool $native = false,
    ) {
    }

    public function count(): int
    {
        return count($this->chunks);
    }

    public function chunk(int $index): ?Chunk
    {
        return $this->chunks[$index] ?? null;
    }

    /** Sum of chunk lengths, overlaps included (case 24). */
    public function totalMs(): int
    {
        $t = 0;
        foreach ($this->chunks as $c) {
            $t += $c->lengthMs();
        }

        return $t;
    }

    /**
     * Replace chunk $index with the given chunks and renumber (case 38).
     *
     * @param list<Chunk> $replacement
     */
    public function replace(int $index, array $replacement): self
    {
        $out = [];
        foreach ($this->chunks as $c) {
            if ($c->index === $index) {
                foreach ($replacement as $r) {
                    $out[] = $r;
                }
                continue;
            }
            $out[] = $c;
        }
        $renumbered = [];
        foreach ($out as $i => $c) {
            $renumbered[] = $c->withIndex($i);
        }

        return new self($this->format, $renumbered, $this->native);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'native' => $this->native,
            'chunks' => array_map(static fn (Chunk $c): array => $c->toArray(), $this->chunks),
        ];
    }

    /** @param array<mixed> $d */
    public static function fromArray(array $d): self
    {
        $chunks = [];
        foreach (is_array($d['chunks'] ?? null) ? $d['chunks'] : [] as $c) {
            if (is_array($c)) {
                $chunks[] = Chunk::fromArray($c);
            }
        }

        return new self(is_string($d['format'] ?? null) ? $d['format'] : 'unknown', $chunks, (bool) ($d['native'] ?? false));
    }
}
