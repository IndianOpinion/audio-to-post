<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Adapter\ChunkResult;
use IndianOpinion\AudioToPost\AudioInfo;
use IndianOpinion\AudioToPost\ChunkPlan;
use IndianOpinion\AudioToPost\Estimate;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Source;
use IndianOpinion\AudioToPost\StateException;
use IndianOpinion\AudioToPost\Transcript;

/**
 * Runs as a private custom post type with one meta key per item, JSON
 * encoded (section 6.1). Chunk results are one meta row per chunk.
 * Transcripts over 64 KB go to a private JSON file (ruling on open
 * question 4).
 */
final class RunRepository
{
    public const POST_TYPE = 'audiopost_run';
    public const INLINE_LIMIT = 65536;
    public const LOG_LIMIT = 500;
    public const P = '_audiopost_';

    public function __construct(
        private readonly Clock $clock,
        private readonly PrivateStorage $files,
        private readonly Settings $settings,
    ) {
    }

    public static function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => 'Audio runs',
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            'show_in_rest' => false,
            'show_in_nav_menus' => false,
            'query_var' => false,
            'rewrite' => false,
            'has_archive' => false,
            'supports' => ['title', 'author'],
            'can_export' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function create(Source $source, array $options, int $userId): int
    {
        $id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => mb_substr($source->label(), 0, 200),
            'post_author' => $userId,
        ], true);
        if (is_wp_error($id) || !is_int($id) || $id <= 0) {
            throw new StateException('storage_error', 'The run could not be stored.');
        }
        $this->setMeta($id, 'state', RunState::NEW);
        $this->setMeta($id, 'source', $source->toArray());
        $this->setMeta($id, 'options', $options);
        $this->setMeta($id, 'created', $this->clock->now());
        $this->setMeta($id, 'flags', []);
        $this->setMeta($id, 'chunks_done', 0);

        return $id;
    }

    public function exists(int $id): bool
    {
        $p = get_post($id);

        return $p instanceof \WP_Post && $p->post_type === self::POST_TYPE;
    }

    public function get(int $id): ?Run
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return null;
        }
        $src = $this->meta($id, 'source', []);
        try {
            $source = Source::fromArray(is_array($src) ? $src : []);
        } catch (\Throwable $e) {
            $source = new Source(Source::URL, ['url' => '']);
        }
        $audio = $this->meta($id, 'audio');
        $plan = $this->meta($id, 'plan');
        $est = $this->meta($id, 'estimate');
        $err = $this->meta($id, 'error');
        $drafts = $this->meta($id, 'drafts', []);
        $draftIds = [];
        foreach (is_array($drafts) ? $drafts : [] as $d) {
            if (is_array($d) && isset($d['post_id'])) {
                $draftIds[] = (int) $d['post_id'];
            }
        }
        $planObj = is_array($plan) ? ChunkPlan::fromArray($plan) : null;
        $options = $this->meta($id, 'options', []);
        $flags = $this->meta($id, 'flags', []);

        return new Run(
            $id,
            (string) $this->meta($id, 'state', RunState::NEW),
            (string) $this->meta($id, 'reason', ''),
            $source,
            is_array($options) ? $options : [],
            is_array($audio) ? AudioInfo::fromArray($audio) : null,
            $planObj,
            is_array($est) ? Estimate::fromArray($est) : null,
            is_array($flags) ? $flags : [],
            (int) $post->post_author,
            (int) $this->meta($id, 'created', 0),
            (int) $this->meta($id, 'chunks_done', 0),
            $planObj?->count() ?? 0,
            $draftIds,
            is_array($err) ? (string) ($err['code'] ?? '') : null,
            is_array($err) ? (string) ($err['message'] ?? '') : null,
        );
    }

    public function meta(int $id, string $key, mixed $default = null): mixed
    {
        $raw = get_post_meta($id, self::P . $key, true);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        $v = json_decode($raw, true);

        return $v === null && $raw !== 'null' ? $default : $v;
    }

    public function setMeta(int $id, string $key, mixed $value): void
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // update_post_meta() unslashes its value; slash first so JSON escapes survive.
        update_post_meta($id, self::P . $key, wp_slash($json));
    }

    public function deleteMeta(int $id, string $key): void
    {
        delete_post_meta($id, self::P . $key);
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function setFlags(int $id, array $changes): void
    {
        $flags = $this->meta($id, 'flags', []);
        $flags = is_array($flags) ? $flags : [];
        foreach ($changes as $k => $v) {
            if ($v === null) {
                unset($flags[$k]);
            } else {
                $flags[$k] = $v;
            }
        }
        $this->setMeta($id, 'flags', $flags);
    }

    public function flag(int $id, string $key): mixed
    {
        $flags = $this->meta($id, 'flags', []);

        return is_array($flags) ? ($flags[$key] ?? null) : null;
    }

    /**
     * Move a run to another state. Refused transitions throw and are
     * logged.
     *
     * @throws StateException
     */
    public function move(int $id, string $to, string $reason = '', ?string $code = null, ?string $message = null): Run
    {
        $from = (string) $this->meta($id, 'state', RunState::NEW);
        if ($from === $to) {
            return $this->get($id) ?? throw new StateException('not_found', 'Run not found.');
        }
        try {
            RunState::assertMove($from, $to);
        } catch (StateException $e) {
            $this->log($id, 'refused transition ' . $from . ' to ' . $to);
            throw $e;
        }
        $this->setMeta($id, 'state', $to);
        $this->setMeta($id, 'reason', $reason);
        if ($code !== null) {
            $this->setMeta($id, 'error', ['code' => $code, 'message' => Settings::redact((string) $message)]);
        } elseif (!in_array($to, [RunState::FAILED, RunState::PAUSED, RunState::TRANSCRIPT_READY], true)) {
            $this->deleteMeta($id, 'error');
        }
        if (in_array($to, [RunState::TRANSCRIPT_READY, RunState::EMPTY, RunState::CANCELLED, RunState::EXPIRED], true)) {
            $this->files->startClock($id, $this->settings->int('keep_recording_hours'), 'keep_recording_hours');
        } elseif ($to === RunState::FAILED) {
            $this->files->startClock($id, $this->settings->int('keep_failed_hours'), 'keep_failed_hours');
        } elseif (in_array($from, [RunState::FAILED, RunState::EXPIRED], true)) {
            $this->files->stopClock($id);
        }
        $line = 'state ' . $from . ' -> ' . $to . ($reason !== '' ? ' (' . $reason . ')' : '') . ($code !== null ? ' [' . $code . ']' : '');
        $this->log($id, $line);
        Debug::log('run ' . $id . ': ' . $line);
        $run = $this->get($id);
        if ($run === null) {
            throw new StateException('not_found', 'Run not found.');
        }
        do_action('audiopost_run_state_changed', $run, $from, $to);
        if ($to === RunState::FAILED) {
            do_action('audiopost_run_failed', $run, (string) $code, Settings::redact((string) $message));
        }

        return $run;
    }

    public function log(int $id, string $line): void
    {
        $log = $this->meta($id, 'log', []);
        $log = is_array($log) ? $log : [];
        $log[] = ['t' => $this->clock->now(), 'line' => Settings::redact($line)];
        if (count($log) > self::LOG_LIMIT) {
            $log = array_slice($log, -self::LOG_LIMIT);
        }
        $this->setMeta($id, 'log', $log);
    }

    /** @return list<array{t: int, line: string}> */
    public function logs(int $id): array
    {
        $out = [];
        $log = $this->meta($id, 'log', []);
        foreach (is_array($log) ? $log : [] as $l) {
            if (is_array($l)) {
                $out[] = ['t' => (int) ($l['t'] ?? 0), 'line' => (string) ($l['line'] ?? '')];
            }
        }

        return $out;
    }

    public function chunkResult(int $id, int $index): ?ChunkResult
    {
        $r = $this->meta($id, 'chunk_' . $index);

        return is_array($r) ? ChunkResult::fromArray($r) : null;
    }

    public function setChunkResult(int $id, int $index, ChunkResult $r): void
    {
        $this->setMeta($id, 'chunk_' . $index, $r->toArray());
        $this->setMeta($id, 'chunks_done', $this->countResults($id));
    }

    public function countResults(int $id): int
    {
        $plan = $this->meta($id, 'plan');
        $n = is_array($plan) ? count(is_array($plan['chunks'] ?? null) ? $plan['chunks'] : []) : 0;
        $done = 0;
        for ($i = 0; $i < $n; $i++) {
            if (get_post_meta($id, self::P . 'chunk_' . $i, true) !== '') {
                $done++;
            }
        }

        return $done;
    }

    /** Remove stored chunk results from $from on (a re-plan, case 38). */
    public function clearChunkResults(int $id, int $from, int $to): void
    {
        for ($i = $from; $i < $to; $i++) {
            delete_post_meta($id, self::P . 'chunk_' . $i);
        }
    }

    public function saveTranscript(int $id, Transcript $t, bool $original = false): void
    {
        $key = $original ? 'transcript_original' : 'transcript';
        $old = $this->meta($id, $key);
        $json = (string) json_encode($t->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::INLINE_LIMIT) {
            $file = PrivateStorage::newId() . '.json';
            file_put_contents($this->files->transcriptDir() . '/' . $file, $json);
            $this->setMeta($id, $key, ['file' => $file]);
        } else {
            $this->setMeta($id, $key, $t->toArray());
        }
        if (is_array($old) && is_string($old['file'] ?? null)) {
            $this->removeTranscriptFile($old['file']);
        }
    }

    public function transcript(int $id, bool $original = false): ?Transcript
    {
        $v = $this->meta($id, $original ? 'transcript_original' : 'transcript');
        if (!$original && $v === null) {
            return null;
        }
        if ($original && $v === null) {
            return $this->transcript($id, false);
        }
        try {
            if (is_array($v) && is_string($v['file'] ?? null) && preg_match('/^[a-f0-9]{32}\.json$/', $v['file']) === 1) {
                $raw = file_get_contents($this->files->transcriptDir() . '/' . $v['file']);

                return $raw === false ? null : Transcript::fromJson($raw);
            }

            return is_array($v) ? Transcript::fromArray($v) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    public function addUsage(int $id, array $record): void
    {
        $u = $this->meta($id, 'usage', []);
        $u = is_array($u) ? $u : [];
        $u[] = $record;
        $this->setMeta($id, 'usage', $u);
    }

    /** @return list<array<string, mixed>> */
    public function usage(int $id): array
    {
        $u = $this->meta($id, 'usage', []);

        return is_array($u) ? array_values(array_filter($u, 'is_array')) : [];
    }

    /**
     * @param list<string> $states
     *
     * @return list<int>
     */
    public function find(array $states = [], int $limit = 100, ?int $author = null, string $order = 'ASC', int $offset = 0): array
    {
        $args = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => $order,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
        ];
        if ($author !== null) {
            $args['author'] = $author;
        }
        if ($states !== []) {
            $args['meta_query'] = [['key' => self::P . 'state', 'value' => array_map(static fn (string $s): string => (string) json_encode($s), $states), 'compare' => 'IN']];
        }
        $ids = get_posts($args);

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /**
     * Delete a run. Drafts stay; their link meta goes. Audio files are
     * handed to the retention rule, never deleted here (case 80).
     */
    public function delete(int $id): void
    {
        $drafts = $this->meta($id, 'drafts', []);
        foreach (is_array($drafts) ? $drafts : [] as $d) {
            if (is_array($d) && isset($d['post_id'])) {
                delete_post_meta((int) $d['post_id'], '_audiopost_run_id');
                delete_post_meta((int) $d['post_id'], '_audiopost_mode');
            }
        }
        foreach (['transcript', 'transcript_original'] as $k) {
            $v = $this->meta($id, $k);
            if (is_array($v) && is_string($v['file'] ?? null)) {
                $this->removeTranscriptFile($v['file']);
            }
        }
        $state = (string) $this->meta($id, 'state', RunState::NEW);
        if ($state === RunState::FAILED) {
            $this->files->handOver($id, $this->settings->int('keep_failed_hours'), 'keep_failed_hours');
        } else {
            $this->files->handOver($id, $this->settings->int('keep_recording_hours'), 'keep_recording_hours');
        }
        wp_delete_post($id, true);
    }

    private function removeTranscriptFile(string $file): void
    {
        if (preg_match('/^[a-f0-9]{32}\.json$/', $file) === 1) {
            $p = $this->files->transcriptDir() . '/' . $file;
            if (is_file($p)) {
                unlink($p);
            }
        }
    }
}
