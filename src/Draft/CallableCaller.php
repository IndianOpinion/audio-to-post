<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Draft;

use IndianOpinion\AudioToPost\ProviderException;

/**
 * Wraps a site-supplied caller from the `audiopost_llm_caller` filter
 * (case 85). The callable receives the request and context and returns
 * the decoded JSON reply plus `tokens_in` and `tokens_out`, or a WP_Error.
 */
final class CallableCaller implements LlmCaller
{
    /** @var \Closure(array<string, mixed>, array<string, mixed>): mixed */
    private \Closure $fn;

    public function __construct(callable $fn)
    {
        $this->fn = \Closure::fromCallable($fn);
    }

    public function id(): string
    {
        return 'site-caller';
    }

    public function call(array $request, array $context): array
    {
        $out = ($this->fn)($request, $context);
        if (is_object($out) && is_a($out, 'WP_Error')) {
            /** @var \WP_Error $out */
            throw new ProviderException(ProviderException::TRANSIENT, 'Site caller: ' . $out->get_error_message(), true);
        }
        if (!is_array($out)) {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'The site caller returned no array.', true);
        }
        $tin = is_numeric($out['tokens_in'] ?? null) ? (int) $out['tokens_in'] : 0;
        $tout = is_numeric($out['tokens_out'] ?? null) ? (int) $out['tokens_out'] : 0;
        $data = is_array($out['data'] ?? null) ? $out['data'] : $out;
        unset($data['tokens_in'], $data['tokens_out']);
        $model = is_string($out['model'] ?? null) ? $out['model'] : 'site-caller';
        unset($data['model']);
        /** @var array<string, mixed> $data */

        return ['data' => $data, 'tokens_in' => $tin, 'tokens_out' => $tout, 'model' => $model];
    }
}
