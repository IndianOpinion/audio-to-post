<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Run;

/**
 * Capability checks (section 8.1). Creating and acting on own runs needs
 * `upload_files` and `edit_posts`; other users' runs, settings, prices,
 * caps and the kill switch need `manage_options`. Both filterable.
 */
final class Access
{
    public static function createCap(): string
    {
        $c = apply_filters('audiopost_capability_create', 'upload_files');

        return is_string($c) && $c !== '' ? $c : 'upload_files';
    }

    public static function manageCap(): string
    {
        $c = apply_filters('audiopost_capability_manage', 'manage_options');

        return is_string($c) && $c !== '' ? $c : 'manage_options';
    }

    public static function canCreate(): bool
    {
        return current_user_can(self::createCap()) && current_user_can('edit_posts');
    }

    public static function canManage(): bool
    {
        return current_user_can(self::manageCap());
    }

    public static function canAct(?Run $run): bool
    {
        if ($run === null) {
            return false;
        }
        if (self::canManage()) {
            return true;
        }

        return self::canCreate() && $run->createdBy === get_current_user_id();
    }
}
