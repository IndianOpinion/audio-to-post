<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * A read-only snapshot of one run. Build a new one after any change.
 */
final class Run
{
    /**
     * @param array<string, mixed> $options mode, language, adapter
     * @param array<string, mixed> $flags   timestamps, speakers, duplicate_of, ...
     * @param list<int>            $draftIds
     */
    public function __construct(
        public readonly int $id,
        public readonly string $state,
        public readonly string $reason,
        public readonly Source $source,
        public readonly array $options,
        public readonly ?AudioInfo $audio,
        public readonly ?ChunkPlan $plan,
        public readonly ?Estimate $estimate,
        public readonly array $flags,
        public readonly int $createdBy,
        public readonly int $createdAt,
        public readonly int $chunksDone,
        public readonly int $chunksTotal,
        public readonly array $draftIds,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {
    }

    public function mode(): string
    {
        $m = $this->options['mode'] ?? 'none';

        return is_string($m) ? $m : 'none';
    }

    public function adapterId(): string
    {
        $a = $this->options['adapter'] ?? '';

        return is_string($a) ? $a : '';
    }

    public function language(): string
    {
        $l = $this->options['language'] ?? 'auto';

        return is_string($l) ? $l : 'auto';
    }

    public function title(): string
    {
        return $this->source->label();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'reason' => $this->reason,
            'title' => $this->title(),
            'source' => $this->source->toArray(),
            'options' => $this->options,
            'audio' => $this->audio?->toArray(),
            'plan' => $this->plan?->toArray(),
            'estimate' => $this->estimate?->toArray(),
            'flags' => $this->flags,
            'created_by' => $this->createdBy,
            'created_at' => gmdate('c', $this->createdAt),
            'progress' => ['done' => $this->chunksDone, 'total' => $this->chunksTotal],
            'draft_ids' => $this->draftIds,
            'error' => $this->errorCode === null ? null : ['code' => $this->errorCode, 'message' => $this->errorMessage],
        ];
    }
}
