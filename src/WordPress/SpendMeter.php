<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * Soft integration with spend-meter (case 71). Present only when the
 * constant SPEND_METER_API is defined and equal to 1.
 *
 * Contract used here: the filter `spend_meter_pre_spend` receives null
 * and an intent array and returns an array with `allowed` (true to go
 * ahead), an optional `reason` and an optional `reservation_id`. After a
 * call, the action `spend_meter_record` receives the usage array (with
 * the reservation id when there was one); a reservation that is not used
 * is closed with `spend_meter_release` (reservation id, reason).
 */
final class SpendMeter
{
    public static function present(): bool
    {
        return defined('SPEND_METER_API') && constant('SPEND_METER_API') === 1;
    }

    /**
     * @param array<string, mixed> $intent
     *
     * @return array<string, mixed>
     */
    public static function preSpend(array $intent): array
    {
        $kind = (string) ($intent['kind'] ?? 'transcribe');
        $meter = [
            'purpose' => 'audio-to-post.' . $kind,
            'caller' => 'audio-to-post',
            'provider' => (string) ($intent['provider'] ?? ''),
            'model' => (string) ($intent['model'] ?? ''),
        ];
        if ($kind === 'transcribe') {
            $meter['est_units'] = round(((int) ($intent['seconds'] ?? 0)) / 60, 3);
        } else {
            $meter['est_input_tokens'] = (int) ($intent['tokens_in'] ?? 0);
            $meter['max_output_tokens'] = (int) ($intent['tokens_out'] ?? 0);
        }
        $res = apply_filters('spend_meter_pre_spend', null, $meter);

        return is_array($res) ? $res : [];
    }

    /**
     * @param array<string, mixed> $usage
     */
    public static function record(?string $reservation, array $usage): void
    {
        do_action('spend_meter_record', $usage + ['reservation_id' => $reservation, 'caller' => 'audio-to-post']);
    }

    public static function release(string $reservation, string $reason): void
    {
        do_action('spend_meter_release', $reservation, $reason);
    }
}
