<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * Soft integration with gen-queue (case 72). Present when the constant
 * GEN_QUEUE_VERSION is defined.
 *
 * Contract used here: on `gen_queue_register_types` the plugin registers
 * the job types `audio-to-post.chunk` and `audio-to-post.draft` (through
 * a `register(string $type, callable $handler)` method on the argument
 * when there is one, and the `gen_queue_job_types` filter otherwise).
 * Jobs are enqueued through the `gen_queue_enqueue_job` filter, which
 * receives null and the job array and returns a job id, or null or a
 * WP_Error when it will not take the job.
 */
final class GenQueue
{
    public const CHUNK = 'audio-to-post.chunk';
    public const DRAFT = 'audio-to-post.draft';

    public static function present(): bool
    {
        return defined('GEN_QUEUE_VERSION');
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $handler
     */
    public static function registerTypes(mixed $registry, callable $handler): void
    {
        if (is_object($registry) && method_exists($registry, 'register')) {
            $registry->register(self::CHUNK, $handler);
            $registry->register(self::DRAFT, $handler);
        }
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return string|int|null job id, or null when gen-queue did not take it
     */
    public static function enqueue(array $job): string|int|null
    {
        $res = apply_filters('gen_queue_enqueue_job', null, $job);
        if ($res === null || (is_object($res) && is_a($res, 'WP_Error')) || !(is_string($res) || is_int($res))) {
            return null;
        }

        return $res;
    }
}
