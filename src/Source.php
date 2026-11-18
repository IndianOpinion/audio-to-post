<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * Where a recording or transcript comes from (cases 1 to 5).
 */
final class Source
{
    public const ATTACHMENT = 'attachment';
    public const URL = 'url';
    public const FEED_EPISODE = 'feed_episode';
    public const TRANSCRIPT_FILE = 'transcript_file';
    public const KINDS = [self::ATTACHMENT, self::URL, self::FEED_EPISODE, self::TRANSCRIPT_FILE];

    /**
     * @param array<string, scalar|null> $fields
     */
    public function __construct(public readonly string $kind, public readonly array $fields)
    {
    }

    public function get(string $key): ?string
    {
        $v = $this->fields[$key] ?? null;

        return $v === null ? null : (string) $v;
    }

    public function attachmentId(): int
    {
        return (int) ($this->fields['attachment_id'] ?? 0);
    }

    /** True when the run needs audio and therefore a rights confirmation (case 6). */
    public function isAudio(): bool
    {
        if ($this->kind === self::TRANSCRIPT_FILE) {
            return false;
        }

        return !($this->kind === self::FEED_EPISODE && ($this->fields['use_transcript'] ?? false));
    }

    /** True when no transcription call is needed (case 39). */
    public function isFree(): bool
    {
        return !$this->isAudio();
    }

    public function label(): string
    {
        foreach (['episode_title', 'title', 'file_name', 'url', 'enclosure_url'] as $k) {
            $v = $this->get($k);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind] + $this->fields;
    }

    /**
     * @param array<mixed> $d
     *
     * @throws SourceException
     */
    public static function fromArray(array $d): self
    {
        $kind = $d['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new SourceException('invalid_source', 'Unknown source kind.');
        }
        $allowed = [
            'attachment_id', 'url', 'feed_url', 'guid', 'episode_title', 'published', 'duration', 'enclosure_url',
            'enclosure_size', 'transcript_url', 'transcript_type', 'use_transcript', 'file_name', 'transcript_attachment_id',
            'audio_url', 'media_attachment_id', 'title',
        ];
        $fields = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $d)) {
                continue;
            }
            $v = $d[$k];
            if (is_bool($v) || is_int($v) || $v === null) {
                $fields[$k] = $v;
            } elseif (is_string($v)) {
                $fields[$k] = substr($v, 0, 2048);
            }
        }
        $s = new self($kind, $fields);
        if ($kind === self::ATTACHMENT && $s->attachmentId() <= 0) {
            throw new SourceException('invalid_source', 'An attachment source needs an attachment id.');
        }
        if ($kind === self::URL && ($s->get('url') ?? '') === '') {
            throw new SourceException('invalid_source', 'A URL source needs a URL.');
        }
        if ($kind === self::FEED_EPISODE && ($s->get('enclosure_url') ?? '') === '' && !($fields['use_transcript'] ?? false)) {
            throw new SourceException('invalid_source', 'A feed episode needs an enclosure URL.');
        }
        if ($kind === self::FEED_EPISODE && ($fields['use_transcript'] ?? false) && ($s->get('transcript_url') ?? '') === '') {
            throw new SourceException('invalid_source', 'The published transcript route needs a transcript link.');
        }
        if ($kind === self::TRANSCRIPT_FILE && (int) ($fields['transcript_attachment_id'] ?? 0) <= 0 && ($s->get('url') ?? '') === '') {
            throw new SourceException('invalid_source', 'A transcript file source needs an uploaded file.');
        }

        return $s;
    }
}
