<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Adapter\TranscriptFileAdapter;
use IndianOpinion\AudioToPost\Adapter\TranscriptionAdapter;
use IndianOpinion\AudioToPost\AudioException;
use IndianOpinion\AudioToPost\AudioInfo;
use IndianOpinion\AudioToPost\AudioToPostException;
use IndianOpinion\AudioToPost\CapException;
use IndianOpinion\AudioToPost\Chunker;
use IndianOpinion\AudioToPost\ChunkPlan;
use IndianOpinion\AudioToPost\Draft\Prompts;
use IndianOpinion\AudioToPost\Draft\TranscriptText;
use IndianOpinion\AudioToPost\Estimate;
use IndianOpinion\AudioToPost\Estimate\EstimateCalculator;
use IndianOpinion\AudioToPost\Estimate\TokenRatios;
use IndianOpinion\AudioToPost\Http\FeedParser;
use IndianOpinion\AudioToPost\Mp3Chunker;
use IndianOpinion\AudioToPost\Plan\ChunkPlanner;
use IndianOpinion\AudioToPost\PlanException;
use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Source;
use IndianOpinion\AudioToPost\SourceException;
use IndianOpinion\AudioToPost\StateException;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\Transcript\Exporter;
use IndianOpinion\AudioToPost\TranscriptFormatException;
use IndianOpinion\AudioToPost\WavChunker;

/**
 * Everything an editor or a script does to a run, plus the free
 * preparation work (fetch, probe, plan, estimate) the runner calls.
 */
final class RunService
{
    public const FETCH_DELAYS = [2, 8, 30];

    public function __construct(private readonly Plugin $p)
    {
    }

    /**
     * @param array<string, mixed> $sourceData
     * @param array<string, mixed> $options
     *
     * @throws AudioToPostException
     */
    public function create(array $sourceData, array $options, int $userId): Run
    {
        $source = Source::fromArray($sourceData);
        $s = $this->p->settings();
        $mode = is_string($options['mode'] ?? null) ? $options['mode'] : $s->string('default_mode');
        if (!in_array($mode, Settings::MODES, true)) {
            throw new SourceException('invalid_mode', 'Unknown draft mode.');
        }
        if (in_array($mode, EstimateCalculator::MODEL_MODES, true) && !$this->p->drafts()->callerAvailable()) {
            throw new SourceException('no_llm_caller', 'This draft mode needs a model caller: install llm-json-php or supply one with the audiopost_llm_caller filter.');
        }
        $language = is_string($options['language'] ?? null) && $options['language'] !== '' ? $options['language'] : $s->string('language');
        if ($language !== 'auto' && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
            throw new SourceException('invalid_language', 'The language must be auto or a BCP 47 tag.');
        }
        $adapter = $source->isFree() ? TranscriptFileAdapter::ID : (is_string($options['adapter'] ?? null) && $options['adapter'] !== '' ? $options['adapter'] : $s->string('adapter'));
        if ($source->isAudio()) {
            if (($options['rights_confirmed'] ?? false) !== true) {
                throw new SourceException('rights_not_confirmed', 'Confirm that you have the right to transcribe and publish from this recording.');
            }
            if ($adapter === '' || $this->p->adapters()->get($adapter) === null) {
                throw new SourceException('no_adapter', 'Choose a transcription adapter on the settings screen first.');
            }
            $url = $source->kind === Source::URL ? $source->get('url') : ($source->kind === Source::FEED_EPISODE ? $source->get('enclosure_url') : null);
            if ($url !== null) {
                $this->p->urlGuard()->check($url);
            }
            if ($source->kind === Source::ATTACHMENT) {
                $mime = (string) get_post_mime_type($source->attachmentId());
                if ($mime === '' || get_post($source->attachmentId()) === null) {
                    throw new SourceException('invalid_source', 'The attachment does not exist.');
                }
                if (str_starts_with($mime, 'video/')) {
                    throw new AudioException('video_not_supported', 'Video files are not supported. Upload the audio only.');
                }
            }
        }
        $repo = $this->p->repo();
        $id = $repo->create($source, ['mode' => $mode, 'language' => $language, 'adapter' => $adapter], $userId);
        if ($source->isAudio()) {
            $repo->setMeta($id, 'rights', ['user' => $userId, 'time' => $this->p->clock()->now()]);
        }
        $run = $repo->get($id);
        if ($run === null) {
            throw new StateException('storage_error', 'The run could not be stored.');
        }
        $repo->log($id, 'run created from ' . $source->kind);
        do_action('audiopost_run_created', $run);

        return $run;
    }

    /**
     * Fetch, probe, plan and estimate. Free: no paid call (case 19).
     * Returns false when the work must wait (a scheduled fetch retry).
     */
    public function prepare(int $id): bool
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null) {
            return false;
        }
        if ($run->state === RunState::NEW) {
            $run = $repo->move($id, RunState::FETCHING);
        }
        if ($run->state !== RunState::FETCHING) {
            return true;
        }
        $due = (int) $repo->meta($id, 'due_at', 0);
        if ($due > $this->p->clock()->now()) {
            return false;
        }
        try {
            if ($run->source->isFree()) {
                $this->importTranscript($run);
            } else {
                $this->fetchAudio($run);
            }
        } catch (SourceException $e) {
            if ($e->errorCode() === 'fetch_failed') {
                $attempt = (int) $repo->meta($id, 'fetch_attempts', 0) + 1;
                $repo->setMeta($id, 'fetch_attempts', $attempt);
                if ($attempt <= count(self::FETCH_DELAYS)) {
                    $delay = self::FETCH_DELAYS[$attempt - 1];
                    $delay += random_int(0, max(1, intdiv($delay, 2)));
                    $repo->setMeta($id, 'due_at', $this->p->clock()->now() + $delay);
                    $repo->log($id, sprintf('fetch failed (%s); retry %d in %d s', $e->getMessage(), $attempt, $delay));

                    return false;
                }
            }
            $this->fail($id, $e->errorCode(), $e->getMessage());
        } catch (AudioToPostException $e) {
            $this->fail($id, $e->errorCode(), $e->getMessage());
        }

        return true;
    }

    private function fail(int $id, string $code, string $message): void
    {
        $this->p->repo()->move($id, RunState::FAILED, $code, $code, $message);
    }

    /**
     * @throws AudioToPostException
     */
    private function fetchAudio(Run $run): void
    {
        $repo = $this->p->repo();
        $files = $this->p->files();
        $maxBytes = $this->filteredInt('audiopost_max_bytes', $this->p->settings()->int('max_bytes'));
        $fileId = (string) $repo->meta($run->id, 'file', '');
        if ($fileId === '') {
            $fileId = PrivateStorage::newId();
            $repo->setMeta($run->id, 'file', $fileId);
            $files->register($run->id, $fileId, 'audio');
        }
        $path = $files->path($fileId);
        $contentType = null;
        if ($run->source->kind === Source::ATTACHMENT) {
            $src = get_attached_file($run->source->attachmentId());
            if (!is_string($src) || !is_file($src)) {
                throw new SourceException('source_unavailable', 'The attachment file is missing.');
            }
            if ((int) filesize($src) > $maxBytes) {
                throw new SourceException('too_large', 'The file is larger than the size limit.');
            }
            if (!copy($src, $path)) {
                throw new SourceException('storage_unwritable', 'Cannot copy the attachment to private storage.');
            }
            $contentType = (string) get_post_mime_type($run->source->attachmentId());
            $repo->log($run->id, 'attachment copied to private storage');
        } else {
            $url = (string) ($run->source->kind === Source::URL ? $run->source->get('url') : $run->source->get('enclosure_url'));
            $dl = $this->p->downloader();
            $head = $dl->head($url);
            if ($head !== null) {
                if ($head->bytes > $maxBytes) {
                    throw new SourceException('too_large', 'The file is larger than the size limit; the download was not started.');
                }
                $key = hash('sha256', strtolower($head->finalUrl) . '|' . $head->bytes . '|' . ($head->etag ?? $head->lastModified ?? ''));
                $repo->setMeta($run->id, 'url_key', $key);
            }
            $resume = 0;
            if (is_file($path) && (bool) $repo->meta($run->id, 'accept_ranges', false)) {
                $resume = (int) filesize($path);
            }
            $res = $dl->download($url, $path, $maxBytes, $resume);
            $repo->setMeta($run->id, 'accept_ranges', $res->acceptRanges);
            $contentType = $res->contentType;
            $repo->log($run->id, sprintf('downloaded %d bytes', $res->bytes));
        }
        $info = $this->p->prober()->probe($path, $contentType);
        $maxSeconds = $this->filteredInt('audiopost_max_seconds', $this->p->settings()->int('max_seconds'));
        if ($info->durationMs !== null && $info->durationMs > $maxSeconds * 1000) {
            throw new AudioException('too_long', sprintf('The recording is longer than the limit of %d minutes.', intdiv($maxSeconds, 60)));
        }
        $repo->setMeta($run->id, 'audio', $info->toArray());
        $repo->log($run->id, sprintf('probed: %s, %s ms (%s), %d bytes', $info->format, $info->durationMs === null ? 'unknown' : (string) $info->durationMs, $info->durationExact ? 'exact' : 'estimated', $info->sizeBytes));
        $this->duplicateCheck($run, $info);
        $this->planAndEstimate($run->id, $info);
    }

    /** @param non-empty-string $filter */
    private function filteredInt(string $filter, int $default): int
    {
        $v = apply_filters($filter, $default);
        if (!is_int($v) || $v <= 0) {
            if ($v !== $default) {
                Debug::once($filter, 'filter ' . $filter . ' returned an invalid value; default used');
            }

            return $default;
        }

        return $v;
    }

    private function dupKey(Run $run, AudioInfo $info): string
    {
        $mp = $this->p->settings()->get('multipart');

        return hash('sha256', $info->sha256 . '|' . $run->adapterId() . '|' . $run->language() . '|' . (is_array($mp) ? (string) ($mp['model'] ?? '') : ''));
    }

    private function duplicateCheck(Run $run, AudioInfo $info): void
    {
        $repo = $this->p->repo();
        $key = $this->dupKey($run, $info);
        $repo->setMeta($run->id, 'dup_key', $key);
        foreach ($repo->find([RunState::TRANSCRIPT_READY, RunState::DRAFTING, RunState::DRAFT_READY], 200, null, 'DESC') as $other) {
            if ($other !== $run->id && $repo->meta($other, 'dup_key') === $key && $repo->transcript($other) !== null) {
                $repo->setFlags($run->id, ['duplicate_of' => $other]);
                $repo->log($run->id, 'same recording and settings as run ' . $other . '; its transcript can be reused for free');

                return;
            }
        }
    }

    /**
     * @throws AudioToPostException
     */
    private function planAndEstimate(int $id, AudioInfo $info): void
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null) {
            return;
        }
        $adapter = $this->p->adapters()->get($run->adapterId());
        if ($adapter === null) {
            throw new PlanException('no_adapter', 'The transcription adapter is not available.');
        }
        $plan = $this->plan($adapter, $info, $this->p->files()->path((string) $repo->meta($id, 'file', '')));
        $repo->setMeta($id, 'plan', $plan->toArray());
        $repo->log($id, sprintf('plan: %d chunk(s)%s', $plan->count(), $plan->native ? ', native long audio' : ''));
        $estimate = $this->estimate($id);
        $run = $repo->move($id, RunState::PROBED);
        do_action('audiopost_estimate_ready', $run, $estimate);
        $repo->move($id, RunState::AWAITING_CONFIRMATION);
    }

    /**
     * @throws PlanException
     */
    public function plan(TranscriptionAdapter $adapter, AudioInfo $info, string $path): ChunkPlan
    {
        $s = $this->p->settings();
        $caps = $this->p->adapters()->effectiveCapabilities($adapter);
        $chunker = $this->chunker($info);
        $overlap = apply_filters('audiopost_overlap_seconds', $s->int('overlap_seconds'));
        $overlap = is_int($overlap) ? max(0, min(30, $overlap)) : $s->int('overlap_seconds');
        $plan = (new ChunkPlanner())->plan($info, $caps, $s->int('chunk_seconds'), $overlap, $chunker !== null);
        $filtered = apply_filters('audiopost_chunk_plan', $plan, $info, $adapter->id());
        if ($filtered instanceof ChunkPlan && $filtered !== $plan) {
            if (ChunkPlanner::isValid($filtered, $caps, ChunkPlanner::planningDuration($info))) {
                $plan = $filtered;
            } else {
                Debug::once('chunk_plan', 'filter audiopost_chunk_plan returned a plan that breaks the adapter limits; ignored');
            }
        }
        if ($plan->count() > 1 && $chunker !== null) {
            $plan = $chunker->locate($path, $info, $plan);
        }

        return $plan;
    }

    public function chunker(AudioInfo $info): ?Chunker
    {
        $dir = $this->p->files()->dir();
        foreach ([new Mp3Chunker($dir), new WavChunker($dir)] as $c) {
            if ($c->supports($info)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Build and store the estimate for a run. With $remainingOnly, only
     * chunks without a stored result count (a retry, case 60).
     */
    public function estimate(int $id, bool $remainingOnly = false, ?bool $reuse = null): Estimate
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null) {
            throw new StateException('not_found', 'Run not found.');
        }
        $s = $this->p->settings();
        $s->flush();
        $adapter = $this->p->adapters()->get($run->adapterId());
        $caps = $adapter !== null ? $this->p->adapters()->effectiveCapabilities($adapter) : null;
        $plan = $run->plan;
        if ($plan !== null && $remainingOnly) {
            $left = [];
            foreach ($plan->chunks as $c) {
                if ($repo->chunkResult($id, $c->index) === null) {
                    $left[] = $c;
                }
            }
            $plan = new ChunkPlan($plan->format, $left, $plan->native);
        }
        $transcript = $repo->transcript($id);
        $reuse ??= (bool) $repo->flag($id, 'reuse_from');
        $free = $run->source->isFree() || $reuse || ($caps->free ?? false);
        if ($reuse && $transcript === null) {
            $other = (int) $repo->flag($id, 'duplicate_of');
            $transcript = $other > 0 ? $repo->transcript($other) : null;
        }
        $speechMs = $run->audio !== null ? ChunkPlanner::planningDuration($run->audio) : ($transcript->durationMs ?? 0);
        $mode = $run->mode();
        $language = $run->language() !== 'auto' ? $run->language() : ($transcript?->language ?: 'auto');
        $script = $transcript !== null ? TokenRatios::scriptOfText($transcript->text()) : null;
        $units = $transcript !== null ? TranscriptText::units($transcript, $script === TokenRatios::CJK) : null;
        $promptMode = in_array($mode, EstimateCalculator::MODEL_MODES, true) ? $mode : 'article';
        $modelId = $s->string('model');
        $prices = $s->get('prices');
        $prices = is_array($prices) ? $prices : [];
        $price = static fn (string $kind, string $key): ?string => is_array($prices[$kind] ?? null) && is_string($prices[$kind][$key] ?? null) ? $prices[$kind][$key] : null;
        /** @var array<string, float> $ratios */
        $ratios = is_array($s->get('ratio_overrides')) ? $s->get('ratio_overrides') : [];
        $est = (new EstimateCalculator())->estimate([
            'plan' => $plan,
            'caps' => $caps,
            'speech_ms' => $speechMs,
            'duration_exact' => $run->audio->durationExact ?? true,
            'upper_bound' => $run->audio !== null && $run->audio->durationMs === null,
            'mode' => $mode,
            'language' => $language,
            'known_units' => $units,
            'script' => $script,
            'words_per_minute' => $s->int('words_per_minute'),
            'characters_per_minute' => $s->int('characters_per_minute'),
            'ratio_overrides' => $ratios,
            'prompt_chars' => Prompts::overheadChars($s->prompt($promptMode, 'single')),
            'notes_prompt_chars' => Prompts::overheadChars($s->prompt($promptMode, 'notes')),
            'max_output_tokens' => $this->maxOutput($mode),
            'notes_max_output_tokens' => $s->int('notes_max_output'),
            'single_pass_max_tokens' => $s->int('single_pass_max_tokens'),
            'block_words' => $s->int('block_words'),
            'price_per_minute' => $adapter !== null ? $price('minute', $adapter->id()) : null,
            'price_input' => $price('input', $modelId),
            'price_output' => $price('output', $modelId),
            'currency' => $s->string('currency'),
            'free_transcription' => $free,
            'fingerprint_material' => [
                'sha' => $run->audio?->sha256,
                'adapter' => $run->adapterId(),
                'model' => $modelId,
                'reuse' => $reuse,
                'settings' => [$s->get('chunk_seconds'), $s->get('overlap_seconds'), $s->get('words_per_minute'), $s->get('characters_per_minute'), $ratios, $this->maxOutput($mode), $s->get('single_pass_max_tokens'), $s->get('block_words'), $s->get('notes_max_output')],
            ],
        ]);
        $repo->setMeta($id, 'estimate', $est->toArray());
        $repo->setMeta($id, 'estimated_at', $this->p->clock()->now());

        return $est;
    }

    public function maxOutput(string $mode): int
    {
        $s = $this->p->settings();

        return match ($mode) {
            'article' => $s->int('max_output_article'),
            'show_notes' => $s->int('max_output_show_notes'),
            default => 0,
        };
    }

    /**
     * @throws TranscriptFormatException|SourceException
     */
    private function importTranscript(Run $run): void
    {
        $repo = $this->p->repo();
        $adapter = new TranscriptFileAdapter();
        $id = $run->id;
        $format = null;
        if ($run->source->kind === Source::FEED_EPISODE) {
            $url = (string) $run->source->get('transcript_url');
            try {
                $res = $this->p->downloader()->text($url, \IndianOpinion\AudioToPost\Transcript\CueParser::MAX_BYTES);
                $type = (string) $run->source->get('transcript_type');
                $parsed = \IndianOpinion\AudioToPost\Transcript\CueParser::parse($res->body, $type !== '' ? $type : null);
            } catch (AudioToPostException $e) {
                throw new SourceException('transcript_unavailable', 'The published transcript could not be used (' . $e->getMessage() . '). Start a new run with the episode audio instead.');
            }
        } else {
            $att = (int) ($run->source->fields['transcript_attachment_id'] ?? 0);
            $path = $att > 0 ? get_attached_file($att) : false;
            if (!is_string($path) || !is_file($path)) {
                throw new TranscriptFormatException('transcript_unreadable', 'The uploaded transcript file is missing.');
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $format = in_array($ext, ['vtt', 'srt', 'json'], true) ? $ext : null;
            $parsed = $adapter->import($path, $format);
        }
        $t = $parsed['transcript'];
        $repo->saveTranscript($id, $t, true);
        $repo->saveTranscript($id, $t);
        $repo->setMeta($id, 'revision', 0);
        $repo->setFlags($id, ['skipped_cues' => $parsed['skipped'], 'timestamps' => $t->timestamps, 'speakers' => $t->speakersFlag]);
        $repo->log($id, sprintf('transcript imported (%s, %d segments, %d cue(s) skipped)', $parsed['format'], count($t->segments), $parsed['skipped']));
        $estimate = $this->estimate($id);
        $run = $repo->move($id, RunState::PROBED);
        do_action('audiopost_estimate_ready', $run, $estimate);
        if (in_array($run->mode(), EstimateCalculator::MODEL_MODES, true)) {
            $repo->move($id, RunState::AWAITING_CONFIRMATION);
        } else {
            $repo->move($id, RunState::QUEUED, 'free source');
            if ($run->mode() === 'transcript') {
                $this->queueDraft($id, 'transcript');
            }
        }
    }

    /**
     * Confirm a stored estimate (case 32). Refuses with `estimate_stale`
     * when anything that affects cost changed.
     *
     * @return array{state: string, queued_for_tomorrow: bool, estimate: Estimate}
     *
     * @throws AudioToPostException
     */
    public function confirm(int $id, string $fingerprint, bool $reuse = false, int $userId = 0): array
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null) {
            throw new StateException('not_found', 'Run not found.');
        }
        if ($run->state !== RunState::AWAITING_CONFIRMATION) {
            throw new StateException('not_awaiting_confirmation', 'This run is not waiting for a confirmation.');
        }
        $reuse = $reuse && (int) $repo->flag($id, 'duplicate_of') > 0;
        $previous = $run->estimate?->fingerprint;
        $fresh = $this->estimate($id, false, $reuse);
        if (!hash_equals($fresh->fingerprint, $fingerprint) || ($previous !== null && !$reuse && !hash_equals($previous, $fresh->fingerprint))) {
            throw new StateException('estimate_stale', 'The estimate changed (settings, prices or the file). Check the new estimate and confirm again.');
        }
        if (!$run->source->isFree() && !$reuse) {
            $adapter = $this->p->adapters()->get($run->adapterId());
            if ($adapter === null || !$adapter->isConfigured()) {
                throw new StateException('adapter_not_configured', 'The transcription adapter is not configured: ' . implode(' ', $adapter?->configurationProblems() ?? ['missing']));
            }
        }
        $fit = $this->p->guard()->checkEstimate($fresh);
        if ($fit !== null && $fit['whole']) {
            throw new CapException('exceeds_daily_cap', sprintf('This run needs more than the whole daily %s cap. Raise the cap or shorten the recording.', $fit['unit']));
        }
        $tomorrow = $fit !== null;
        $repo->setMeta($id, 'confirmed', ['user' => $userId, 'time' => $this->p->clock()->now(), 'fingerprint' => $fresh->fingerprint]);
        if ($reuse) {
            $repo->setFlags($id, ['reuse_from' => (int) $repo->flag($id, 'duplicate_of')]);
        }
        if ($tomorrow) {
            $repo->setMeta($id, 'due_at', $this->p->clock()->nextLocalMidnight());
            $repo->setFlags($id, ['queued_for_tomorrow' => true]);
        } else {
            $repo->setMeta($id, 'due_at', 0);
        }
        $repo->move($id, RunState::QUEUED, $tomorrow ? 'queued for tomorrow: ' . $fit['unit'] . ' cap' : 'confirmed');
        if ($run->mode() !== 'none') {
            $this->queueDraft($id, $run->mode());
        }

        return ['state' => RunState::QUEUED, 'queued_for_tomorrow' => $tomorrow, 'estimate' => $fresh];
    }

    /**
     * Cancel (case 61): no new call starts; a call in flight completes
     * and its result is discarded by the runner.
     */
    public function cancel(int $id): bool
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null || !RunState::canMove($run->state, RunState::CANCELLED)) {
            return false;
        }
        $repo->setMeta($id, 'cancel', true);
        $repo->log($id, 'cancel requested');
        if (!$this->p->locks()->isHeld('run_' . $id)) {
            $repo->move($id, RunState::CANCELLED, 'cancelled by editor');
        }

        return true;
    }

    /**
     * Retry a failed run (case 60). Only chunks without a stored result
     * are sent. When the remaining cost changed since the confirmation,
     * the caller must pass the new fingerprint.
     *
     * @return array{state: string, estimate: Estimate}
     *
     * @throws AudioToPostException
     */
    public function retry(int $id, ?string $fingerprint = null): array
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null) {
            throw new StateException('not_found', 'Run not found.');
        }
        if ($run->state !== RunState::FAILED) {
            throw new StateException('not_failed', 'Only a failed run can be retried.');
        }
        $confirmed = $repo->meta($id, 'confirmed');
        if (!is_array($confirmed)) {
            throw new StateException('not_confirmed', 'This run failed before its estimate was confirmed. Start a new run.');
        }
        $est = $this->estimate($id, true);
        {
            $before = $repo->meta($id, 'confirmed_remaining');
            $fresh = $est->fingerprint;
            $changed = is_string($before) && !hash_equals($before, $fresh);
        if (($changed || $fingerprint !== null) && ($fingerprint === null || !hash_equals($fresh, $fingerprint))) {
            $repo->setMeta($id, 'confirmed_remaining', $fresh);
            throw new StateException('estimate_stale', 'The remaining cost changed. Check the new estimate and confirm the retry.');
        }
            $repo->setMeta($id, 'confirmed_remaining', $fresh);
        }
        $repo->deleteMeta($id, 'chunk_attempts');
        $repo->deleteMeta($id, 'retry_at');
        $repo->deleteMeta($id, 'error');
        $repo->setMeta($id, 'due_at', 0);
        $fileId = (string) $repo->meta($id, 'file', '');
        if ($fileId !== '' && !is_file($this->p->files()->path($fileId))) {
            $repo->setFlags($id, ['refetch' => true]);
        }
        $repo->move($id, RunState::QUEUED, 'retry');

        return ['state' => RunState::QUEUED, 'estimate' => $est];
    }

    /**
     * Queue a draft (case 65). Returns the job number.
     *
     * @throws AudioToPostException
     */
    public function createDraft(int $id, string $mode): int
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null) {
            throw new StateException('not_found', 'Run not found.');
        }
        if (!in_array($mode, ['article', 'show_notes', 'transcript'], true)) {
            throw new SourceException('invalid_mode', 'Unknown draft mode.');
        }
        if (in_array($mode, EstimateCalculator::MODEL_MODES, true) && !$this->p->drafts()->callerAvailable()) {
            throw new StateException('no_llm_caller', 'This mode needs a model caller.');
        }
        if (!in_array($run->state, [RunState::TRANSCRIPT_READY, RunState::DRAFT_READY, RunState::DRAFTING], true)) {
            throw new StateException('transcript_not_ready', 'The transcript is not ready.');
        }

        return $this->queueDraft($id, $mode);
    }

    public function queueDraft(int $id, string $mode): int
    {
        $repo = $this->p->repo();
        $queue = $repo->meta($id, 'draft_queue', []);
        $queue = is_array($queue) ? $queue : [];
        $job = (int) $repo->meta($id, 'draft_seq', 0) + 1;
        $repo->setMeta($id, 'draft_seq', $job);
        $queue[] = ['job' => $job, 'mode' => $mode];
        $repo->setMeta($id, 'draft_queue', $queue);
        $repo->log($id, 'draft queued: ' . $mode . ' (job ' . $job . ')');

        return $job;
    }

    public function transcript(int $id, bool $original = false): ?Transcript
    {
        return $this->p->repo()->transcript($id, $original);
    }

    /**
     * @throws StateException
     */
    public function export(int $id, string $format, bool $original = false): string
    {
        if (!in_array($format, Exporter::FORMATS, true)) {
            throw new StateException('invalid_format', 'Export formats are json, vtt, srt and txt.');
        }
        $t = $this->transcript($id, $original);
        if ($t === null) {
            throw new StateException('no_transcript', 'This run has no transcript yet.');
        }

        return Exporter::export($t, $format, $this->p->settings()->int('turn_gap_seconds') * 1000);
    }

    /**
     * Apply transcript edits (case 77) with optimistic locking.
     *
     * @param list<array<string, mixed>> $edits
     *
     * @throws AudioToPostException
     */
    public function editTranscript(int $id, array $edits, int $revision): Transcript
    {
        $repo = $this->p->repo();
        $current = (int) $repo->meta($id, 'revision', 0);
        if ($revision !== $current) {
            throw new StateException('revision_conflict', 'The transcript changed since you loaded it. Reload and try again.');
        }
        $t = $repo->transcript($id);
        if ($t === null) {
            throw new StateException('no_transcript', 'This run has no transcript yet.');
        }
        $t = \IndianOpinion\AudioToPost\Transcript\Editor::apply($t, $edits);
        $repo->saveTranscript($id, $t);
        $repo->setMeta($id, 'revision', $current + 1);
        $repo->log($id, sprintf('transcript edited (%d change(s)), revision %d', count($edits), $current + 1));

        return $t;
    }

    /**
     * Episodes of a podcast feed for the picker (case 3).
     *
     * @return list<array<string, mixed>>
     *
     * @throws AudioToPostException
     */
    public function feedEpisodes(string $url): array
    {
        $res = $this->p->downloader()->text($url, 20 * 1024 * 1024);

        return FeedParser::episodes($res->body);
    }

    /**
     * Re-estimate an expired run (case 40).
     *
     * @throws AudioToPostException
     */
    public function reestimate(int $id): Estimate
    {
        $repo = $this->p->repo();
        $run = $repo->get($id);
        if ($run === null || $run->state !== RunState::EXPIRED) {
            throw new StateException('not_expired', 'Only an expired run can be re-estimated.');
        }
        $fileId = (string) $repo->meta($id, 'file', '');
        $copy = $fileId !== '' && is_file($this->p->files()->path($fileId));
        if (!$copy && !$run->source->isFree()) {
            $repo->move($id, RunState::PROBED, 're-estimate');
            $repo->move($id, RunState::FAILED, 'copy gone', 'source_copy_missing', 'The audio copy was removed under the retention setting. Start a new run to fetch it again.');
            throw new StateException('source_copy_missing', 'The audio copy is gone; start a new run.');
        }
        $repo->move($id, RunState::PROBED, 're-estimate');
        $est = $this->estimate($id);
        $repo->move($id, RunState::AWAITING_CONFIRMATION);

        return $est;
    }

    public function delete(int $id): void
    {
        $this->p->repo()->log($id, 'run deleted');
        $this->p->repo()->delete($id);
    }
}
