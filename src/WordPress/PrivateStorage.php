<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * Private files under `uploads/audio-to-post/` (section 6.1 item 4):
 * random 32-hex names, an index.php and a deny-all .htaccess. Audio
 * copies and chunk files are tracked in a registry with their retention
 * due time (case 80). Large transcripts live in a `transcripts/`
 * subdirectory and follow the run, not the audio rule.
 */
final class PrivateStorage
{
    public const REGISTRY = 'audiopost_files';
    public const ORPHAN_MIN_AGE = 86400;

    public function __construct(private readonly Clock $clock, private readonly ?string $baseDir = null)
    {
    }

    public function dir(): string
    {
        if ($this->baseDir !== null) {
            $dir = $this->baseDir;
        } else {
            $uploads = wp_upload_dir(null, false);
            $dir = rtrim((string) $uploads['basedir'], '/') . '/audio-to-post';
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        foreach (['index.php' => "<?php\n// Silence is golden.\n", '.htaccess' => "Require all denied\nDeny from all\n"] as $name => $body) {
            if (!is_file($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $body);
            }
        }

        return $dir;
    }

    public function transcriptDir(): string
    {
        $dir = $this->dir() . '/transcripts';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }

        return $dir;
    }

    /** A new random file id (32 hex characters). */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function path(string $id): string
    {
        if (preg_match('/^[a-f0-9]{32}(\.[a-z0-9]{1,5})?$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid private file id.');
        }

        return $this->dir() . '/' . $id;
    }

    /**
     * Register an audio or chunk file against a run.
     */
    public function register(int $runId, string $id, string $kind): void
    {
        $reg = $this->registry();
        $reg[$id] = ['run' => $runId, 'kind' => $kind, 'created' => $this->clock->now(), 'due' => null, 'reason' => '', 'failures' => 0];
        $this->save($reg);
    }

    public function unregister(string $id): void
    {
        $reg = $this->registry();
        unset($reg[$id]);
        $this->save($reg);
    }

    /** @return array<string, array{run: int, kind: string, created: int, due: ?int, reason: string, failures: int}> */
    public function registry(): array
    {
        $reg = Options::fresh(self::REGISTRY, []);
        $out = [];
        foreach (is_array($reg) ? $reg : [] as $id => $e) {
            if (is_array($e)) {
                $out[(string) $id] = [
                    'run' => (int) ($e['run'] ?? 0),
                    'kind' => (string) ($e['kind'] ?? 'audio'),
                    'created' => (int) ($e['created'] ?? 0),
                    'due' => isset($e['due']) && is_numeric($e['due']) ? (int) $e['due'] : null,
                    'reason' => (string) ($e['reason'] ?? ''),
                    'failures' => (int) ($e['failures'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /** @return array<string, array{run: int, kind: string, created: int, due: ?int, reason: string, failures: int}> */
    public function filesOf(int $runId): array
    {
        return array_filter($this->registry(), static fn (array $e): bool => $e['run'] === $runId);
    }

    /**
     * Start the retention clock for a run's files that have none yet.
     */
    public function startClock(int $runId, int $hours, string $setting): void
    {
        $reg = $this->registry();
        $due = $this->clock->now() + max(0, $hours) * 3600;
        $changed = false;
        foreach ($reg as $id => $e) {
            if ($e['run'] === $runId && $e['due'] === null) {
                $reg[$id]['due'] = $due;
                $reg[$id]['reason'] = $setting . '=' . $hours;
                $changed = true;
            }
        }
        if ($changed) {
            $this->save($reg);
        }
    }

    /** A run became active again (retry, re-estimate): no deletion while it runs. */
    public function stopClock(int $runId): void
    {
        $reg = $this->registry();
        foreach ($reg as $id => $e) {
            if ($e['run'] === $runId) {
                $reg[$id]['due'] = null;
                $reg[$id]['reason'] = '';
            }
        }
        $this->save($reg);
    }

    /**
     * A run was deleted: its files stay under the same rule with the due
     * time already computed, now with no run.
     */
    public function handOver(int $runId, int $hours, string $setting): void
    {
        $this->startClock($runId, $hours, $setting);
        $reg = $this->registry();
        foreach ($reg as $id => $e) {
            if ($e['run'] === $runId) {
                $reg[$id]['run'] = 0;
            }
        }
        $this->save($reg);
    }

    /**
     * Delete every registered file whose due time has passed, and orphans
     * that are due. Every deletion is logged through $log and fires
     * `audiopost_recording_deleted`. Returns what was deleted.
     *
     * @param callable(int, string): void $log run id (0 for none) and line
     * @param bool $dryRun only report
     * @param (callable(int): ?\IndianOpinion\AudioToPost\Run)|null $runOf run lookup for the action
     *
     * @return list<array{id: string, run: int, bytes: int, reason: string, due: int}>
     */
    public function sweep(callable $log, int $keepRecordingHours, bool $dryRun = false, ?callable $runOf = null): array
    {
        $now = $this->clock->now();
        $reg = $this->registry();
        $done = [];
        foreach ($reg as $id => $e) {
            if ($e['due'] === null || $e['due'] > $now) {
                continue;
            }
            $path = $this->dir() . '/' . $id;
            $bytes = is_file($path) ? (int) filesize($path) : 0;
            if ($dryRun) {
                $done[] = ['id' => $id, 'run' => $e['run'], 'bytes' => $bytes, 'reason' => $e['reason'], 'due' => $e['due']];
                continue;
            }
            if (is_file($path) && !unlink($path)) {
                $reg[$id]['failures']++;
                $log($e['run'], sprintf('could not delete %s file %s (%d bytes, due %s); will retry', $e['kind'], $id, $bytes, gmdate('c', $e['due'])));
                continue;
            }
            unset($reg[$id]);
            $done[] = ['id' => $id, 'run' => $e['run'], 'bytes' => $bytes, 'reason' => $e['reason'], 'due' => $e['due']];
            $log($e['run'], sprintf('deleted %s file %s: %d bytes at %s, due %s under %s', $e['kind'], $id, $bytes, gmdate('c', $now), gmdate('c', $e['due']), $e['reason']));
            do_action('audiopost_recording_deleted', $runOf !== null && $e['run'] > 0 ? $runOf($e['run']) : null, $id, $bytes, $e['reason']);
        }
        if (!$dryRun) {
            $this->save($reg);
        }
        foreach ($this->orphans() as $o) {
            $due = $o['mtime'] + max(self::ORPHAN_MIN_AGE, $keepRecordingHours * 3600);
            if ($due > $now) {
                continue;
            }
            $reason = 'orphan, keep_recording_hours=' . $keepRecordingHours . ' (at least 24 h)';
            if (!$dryRun) {
                if (!unlink($this->dir() . '/' . $o['id'])) {
                    $log(0, sprintf('could not delete orphan %s; will retry', $o['id']));
                    continue;
                }
                $log(0, sprintf('deleted orphan %s: %d bytes at %s, due %s under %s', $o['id'], $o['bytes'], gmdate('c', $now), gmdate('c', $due), $reason));
                do_action('audiopost_recording_deleted', null, $o['id'], $o['bytes'], $reason);
            }
            $done[] = ['id' => $o['id'], 'run' => 0, 'bytes' => $o['bytes'], 'reason' => $reason, 'due' => $due];
        }

        return $done;
    }

    /**
     * Files in the private directory that no registry entry references.
     *
     * @return list<array{id: string, bytes: int, mtime: int}>
     */
    public function orphans(): array
    {
        $reg = $this->registry();
        $out = [];
        foreach (scandir($this->dir()) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f === 'index.php' || $f === '.htaccess' || $f === 'transcripts' || isset($reg[$f])) {
                continue;
            }
            if (str_ends_with($f, '.part') || preg_match('/^[a-f0-9]{32}(\.[a-z0-9]{1,5})?$/', $f) !== 1) {
                continue;
            }
            $p = $this->dir() . '/' . $f;
            if (is_file($p)) {
                $out[] = ['id' => $f, 'bytes' => (int) filesize($p), 'mtime' => (int) filemtime($p)];
            }
        }

        return $out;
    }

    /** Delete the whole directory (uninstall, only when the operator ticked it). */
    public function removeAll(): void
    {
        $dir = $this->dir();
        foreach (['transcripts', ''] as $sub) {
            $d = $sub === '' ? $dir : $dir . '/' . $sub;
            foreach (is_dir($d) ? (scandir($d) ?: []) : [] as $f) {
                if ($f !== '.' && $f !== '..' && is_file($d . '/' . $f)) {
                    unlink($d . '/' . $f);
                }
            }
        }
        foreach ([$dir . '/transcripts', $dir] as $d) {
            if (is_dir($d)) {
                rmdir($d);
            }
        }
        delete_option(self::REGISTRY);
    }

    /** @param array<string, array<string, mixed>> $reg */
    private function save(array $reg): void
    {
        Options::set(self::REGISTRY, $reg);
    }
}
