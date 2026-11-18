<?php

/**
 * Public functions (section 5.2 of the PRD).
 */

declare(strict_types=1);

use IndianOpinion\AudioToPost\Adapter\TranscriptionAdapter;
use IndianOpinion\AudioToPost\AudioToPostException;
use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\WordPress\KillSwitch;

if (!function_exists('audiopost_error')) {
    /**
     * @internal Turn a plugin exception into a WP_Error with an HTTP status.
     */
    function audiopost_error(AudioToPostException $e): WP_Error
    {
        $status = match ($e->errorCode()) {
            'not_found' => 404,
            'estimate_stale', 'revision_conflict' => 409,
            'kill_switch' => 503,
            default => 400,
        };

        return new WP_Error($e->errorCode(), $e->getMessage(), ['status' => $status]);
    }

    /**
     * Create a run. Options: mode, language, adapter, rights_confirmed.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     */
    function audiopost_create_run(array $source, array $options = []): Run|WP_Error
    {
        try {
            return Plugin::instance()->runs()->create($source, $options, get_current_user_id());
        } catch (AudioToPostException $e) {
            return audiopost_error($e);
        }
    }

    function audiopost_get_run(int $id): ?Run
    {
        return Plugin::instance()->repo()->get($id);
    }

    /**
     * @return true|WP_Error
     */
    function audiopost_confirm_run(int $id, string $fingerprint): bool|WP_Error
    {
        try {
            Plugin::instance()->runs()->confirm($id, $fingerprint, false, get_current_user_id());

            return true;
        } catch (AudioToPostException $e) {
            return audiopost_error($e);
        }
    }

    function audiopost_cancel_run(int $id): bool
    {
        return Plugin::instance()->runs()->cancel($id);
    }

    /**
     * @return true|WP_Error
     */
    function audiopost_retry_run(int $id): bool|WP_Error
    {
        try {
            Plugin::instance()->runs()->retry($id);

            return true;
        } catch (AudioToPostException $e) {
            return audiopost_error($e);
        }
    }

    function audiopost_create_draft(int $id, string $mode): int|WP_Error
    {
        try {
            return Plugin::instance()->runs()->createDraft($id, $mode);
        } catch (AudioToPostException $e) {
            return audiopost_error($e);
        }
    }

    function audiopost_get_transcript(int $id, bool $original = false): ?Transcript
    {
        return Plugin::instance()->runs()->transcript($id, $original);
    }

    /**
     * Export as json, vtt, srt or txt. Returns an empty string when the run
     * has no transcript or the format is unknown.
     */
    function audiopost_export_transcript(int $id, string $format): string
    {
        try {
            return Plugin::instance()->runs()->export($id, $format);
        } catch (AudioToPostException $e) {
            return '';
        }
    }

    function audiopost_is_disabled(): bool
    {
        return KillSwitch::isOn();
    }

    /**
     * @return array{minutes: float, tokens_in: int, tokens_out: int, money: ?string}
     */
    function audiopost_usage_today(): array
    {
        $t = Plugin::instance()->ledger()->totals();

        return [
            'minutes' => round($t['seconds'] / 60, 2),
            'tokens_in' => $t['tokens_in'],
            'tokens_out' => $t['tokens_out'],
            'money' => $t['has_money'] ? \IndianOpinion\AudioToPost\Estimate\Money::format($t['money_micros']) : null,
        ];
    }

    function audiopost_register_adapter(TranscriptionAdapter $adapter): void
    {
        Plugin::instance()->adapters()->register($adapter);
    }
}
