<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Adapter;

/**
 * One chunk to transcribe.
 */
final class ChunkRequest
{
    public function __construct(
        public readonly string $path,
        public readonly string $format,
        public readonly int $startMs,
        public readonly int $lengthMs,
        public readonly ?string $language,
        public readonly string $requestId,
        public readonly int $index = 0,
    ) {
    }
}
