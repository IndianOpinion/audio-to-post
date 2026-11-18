<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Http;

/**
 * What a download produced.
 */
final class FetchResult
{
    public function __construct(
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly ?string $contentType,
        public readonly int $bytes,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
        public readonly bool $acceptRanges,
        public readonly string $body = '',
    ) {
    }
}
