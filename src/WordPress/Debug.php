<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * `error_log` lines, only when WP_DEBUG_LOG is on (section 11.5). Never a
 * key, never transcript text.
 */
final class Debug
{
    /** @var array<string, true> */
    private static array $once = [];

    public static function log(string $line): void
    {
        if (defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG')) {
            error_log('[audio-to-post] ' . Settings::redact($line));
        }
    }

    /** Log a line once per request. */
    public static function once(string $key, string $line): bool
    {
        if (isset(self::$once[$key])) {
            return false;
        }
        self::$once[$key] = true;
        self::log($line);

        return true;
    }

    public static function reset(): void
    {
        self::$once = [];
    }
}
