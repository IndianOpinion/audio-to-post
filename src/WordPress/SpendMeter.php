<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * Soft integration with spend-meter (case 71), contract version 1.
 *
 * Present only when the constant SPEND_METER_API is defined and equal to
 * 1. The contract used here is the one spend-meter 0.1.0 documents:
 *
 * - `apply_filters('spend_meter_pre_spend', null, array $intent)` with
 *   `purpose`, `caller`, `provider`, `model`, `est_input_tokens`,
 *   `max_output_tokens` (and `est_units` for minute-priced audio). A null
 *   result means no meter is listening; otherwise the call goes ahead only
 *   when `allowed` is exactly true.
 * - `do_action('spend_meter_record', array $call)` once per attempt that
 *   reached the provider, with `status` `ok`, `error_billed` or
 *   `error_unbilled` and the reservation id when there was one.
 * - `do_action('spend_meter_release', string $reservationId)` when a
 *   reservation is given up without a call.
 */
final class SpendMeter
{
    public const CALLER = 'audio-to-post';

    public static function present(): bool
    {
        return defined('SPEND_METER_API') && (int) constant('SPEND_METER_API') === 1;
    }

    /**
     * Ask the meter. Returns null when no meter answered (absent), else the
     * decision array.
     *
     * @param array{kind: string, seconds: int, tokens_in: int, tokens_out: int, money_micros: ?int, provider: string, model: string} $intent
     *
     * @return array<string, mixed>|null
     */
    public static function preSpend(array $intent, int $runId = 0): ?array
    {
        $res = apply_filters('spend_meter_pre_spend', null, self::intent($intent, $runId));
        if ($res === null) {
            return null;
        }

        // Anything other than a decision array is a deny (fail closed).
        return is_array($res) ? $res : ['allowed' => false, 'reason' => 'invalid_decision', 'reservation_id' => null];
    }

    /**
     * The intent array of spend-meter section 5.2.
     *
     * @param array{kind: string, seconds: int, tokens_in: int, tokens_out: int, money_micros: ?int, provider: string, model: string} $intent
     *
     * @return array<string, mixed>
     */
    public static function intent(array $intent, int $runId = 0): array
    {
        $out = [
            'purpose' => self::purpose($intent['kind']),
            'caller' => self::CALLER,
            'provider' => self::slug($intent['provider']),
            'model' => self::model($intent['model'], $intent['provider']),
            'est_input_tokens' => max(0, $intent['tokens_in']),
            'max_output_tokens' => max(0, $intent['tokens_out']),
        ];
        if ($intent['kind'] === 'transcribe') {
            $out['est_units'] = round(max(0, $intent['seconds']) / 60, 3);
        }
        if ($runId > 0) {
            $out['meta'] = ['run_id' => (string) $runId];
        }

        return $out;
    }

    /**
     * Record one attempt that reached the provider.
     *
     * @param array{kind: string, provider: string, model: string, status: string, http_status?: ?int, input_tokens?: int, output_tokens?: int, seconds?: int, attempt?: int, error_code?: string, run_id?: int} $call
     */
    public static function record(?string $reservation, array $call): void
    {
        if (!self::present()) {
            return;
        }
        $row = [
            'reservation_id' => $reservation,
            'purpose' => self::purpose($call['kind']),
            'caller' => self::CALLER,
            'provider' => self::slug($call['provider']),
            'model' => self::model($call['model'], $call['provider']),
            'status' => in_array($call['status'], ['ok', 'error_billed', 'error_unbilled'], true) ? $call['status'] : 'error_unbilled',
            'input_tokens' => max(0, (int) ($call['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($call['output_tokens'] ?? 0)),
            'attempt' => max(1, (int) ($call['attempt'] ?? 1)),
        ];
        if ($reservation === null) {
            unset($row['reservation_id']);
        }
        if (isset($call['seconds']) && $call['kind'] === 'transcribe') {
            $row['units'] = round(max(0, $call['seconds']) / 60, 3);
        }
        if (isset($call['http_status'])) {
            $row['http_status'] = (int) $call['http_status'];
        }
        if (isset($call['error_code']) && $call['error_code'] !== '') {
            $row['error_code'] = substr($call['error_code'], 0, 64);
        }
        if (isset($call['run_id']) && $call['run_id'] > 0) {
            $row['meta'] = ['run_id' => (string) $call['run_id']];
        }
        do_action('spend_meter_record', $row);
    }

    /** Give up a reservation when no call was made. */
    public static function release(?string $reservation): void
    {
        if ($reservation !== null && $reservation !== '' && self::present()) {
            do_action('spend_meter_release', $reservation);
        }
    }

    private static function purpose(string $kind): string
    {
        return self::CALLER . '.' . ($kind === 'draft' ? 'draft' : 'transcribe');
    }

    /** Provider slug, lower case, as spend-meter expects. */
    private static function slug(string $provider): string
    {
        $s = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $provider));
        $s = trim($s, '-');

        return $s === '' ? 'unknown' : substr($s, 0, 64);
    }

    /** The model is required; adapters without a model name use their id. */
    private static function model(string $model, string $provider): string
    {
        $m = trim($model);

        return substr($m !== '' ? $m : self::slug($provider), 0, 128);
    }
}
