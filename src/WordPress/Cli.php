<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\AudioToPostException;
use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Source;

/**
 * Turn recordings into transcripts and draft posts.
 *
 * Exit codes: 0 success, 1 error, 3 stopped by the kill switch, 4 stopped
 * by a daily cap.
 */
final class Cli
{
    public const EXIT_DISABLED = 3;
    public const EXIT_CAP = 4;

    /**
     * Create a run, fetch and probe it, and print the estimate.
     *
     * ## OPTIONS
     *
     * [<path-or-url>]
     * : A local audio file or an audio URL.
     *
     * [--attachment=<id>]
     * : Use a Media Library attachment.
     *
     * [--feed=<url>]
     * : A podcast feed URL (with --episode).
     *
     * [--episode=<guid>]
     * : The episode GUID in the feed.
     *
     * [--mode=<mode>]
     * : article, show_notes, transcript or none.
     *
     * [--rights-confirmed]
     * : You have the right to transcribe and publish from this recording.
     *
     * [--yes]
     * : Confirm the estimate at once (for scripts).
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function add(array $args, array $assoc): void
    {
        $p = Plugin::instance();
        $source = null;
        if (isset($assoc['attachment'])) {
            $source = ['kind' => Source::ATTACHMENT, 'attachment_id' => (int) $assoc['attachment']];
        } elseif (isset($assoc['feed'], $assoc['episode'])) {
            try {
                $episodes = $p->runs()->feedEpisodes((string) $assoc['feed']);
            } catch (AudioToPostException $e) {
                \WP_CLI::error($e->errorCode() . ': ' . $e->getMessage());

                return;
            }
            foreach ($episodes as $ep) {
                if ($ep['guid'] === $assoc['episode']) {
                    $source = ['kind' => Source::FEED_EPISODE, 'feed_url' => (string) $assoc['feed'], 'guid' => $ep['guid'], 'episode_title' => $ep['title'], 'published' => $ep['published'], 'enclosure_url' => $ep['enclosure_url']];
                }
            }
            if ($source === null) {
                \WP_CLI::error('Episode not found in the newest ' . count($episodes) . ' items. Pass its enclosure URL instead.');

                return;
            }
        } elseif (isset($args[0]) && preg_match('#^https?://#i', $args[0]) === 1) {
            $source = ['kind' => Source::URL, 'url' => $args[0]];
        } elseif (isset($args[0]) && is_file($args[0])) {
            $att = self::importFile($args[0]);
            if ($att === 0) {
                \WP_CLI::error('Could not add the file to the Media Library.');

                return;
            }
            $source = ['kind' => Source::ATTACHMENT, 'attachment_id' => $att];
        } else {
            \WP_CLI::error('Give a file, a URL, --attachment or --feed with --episode.');

            return;
        }
        $options = ['rights_confirmed' => isset($assoc['rights-confirmed'])];
        if (isset($assoc['mode'])) {
            $options['mode'] = (string) $assoc['mode'];
        }
        try {
            $run = $p->runs()->create($source, $options, get_current_user_id());
            $p->runs()->prepare($run->id);
            $run = $p->repo()->get($run->id);
            if ($run === null) {
                \WP_CLI::error('The run disappeared.');

                return;
            }
            if ($run->state === RunState::FAILED) {
                \WP_CLI::error(sprintf('Run %d failed: %s: %s', $run->id, (string) $run->errorCode, (string) $run->errorMessage));

                return;
            }
            \WP_CLI::log(sprintf('Run %d created (%s).', $run->id, $run->state));
            $this->printEstimate($run);
            if (isset($assoc['yes']) && $run->state === RunState::AWAITING_CONFIRMATION && $run->estimate !== null) {
                $res = $p->runs()->confirm($run->id, $run->estimate->fingerprint, false, get_current_user_id());
                \WP_CLI::success($res['queued_for_tomorrow'] ? 'Confirmed; queued for tomorrow because of a daily cap.' : 'Confirmed and queued.');
            }
        } catch (AudioToPostException $e) {
            \WP_CLI::error($e->errorCode() . ': ' . $e->getMessage());
        }
    }

    /**
     * Print the current estimate.
     *
     * <run>
     * : Run id.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function estimate(array $args, array $assoc): void
    {
        $run = $this->loadRun($args);
        $this->printEstimate($run);
    }

    /**
     * Confirm the current estimate of a run.
     *
     * <run>
     * : Run id.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function confirm(array $args, array $assoc): void
    {
        $run = $this->loadRun($args);
        try {
            $res = Plugin::instance()->runs()->confirm($run->id, (string) $run->estimate?->fingerprint, false, get_current_user_id());
            \WP_CLI::success($res['queued_for_tomorrow'] ? 'Queued for tomorrow (daily cap).' : 'Queued.');
        } catch (AudioToPostException $e) {
            \WP_CLI::error($e->errorCode() . ': ' . $e->getMessage());
        }
    }

    /**
     * Process due work until the queue is empty or the time is up.
     *
     * [--time=<seconds>]
     * : Wall-clock limit. Default 3000.
     *
     * [--max-chunks=<n>]
     * : Stop after this many paid calls.
     *
     * [--run=<id>]
     * : Only this run.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $p = Plugin::instance();
        $limit = isset($assoc['time']) ? max(1, (int) $assoc['time']) : 3000;
        $max = isset($assoc['max-chunks']) ? max(1, (int) $assoc['max-chunks']) : null;
        $only = isset($assoc['run']) ? (int) $assoc['run'] : null;
        $end = microtime(true) + $limit;
        $calls = 0;
        $stopped = null;
        do {
            $left = $end - microtime(true);
            $res = $p->runner()->tick(max(1.0, $left), $max === null ? null : $max - $calls, $only);
            $calls += $res['calls'];
            $stopped = $res['stopped'] ?? $stopped;
            if (in_array($res['stopped'], ['kill_switch', 'daily_cap'], true) || ($max !== null && $calls >= $max)) {
                break;
            }
        } while ($res['runs'] > 0 && microtime(true) < $end);
        if (KillSwitch::isOn() && $this->hasPaidWork()) {
            $stopped = 'kill_switch';
        }
        \WP_CLI::log(sprintf('%d paid call(s) made.', $calls));
        if ($stopped === 'kill_switch') {
            \WP_CLI::warning('Stopped: the kill switch is on.');
            \WP_CLI::halt(self::EXIT_DISABLED);
        }
        if ($stopped === 'daily_cap') {
            \WP_CLI::warning('Stopped: a daily cap is reached.');
            \WP_CLI::halt(self::EXIT_CAP);
        }
        \WP_CLI::success('Done.');
    }

    private function hasPaidWork(): bool
    {
        return Plugin::instance()->repo()->find([RunState::QUEUED, RunState::TRANSCRIBING, RunState::PAUSED, RunState::DRAFTING], 1) !== [];
    }

    /**
     * Kill switch, caps, usage today and runs by state.
     *
     * [--format=<format>]
     * : table or json.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $p = Plugin::instance();
        $counts = [];
        foreach (RunState::ALL as $s) {
            $counts[$s] = count($p->repo()->find([$s], 1000));
        }
        $data = ['kill_switch' => KillSwitch::isOn(), 'today' => $p->guard()->today(), 'runs' => $counts];
        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) json_encode($data, JSON_PRETTY_PRINT));

            return;
        }
        \WP_CLI::log('Kill switch: ' . ($data['kill_switch'] ? 'ON' : 'off'));
        foreach ($data['today'] as $unit => $row) {
            \WP_CLI::log(sprintf('Today %s: used %s, cap %s, left %s', $unit, $row['used'], $row['cap'] ?? 'none', $row['left'] ?? '-'));
        }
        foreach ($counts as $s => $n) {
            if ($n > 0) {
                \WP_CLI::log(sprintf('%s: %d', $s, $n));
            }
        }
    }

    /**
     * List runs.
     *
     * [--state=<state>]
     * : Only runs in this state.
     *
     * [--limit=<n>]
     * : Default 50.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $p = Plugin::instance();
        $state = isset($assoc['state']) ? [(string) $assoc['state']] : [];
        foreach ($p->repo()->find($state, isset($assoc['limit']) ? (int) $assoc['limit'] : 50, null, 'DESC') as $id) {
            $r = $p->repo()->get($id);
            if ($r !== null) {
                \WP_CLI::log(sprintf("%d\t%s\t%d/%d\t%s", $r->id, $r->state, $r->chunksDone, $r->chunksTotal, $r->title()));
            }
        }
    }

    /**
     * Show one run with its chunks and review notes.
     *
     * <run>
     * : Run id.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function show(array $args, array $assoc): void
    {
        $run = $this->loadRun($args);
        $repo = Plugin::instance()->repo();
        \WP_CLI::line((string) json_encode($run->toArray() + [
            'review_notes' => $repo->meta($run->id, 'review_notes', []),
            'usage' => $repo->usage($run->id),
            'log' => array_slice($repo->logs($run->id), -30),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Cancel a run.
     *
     * <run>
     * : Run id.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function cancel(array $args, array $assoc): void
    {
        $run = $this->loadRun($args);
        Plugin::instance()->runs()->cancel($run->id) ? \WP_CLI::success('Cancel requested.') : \WP_CLI::error('This run cannot be cancelled now.');
    }

    /**
     * Retry a failed run.
     *
     * <run>
     * : Run id.
     *
     * [--yes]
     * : Accept a changed remaining estimate.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function retry(array $args, array $assoc): void
    {
        $run = $this->loadRun($args);
        $p = Plugin::instance();
        try {
            $p->runs()->retry($run->id);
            \WP_CLI::success('Queued for retry.');
        } catch (AudioToPostException $e) {
            if ($e->errorCode() === 'estimate_stale' && isset($assoc['yes'])) {
                $est = $p->repo()->meta($run->id, 'confirmed_remaining');
                $p->runs()->retry($run->id, is_string($est) ? $est : null);
                \WP_CLI::success('Queued for retry with the new estimate.');

                return;
            }
            \WP_CLI::error($e->errorCode() . ': ' . $e->getMessage());
        }
    }

    /**
     * Queue another draft from the transcript.
     *
     * <run>
     * : Run id.
     *
     * --mode=<mode>
     * : article, show_notes or transcript.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function draft(array $args, array $assoc): void
    {
        $run = $this->loadRun($args);
        try {
            $job = Plugin::instance()->runs()->createDraft($run->id, (string) ($assoc['mode'] ?? ''));
            \WP_CLI::success('Draft queued as job ' . $job . '. Run `wp audio-to-post run` to process it.');
        } catch (AudioToPostException $e) {
            \WP_CLI::error($e->errorCode() . ': ' . $e->getMessage());
        }
    }

    /**
     * Export the transcript to standard output.
     *
     * <run>
     * : Run id.
     *
     * --format=<format>
     * : json, vtt, srt or txt.
     *
     * [--original]
     * : The provider's original, not the edited version.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function export(array $args, array $assoc): void
    {
        $run = $this->loadRun($args);
        try {
            \WP_CLI::line(rtrim(Plugin::instance()->runs()->export($run->id, (string) ($assoc['format'] ?? 'json'), isset($assoc['original'])), "\n"));
        } catch (AudioToPostException $e) {
            \WP_CLI::error($e->errorCode() . ': ' . $e->getMessage());
        }
    }

    /**
     * Turn the kill switch on.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function disable(array $args, array $assoc): void
    {
        KillSwitch::set(true, 'wp-cli');
        \WP_CLI::success('Kill switch on. No paid call will start.');
    }

    /**
     * Turn the kill switch off.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function enable(array $args, array $assoc): void
    {
        KillSwitch::set(false, 'wp-cli');
        if (KillSwitch::forcedByConstant()) {
            \WP_CLI::warning('AUDIOPOST_DISABLED is set in wp-config.php; the switch stays on.');

            return;
        }
        \WP_CLI::success('Kill switch off.');
    }

    /**
     * Apply retention now: only files and runs already due.
     *
     * [--dry-run]
     * : List what would be deleted.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function purge(array $args, array $assoc): void
    {
        $p = Plugin::instance();
        if (isset($assoc['dry-run'])) {
            $due = $p->files()->sweep(static function (): void {
            }, $p->settings()->int('keep_recording_hours'), true);
            foreach ($due as $d) {
                \WP_CLI::log(sprintf('would delete %s (%d bytes, run %d, due %s, %s)', $d['id'], $d['bytes'], $d['run'], gmdate('c', $d['due']), $d['reason']));
            }
            \WP_CLI::success(count($due) . ' file(s) due.');

            return;
        }
        $p->runner()->daily();
        \WP_CLI::success('Retention applied.');
    }

    /**
     * @param list<string> $args
     */
    private function loadRun(array $args): Run
    {
        $run = Plugin::instance()->repo()->get((int) ($args[0] ?? 0));
        if ($run === null) {
            \WP_CLI::error('Run not found.');
            throw new \RuntimeException('unreachable');
        }

        return $run;
    }

    private function printEstimate(Run $run): void
    {
        $e = $run->estimate;
        if ($e === null) {
            \WP_CLI::log('No estimate yet (state ' . $run->state . ').');

            return;
        }
        \WP_CLI::log(sprintf('Duration: %s ms (%s)', $run->audio->durationMs ?? 'unknown', $e->upperBound ? 'upper bound from size' : ($e->durationExact ? 'exact' : 'estimated')));
        \WP_CLI::log(sprintf('Chunks: %d, billed audio: %.1f min', $e->chunks, $e->billedMinutes()));
        \WP_CLI::log(sprintf('Draft (%s): about %d input tokens, up to %d output tokens', $e->mode, $e->tokensIn, $e->tokensOut));
        \WP_CLI::log('Price: ' . $e->priceLine());
        \WP_CLI::log('Fingerprint: ' . $e->fingerprint);
    }

    private static function importFile(string $path): int
    {
        $name = basename($path);
        $data = file_get_contents($path);
        if ($data === false) {
            return 0;
        }
        if ($name === '') {
            return 0;
        }
        $up = wp_upload_bits($name, null, $data);
        if (!empty($up['error'])) {
            return 0;
        }
        $type = wp_check_filetype($name);
        $id = wp_insert_attachment(['post_mime_type' => (string) ($type['type'] ?: 'audio/mpeg'), 'post_title' => $name, 'post_status' => 'inherit'], $up['file']);

        return is_int($id) ? $id : 0;
    }
}
