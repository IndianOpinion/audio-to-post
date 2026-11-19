<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * A per-run lock stored as a non-autoloaded option. `add_option` is an
 * insert, so only one process gets a fresh lock; an expired lock is taken
 * over with a conditional UPDATE, so only one process wins that too
 * (case 36: 15-minute expiry, renewed after each chunk).
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
        if ($current === null) {
            // Released between our insert and our read: one more insert.
            return add_option($key, $value, '', false) ? $token : null;
        }

        return self::swap($key, $current, $value) ? $token : null;
    }

    /**
     * Take over an expired lock with one conditional UPDATE, so of two
     * processes that both saw it expire only one wins. A plain delete and
     * add would let the second one delete the first one's fresh lock.
     *
     * @param array<string, mixed> $value
     */
    private static function swap(string $key, mixed $current, array $value): bool
    {
        global $wpdb;
        $won = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            maybe_serialize($value),
            $key,
            maybe_serialize($current)
        )) === 1;
        wp_cache_delete($key, 'options');
        wp_cache_delete('alloptions', 'options');

        return $won;
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
