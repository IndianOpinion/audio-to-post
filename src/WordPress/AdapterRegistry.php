<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\Adapter\FakeAdapter;
use IndianOpinion\AudioToPost\Adapter\HttpMultipartAdapter;
use IndianOpinion\AudioToPost\Adapter\TranscriptFileAdapter;
use IndianOpinion\AudioToPost\Adapter\TranscriptionAdapter;
use IndianOpinion\AudioToPost\Http\HttpClient;

/**
 * Holds the transcription adapters. Bundled: `http-multipart`,
 * `transcript-file`, and `fake` when WP_DEBUG is on. Others register on
 * `audiopost_register_adapters`.
 */
final class AdapterRegistry
{
    public const LIMITS_OPTION = 'audiopost_adapter_limits';

    /** @var array<string, TranscriptionAdapter> */
    private array $adapters = [];
    private bool $loaded = false;

    public function __construct(private readonly Settings $settings, private readonly HttpClient $http)
    {
    }

    public function register(TranscriptionAdapter $adapter): void
    {
        $id = $adapter->id();
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id) !== 1) {
            Debug::once('bad-adapter-' . md5($id), 'adapter with an invalid id ignored');

            return;
        }
        $this->adapters[$id] = $adapter;
    }

    public function get(string $id): ?TranscriptionAdapter
    {
        $this->load();

        return $this->adapters[$id] ?? null;
    }

    /** @return array<string, TranscriptionAdapter> */
    public function all(): array
    {
        $this->load();

        return $this->adapters;
    }

    /**
     * Capabilities after operator limits and limits learnt from 413
     * answers (case 38). Never above what the adapter declares.
     */
    public function effectiveCapabilities(TranscriptionAdapter $adapter): AdapterCapabilities
    {
        $caps = $adapter->capabilities();
        $learnt = Options::fresh(self::LIMITS_OPTION, []);
        $l = is_array($learnt) && is_array($learnt[$adapter->id()] ?? null) ? $learnt[$adapter->id()] : [];

        return $caps->clamped(
            isset($l['max_bytes']) ? (int) $l['max_bytes'] : null,
            isset($l['max_seconds']) ? (int) $l['max_seconds'] : null,
        );
    }

    /** Lower the stored effective byte limit by 20 per cent (case 38). */
    public function lowerLimit(TranscriptionAdapter $adapter, int $chunkBytes): int
    {
        $caps = $this->effectiveCapabilities($adapter);
        $new = (int) floor(min($caps->maxBytes, max(1, $chunkBytes)) * 0.8);
        $learnt = Options::fresh(self::LIMITS_OPTION, []);
        $learnt = is_array($learnt) ? $learnt : [];
        $learnt[$adapter->id()] = ['max_bytes' => $new] + (is_array($learnt[$adapter->id()] ?? null) ? $learnt[$adapter->id()] : []);
        $learnt[$adapter->id()]['max_bytes'] = $new;
        Options::set(self::LIMITS_OPTION, $learnt);

        return $new;
    }

    public function reset(): void
    {
        $this->adapters = [];
        $this->loaded = false;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $mp = $this->settings->get('multipart');
        $config = (is_array($mp) ? $mp : []) + ['key' => Settings::adapterKey(HttpMultipartAdapter::ID)];
        $config['key'] = Settings::adapterKey(HttpMultipartAdapter::ID);
        $config['timeout'] = $this->settings->int('http_timeout');
        $this->register(new HttpMultipartAdapter($this->http, $config));
        $this->register(new TranscriptFileAdapter());
        if (defined('WP_DEBUG') && constant('WP_DEBUG')) {
            $this->register(new FakeAdapter());
        }
        do_action('audiopost_register_adapters');
    }
}
