<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Plugin;

/**
 * Removes what the plugin created (section 6.2), on every site of a
 * network.
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }

            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        Plugin::reset();
        $p = Plugin::instance();
        $settings = get_option(Settings::OPTION, []);
        $removeAudio = is_array($settings) && !empty($settings['remove_recordings_on_uninstall']);
        $ids = get_posts(['post_type' => RunRepository::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
        foreach (is_array($ids) ? $ids : [] as $id) {
            foreach (['transcript', 'transcript_original'] as $k) {
                $v = $p->repo()->meta((int) $id, $k);
                if (is_array($v) && is_string($v['file'] ?? null) && preg_match('/^[a-f0-9]{32}\.json$/', $v['file']) === 1) {
                    $f = $p->files()->transcriptDir() . '/' . $v['file'];
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
            }
            wp_delete_post((int) $id, true);
        }
        delete_post_meta_by_key('_audiopost_run_id');
        delete_post_meta_by_key('_audiopost_mode');
        delete_post_meta_by_key('_audiopost_review_notes');
        if ($removeAudio) {
            // No run is left to carry the log line: it goes to the PHP error log.
            $p->files()->removeAll(static function (string $line): void {
                error_log('[audio-to-post] ' . $line);
            });
        }
        foreach ([Settings::OPTION, Settings::SECRETS, KillSwitch::OPTION, PrivateStorage::REGISTRY, AdapterRegistry::LIMITS_OPTION, Runner::HOLD_OPTION, SpendGuard::HOLDS, UsageLedger::BUFFER, 'audiopost_db_version', 'audiopost_ticks', 'audiopost_notice_cap', 'audiopost_notice_provider', 'audiopost_orphan_log'] as $o) {
            delete_option($o);
        }
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'audiopost\\_lock\\_%' OR option_name LIKE 'audiopost\\_usage\\_%'");
        $wpdb->query('DROP TABLE IF EXISTS ' . UsageLedger::table());
        wp_clear_scheduled_hook(Hooks::TICK);
        wp_clear_scheduled_hook(Hooks::DAILY);
    }
}
