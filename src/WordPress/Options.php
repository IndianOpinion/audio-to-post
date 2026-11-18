<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * Fresh option reads: the object cache entry is dropped before reading so
 * a stale cache can never allow a paid call (section 10).
 */
final class Options
{
    public static function fresh(string $name, mixed $default = false): mixed
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }

        return get_option($name, $default);
    }

    public static function set(string $name, mixed $value): void
    {
        if (get_option($name, null) === null) {
            add_option($name, $value, '', false);
        } else {
            update_option($name, $value, false);
        }
    }
}
