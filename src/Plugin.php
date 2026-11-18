<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

use IndianOpinion\AudioToPost\Audio\Prober;
use IndianOpinion\AudioToPost\Http\Downloader;
use IndianOpinion\AudioToPost\Http\HttpClient;
use IndianOpinion\AudioToPost\Http\UrlGuard;
use IndianOpinion\AudioToPost\Http\WordPressHttpClient;
use IndianOpinion\AudioToPost\WordPress\AdapterRegistry;
use IndianOpinion\AudioToPost\WordPress\Clock;
use IndianOpinion\AudioToPost\WordPress\DraftService;
use IndianOpinion\AudioToPost\WordPress\Lock;
use IndianOpinion\AudioToPost\WordPress\PrivateStorage;
use IndianOpinion\AudioToPost\WordPress\RunRepository;
use IndianOpinion\AudioToPost\WordPress\Runner;
use IndianOpinion\AudioToPost\WordPress\RunService;
use IndianOpinion\AudioToPost\WordPress\Settings;
use IndianOpinion\AudioToPost\WordPress\SpendGuard;
use IndianOpinion\AudioToPost\WordPress\UsageLedger;

/**
 * The service container. Every service is built on first use and can be
 * replaced (tests swap the clock, the HTTP client and the DNS resolver).
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];
    /** @var (\Closure(string): list<string>)|null */
    private ?\Closure $resolver = null;
    /** @var \IndianOpinion\LlmJson\Transport\Transport|null */
    private ?object $llmTransport = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Drop every service (tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function set(string $name, object $service): void
    {
        $this->services[$name] = $service;
    }

    /** @param callable(string): list<string> $resolver */
    public function setResolver(callable $resolver): void
    {
        $this->resolver = \Closure::fromCallable($resolver);
    }

    public function setLlmTransport(?object $transport): void
    {
        $this->llmTransport = $transport instanceof \IndianOpinion\LlmJson\Transport\Transport ? $transport : null;
    }

    public function llmTransport(): ?object
    {
        return $this->llmTransport;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param callable(): T   $make
     *
     * @return T
     */
    private function service(string $name, string $class, callable $make): object
    {
        if (!isset($this->services[$name])) {
            $this->services[$name] = $make();
        }
        $s = $this->services[$name];
        if (!$s instanceof $class) {
            throw new \LogicException('Service ' . $name . ' has the wrong type.');
        }

        return $s;
    }

    public function clock(): Clock
    {
        return $this->service('clock', Clock::class, static fn (): Clock => new Clock());
    }

    public function settings(): Settings
    {
        return $this->service('settings', Settings::class, static fn (): Settings => new Settings());
    }

    public function http(): HttpClient
    {
        return $this->service('http', HttpClient::class, static fn (): HttpClient => new WordPressHttpClient());
    }

    public function urlGuard(): UrlGuard
    {
        $hosts = apply_filters('audiopost_allowed_hosts', []);
        $list = [];
        if (is_array($hosts)) {
            foreach ($hosts as $h) {
                if (is_string($h) && $h !== '') {
                    $list[] = $h;
                }
            }
        }

        return new UrlGuard($this->resolver, $list);
    }

    public function downloader(): Downloader
    {
        return new Downloader($this->http(), $this->urlGuard(), $this->settings()->int('http_timeout'));
    }

    public function prober(): Prober
    {
        return $this->service('prober', Prober::class, static function (): Prober {
            return new Prober(static function (string $path): ?array {
                if (!function_exists('wp_read_audio_metadata') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/media.php')) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                }
                if (!function_exists('wp_read_audio_metadata')) {
                    return null;
                }
                $m = wp_read_audio_metadata($path);

                return is_array($m) ? $m : null;
            });
        });
    }

    public function adapters(): AdapterRegistry
    {
        return $this->service('adapters', AdapterRegistry::class, fn (): AdapterRegistry => new AdapterRegistry($this->settings(), $this->http()));
    }

    public function files(): PrivateStorage
    {
        return $this->service('files', PrivateStorage::class, fn (): PrivateStorage => new PrivateStorage($this->clock()));
    }

    public function repo(): RunRepository
    {
        return $this->service('repo', RunRepository::class, fn (): RunRepository => new RunRepository($this->clock(), $this->files(), $this->settings()));
    }

    public function ledger(): UsageLedger
    {
        return $this->service('ledger', UsageLedger::class, fn (): UsageLedger => new UsageLedger($this->clock()));
    }

    public function guard(): SpendGuard
    {
        return $this->service('guard', SpendGuard::class, fn (): SpendGuard => new SpendGuard($this->settings(), $this->ledger(), $this->clock(), $this->repo()));
    }

    public function locks(): Lock
    {
        return $this->service('locks', Lock::class, fn (): Lock => new Lock($this->clock()));
    }

    public function runs(): RunService
    {
        return $this->service('runs', RunService::class, fn (): RunService => new RunService($this));
    }

    public function drafts(): DraftService
    {
        return $this->service('drafts', DraftService::class, fn (): DraftService => new DraftService($this));
    }

    public function runner(): Runner
    {
        return $this->service('runner', Runner::class, fn (): Runner => new Runner($this));
    }
}
