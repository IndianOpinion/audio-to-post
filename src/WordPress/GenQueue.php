<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * Soft integration with gen-queue (case 72), as gen-queue 0.1.0
 * documents it.
 *
 * - Detection: the constant GEN_QUEUE_VERSION.
 * - Registration: on the `gen_queue_register_types` action (no
 *   arguments) the plugin calls `gen_queue_register_type($type,
 *   $handler, $args)` for `audio-to-post.chunk`. The handler receives
 *   gen-queue's Job and JobContext objects.
 * - Enqueue: `apply_filters('gen_queue_enqueue_job', null, string $type,
 *   array $payload, array $args)` returns a job id (int) when gen-queue
 *   took the job, the null default when it is not active, or a WP_Error
 *   when the enqueue failed. Anything but an id means "run it ourselves".
 */
final class GenQueue
{
    public const CHUNK = 'audio-to-post.chunk';

    public static function present(): bool
    {
        return defined('GEN_QUEUE_VERSION');
    }

    /**
     * Register the job type. Called on `gen_queue_register_types`.
     *
     * @param callable(mixed, mixed=): mixed $handler
     */
    public static function registerTypes(callable $handler, string $resourceKey = ''): void
    {
        if (!function_exists('gen_queue_register_type')) {
            return;
        }
        $args = [
            // One chunk call can take minutes; the runner allows ten.
            'timeout' => 600,
            'group' => 'audio-to-post',
        ];
        if ($resourceKey !== '') {
            $args['resource_key'] = $resourceKey;
        }
        gen_queue_register_type(self::CHUNK, $handler, $args);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $args    idempotency_key, cost_estimate, source, ...
     *
     * @return int|null job id, or null when gen-queue did not take it
     */
    public static function enqueue(string $type, array $payload, array $args): ?int
    {
        $res = apply_filters('gen_queue_enqueue_job', null, $type, $payload, $args);
        if ($res instanceof \WP_Error) {
            Debug::log('gen-queue refused a job: ' . $res->get_error_message());

            return null;
        }
        if (is_int($res) && $res > 0) {
            return $res;
        }
        if (is_string($res) && ctype_digit($res) && (int) $res > 0) {
            return (int) $res;
        }

        return null;
    }

    /**
     * Payload and type of a job, from gen-queue's Job object or a plain
     * array (tests, other queues).
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    public static function jobData(mixed $job): array
    {
        if (is_object($job)) {
            $type = property_exists($job, 'type') && is_string($job->type) ? $job->type : self::CHUNK;
            $payload = property_exists($job, 'payload') && is_array($job->payload) ? $job->payload : [];
        } elseif (is_array($job)) {
            $type = is_string($job['type'] ?? null) ? $job['type'] : self::CHUNK;
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : $job;
        } else {
            $type = self::CHUNK;
            $payload = [];
        }

        /** @var array<string, mixed> $payload */
        return ['type' => $type, 'payload' => $payload];
    }

    /**
     * Ask gen-queue to run the job again later (a busy run lock). Uses
     * gen-queue's RetryableException when it is loaded.
     *
     * @throws \Throwable
     */
    public static function retryLater(string $message): void
    {
        $class = 'IndianOpinion\\GenQueue\\RetryableException';
        if (class_exists($class)) {
            $e = new $class($message);
            if ($e instanceof \Throwable) {
                throw $e;
            }
        }
        throw new \RuntimeException($message);
    }
}
