<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\CapException;
use IndianOpinion\AudioToPost\DisabledException;
use IndianOpinion\AudioToPost\Estimate;
use IndianOpinion\AudioToPost\Estimate\Money;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\StateException;

/**
 * The checks before every paid call (case 33), in this order, with fresh
 * reads: the kill switch, the daily caps, the `audiopost_can_spend`
 * filter, spend-meter when present, and the run's cancel flag.
 */
final class SpendGuard
{
    public function __construct(
        private readonly Settings $settings,
        private readonly UsageLedger $ledger,
        private readonly Clock $clock,
        private readonly RunRepository $repo,
    ) {
    }

    /**
     * Effective caps: seconds, tokens and money micros, null for no cap.
     * Constants may only lower a cap (section 5.8).
     *
     * @return array{seconds: ?int, tokens: ?int, money: ?int}
     */
    public function caps(): array
    {
        $this->settings->flush();
        $minutes = self::lower(self::parseInt($this->settings->string('cap_minutes')), self::constInt('AUDIOPOST_DAILY_MINUTES'));
        $tokens = self::lower(self::parseInt($this->settings->string('cap_tokens')), self::constInt('AUDIOPOST_DAILY_TOKENS'));
        $money = self::lower(Money::parse($this->settings->string('cap_money')), defined('AUDIOPOST_DAILY_MONEY') ? Money::parse((string) constant('AUDIOPOST_DAILY_MONEY')) : null);

        return ['seconds' => $minutes === null ? null : $minutes * 60, 'tokens' => $tokens, 'money' => $money];
    }

    /**
     * What is used and left today in every unit a cap is set in (case 51).
     *
     * @return array<string, array{used: string, cap: ?string, left: ?string}>
     */
    public function today(): array
    {
        $t = $this->ledger->totals();
        $caps = $this->caps();
        $out = [
            'minutes' => self::row(intdiv($t['seconds'] + 59, 60), $caps['seconds'] === null ? null : intdiv($caps['seconds'], 60)),
            'tokens' => self::row($t['tokens_in'] + $t['tokens_out'], $caps['tokens']),
        ];
        $money = $caps['money'];
        $out['money'] = [
            'used' => $t['has_money'] ? Money::format($t['money_micros']) : '0',
            'cap' => $money === null ? null : Money::format($money),
            'left' => $money === null ? null : Money::format(max(0, $money - $t['money_micros'])),
        ];

        return $out;
    }

    /**
     * Which cap an estimate does not fit, today or at all.
     *
     * @return array{unit: string, whole: bool}|null
     */
    public function checkEstimate(Estimate $e): ?array
    {
        $caps = $this->caps();
        $t = $this->ledger->totals();
        $money = isset($e->price['total_micros']) ? (int) $e->price['total_micros'] : null;
        $checks = [
            'minutes' => [$caps['seconds'], $t['seconds'], $e->billedSeconds],
            'tokens' => [$caps['tokens'], $t['tokens_in'] + $t['tokens_out'], $e->tokensIn + $e->tokensOut],
            'money' => [$money === null ? null : $caps['money'], $t['money_micros'], (int) $money],
        ];
        foreach ($checks as $unit => [$cap, $used, $need]) {
            if ($cap === null || $need <= 0) {
                continue;
            }
            if ($need > $cap) {
                return ['unit' => $unit, 'whole' => true];
            }
            if ($used + $need > $cap) {
                return ['unit' => $unit, 'whole' => false];
            }
        }

        return null;
    }

    /**
     * Run every check before one paid call. Returns a spend-meter
     * reservation id when one was given.
     *
     * @param array{kind: string, seconds: int, tokens_in: int, tokens_out: int, money_micros: ?int, provider: string, model: string} $intent
     *
     * @throws DisabledException
     * @throws CapException      daily_cap, denied, spend_meter
     * @throws StateException    cancelled
     */
    public function authorize(Run $run, array $intent): ?string
    {
        if (KillSwitch::isOn()) {
            throw new DisabledException('kill_switch', 'The kill switch is on.');
        }
        $caps = $this->caps();
        $t = $this->ledger->totals();
        $day = $this->clock->localDay();
        $checks = [
            'minutes' => [$caps['seconds'], $t['seconds'], $intent['seconds']],
            'tokens' => [$caps['tokens'], $t['tokens_in'] + $t['tokens_out'], $intent['tokens_in'] + $intent['tokens_out']],
            'money' => [$intent['money_micros'] === null ? null : $caps['money'], $t['money_micros'], (int) $intent['money_micros']],
        ];
        foreach ($checks as $unit => [$cap, $used, $need]) {
            if ($cap !== null && $need > 0 && $used + $need > $cap) {
                $fmt = static fn (int $v): string => $unit === 'minutes' ? (string) round($v / 60, 2) : ($unit === 'money' ? Money::format($v) : (string) $v);
                do_action('audiopost_cap_reached', $unit, $fmt($used), $fmt($cap), $day);
                Options::set('audiopost_notice_cap', $day);
                throw new CapException('daily_cap', sprintf('The daily %s cap does not leave room for the next call.', $unit));
            }
        }
        $intentForFilters = $intent + ['run_id' => $run->id, 'caller' => 'audio-to-post'];
        $allow = apply_filters('audiopost_can_spend', true, $intentForFilters);
        if ($allow !== true) {
            throw new CapException('denied', 'A site filter denied this call.');
        }
        $reservation = null;
        if (SpendMeter::present()) {
            $res = SpendMeter::preSpend($intent);
            if (($res['allowed'] ?? null) !== true) {
                $reason = is_string($res['reason'] ?? null) ? $res['reason'] : 'denied';
                throw new CapException('spend_meter', 'spend-meter said no: ' . $reason);
            }
            $reservation = is_string($res['reservation_id'] ?? null) || is_int($res['reservation_id'] ?? null) ? (string) $res['reservation_id'] : null;
        }
        wp_cache_delete($run->id, 'post_meta');
        if ($this->repo->meta($run->id, 'cancel')) {
            if ($reservation !== null) {
                SpendMeter::release($reservation, 'cancelled');
            }
            throw new StateException('cancelled', 'The run was cancelled.');
        }

        return $reservation;
    }

    private static function parseInt(string $v): ?int
    {
        $v = trim($v);

        return $v === '' || !ctype_digit($v) ? null : (int) $v;
    }

    private static function constInt(string $name): ?int
    {
        if (!defined($name)) {
            return null;
        }
        $v = constant($name);

        return is_numeric($v) ? (int) $v : null;
    }

    private static function lower(?int $option, ?int $constant): ?int
    {
        if ($constant === null) {
            return $option;
        }

        return $option === null ? $constant : min($option, $constant);
    }

    /** @return array{used: string, cap: ?string, left: ?string} */
    private static function row(int $used, ?int $cap): array
    {
        return ['used' => (string) $used, 'cap' => $cap === null ? null : (string) $cap, 'left' => $cap === null ? null : (string) max(0, $cap - $used)];
    }
}
