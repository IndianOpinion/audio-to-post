<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Adapter;

use IndianOpinion\AudioToPost\ProviderException;

/**
 * A speech-to-text service (section 5.3).
 *
 * Synchronous adapters implement `transcribe()`. Asynchronous adapters
 * (capabilities `async` true) implement `submit()` and `poll()` and may
 * throw from `transcribe()`. Every error is a ProviderException with
 * `retryable` set; an adapter never returns a partial result silently.
 */
interface TranscriptionAdapter
{
    /** Lower-case slug, `^[a-z0-9][a-z0-9-]{1,39}$`. */
    public function id(): string;

    public function label(): string;

    public function capabilities(): AdapterCapabilities;

    public function isConfigured(): bool;

    /** @return list<string> */
    public function configurationProblems(): array;

    /**
     * @throws ProviderException
     */
    public function transcribe(ChunkRequest $request): ChunkResult;

    /**
     * @throws ProviderException
     */
    public function submit(ChunkRequest $request): string;

    /**
     * @throws ProviderException
     */
    public function poll(string $jobId): ?ChunkResult;
}
