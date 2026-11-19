<?php

/**
 * Plugin Name: Audio to post: custom adapter example
 * Description: Registers a transcription adapter of your own.
 */

declare(strict_types=1);

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\Adapter\ChunkRequest;
use IndianOpinion\AudioToPost\Adapter\ChunkResult;
use IndianOpinion\AudioToPost\Adapter\TranscriptionAdapter;
use IndianOpinion\AudioToPost\ProviderException;
use IndianOpinion\AudioToPost\Segment;

add_action('audiopost_register_adapters', static function (): void {
    audiopost_register_adapter(new class () implements TranscriptionAdapter {
        public function id(): string
        {
            return 'my-service';
        }

        public function label(): string
        {
            return 'My transcription service';
        }

        public function capabilities(): AdapterCapabilities
        {
            // Check these limits against your provider's documentation.
            return new AdapterCapabilities(20_000_000, 900, ['mp3', 'wav'], diarisation: true, segmentTimestamps: true, languageHint: true);
        }

        public function isConfigured(): bool
        {
            return defined('AUDIOPOST_ADAPTER_KEY_MY_SERVICE');
        }

        public function configurationProblems(): array
        {
            return $this->isConfigured() ? [] : ['Define AUDIOPOST_ADAPTER_KEY_MY_SERVICE in wp-config.php.'];
        }

        public function transcribe(ChunkRequest $request): ChunkResult
        {
            // Send $request->path to your service through the WordPress HTTP API.
            // Throw ProviderException with `retryable` set on any error.
            $segments = [new Segment('', 0, $request->lengthMs, 'A', 'text from your service')];

            return new ChunkResult($segments, 'text from your service', $request->language, (int) ceil($request->lengthMs / 1000));
        }

        public function submit(ChunkRequest $request): string
        {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'Synchronous only.');
        }

        public function poll(string $jobId): ?ChunkResult
        {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'Synchronous only.');
        }
    });
});
