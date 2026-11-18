<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * One switch for the whole plugin (case 55). The constant
 * AUDIOPOST_DISABLED forces it on; the option cannot override it.
 */
final class KillSwitch
{
    public const OPTION = 'audiopost_disabled';

    public static function forcedByConstant(): bool
    {
        return defined('AUDIOPOST_DISABLED') && (bool) constant('AUDIOPOST_DISABLED');
    }

    public static function isOn(): bool
    {
        return self::forcedByConstant() || (bool) Options::fresh(self::OPTION, false);
    }

    public static function set(bool $on, string $actor): void
    {
        $old = self::isOn();
        Options::set(self::OPTION, $on ? 1 : 0);
        $new = self::isOn();
        if ($old !== $new) {
            do_action('audiopost_kill_switch_changed', $old, $new, $actor);
        }
    }
}
