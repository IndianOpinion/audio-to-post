<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Estimate;

/**
 * Decimal money in millionths, so no float rounding creeps into an
 * estimate. Prices are the operator's own; none are shipped.
 */
final class Money
{
    /** Parse a non-negative decimal string; null when empty or invalid. */
    public static function parse(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '' || preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/', $v, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 1_000_000 + (int) str_pad($m[2] ?? '', 6, '0');
    }

    public static function format(int $micros, int $decimals = 4): string
    {
        $neg = $micros < 0;
        $micros = abs($micros);
        $scale = 10 ** (6 - $decimals);
        $rounded = intdiv($micros + intdiv($scale, 2), $scale);
        $unit = 10 ** $decimals;
        $s = intdiv($rounded, $unit) . ($decimals > 0 ? '.' . str_pad((string) ($rounded % $unit), $decimals, '0', STR_PAD_LEFT) : '');

        return ($neg ? '-' : '') . $s;
    }

    /** price (per unit, in micros) times quantity / divisor, rounded half up. */
    public static function times(int $priceMicros, int $quantity, int $divisor): int
    {
        if ($divisor <= 0) {
            return 0;
        }

        return intdiv($priceMicros * $quantity + intdiv($divisor, 2), $divisor);
    }
}
