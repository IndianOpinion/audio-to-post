<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * A stitched transcript in the plugin's schema (section 6.3 of the PRD).
 */
final class Transcript
{
    public const SCHEMA = 'audio-to-post/transcript@1';
    public const TIMESTAMPS_FINE = 'fine';
    public const TIMESTAMPS_COARSE = 'coarse';
    public const SPEAKERS_DIARISED = 'diarised';
    public const SPEAKERS_NONE = 'none';
    public const SPEAKERS_EDITED = 'edited';

    /**
     * @param list<Speaker>        $speakers
     * @param list<Segment>        $segments
     * @param array<string, mixed> $extra unknown top-level keys kept from an import
     */
    public function __construct(
        public readonly int $durationMs,
        public readonly string $language,
        public readonly string $timestamps,
        public readonly string $speakersFlag,
        public readonly array $speakers,
        public readonly array $segments,
        public readonly array $extra = [],
    ) {
    }

    /** @param list<Segment> $segments */
    public function withSegments(array $segments): self
    {
        return new self($this->durationMs, $this->language, $this->timestamps, $this->speakersFlag, $this->speakers, $segments, $this->extra);
    }

    /** @param list<Speaker> $speakers */
    public function withSpeakers(array $speakers, ?string $flag = null): self
    {
        return new self($this->durationMs, $this->language, $this->timestamps, $flag ?? $this->speakersFlag, $speakers, $this->segments, $this->extra);
    }

    public function speaker(string $id): ?Speaker
    {
        foreach ($this->speakers as $s) {
            if ($s->id === $id) {
                return $s;
            }
        }

        return null;
    }

    public function speakerDisplay(string $id): string
    {
        $s = $this->speaker($id);

        return $s === null ? $id : $s->display();
    }

    public function segment(string $id): ?Segment
    {
        foreach ($this->segments as $s) {
            if ($s->id === $id) {
                return $s;
            }
        }

        return null;
    }

    public function segmentIndex(string $id): ?int
    {
        foreach ($this->segments as $i => $s) {
            if ($s->id === $id) {
                return $i;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        foreach ($this->segments as $s) {
            if (trim($s->text) !== '') {
                return false;
            }
        }

        return true;
    }

    public function text(): string
    {
        return implode("\n", array_map(static fn (Segment $s): string => $s->text, $this->segments));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['schema' => self::SCHEMA];
        $out += [
            'duration_ms' => $this->durationMs,
            'language' => $this->language,
            'flags' => ['timestamps' => $this->timestamps, 'speakers' => $this->speakersFlag],
            'speakers' => array_map(static fn (Speaker $s): array => $s->toArray(), $this->speakers),
            'segments' => array_map(static fn (Segment $s): array => $s->toArray(), $this->segments),
        ];

        return $out + $this->extra;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws TranscriptFormatException
     */
    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA || !is_array($data['segments'] ?? null)) {
            throw new TranscriptFormatException('transcript_unreadable', 'Not an audio-to-post transcript (schema ' . self::SCHEMA . ').');
        }
        $known = ['schema', 'duration_ms', 'language', 'flags', 'speakers', 'segments'];
        $extra = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && !in_array($k, $known, true)) {
                $extra[$k] = $v;
            }
        }
        $speakers = [];
        foreach (is_array($data['speakers'] ?? null) ? $data['speakers'] : [] as $s) {
            if (is_array($s)) {
                $speakers[] = Speaker::fromArray($s);
            }
        }
        $segments = [];
        foreach ($data['segments'] as $s) {
            if (is_array($s)) {
                $segments[] = Segment::fromArray($s);
            }
        }
        $flags = is_array($data['flags'] ?? null) ? $data['flags'] : [];
        $ts = ($flags['timestamps'] ?? '') === self::TIMESTAMPS_COARSE ? self::TIMESTAMPS_COARSE : self::TIMESTAMPS_FINE;
        $sp = $flags['speakers'] ?? self::SPEAKERS_DIARISED;
        if (!in_array($sp, [self::SPEAKERS_DIARISED, self::SPEAKERS_NONE, self::SPEAKERS_EDITED], true)) {
            $sp = self::SPEAKERS_DIARISED;
        }
        $duration = $data['duration_ms'] ?? 0;

        return new self(
            is_int($duration) ? $duration : (is_numeric($duration) ? (int) $duration : 0),
            is_string($data['language'] ?? null) ? $data['language'] : '',
            $ts,
            $sp,
            $speakers,
            $segments,
            $extra,
        );
    }

    /**
     * @throws TranscriptFormatException
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new TranscriptFormatException('transcript_unreadable', 'Transcript JSON could not be decoded.');
        }

        return self::fromArray($data);
    }
}
