<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Adapter;

use IndianOpinion\AudioToPost\ProviderException;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\Transcript\CueParser;
use IndianOpinion\AudioToPost\TranscriptFormatException;

/**
 * Imports a transcript the editor already has (id `transcript-file`,
 * case 79): WebVTT, SRT, the plugin's JSON, or the open Podcasting 2.0
 * JSON shape. Free: no network, no cost.
 */
final class TranscriptFileAdapter implements TranscriptionAdapter
{
    public const ID = 'transcript-file';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Transcript file (WebVTT, SRT or JSON)';
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(CueParser::MAX_BYTES, PHP_INT_MAX, ['vtt', 'srt', 'json'], diarisation: true, segmentTimestamps: true, longAudio: true, free: true);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function configurationProblems(): array
    {
        return [];
    }

    /**
     * @return array{transcript: Transcript, skipped: int, format: string}
     *
     * @throws TranscriptFormatException
     */
    public function import(string $path, ?string $format = null): array
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) {
            throw new TranscriptFormatException('transcript_unreadable', 'The transcript file is missing.');
        }
        if ($size > CueParser::MAX_BYTES) {
            throw new TranscriptFormatException('transcript_too_large', 'Transcript files are limited to 10 MB.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new TranscriptFormatException('transcript_unreadable', 'The transcript file cannot be read.');
        }

        return CueParser::parse($content, $format);
    }

    public function transcribe(ChunkRequest $request): ChunkResult
    {
        try {
            $t = $this->import($request->path)['transcript'];
        } catch (TranscriptFormatException $e) {
            throw new ProviderException(ProviderException::REJECTED_AUDIO, $e->getMessage());
        }
        $segments = [];
        foreach ($t->segments as $s) {
            $sp = $t->speaker($s->speaker);
            $segments[] = $s->with(['speaker' => $t->speakersFlag === Transcript::SPEAKERS_NONE ? '' : ($sp?->display() ?? $s->speaker)]);
        }

        return new ChunkResult($segments, $t->text(), $t->language !== '' ? $t->language : null, 0);
    }

    public function submit(ChunkRequest $request): string
    {
        throw new ProviderException(ProviderException::BAD_RESPONSE, 'This adapter is synchronous.');
    }

    public function poll(string $jobId): ?ChunkResult
    {
        throw new ProviderException(ProviderException::BAD_RESPONSE, 'This adapter is synchronous.');
    }
}
