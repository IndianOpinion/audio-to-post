<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\Adapter\ChunkRequest;
use IndianOpinion\AudioToPost\Adapter\ChunkResult;
use IndianOpinion\AudioToPost\Adapter\HttpMultipartAdapter;
use IndianOpinion\AudioToPost\Adapter\TranscriptionAdapter;
use IndianOpinion\AudioToPost\AudioToPostException;
use IndianOpinion\AudioToPost\CapException;
use IndianOpinion\AudioToPost\Chunk;
use IndianOpinion\AudioToPost\ChunkPlan;
use IndianOpinion\AudioToPost\DisabledException;
use IndianOpinion\AudioToPost\Estimate\Money;
use IndianOpinion\AudioToPost\Plan\ChunkPlanner;
use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\ProviderException;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Source;
use IndianOpinion\AudioToPost\StateException;
use IndianOpinion\AudioToPost\Stitcher;

/**
 * The background runner (cases 33 to 61, 74 to 76). One tick processes
 * due runs within a time budget; every paid call is preceded by the
 * SpendGuard checks; every result is stored before the run advances.
 */
final class Runner
{
    public const HOLD_OPTION = 'audiopost_adapter_hold';
    public const HOLD_SECONDS = 1800;
    public const METER_RETRY = 900;
    public const MAX_RETRY_AFTER = 900;

    /** @var \Closure(float): void */
    private \Closure $sleeper;

    /** @var array{calls: int, runs: int, stopped: ?string} */
    private array $stats = ['calls' => 0, 'runs' => 0, 'stopped' => null];
    private ?int $maxChunks = null;
    private float $deadline = 0.0;

    public function __construct(private readonly Plugin $p)
    {
        $this->sleeper = static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /** @param callable(float): void $sleeper */
    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = \Closure::fromCallable($sleeper);
    }

    /**
     * @return array{calls: int, runs: int, stopped: ?string}
     */
    public function tick(?float $budget = null, ?int $maxChunks = null, ?int $onlyRun = null): array
    {
        $this->stats = ['calls' => 0, 'runs' => 0, 'stopped' => null];
        $this->maxChunks = $maxChunks;
        if ($budget === null) {
            $b = apply_filters('audiopost_tick_seconds', 25);
            $budget = is_int($b) || is_float($b) ? max(1.0, (float) $b) : 25.0;
        }
        $this->deadline = microtime(true) + $budget;
        $this->recordTick();
        try {
            $this->housekeeping();
            $ids = $onlyRun !== null ? [$onlyRun] : $this->p->repo()->find(RunState::ACTIVE, 100, null, 'ASC');
            foreach ($ids as $id) {
                if (microtime(true) >= $this->deadline || $this->chunksExhausted()) {
                    break;
                }
                $this->processRun($id);
            }
        } finally {
            $this->sweep();
        }

        return $this->stats;
    }

    private function chunksExhausted(): bool
    {
        return $this->maxChunks !== null && $this->stats['calls'] >= $this->maxChunks;
    }

    public function processRun(int $id, ?int $onlyChunk = null): void
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null || in_array($run->state, [RunState::AWAITING_CONFIRMATION, RunState::PAUSED, RunState::EMPTY, RunState::FAILED, RunState::CANCELLED, RunState::EXPIRED], true)) {
            return;
        }
        if (in_array($run->state, [RunState::TRANSCRIPT_READY, RunState::DRAFT_READY], true) && !$this->p->drafts()->hasWork($id)) {
            return;
        }
        if ($this->busyRuns($id) >= $this->p->settings()->int('max_parallel_runs')) {
            return;
        }
        $lockName = 'run_' . $id;
        $token = $this->p->locks()->acquire($lockName);
        if ($token === null) {
            return;
        }
        $this->stats['runs']++;
        try {
            if ($repo->meta($id, 'cancel') && RunState::canMove($run->state, RunState::CANCELLED)) {
                $repo->move($id, RunState::CANCELLED, 'cancelled by editor');

                return;
            }
            if (in_array($run->state, [RunState::NEW, RunState::FETCHING], true)) {
                $this->p->runs()->prepare($id);
                $run = $repo->get($id);
                if ($run === null) {
                    return;
                }
            }
            if ($run->state === RunState::QUEUED) {
                if ((int) $repo->meta($id, 'due_at', 0) > $this->p->clock()->now()) {
                    return;
                }
                $hold = $this->holdUntil($run->adapterId());
                if ($hold !== null && !$run->source->isFree() && $repo->transcript($id) === null) {
                    $this->pause($id, 'provider_hold', $hold);

                    return;
                }
                $run = $repo->move($id, RunState::TRANSCRIBING);
            }
            if ($run->state === RunState::TRANSCRIBING) {
                $this->transcribe($run, $token, $onlyChunk);
                $run = $repo->get($id);
                if ($run === null) {
                    return;
                }
            }
            if (in_array($run->state, [RunState::TRANSCRIPT_READY, RunState::DRAFT_READY, RunState::DRAFTING], true) && $onlyChunk === null) {
                $this->stats['stopped'] = $this->p->drafts()->process($id, $this->deadline) ?? $this->stats['stopped'];
            }
        } catch (AudioToPostException $e) {
            $repo->log($id, 'runner: ' . $e->getMessage());
            $current = $repo->get($id);
            if ($current !== null && RunState::canMove($current->state, RunState::FAILED)) {
                $repo->move($id, RunState::FAILED, $e->errorCode(), $e->errorCode(), $e->getMessage());
            }
        } finally {
            $this->p->locks()->release($lockName, $token);
        }
    }

    /** Runs other than $except that another worker holds right now. */
    private function busyRuns(int $except): int
    {
        $n = 0;
        foreach ($this->p->repo()->find([RunState::FETCHING, RunState::TRANSCRIBING, RunState::DRAFTING], 50) as $id) {
            if ($id !== $except && $this->p->locks()->isHeld('run_' . $id)) {
                $n++;
            }
        }

        return $n;
    }

    private function transcribe(Run $run, string $token, ?int $onlyChunk): void
    {
        $repo = $this->p->repo();
        $id = $run->id;
        if ($repo->transcript($id) !== null) {
            // Free source, or resuming after a pause in the draft step.
            $repo->move($id, RunState::TRANSCRIPT_READY);

            return;
        }
        $reuse = (int) $repo->flag($id, 'reuse_from');
        if ($reuse > 0) {
            $t = $repo->transcript($reuse, true);
            $edited = $repo->transcript($reuse);
            if ($t !== null && $edited !== null) {
                $repo->saveTranscript($id, $t, true);
                $repo->saveTranscript($id, $edited);
                $repo->setMeta($id, 'revision', 0);
                $repo->setFlags($id, ['timestamps' => $t->timestamps, 'speakers' => $t->speakersFlag]);
                $repo->log($id, 'reused the transcript of run ' . $reuse . '; no call made');
                $this->finishTranscript($id);

                return;
            }
        }
        $adapter = $this->p->adapters()->get($run->adapterId());
        $plan = $run->plan;
        if ($adapter === null || $plan === null) {
            $repo->move($id, RunState::FAILED, 'no_adapter', 'no_adapter', 'The transcription adapter or the plan is missing.');

            return;
        }
        if ($onlyChunk === null && GenQueue::present() && !$repo->flag($id, 'gen_queue_fallback')) {
            if ($repo->flag($id, 'gen_queue')) {
                return;
            }
            if ($this->enqueueGenQueue($run, $adapter, $plan)) {
                return;
            }
        }
        $caps = $this->p->adapters()->effectiveCapabilities($adapter);
        for ($i = 0; $i < $plan->count(); $i++) {
            $chunk = $plan->chunk($i);
            if ($chunk === null || ($onlyChunk !== null && $i !== $onlyChunk)) {
                continue;
            }
            if ($repo->chunkResult($id, $i) !== null) {
                continue;
            }
            $retryAt = $repo->meta($id, 'retry_at', []);
            if (is_array($retryAt) && (int) ($retryAt[(string) $i] ?? 0) > $this->p->clock()->now()) {
                return;
            }
            if ($this->chunksExhausted()) {
                $this->stats['stopped'] ??= 'max_chunks';

                return;
            }
            $latency = $caps->typicalSecondsPerAudioMinute !== null ? $caps->typicalSecondsPerAudioMinute * $chunk->lengthMs() / 60000 : 20.0;
            if ($this->deadline - microtime(true) < $latency) {
                $this->stats['stopped'] ??= 'budget';

                return;
            }
            $outcome = $caps->async
                ? $this->asyncChunk($run, $adapter, $caps, $chunk, $plan)
                : $this->syncChunk($run, $adapter, $caps, $chunk, $plan);
            if ($outcome === 'replanned') {
                $run = $repo->get($id) ?? $run;
                $plan = $run->plan ?? $plan;
                $i--;
                continue;
            }
            if ($outcome !== 'done') {
                return;
            }
            $this->p->locks()->renew('run_' . $id, $token);
            $run = $repo->get($id) ?? $run;
        }
        if ($repo->countResults($id) >= $plan->count()) {
            $this->stitch($id);
        }
    }

    /**
     * @return 'done'|'wait'|'stop'|'replanned'
     */
    private function syncChunk(Run $run, TranscriptionAdapter $adapter, AdapterCapabilities $caps, Chunk $chunk, ChunkPlan $plan): string
    {
        $repo = $this->p->repo();
        $id = $run->id;
        $intent = $this->intent($run, $adapter, $caps->billedSeconds($chunk->lengthMs()));
        $reservation = $this->authorize($run, $intent);
        if ($reservation === false) {
            return 'stop';
        }
        $path = $this->chunkPath($run, $chunk, $plan);
        if ($path === null) {
            SpendMeter::release($reservation);
            $this->p->guard()->settle($id);

            return 'stop';
        }
        $i = $chunk->index;
        if ($repo->meta($id, 'inflight_' . $i)) {
            $repo->log($id, 'chunk ' . $i . ' re-sent after interrupted store');
        }
        $repo->setMeta($id, 'inflight_' . $i, true);
        $attempts = $repo->meta($id, 'chunk_attempts', []);
        $attempts = is_array($attempts) ? $attempts : [];
        $attempt = (int) ($attempts[(string) $i] ?? 0) + 1;
        $attempts[(string) $i] = $attempt;
        $repo->setMeta($id, 'chunk_attempts', $attempts);
        $request = new ChunkRequest(
            $path,
            $plan->format,
            $chunk->startMs,
            $chunk->lengthMs(),
            $caps->languageHint && $run->language() !== 'auto' ? $run->language() : null,
            'run' . $id . '-c' . $i . '-a' . $attempt,
            $i,
        );
        $repo->log($id, sprintf('chunk %d sent (attempt %d)', $i, $attempt));
        try {
            $result = $adapter->transcribe($request);
        } catch (ProviderException $e) {
            $repo->deleteMeta($id, 'inflight_' . $i);

            return $this->providerError($run, $adapter, $chunk, $plan, $e, $attempt, $reservation, $intent);
        }

        return $this->accept($run, $adapter, $chunk, $result, $intent, $reservation, $attempt);
    }

    /**
     * Store a chunk result, then record usage (cases 35 and 61).
     *
     * @param array{kind: string, seconds: int, tokens_in: int, tokens_out: int, money_micros: ?int, provider: string, model: string} $intent
     *
     * @return 'done'|'stop'
     */
    private function accept(Run $run, TranscriptionAdapter $adapter, Chunk $chunk, ChunkResult $result, array $intent, ?string $reservation, int $attempt): string
    {
        $repo = $this->p->repo();
        $id = $run->id;
        $i = $chunk->index;
        $billed = $result->billedSeconds ?? $intent['seconds'];
        wp_cache_delete($id, 'post_meta');
        $cancelled = (bool) $repo->meta($id, 'cancel');
        if (!$cancelled) {
            $repo->setChunkResult($id, $i, $result);
        }
        $repo->deleteMeta($id, 'inflight_' . $i);
        $usage = $this->recordUsage($id, 'transcribe', $adapter->id(), 'chunk ' . $i, $billed, 0, 0, $this->minuteMoney($adapter, $billed), $result->httpStatus, $attempt);
        SpendMeter::record($reservation, ['kind' => 'transcribe', 'provider' => $adapter->id(), 'model' => $intent['model'], 'status' => 'ok', 'http_status' => $result->httpStatus, 'seconds' => $billed, 'attempt' => $attempt, 'run_id' => $id]);
        $this->stats['calls']++;
        if ($cancelled) {
            $repo->log($id, 'chunk ' . $i . ' finished after cancel; result discarded, usage recorded');
            $repo->move($id, RunState::CANCELLED, 'cancelled by editor');

            return 'stop';
        }
        $repo->log($id, sprintf('chunk %d done, %d s billed', $i, $billed));
        $fresh = $repo->get($id) ?? $run;
        do_action('audiopost_chunk_done', $fresh, $i, $usage);

        return 'done';
    }

    /**
     * @param array{kind: string, seconds: int, tokens_in: int, tokens_out: int, money_micros: ?int, provider: string, model: string} $intent
     *
     * @return 'wait'|'stop'|'replanned'|'done'
     */
    private function providerError(Run $run, TranscriptionAdapter $adapter, Chunk $chunk, ChunkPlan $plan, ProviderException $e, int $attempt, ?string $reservation, array $intent): string
    {
        $repo = $this->p->repo();
        $id = $run->id;
        $i = $chunk->index;
        $billed = (int) $e->billedSeconds();
        if ($billed > 0) {
            $this->recordUsage($id, 'transcribe', $adapter->id(), 'chunk ' . $i, $billed, 0, 0, $this->minuteMoney($adapter, $billed), $e->httpStatus(), $attempt);
        }
        // The call reached the provider: record it, billed or not.
        SpendMeter::record($reservation, ['kind' => 'transcribe', 'provider' => $adapter->id(), 'model' => $intent['model'], 'status' => $billed > 0 ? 'error_billed' : 'error_unbilled', 'http_status' => $e->httpStatus(), 'seconds' => $billed, 'attempt' => $attempt, 'error_code' => $e->errorCode(), 'run_id' => $id]);
        $this->p->guard()->settle($id);
        $msg = Settings::redact($e->getMessage());
        $repo->log($id, sprintf('chunk %d failed: %s [%s, HTTP %s]', $i, $msg, $e->errorCode(), $e->httpStatus() ?? '-'));
        if ($e->retryable()) {
            $max = $this->p->settings()->int('max_attempts');
            if ($attempt >= $max) {
                $repo->move($id, RunState::FAILED, 'chunk ' . $i, $e->errorCode(), sprintf('Chunk %d failed after %d attempts: %s', $i, $attempt, $msg));

                return 'stop';
            }
            $delay = $e->retryAfterSeconds() !== null
                ? min(self::MAX_RETRY_AFTER, max(0, $e->retryAfterSeconds()))
                : random_int(0, (int) min(300, 5 * 2 ** ($attempt - 1)));
            if ($delay < 5 && $this->deadline - microtime(true) > $delay + 20) {
                ($this->sleeper)((float) $delay);
                $repo->log($id, sprintf('retrying chunk %d after %d s', $i, $delay));

                return 'replanned';
            }
            $retryAt = $repo->meta($id, 'retry_at', []);
            $retryAt = is_array($retryAt) ? $retryAt : [];
            $retryAt[(string) $i] = $this->p->clock()->now() + $delay;
            $repo->setMeta($id, 'retry_at', $retryAt);
            $repo->log($id, sprintf('retry of chunk %d scheduled in %d s', $i, $delay));

            return 'wait';
        }
        switch ($e->errorCode()) {
            case ProviderException::TOO_LARGE:
                $audio = $run->audio;
                $chunker = $audio !== null ? $this->p->runs()->chunker($audio) : null;
                if ($chunker === null || $audio === null || $chunk->lengthMs() < ChunkPlanner::MIN_CHUNK_MS) {
                    $repo->move($id, RunState::FAILED, 'chunk ' . $i, ProviderException::REJECTED_AUDIO, 'The provider refused chunk ' . $i . ' as too large and it cannot be split further.');

                    return 'stop';
                }
                $newLimit = $this->p->adapters()->lowerLimit($adapter, max(1, $chunk->byteEnd - $chunk->byteStart));
                $halves = ChunkPlanner::halve($chunk, min($chunk->overlapAfterMs ?: 10_000, 30_000));
                $old = $plan->count();
                $newPlan = $plan->replace($i, $halves);
                $path = $this->p->files()->path((string) $repo->meta($id, 'file', ''));
                $newPlan = $chunker->locate($path, $audio, $newPlan);
                $repo->clearChunkResults($id, $i, $old + 1);
                $repo->deleteMeta($id, 'chunk_files');
                $repo->setMeta($id, 'plan', $newPlan->toArray());
                $repo->setMeta($id, 'chunks_done', $repo->countResults($id));
                $attempts = $repo->meta($id, 'chunk_attempts', []);
                if (is_array($attempts)) {
                    unset($attempts[(string) $i]);
                    $repo->setMeta($id, 'chunk_attempts', $attempts);
                }
                $repo->log($id, sprintf('chunk %d split in two after a size refusal; adapter byte limit lowered to %d', $i, $newLimit));
                Debug::log(sprintf('adapter %s byte limit lowered to %d after HTTP 413', $adapter->id(), $newLimit));

                return 'replanned';
            case ProviderException::AUTH:
            case ProviderException::ACCOUNT:
                $repo->move($id, RunState::FAILED, 'chunk ' . $i, $e->errorCode(), $msg);
                $this->holdAdapter($adapter->id(), $e->errorCode(), $id);

                return 'stop';
            default:
                $repo->move($id, RunState::FAILED, 'chunk ' . $i, $e->errorCode(), $msg);

                return 'stop';
        }
    }

    /**
     * Submit and poll for asynchronous adapters (case 76). Polls are free
     * and not counted against the cap.
     *
     * @return 'done'|'wait'|'stop'|'replanned'
     */
    private function asyncChunk(Run $run, TranscriptionAdapter $adapter, AdapterCapabilities $caps, Chunk $chunk, ChunkPlan $plan): string
    {
        $repo = $this->p->repo();
        $id = $run->id;
        $i = $chunk->index;
        $now = $this->p->clock()->now();
        $job = $repo->meta($id, 'async_' . $i);
        $intent = $this->intent($run, $adapter, $caps->billedSeconds($chunk->lengthMs()));
        if (!is_array($job)) {
            // The hold lasts while the provider job may run.
            $reservation = $this->authorize($run, $intent, $this->p->settings()->int('async_timeout_hours') * 3600);
            if ($reservation === false) {
                return 'stop';
            }
            $path = $this->chunkPath($run, $chunk, $plan);
            if ($path === null) {
                SpendMeter::release($reservation);
                $this->p->guard()->settle($id);

                return 'stop';
            }
            $request = new ChunkRequest($path, $plan->format, $chunk->startMs, $chunk->lengthMs(), $caps->languageHint && $run->language() !== 'auto' ? $run->language() : null, 'run' . $id . '-c' . $i, $i);
            try {
                $jobId = $adapter->submit($request);
            } catch (ProviderException $e) {
                return $this->providerError($run, $adapter, $chunk, $plan, $e, 1, $reservation, $intent);
            }
            $repo->setMeta($id, 'async_' . $i, ['job' => $jobId, 'submitted' => $now, 'next' => $now + 15, 'interval' => 15, 'reservation' => $reservation]);
            $repo->log($id, 'chunk ' . $i . ' submitted as job ' . $jobId);

            return 'wait';
        }
        $reservation = is_string($job['reservation'] ?? null) ? $job['reservation'] : null;
        if ($now - (int) ($job['submitted'] ?? $now) > $this->p->settings()->int('async_timeout_hours') * 3600) {
            SpendMeter::record($reservation, ['kind' => 'transcribe', 'provider' => $adapter->id(), 'model' => $intent['model'], 'status' => 'error_unbilled', 'error_code' => ProviderException::TIMEOUT, 'run_id' => $id]);
            $this->p->guard()->settle($id);
            $repo->move($id, RunState::FAILED, 'chunk ' . $i, ProviderException::TIMEOUT, 'The provider job did not finish in time.');

            return 'stop';
        }
        if ((int) ($job['next'] ?? 0) > $now) {
            return 'wait';
        }
        try {
            $result = $adapter->poll((string) ($job['job'] ?? ''));
        } catch (ProviderException $e) {
            if ($e->retryable()) {
                $job['next'] = $now + (int) ($job['interval'] ?? 15);
                $repo->setMeta($id, 'async_' . $i, $job);

                return 'wait';
            }
            $repo->deleteMeta($id, 'async_' . $i);

            return $this->providerError($run, $adapter, $chunk, $plan, $e, 1, $reservation, $intent);
        }
        if ($result === null) {
            $interval = min(300, max(15, (int) ($job['interval'] ?? 15) * 2));
            $job['interval'] = $interval;
            $job['next'] = $now + $interval;
            $repo->setMeta($id, 'async_' . $i, $job);

            return 'wait';
        }
        $repo->deleteMeta($id, 'async_' . $i);

        return $this->accept($run, $adapter, $chunk, $result, $intent, $reservation, 1);
    }

    /**
     * @param array{kind: string, seconds: int, tokens_in: int, tokens_out: int, money_micros: ?int, provider: string, model: string} $intent
     *
     * @return string|null|false reservation id, null for none, false when stopped
     */
    private function authorize(Run $run, array $intent, int $holdSeconds = Lock::TTL): string|null|false
    {
        $repo = $this->p->repo();
        try {
            return $this->p->guard()->authorize($run, $intent, $holdSeconds);
        } catch (DisabledException $e) {
            $this->pause($run->id, 'kill_switch', null);
            $this->stats['stopped'] = 'kill_switch';
        } catch (CapException $e) {
            if ($e->errorCode() === 'spend_busy') {
                // Another run holds the spend check for a moment: try again next tick.
                $this->stats['stopped'] ??= 'busy';
            } elseif ($e->errorCode() === 'daily_cap') {
                $this->pause($run->id, 'daily_cap', $this->p->clock()->nextLocalMidnight());
                $this->stats['stopped'] = 'daily_cap';
            } else {
                $this->pause($run->id, $e->errorCode() === 'spend_meter' ? 'spend_meter' : 'denied', $this->p->clock()->now() + self::METER_RETRY);
                $repo->log($run->id, Settings::redact($e->getMessage()));
                $this->stats['stopped'] = 'spend_meter';
            }
        } catch (StateException $e) {
            $repo->move($run->id, RunState::CANCELLED, 'cancelled by editor');
        }

        return false;
    }

    public function pause(int $id, string $reason, ?int $resumeAt): void
    {
        $repo = $this->p->repo();
        $repo->setMeta($id, 'resume_at', $resumeAt ?? 0);
        $run = $repo->get($id);
        if ($run !== null && RunState::canMove($run->state, RunState::PAUSED)) {
            $repo->move($id, RunState::PAUSED, $reason);
        }
    }

    /**
     * The file to send for a chunk: the private copy for a one-chunk plan,
     * otherwise a chunk file cut from it.
     */
    private function chunkPath(Run $run, Chunk $chunk, ChunkPlan $plan): ?string
    {
        $repo = $this->p->repo();
        $files = $this->p->files();
        $id = $run->id;
        $fileId = (string) $repo->meta($id, 'file', '');
        $copy = $fileId === '' ? '' : $files->path($fileId);
        if ($copy === '' || !is_file($copy)) {
            if (!$this->refetch($run)) {
                return null;
            }
            $copy = $files->path((string) $repo->meta($id, 'file', ''));
        }
        if ($plan->count() === 1) {
            return $copy;
        }
        $map = $repo->meta($id, 'chunk_files', []);
        $map = is_array($map) ? $map : [];
        $cid = is_string($map[(string) $chunk->index] ?? null) ? $map[(string) $chunk->index] : '';
        if ($cid !== '' && is_file($files->path($cid))) {
            return $files->path($cid);
        }
        $audio = $run->audio;
        $chunker = $audio !== null ? $this->p->runs()->chunker($audio) : null;
        if ($chunker === null) {
            return $copy;
        }
        $cid = PrivateStorage::newId();
        $files->register($id, $cid, 'chunk');
        $chunker->extract($copy, $chunk, $files->path($cid));
        $map[(string) $chunk->index] = $cid;
        $repo->setMeta($id, 'chunk_files', $map);

        return $files->path($cid);
    }

    /**
     * The private copy is missing (case 57). On a retry the source is
     * fetched again when it still exists and is the same recording.
     */
    private function refetch(Run $run): bool
    {
        $repo = $this->p->repo();
        $id = $run->id;
        if (!$repo->flag($id, 'refetch')) {
            $repo->move($id, RunState::FAILED, 'copy missing', 'source_copy_missing', 'The private audio copy is missing. Retry to fetch the source again.');

            return false;
        }
        try {
            $files = $this->p->files();
            $fileId = PrivateStorage::newId();
            $path = $files->path($fileId);
            if ($run->source->kind === Source::ATTACHMENT) {
                $src = get_attached_file($run->source->attachmentId());
                if (!is_string($src) || !is_file($src) || !copy($src, $path)) {
                    throw new StateException('source_unavailable', 'The attachment no longer exists.');
                }
            } else {
                $url = (string) ($run->source->kind === Source::URL ? $run->source->get('url') : $run->source->get('enclosure_url'));
                $this->p->downloader()->download($url, $path, $this->p->settings()->int('max_bytes'));
            }
            $files->register($id, $fileId, 'audio');
            $sha = hash_file('sha256', $path);
            if ($run->audio !== null && $sha !== $run->audio->sha256) {
                throw new StateException('source_changed', 'The source changed since the estimate. Start a new run.');
            }
            $repo->setMeta($id, 'file', $fileId);
            $repo->deleteMeta($id, 'chunk_files');
            $repo->setFlags($id, ['refetch' => null]);
            $repo->log($id, 'source fetched again after the private copy went missing');

            return true;
        } catch (AudioToPostException $e) {
            $repo->move($id, RunState::FAILED, 'refetch', $e->errorCode(), $e->getMessage());

            return false;
        }
    }

    private function stitch(int $id): void
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null || $run->plan === null) {
            return;
        }
        $results = [];
        foreach ($run->plan->chunks as $c) {
            $r = $repo->chunkResult($id, $c->index);
            if ($r !== null) {
                $results[$c->index] = $r;
            }
        }
        $duration = $run->audio->durationMs ?? $run->plan->chunks[$run->plan->count() - 1]->endMs;
        $out = (new Stitcher())->stitch($run->plan->chunks, $results, $duration);
        $t = $out['transcript'];
        $repo->setMeta($id, 'mappings', $out['mappings']);
        $repo->setFlags($id, ['timestamps' => $t->timestamps, 'speakers' => $t->speakersFlag, 'language' => $t->language, 'seams' => $out['seams']]);
        if ($t->isEmpty()) {
            $repo->setMeta($id, 'draft_queue', []);
            $repo->move($id, RunState::EMPTY, 'no speech found');

            return;
        }
        $repo->saveTranscript($id, $t, true);
        $repo->saveTranscript($id, $t);
        $repo->setMeta($id, 'revision', 0);
        $repo->log($id, sprintf('transcript stitched: %d segments, %d speaker(s), %d seam marker(s)', count($t->segments), count($t->speakers), $out['seams']));
        $this->finishTranscript($id);
    }

    private function finishTranscript(int $id): void
    {
        $repo = $this->p->repo();
        $run = $repo->move($id, RunState::TRANSCRIPT_READY);
        $t = $repo->transcript($id);
        if ($t !== null) {
            do_action('audiopost_transcript_ready', $run, $t);
        }
    }

    /**
     * @return array{kind: string, seconds: int, tokens_in: int, tokens_out: int, money_micros: ?int, provider: string, model: string}
     */
    private function intent(Run $run, TranscriptionAdapter $adapter, int $seconds): array
    {
        $mp = $this->p->settings()->get('multipart');

        return [
            'kind' => 'transcribe',
            'seconds' => $seconds,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'money_micros' => $this->minuteMoney($adapter, $seconds),
            'provider' => $adapter->id(),
            'model' => $adapter->id() === HttpMultipartAdapter::ID && is_array($mp) ? (string) ($mp['model'] ?? '') : '',
        ];
    }

    private function minuteMoney(TranscriptionAdapter $adapter, int $seconds): ?int
    {
        $prices = $this->p->settings()->get('prices');
        $v = is_array($prices) && is_array($prices['minute'] ?? null) ? ($prices['minute'][$adapter->id()] ?? null) : null;
        $micros = Money::parse(is_string($v) ? $v : null);

        return $micros === null ? null : Money::times($micros, $seconds, 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function recordUsage(int $runId, string $kind, string $provider, string $part, int $seconds, int $tin, int $tout, ?int $money, ?int $status, int $attempt): array
    {
        $record = $this->p->ledger()->record([
            'run_id' => $runId,
            'kind' => $kind,
            'provider' => $provider,
            'part' => $part,
            'billed_seconds' => $seconds,
            'tokens_in' => $tin,
            'tokens_out' => $tout,
            'money_micros' => $money,
            'currency' => $this->p->settings()->string('currency'),
            'http_status' => $status,
            'attempt' => $attempt,
        ]);
        $this->p->repo()->addUsage($runId, $record);
        $this->p->guard()->settle($runId);

        return $record;
    }

    private function enqueueGenQueue(Run $run, TranscriptionAdapter $adapter, ChunkPlan $plan): bool
    {
        $repo = $this->p->repo();
        $caps = $this->p->adapters()->effectiveCapabilities($adapter);
        foreach ($plan->chunks as $c) {
            if ($repo->chunkResult($run->id, $c->index) !== null) {
                continue;
            }
            $seconds = $caps->billedSeconds($c->lengthMs());
            $money = $this->minuteMoney($adapter, $seconds);
            // A job that failed in gen-queue (for example while this run was
            // paused) is moved back to queued; a running one is left alone.
            $args = ['idempotency_key' => 'run' . $run->id . '-c' . $c->index, 'on_conflict' => 'requeue', 'source' => 'audio-to-post'];
            if ($money !== null) {
                $args['cost_estimate'] = Money::format($money, 6);
            }
            $jobId = GenQueue::enqueue(GenQueue::CHUNK, ['run_id' => $run->id, 'chunk' => $c->index], $args);
            if ($jobId === null) {
                $repo->setFlags($run->id, ['gen_queue_fallback' => true]);
                $repo->log($run->id, 'gen-queue did not take the job; the plugin runner is used');
                Debug::once('gen_queue_fallback', 'gen-queue did not take a job; falling back to the plugin runner');

                return false;
            }
        }
        $repo->setFlags($run->id, ['gen_queue' => true]);
        $repo->log($run->id, 'chunks handed to gen-queue');

        return true;
    }

    /**
     * gen-queue job handler for `audio-to-post.chunk`. Receives gen-queue's
     * Job and JobContext. It runs the chunk under the run lock with every
     * check of case 33, reports the cost to the context (marked as metered
     * when spend-meter already recorded it), and asks gen-queue to try
     * again later when the run was busy.
     *
     * @return array<string, mixed>
     *
     * @throws \Throwable gen-queue's RetryableException when the chunk could not run yet
     */
    public function handleJob(mixed $job, mixed $context = null): array
    {
        $data = GenQueue::jobData($job);
        $payload = $data['payload'];
        $runId = (int) ($payload['run_id'] ?? 0);
        $chunk = (int) ($payload['chunk'] ?? 0);
        $repo = $this->p->repo();
        $before = count($repo->usage($runId));
        $this->deadline = microtime(true) + 600;
        $this->processRun($runId, $chunk);
        $usage = array_slice($repo->usage($runId), $before);
        if (is_object($context) && method_exists($context, 'addCost')) {
            foreach ($usage as $u) {
                $money = isset($u['money_micros']) && is_numeric($u['money_micros']) ? Money::format((int) $u['money_micros'], 6) : '0';
                $context->addCost($money, ['input_units' => (int) ($u['billed_seconds'] ?? 0), 'output_units' => 0, 'metered' => SpendMeter::present()]);
            }
        }
        $run = $repo->get($runId);
        if ($run !== null && in_array($run->state, [RunState::QUEUED, RunState::TRANSCRIBING, RunState::PAUSED], true) && $repo->chunkResult($runId, $chunk) === null) {
            // Locked by another worker, waiting on a retry time, or paused by a cap.
            GenQueue::retryLater('audio-to-post run ' . $runId . ' chunk ' . $chunk . ' could not run yet');
        }

        return ['run_id' => $runId, 'chunk' => $chunk, 'calls' => count($usage)];
    }

    private function holdAdapter(string $adapterId, string $code, int $failedRun): void
    {
        $until = $this->p->clock()->now() + self::HOLD_SECONDS;
        $holds = Options::fresh(self::HOLD_OPTION, []);
        $holds = is_array($holds) ? $holds : [];
        $holds[$adapterId] = $until;
        Options::set(self::HOLD_OPTION, $holds);
        Options::set('audiopost_notice_provider', ['adapter' => $adapterId, 'code' => $code, 'until' => $until]);
        foreach ($this->p->repo()->find([RunState::QUEUED, RunState::TRANSCRIBING], 200) as $other) {
            $r = $this->p->repo()->get($other);
            if ($other !== $failedRun && $r !== null && $r->adapterId() === $adapterId) {
                $this->pause($other, 'provider_hold', $until);
            }
        }
    }

    private function holdUntil(string $adapterId): ?int
    {
        $holds = Options::fresh(self::HOLD_OPTION, []);
        $until = is_array($holds) ? (int) ($holds[$adapterId] ?? 0) : 0;

        return $until > $this->p->clock()->now() ? $until : null;
    }

    /** Expire estimates and resume paused runs in their original order. */
    public function housekeeping(): void
    {
        $repo = $this->p->repo();
        $now = $this->p->clock()->now();
        $ttl = apply_filters('audiopost_estimate_ttl', $this->p->settings()->int('estimate_ttl_hours') * 3600);
        $ttl = is_int($ttl) && $ttl > 0 ? $ttl : 86400;
        foreach ($repo->find([RunState::AWAITING_CONFIRMATION], 200) as $id) {
            if ((int) $repo->meta($id, 'estimated_at', $now) + $ttl < $now) {
                $repo->move($id, RunState::EXPIRED, 'estimate not confirmed in time');
            }
        }
        $switchOn = KillSwitch::isOn();
        foreach ($repo->find([RunState::PAUSED], 200, null, 'ASC') as $id) {
            $reason = (string) $repo->meta($id, 'reason', '');
            $resumeAt = (int) $repo->meta($id, 'resume_at', 0);
            $ready = $reason === 'kill_switch' ? !$switchOn : $resumeAt <= $now;
            if ($ready) {
                $repo->setMeta($id, 'due_at', 0);
                // Hand missing chunks to gen-queue again on the next tick.
                $repo->setFlags($id, ['gen_queue' => null]);
                $repo->move($id, RunState::QUEUED, 'resumed after ' . $reason);
            }
        }
    }

    public function sweep(): void
    {
        $repo = $this->p->repo();
        try {
            $this->sweepFiles($repo);
        } catch (StateException $e) {
            // The private directory is not usable; nothing can be deleted from it either.
            Debug::log('retention sweep skipped: ' . $e->getMessage());
        }
    }

    private function sweepFiles(RunRepository $repo): void
    {
        $this->p->files()->sweep(function (int $runId, string $line) use ($repo): void {
            if ($runId > 0 && $repo->exists($runId)) {
                $repo->log($runId, $line);
            } else {
                $log = Options::fresh('audiopost_orphan_log', []);
                $log = is_array($log) ? $log : [];
                $log[] = ['t' => $this->p->clock()->now(), 'line' => $line];
                Options::set('audiopost_orphan_log', array_slice($log, -100));
            }
            Debug::log($line);
        }, $this->p->settings()->int('keep_recording_hours'), false, [$repo, 'get']);
    }

    /**
     * The daily event: retention of runs (case 81), the usage ledger and
     * audio files (cases 80 to 83).
     */
    public function daily(): void
    {
        $repo = $this->p->repo();
        $days = apply_filters('audiopost_retention_days', $this->p->settings()->int('retention_days'));
        $days = is_int($days) && $days >= 0 ? $days : $this->p->settings()->int('retention_days');
        if ($days > 0) {
            $cutoff = $this->p->clock()->now() - $days * 86400;
            foreach ($repo->find(RunState::FINAL, 500) as $id) {
                if ((int) $repo->meta($id, 'created', 0) < $cutoff) {
                    $repo->delete($id);
                }
            }
        }
        $this->p->ledger()->purge();
        $this->sweep();
    }

    private function recordTick(): void
    {
        $now = $this->p->clock()->now();
        $ticks = Options::fresh('audiopost_ticks', []);
        $ticks = is_array($ticks) ? array_values(array_filter($ticks, static fn ($t): bool => is_int($t) && $t > $now - 3600)) : [];
        $ticks[] = $now;
        Options::set('audiopost_ticks', $ticks);
    }
}
