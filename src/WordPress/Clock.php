<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * Wall-clock time, replaceable in tests.
 */
class Clock
{
    private ?int $fixed = null;

    public function now(): int
    {
        return $this->fixed ?? time();
    }

    /** Freeze time (tests). */
    public function set(int $ts): void
    {
        $this->fixed = $ts;
    }

    public function advance(int $seconds): void
    {
        $this->fixed = $this->now() + $seconds;
    }

    public function timezone(): \DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    }

    /** The site's local day, `Y-m-d`. */
    public function localDay(?int $ts = null): string
    {
        return (new \DateTimeImmutable('@' . ($ts ?? $this->now())))->setTimezone($this->timezone())->format('Y-m-d');
    }

    /** UTC timestamp of the next local midnight. */
    public function nextLocalMidnight(?int $ts = null): int
    {
        $d = (new \DateTimeImmutable('@' . ($ts ?? $this->now())))->setTimezone($this->timezone());

        return $d->modify('tomorrow')->getTimestamp();
    }
}
