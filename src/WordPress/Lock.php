<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * A per-run lock stored as a non-autoloaded option. `add_option` is an
 * insert, so only one process gets a fresh lock; an expired lock can be
 * taken over (case 36: 15-minute expiry, renewed after each chunk).
 */
final class Lock
{
    public const TTL = 900;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function acquire(string $name, int $ttl = self::TTL): ?string
    {
        $key = 'audiopost_lock_' . $name;
        $token = bin2hex(random_bytes(8));
        $value = ['token' => $token, 'expires' => $this->clock->now() + $ttl];
        if (add_option($key, $value, '', false)) {
            return $token;
        }
        $current = Options::fresh($key, null);
        if (is_array($current) && (int) ($current['expires'] ?? 0) > $this->clock->now()) {
            return null;
        }
        delete_option($key);

        return add_option($key, $value, '', false) ? $token : null;
    }

    public function renew(string $name, string $token, int $ttl = self::TTL): bool
    {
        $key = 'audiopost_lock_' . $name;
        $current = Options::fresh($key, null);
        if (!is_array($current) || ($current['token'] ?? '') !== $token) {
            return false;
        }
        update_option($key, ['token' => $token, 'expires' => $this->clock->now() + $ttl], false);

        return true;
    }

    public function release(string $name, string $token): void
    {
        $key = 'audiopost_lock_' . $name;
        $current = Options::fresh($key, null);
        if (is_array($current) && ($current['token'] ?? '') === $token) {
            delete_option($key);
        }
    }

    public function isHeld(string $name): bool
    {
        $current = Options::fresh('audiopost_lock_' . $name, null);

        return is_array($current) && (int) ($current['expires'] ?? 0) > $this->clock->now();
    }
}
