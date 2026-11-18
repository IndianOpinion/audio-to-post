<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\RunState;

/**
 * Wires the plugin into WordPress. Nothing here runs work on public
 * pages (performance budget 9.1).
 */
final class Hooks
{
    public const TICK = 'audiopost_tick';
    public const DAILY = 'audiopost_daily';

    public static function register(): void
    {
        add_action('init', [RunRepository::class, 'registerPostType']);
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action(self::TICK, [self::class, 'tick']);
        add_action(self::DAILY, [self::class, 'daily']);
        add_action('init', [self::class, 'ensureEvents'], 20);
        add_action('rest_api_init', [Rest::class, 'register']);
        add_action('gen_queue_register_types', [self::class, 'genQueueTypes']);
        add_filter('wp_privacy_personal_data_exporters', [Privacy::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [Privacy::class, 'erasers']);
        add_action('admin_init', [Privacy::class, 'policy']);
        add_action('before_delete_post', [self::class, 'beforeDeletePost']);
        if (is_admin()) {
            Admin::register();
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('audio-to-post', Cli::class);
        }
    }

    /**
     * @param array<string, array{interval: int, display: string}> $s
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public static function schedules(array $s): array
    {
        $s['audiopost_minute'] = ['interval' => 60, 'display' => 'Every minute (audio-to-post)'];

        return $s;
    }

    public static function ensureEvents(): void
    {
        if (!wp_next_scheduled(self::TICK)) {
            wp_schedule_event(time() + 60, 'audiopost_minute', self::TICK);
        }
        if (!wp_next_scheduled(self::DAILY)) {
            wp_schedule_event(time() + 3600, 'daily', self::DAILY);
        }
    }

    public static function tick(): void
    {
        $runner = Plugin::instance()->runner();
        if (defined('AUDIOPOST_DISABLE_CRON_RUNNER') && constant('AUDIOPOST_DISABLE_CRON_RUNNER')) {
            // Housekeeping only; paid work runs from WP-CLI.
            $runner->housekeeping();
            $runner->sweep();

            return;
        }
        $runner->tick();
    }

    public static function daily(): void
    {
        Plugin::instance()->runner()->daily();
    }

    public static function genQueueTypes(mixed $registry = null): void
    {
        GenQueue::registerTypes($registry, [Plugin::instance()->runner(), 'handleJob']);
    }

    /** A draft deleted by hand: drop it from its run (case 70). */
    public static function beforeDeletePost(int $postId): void
    {
        $runId = (int) get_post_meta($postId, '_audiopost_run_id', true);
        if ($runId <= 0) {
            return;
        }
        $repo = Plugin::instance()->repo();
        if (!$repo->exists($runId)) {
            return;
        }
        $drafts = $repo->meta($runId, 'drafts', []);
        $kept = [];
        foreach (is_array($drafts) ? $drafts : [] as $d) {
            if (is_array($d) && (int) ($d['post_id'] ?? 0) !== $postId) {
                $kept[] = $d;
            }
        }
        $repo->setMeta($runId, 'drafts', $kept);
        $repo->log($runId, 'draft post ' . $postId . ' deleted; the run is kept');
    }

    public static function activate(): void
    {
        UsageLedger::install();
        RunRepository::registerPostType();
        Plugin::instance()->files()->dir();
        self::ensureEvents();
    }

    /**
     * Deactivation clears scheduled events only (case 58). Runs in
     * flight are picked up again on reactivation.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::TICK);
        wp_clear_scheduled_hook(self::DAILY);
    }

    /**
     * States the runner resumes after reactivation (case 58).
     *
     * @return list<string>
     */
    public static function resumable(): array
    {
        return [RunState::QUEUED, RunState::TRANSCRIBING, RunState::PAUSED, RunState::DRAFTING];
    }
}
