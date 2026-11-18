<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * A created draft post and its review notes (cases 65 to 67).
 */
final class DraftResult
{
    /**
     * @param list<array{type: string, message: string, segment_id?: string}> $reviewNotes
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $mode,
        public readonly array $reviewNotes,
    ) {
    }
}
