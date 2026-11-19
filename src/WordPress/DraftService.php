<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\AudioToPostException;
use IndianOpinion\AudioToPost\CapException;
use IndianOpinion\AudioToPost\DisabledException;
use IndianOpinion\AudioToPost\Draft\BlockBuilder;
use IndianOpinion\AudioToPost\Draft\CallableCaller;
use IndianOpinion\AudioToPost\Draft\DraftComposer;
use IndianOpinion\AudioToPost\Draft\DraftSchema;
use IndianOpinion\AudioToPost\Draft\LlmCaller;
use IndianOpinion\AudioToPost\Draft\LlmJsonCaller;
use IndianOpinion\AudioToPost\Draft\Prompts;
use IndianOpinion\AudioToPost\Draft\TranscriptText;
use IndianOpinion\AudioToPost\DraftResult;
use IndianOpinion\AudioToPost\Estimate\EstimateCalculator;
use IndianOpinion\AudioToPost\Estimate\Money;
use IndianOpinion\AudioToPost\Estimate\TokenRatios;
use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\ProviderException;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Source;
use IndianOpinion\AudioToPost\StateException;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\Transcript\Timecode;

/**
 * The draft step (cases 62 to 70, 84 to 86). Every model call goes
 * through a caller: a site-supplied one from `audiopost_llm_caller`, or
 * llm-json-php. There is no hidden built-in HTTP client.
 */
final class DraftService
{
    public const NOTES_CARRY = 12;

    public function __construct(private readonly Plugin $p)
    {
    }

    public function hasWork(int $id): bool
    {
        $q = $this->p->repo()->meta($id, 'draft_queue', []);

        return is_array($q) && $q !== [];
    }

    /** True when `article` and `show_notes` can run (case 86). */
    public function callerAvailable(?Run $run = null): bool
    {
        return $this->caller($run, ['mode' => 'article', 'pass' => 'single', 'run_id' => $run->id ?? 0], false) !== null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function caller(?Run $run, array $context, bool $log = true): ?LlmCaller
    {
        $context += ['schema' => null, 'model' => $this->p->settings()->string('model')];
        $filtered = apply_filters('audiopost_llm_caller', null, $context);
        if ($filtered !== null) {
            if (is_callable($filtered)) {
                return new CallableCaller($filtered);
            }
            if ($log && Debug::once('llm_caller_invalid', 'audiopost_llm_caller returned something that is not callable; ignored') && $run !== null) {
                $this->p->repo()->log($run->id, 'audiopost_llm_caller returned a non-callable value; ignored');
            }
        }
        if (!LlmJsonCaller::available()) {
            return null;
        }
        $transport = $this->p->llmTransport();

        return new LlmJsonCaller([
            'provider' => $this->p->settings()->string('model_provider'),
            'base_url' => $this->p->settings()->string('model_base_url'),
            'model' => $this->p->settings()->string('model'),
            'key' => Settings::modelKey(),
            'timeout' => $this->p->settings()->int('http_timeout'),
        ], $transport instanceof \IndianOpinion\LlmJson\Transport\Transport ? $transport : null);
    }

    /**
     * Work through the run's draft queue. Returns `kill_switch`,
     * `daily_cap`, `spend_meter` or `busy` when stopped by one of them.
     */
    public function process(int $id, float $deadline): ?string
    {
        $repo = $this->p->repo();
        while (microtime(true) < $deadline) {
            $run = $repo->get($id);
            if ($run === null) {
                return null;
            }
            $queue = $repo->meta($id, 'draft_queue', []);
            $queue = is_array($queue) ? array_values($queue) : [];
            if ($queue === []) {
                if ($run->state === RunState::DRAFTING) {
                    $repo->move($id, $run->draftIds === [] ? RunState::TRANSCRIPT_READY : RunState::DRAFT_READY);
                }

                return null;
            }
            $item = is_array($queue[0]) ? $queue[0] : [];
            $mode = (string) ($item['mode'] ?? 'transcript');
            $job = (int) ($item['job'] ?? 0);
            if ($run->state !== RunState::DRAFTING) {
                $run = $repo->move($id, RunState::DRAFTING, $mode);
            }
            $transcript = $repo->transcript($id);
            if ($transcript === null) {
                $repo->setMeta($id, 'draft_queue', array_slice($queue, 1));
                $repo->move($id, RunState::TRANSCRIPT_READY, 'no transcript', 'no_transcript', 'The transcript is missing.');

                return null;
            }
            try {
                $reply = $mode === 'transcript' ? null : $this->modelDraft($run, $transcript, $mode, $job);
                $this->createPost($run, $transcript, $mode, $reply);
                $repo->setMeta($id, 'draft_queue', array_slice($queue, 1));
                $repo->deleteMeta($id, 'notes_' . $job);
                $current = $repo->get($id);
                if ($current !== null && $current->state === RunState::DRAFTING) {
                    $repo->move($id, RunState::DRAFT_READY, $mode);
                }
            } catch (DisabledException $e) {
                $this->p->runner()->pause($id, 'kill_switch', null);

                return 'kill_switch';
            } catch (CapException $e) {
                if ($e->errorCode() === 'spend_busy') {
                    return 'busy';
                }
                if ($e->errorCode() === 'daily_cap') {
                    $this->p->runner()->pause($id, 'daily_cap', $this->p->clock()->nextLocalMidnight());

                    return 'daily_cap';
                }
                $this->p->runner()->pause($id, 'spend_meter', $this->p->clock()->now() + Runner::METER_RETRY);

                return 'spend_meter';
            } catch (AudioToPostException $e) {
                $repo->setMeta($id, 'draft_queue', array_slice($queue, 1));
                $current = $repo->get($id);
                if ($current !== null && $current->state === RunState::DRAFTING) {
                    $repo->move($id, RunState::TRANSCRIPT_READY, 'draft failed', $e->errorCode(), 'The draft failed: ' . $e->getMessage());
                }
                if ($e instanceof ProviderException && in_array($e->errorCode(), [ProviderException::AUTH, ProviderException::ACCOUNT], true)) {
                    Options::set('audiopost_notice_provider', ['adapter' => 'draft model', 'code' => $e->errorCode(), 'until' => $this->p->clock()->now() + Runner::HOLD_SECONDS]);
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Single pass, or notes per block then a final pass (case 62).
     *
     * @return array<string, mixed>
     *
     * @throws AudioToPostException
     */
    private function modelDraft(Run $run, Transcript $t, string $mode, int $job): array
    {
        $s = $this->p->settings();
        $script = TokenRatios::scriptOfText($t->text());
        $cjk = $script === TokenRatios::CJK;
        $language = $run->language() !== 'auto' ? $run->language() : ($t->language !== '' ? $t->language : 'auto');
        /** @var array<string, float> $ratios */
        $ratios = is_array($s->get('ratio_overrides')) ? $s->get('ratio_overrides') : [];
        $ratio = TokenRatios::ratio($language, $ratios, $script);
        $tokens = (int) ceil(TranscriptText::units($t, $cjk) * $ratio);
        $vars = $this->vars($run, $t);
        if ($tokens <= $s->int('single_pass_max_tokens')) {
            return $this->callModel($run, $mode, 'single', $this->schema($mode), $vars + ['transcript' => TranscriptText::lines($t), 'notes' => ''], $this->p->runs()->maxOutput($mode), $ratio);
        }
        $repo = $this->p->repo();
        $notes = $repo->meta($run->id, 'notes_' . $job, []);
        $notes = is_array($notes) ? $notes : [];
        $blocks = TranscriptText::blocks($t, $s->int('block_words'), $cjk);
        foreach ($blocks as $b => $segments) {
            if (isset($notes[$b]) && is_array($notes[$b])) {
                continue;
            }
            $carry = [];
            foreach ($notes as $n) {
                foreach (is_array($n['key_points'] ?? null) ? $n['key_points'] : [] as $kp) {
                    if (is_string($kp)) {
                        $carry[] = '- ' . $kp;
                    }
                }
            }
            $carry = array_slice($carry, -self::NOTES_CARRY);
            $notes[$b] = $this->callModel($run, $mode, 'notes', DraftSchema::notes(), $vars + ['transcript' => TranscriptText::lines($t, $segments), 'notes' => $carry === [] ? '(none yet)' : implode("\n", $carry)], $s->int('notes_max_output'), $ratio);
            $repo->setMeta($run->id, 'notes_' . $job, $notes);
            $repo->log($run->id, sprintf('notes for block %d of %d done', $b + 1, count($blocks)));
        }
        $points = [];
        $quoteIds = [];
        $topics = [];
        foreach ($notes as $n) {
            foreach (is_array($n['key_points'] ?? null) ? $n['key_points'] : [] as $kp) {
                if (is_string($kp)) {
                    $points[] = '- ' . $kp;
                }
            }
            foreach (is_array($n['quote_segment_ids'] ?? null) ? $n['quote_segment_ids'] : [] as $q) {
                if (is_string($q) && $t->segment($q) !== null) {
                    $quoteIds[$q] = true;
                }
            }
            foreach (is_array($n['topics'] ?? null) ? $n['topics'] : [] as $tp) {
                if (is_array($tp) && is_string($tp['segment_id'] ?? null) && is_string($tp['title'] ?? null)) {
                    $topics[] = '- topic "' . $tp['title'] . '" starts at [' . $tp['segment_id'] . ']';
                }
            }
        }
        $quoteSegs = array_values(array_filter($t->segments, static fn ($seg): bool => isset($quoteIds[$seg->id])));

        return $this->callModel($run, $mode, 'final', $this->schema($mode), $vars + [
            'notes' => implode("\n", array_merge($points, $topics)),
            'transcript' => TranscriptText::lines($t, $quoteSegs),
        ], $this->p->runs()->maxOutput($mode), $ratio);
    }

    /** @return array<string, mixed> */
    private function schema(string $mode): array
    {
        $base = DraftSchema::draft($mode);
        $filtered = apply_filters('audiopost_draft_schema', $base, $mode);
        if (is_array($filtered) && DraftSchema::isSuperset($base, $filtered)) {
            /** @var array<string, mixed> $filtered */
            return $filtered;
        }
        if ($filtered !== $base) {
            Debug::once('draft_schema', 'audiopost_draft_schema returned a schema that is not a superset; default used');
        }

        return $base;
    }

    /**
     * @return array<string, string>
     */
    private function vars(Run $run, Transcript $t): array
    {
        return [
            'title' => (string) ($run->source->get('episode_title') ?? $run->source->label()),
            'source_label' => $run->source->label(),
            'duration' => Timecode::hms($t->durationMs),
            'language' => $run->language() !== 'auto' ? $run->language() : ($t->language !== '' ? $t->language : 'as spoken'),
            'speakers' => TranscriptText::speakerList($t),
            'mode' => $run->mode(),
        ];
    }

    /**
     * One model call with the checks, the schema check and usage.
     *
     * @param array<string, mixed>  $schema
     * @param array<string, string> $vars
     *
     * @return array<string, mixed>
     *
     * @throws AudioToPostException
     */
    private function callModel(Run $run, string $mode, string $pass, array $schema, array $vars, int $maxOut, float $ratio): array
    {
        $repo = $this->p->repo();
        $context = ['mode' => $mode, 'pass' => $pass, 'run_id' => $run->id, 'schema' => $schema, 'model' => $this->p->settings()->string('model')];
        $caller = $this->caller($run, $context);
        if ($caller === null) {
            throw new StateException('no_llm_caller', 'No model caller: install llm-json-php or supply one with the audiopost_llm_caller filter.');
        }
        $template = $this->p->settings()->prompt($mode, $pass);
        $filtered = apply_filters('audiopost_prompt', $template, $mode, $pass, $run);
        if (is_string($filtered) && $filtered !== '') {
            $template = $filtered;
        } elseif ($filtered !== $template) {
            Debug::once('prompt_filter', 'audiopost_prompt returned a non-string; default used');
        }
        $rendered = Prompts::render($template, $vars);
        foreach ($rendered['unknown'] as $u) {
            if (Debug::once('prompt_var_' . $u, 'unknown prompt variable {' . $u . '} left as typed')) {
                $repo->log($run->id, 'unknown prompt variable {' . $u . '} left as typed');
            }
        }
        $prompt = $rendered['text'];
        $estIn = (int) ceil(mb_strlen($prompt) / 4 * 1.1);
        $model = $context['model'];
        $intent = [
            'kind' => 'draft',
            'seconds' => 0,
            'tokens_in' => $estIn,
            'tokens_out' => $maxOut,
            'money_micros' => $this->tokenMoney($model, $estIn, $maxOut),
            'provider' => $caller->id(),
            'model' => $model,
        ];
        $max = $this->p->settings()->int('max_attempts');
        $request = ['prompt' => $prompt, 'input' => 'Answer now with the JSON object.', 'schema' => $schema, 'schema_name' => $pass === 'notes' ? 'notes' : 'draft', 'max_output_tokens' => $maxOut];
        for ($attempt = 1; $attempt <= $max; $attempt++) {
            $reservation = $this->p->guard()->authorize($run, $intent);
            try {
                $out = $caller->call($request, $context);
            } catch (ProviderException $e) {
                // The call was attempted: record it for spend-meter, drop the hold.
                SpendMeter::record($reservation, ['kind' => 'draft', 'provider' => $caller->id(), 'model' => $model, 'status' => 'error_unbilled', 'http_status' => $e->httpStatus(), 'attempt' => $attempt, 'error_code' => $e->errorCode(), 'run_id' => $run->id]);
                $this->p->guard()->settle($run->id);
                $repo->log($run->id, sprintf('%s %s call failed: %s', $mode, $pass, Settings::redact($e->getMessage())));
                if ($e->retryable() && $attempt < $max && $caller instanceof CallableCaller) {
                    continue;
                }
                throw $e;
            }
            $money = $this->tokenMoney($model, $out['tokens_in'], $out['tokens_out']);
            $this->p->runner()->recordUsage($run->id, 'draft', $caller->id() . ($model !== '' ? ':' . $model : ''), $mode . '.' . $pass, 0, $out['tokens_in'], $out['tokens_out'], $money, 200, $attempt);
            SpendMeter::record($reservation, ['kind' => 'draft', 'provider' => $caller->id(), 'model' => $model, 'status' => 'ok', 'http_status' => 200, 'input_tokens' => $out['tokens_in'], 'output_tokens' => $out['tokens_out'], 'attempt' => $attempt, 'run_id' => $run->id]);
            $problems = DraftSchema::validate($out['data'], $schema);
            if ($problems === []) {
                return $out['data'];
            }
            $repo->log($run->id, sprintf('%s %s reply rejected by the schema check: %s', $mode, $pass, implode('; ', array_slice($problems, 0, 3))));
        }
        throw new ProviderException(ProviderException::BAD_RESPONSE, 'The model reply did not match the schema after ' . $max . ' attempts.');
    }

    private function tokenMoney(string $model, int $in, int $out): ?int
    {
        $prices = $this->p->settings()->get('prices');
        $pi = is_array($prices) && is_array($prices['input'] ?? null) ? Money::parse(is_string($prices['input'][$model] ?? null) ? $prices['input'][$model] : null) : null;
        $po = is_array($prices) && is_array($prices['output'] ?? null) ? Money::parse(is_string($prices['output'][$model] ?? null) ? $prices['output'][$model] : null) : null;
        if ($pi === null || $po === null) {
            return null;
        }

        return Money::times($pi, $in, 1_000_000) + Money::times($po, $out, 1_000_000);
    }

    /**
     * @param array<string, mixed>|null $reply
     */
    private function createPost(Run $run, Transcript $t, string $mode, ?array $reply): DraftResult
    {
        $repo = $this->p->repo();
        $audioUrl = '';
        $attachmentId = 0;
        if ($run->source->kind === Source::ATTACHMENT) {
            $attachmentId = $run->source->attachmentId();
            $audioUrl = (string) wp_get_attachment_url($attachmentId);
        } elseif ($run->source->kind === Source::FEED_EPISODE) {
            $audioUrl = (string) $run->source->get('enclosure_url');
        } elseif ($run->source->kind === Source::URL) {
            $audioUrl = (string) $run->source->get('url');
        } elseif ((int) ($run->source->fields['media_attachment_id'] ?? 0) > 0) {
            $attachmentId = (int) $run->source->fields['media_attachment_id'];
            $audioUrl = (string) wp_get_attachment_url($attachmentId);
        } elseif ((string) $run->source->get('audio_url') !== '') {
            $audioUrl = (string) $run->source->get('audio_url');
        }
        $composer = new DraftComposer($t, $this->p->settings()->int('turn_gap_seconds') * 1000);
        $mappings = $repo->meta($run->id, 'mappings', []);
        $out = $composer->compose($mode, $reply, [
            'audio_url' => $audioUrl,
            'attachment_id' => $attachmentId,
            'title' => $run->source->label(),
            'append_transcript' => $this->p->settings()->bool('append_transcript'),
            'flags' => $run->flags,
            'mappings' => is_array($mappings) ? array_values(array_filter($mappings, 'is_array')) : [],
        ]);
        $blocks = $out['blocks'];
        $filtered = apply_filters('audiopost_draft_blocks', $blocks, $run, $mode);
        if (is_array($filtered) && self::validBlocks($filtered)) {
            /** @var list<array{name: string, attrs: array<string, mixed>, html: string}> $filtered */
            $blocks = $filtered;
        } elseif ($filtered !== $blocks) {
            Debug::once('draft_blocks', 'audiopost_draft_blocks returned an invalid block list; default used');
        }
        $postarr = [
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_author' => $run->createdBy,
            'post_title' => $out['title'],
            'post_excerpt' => $out['excerpt'],
            'post_content' => BlockBuilder::serialize($blocks),
        ];
        $filteredPost = apply_filters('audiopost_draft_post', $postarr, $run, $mode);
        if (is_array($filteredPost)) {
            $postarr = $filteredPost;
        }
        // Drafts only (lesson 6): forced back after every filter.
        $postarr['post_status'] = 'draft';
        unset($postarr['post_date'], $postarr['post_date_gmt'], $postarr['ID']);
        $postId = wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($postId) || !is_int($postId) || $postId <= 0) {
            throw new StateException('draft_not_saved', 'The draft post could not be saved.');
        }
        update_post_meta($postId, '_audiopost_run_id', $run->id);
        update_post_meta($postId, '_audiopost_mode', $mode);
        update_post_meta($postId, '_audiopost_review_notes', wp_slash((string) json_encode($out['notes'], JSON_UNESCAPED_UNICODE)));
        $drafts = $repo->meta($run->id, 'drafts', []);
        $drafts = is_array($drafts) ? $drafts : [];
        $drafts[] = ['post_id' => $postId, 'mode' => $mode, 'created' => $this->p->clock()->now()];
        $repo->setMeta($run->id, 'drafts', $drafts);
        $repo->setMeta($run->id, 'review_notes', $out['notes']);
        if ($out['suggestions'] !== []) {
            $repo->setMeta($run->id, 'speaker_suggestions', $out['suggestions']);
        }
        $repo->log($run->id, sprintf('draft post %d created (%s, %d review note(s))', $postId, $mode, count($out['notes'])));
        $fresh = $repo->get($run->id) ?? $run;
        do_action('audiopost_draft_created', $fresh, $postId, $mode, $out['notes']);

        return new DraftResult($postId, $mode, $out['notes']);
    }

    /** @param array<mixed> $blocks */
    private static function validBlocks(array $blocks): bool
    {
        if (!array_is_list($blocks)) {
            return false;
        }
        foreach ($blocks as $b) {
            if (!is_array($b) || !is_string($b['name'] ?? null) || !is_string($b['html'] ?? null) || !is_array($b['attrs'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Estimated tokens for one more draft in a mode, for the screens. */
    public function estimateFor(int $id, string $mode): int
    {
        $t = $this->p->repo()->transcript($id);
        if ($t === null || !in_array($mode, EstimateCalculator::MODEL_MODES, true)) {
            return 0;
        }
        $s = $this->p->settings();
        $script = TokenRatios::scriptOfText($t->text());
        /** @var array<string, float> $ratios */
        $ratios = is_array($s->get('ratio_overrides')) ? $s->get('ratio_overrides') : [];
        $tok = EstimateCalculator::draftTokens([
            'mode' => $mode,
            'language' => $t->language ?: 'auto',
            'script' => $script,
            'ratio_overrides' => $ratios,
            'speech_ms' => $t->durationMs,
            'known_units' => TranscriptText::units($t, $script === TokenRatios::CJK),
            'words_per_minute' => $s->int('words_per_minute'),
            'characters_per_minute' => $s->int('characters_per_minute'),
            'prompt_chars' => Prompts::overheadChars($s->prompt($mode, 'single')),
            'notes_prompt_chars' => Prompts::overheadChars($s->prompt($mode, 'notes')),
            'max_output_tokens' => $this->p->runs()->maxOutput($mode),
            'notes_max_output_tokens' => $s->int('notes_max_output'),
            'single_pass_max_tokens' => $s->int('single_pass_max_tokens'),
            'block_words' => $s->int('block_words'),
        ]);

        return $tok['in'] + $tok['out'];
    }
}
