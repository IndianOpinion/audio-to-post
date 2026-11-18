<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * Splits a recording into valid, playable pieces without decoding it
 * (case 22).
 */
interface Chunker
{
    public function supports(AudioInfo $info): bool;

    /**
     * Snap the plan's millisecond boundaries to frame boundaries and fill
     * in the byte ranges.
     */
    public function locate(string $path, AudioInfo $info, ChunkPlan $plan): ChunkPlan;

    /**
     * Write one chunk as a standalone file.
     *
     * @throws AudioException
     */
    public function extract(string $path, Chunk $chunk, string $destination): void;

    /**
     * Write every chunk of the plan, yielding each chunk file path.
     *
     * @return iterable<int, string>
     */
    public function split(string $path, ChunkPlan $plan): iterable;
}
