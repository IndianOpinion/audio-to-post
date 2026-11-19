<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Draft;

use IndianOpinion\AudioToPost\ProviderException;

/**
 * One model call that returns decoded JSON and usage.
 */
interface LlmCaller
{
    /**
     * @param array{prompt: string, input: string, schema: array<string, mixed>, schema_name: string, max_output_tokens: int} $request
     * @param array<string, mixed> $context mode, pass, run id, model setting
     *
     * @return array{data: array<string, mixed>, tokens_in: int, tokens_out: int, model: string}
     *
     * @throws ProviderException
     */
    public function call(array $request, array $context): array;

    public function id(): string;
}
