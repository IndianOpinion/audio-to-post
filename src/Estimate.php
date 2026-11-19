<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * A pre-run estimate (cases 28 to 32). Money is a decimal string in the
 * operator's own currency label, or null when any needed price is missing.
 */
final class Estimate
{
    /**
     * @param array<string, string>|null $price keys: transcription, draft_input, draft_output, total
     */
    public function __construct(
        public readonly int $billedSeconds,
        public readonly int $chunks,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly ?array $price,
        public readonly string $currency,
        public readonly string $fingerprint,
        public readonly bool $durationExact,
        public readonly bool $upperBound,
        public readonly string $mode = 'none',
        public readonly int $draftPasses = 0,
        public readonly bool $priceConfigured = false,
    ) {
    }

    public function billedMinutes(): float
    {
        return $this->billedSeconds / 60;
    }

    public function priceLine(): string
    {
        if ($this->price === null) {
            return 'price not configured';
        }

        return trim($this->price['total'] . ' ' . $this->currency) . ' (estimate, not a quote)';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'billed_seconds' => $this->billedSeconds,
            'chunks' => $this->chunks,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'price' => $this->price,
            'currency' => $this->currency,
            'fingerprint' => $this->fingerprint,
            'duration_exact' => $this->durationExact,
            'upper_bound' => $this->upperBound,
            'mode' => $this->mode,
            'draft_passes' => $this->draftPasses,
            'price_line' => $this->priceLine(),
        ];
    }

    /** @param array<mixed> $d */
    public static function fromArray(array $d): self
    {
        $i = static fn (string $k): int => is_numeric($d[$k] ?? null) ? (int) $d[$k] : 0;
        $price = null;
        if (is_array($d['price'] ?? null)) {
            $price = [];
            foreach ($d['price'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $price[$k] = $v;
                }
            }
        }

        return new self(
            $i('billed_seconds'),
            $i('chunks'),
            $i('tokens_in'),
            $i('tokens_out'),
            $price,
            is_string($d['currency'] ?? null) ? $d['currency'] : '',
            is_string($d['fingerprint'] ?? null) ? $d['fingerprint'] : '',
            (bool) ($d['duration_exact'] ?? false),
            (bool) ($d['upper_bound'] ?? false),
            is_string($d['mode'] ?? null) ? $d['mode'] : 'none',
            $i('draft_passes'),
            $price !== null,
        );
    }
}
