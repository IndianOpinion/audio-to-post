<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Adapter\HttpMultipartAdapter;
use IndianOpinion\AudioToPost\Draft\Prompts;

/**
 * The plugin's settings (section 7). One autoloaded option without
 * secrets, one non-autoloaded option for keys typed on screen. Keys from
 * constants always win.
 */
final class Settings
{
    public const OPTION = 'audiopost_settings';
    public const SECRETS = 'audiopost_secrets';
    public const MODES = ['article', 'show_notes', 'transcript', 'none'];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $multipart = HttpMultipartAdapter::defaults();
        unset($multipart['key']);

        return [
            'adapter' => '',
            'multipart' => $multipart,
            'model_provider' => 'openrouter',
            'model_base_url' => '',
            'model' => '',
            'prices' => ['minute' => [], 'input' => [], 'output' => []],
            'currency' => '',
            'default_mode' => 'article',
            'append_transcript' => false,
            'language' => 'auto',
            'chunk_seconds' => 600,
            'overlap_seconds' => 10,
            'max_bytes' => 500 * 1000 * 1000,
            'max_seconds' => 4 * 3600,
            'words_per_minute' => 150,
            'characters_per_minute' => 250,
            'ratio_overrides' => [],
            'max_output_article' => 2000,
            'max_output_show_notes' => 1500,
            'notes_max_output' => 600,
            'single_pass_max_tokens' => 60000,
            'block_words' => 1500,
            'cap_minutes' => '120',
            'cap_tokens' => '200000',
            'cap_money' => '',
            'max_attempts' => 4,
            'http_timeout' => 300,
            'async_timeout_hours' => 6,
            'max_parallel_runs' => 1,
            'estimate_ttl_hours' => 24,
            'keep_recording_hours' => 0,
            'keep_failed_hours' => 168,
            'remove_recordings_on_uninstall' => false,
            'retention_days' => 90,
            'turn_gap_seconds' => 4,
            'prompts' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $stored = is_array($stored) ? $stored : [];
            $merged = array_replace(self::defaults(), $stored);
            $merged['multipart'] = array_replace(self::defaults()['multipart'], is_array($stored['multipart'] ?? null) ? $stored['multipart'] : []);
            $this->cache = $merged;
        }

        return $this->cache;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function int(string $key): int
    {
        $v = $this->get($key);

        return is_numeric($v) ? (int) $v : (int) (self::defaults()[$key] ?? 0);
    }

    public function string(string $key): string
    {
        $v = $this->get($key);

        return is_scalar($v) ? (string) $v : '';
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * Save after sanitising. Unknown keys are dropped.
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $clean = self::sanitize(array_replace($this->all(), $values));
        update_option(self::OPTION, $clean, true);
        $this->cache = null;
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public static function sanitize(array $in): array
    {
        $d = self::defaults();
        $out = [];
        $int = static function (string $k, int $min, int $max) use ($in, $d): int {
            $v = $in[$k] ?? $d[$k];

            return max($min, min($max, is_numeric($v) ? (int) $v : (int) $d[$k]));
        };
        $out['adapter'] = preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', (string) ($in['adapter'] ?? '')) === 1 ? (string) $in['adapter'] : '';
        $mp = is_array($in['multipart'] ?? null) ? $in['multipart'] : [];
        $mpd = $d['multipart'];
        $outMp = [];
        foreach ($mpd as $k => $def) {
            $v = $mp[$k] ?? $def;
            if (is_bool($def)) {
                $outMp[$k] = (bool) $v;
            } elseif (is_int($def)) {
                $outMp[$k] = max(0, is_numeric($v) ? (int) $v : $def);
            } elseif (is_array($def)) {
                $outMp[$k] = self::extraFields($v);
            } else {
                $outMp[$k] = is_scalar($v) ? trim(self::text((string) $v)) : $def;
            }
        }
        if (!in_array($outMp['billing_rounding'], ['none', 'second', 'minute'], true)) {
            $outMp['billing_rounding'] = 'none';
        }
        $out['multipart'] = $outMp;
        $out['model_provider'] = in_array($in['model_provider'] ?? '', ['openrouter', 'openai', 'generic'], true) ? (string) $in['model_provider'] : 'openrouter';
        $out['model_base_url'] = self::text((string) ($in['model_base_url'] ?? ''));
        $out['model'] = self::text((string) ($in['model'] ?? ''));
        $prices = is_array($in['prices'] ?? null) ? $in['prices'] : [];
        $out['prices'] = [];
        foreach (['minute', 'input', 'output'] as $kind) {
            $out['prices'][$kind] = [];
            foreach (is_array($prices[$kind] ?? null) ? $prices[$kind] : [] as $id => $val) {
                $val = trim((string) (is_scalar($val) ? $val : ''));
                if ($val !== '' && preg_match('/^\d{1,12}(\.\d{1,6})?$/', $val) === 1) {
                    $out['prices'][$kind][self::text((string) $id)] = $val;
                }
            }
        }
        $out['currency'] = mb_substr(self::text((string) ($in['currency'] ?? '')), 0, 12);
        $out['default_mode'] = in_array($in['default_mode'] ?? '', self::MODES, true) ? (string) $in['default_mode'] : 'article';
        $out['append_transcript'] = (bool) ($in['append_transcript'] ?? false);
        $lang = trim((string) ($in['language'] ?? 'auto'));
        $out['language'] = ($lang === 'auto' || preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang) === 1) ? $lang : 'auto';
        $out['chunk_seconds'] = $int('chunk_seconds', 60, 86400);
        $out['overlap_seconds'] = $int('overlap_seconds', 0, 30);
        $out['max_bytes'] = $int('max_bytes', 1, PHP_INT_MAX);
        $out['max_seconds'] = $int('max_seconds', 1, 7 * 86400);
        $out['words_per_minute'] = $int('words_per_minute', 1, 1000);
        $out['characters_per_minute'] = $int('characters_per_minute', 1, 3000);
        $ratios = [];
        foreach (is_array($in['ratio_overrides'] ?? null) ? $in['ratio_overrides'] : [] as $k => $v) {
            if (is_numeric($v) && (float) $v > 0 && preg_match('/^[a-z]{2,8}(-[a-z0-9]{2,8})*$/', strtolower((string) $k)) === 1) {
                $ratios[strtolower((string) $k)] = (float) $v;
            }
        }
        $out['ratio_overrides'] = $ratios;
        $out['max_output_article'] = $int('max_output_article', 16, 100000);
        $out['max_output_show_notes'] = $int('max_output_show_notes', 16, 100000);
        $out['notes_max_output'] = $int('notes_max_output', 16, 100000);
        $out['single_pass_max_tokens'] = $int('single_pass_max_tokens', 1000, 10000000);
        $out['block_words'] = $int('block_words', 100, 100000);
        foreach (['cap_minutes', 'cap_tokens'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            $out[$k] = $v === '' ? '' : (ctype_digit($v) ? $v : (string) $d[$k]);
        }
        $money = trim((string) ($in['cap_money'] ?? ''));
        $out['cap_money'] = $money === '' || preg_match('/^\d{1,12}(\.\d{1,6})?$/', $money) === 1 ? $money : '';
        $out['max_attempts'] = $int('max_attempts', 1, 10);
        $out['http_timeout'] = $int('http_timeout', 10, 3600);
        $out['async_timeout_hours'] = $int('async_timeout_hours', 1, 72);
        $out['max_parallel_runs'] = $int('max_parallel_runs', 1, 10);
        $out['estimate_ttl_hours'] = $int('estimate_ttl_hours', 1, 720);
        $out['keep_recording_hours'] = $int('keep_recording_hours', 0, 87600);
        $out['keep_failed_hours'] = $int('keep_failed_hours', 0, 87600);
        $out['remove_recordings_on_uninstall'] = (bool) ($in['remove_recordings_on_uninstall'] ?? false);
        $out['retention_days'] = $int('retention_days', 0, 36500);
        $out['turn_gap_seconds'] = $int('turn_gap_seconds', 1, 600);
        $prompts = [];
        $known = array_keys(Prompts::defaults());
        foreach (is_array($in['prompts'] ?? null) ? $in['prompts'] : [] as $k => $v) {
            if (in_array($k, $known, true) && is_string($v) && trim($v) !== '') {
                $prompts[$k] = str_replace("\r\n", "\n", $v);
            }
        }
        $out['prompts'] = $prompts;

        return $out;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function extraFields(mixed $v): array
    {
        if (is_string($v)) {
            $lines = preg_split('/\R/', $v) ?: [];
            $v = [];
            foreach ($lines as $line) {
                $pos = strpos($line, '=');
                if ($pos !== false) {
                    $v[] = [trim(substr($line, 0, $pos)), trim(substr($line, $pos + 1))];
                }
            }
        }
        $out = [];
        foreach (is_array($v) ? $v : [] as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1]) && is_string($pair[0]) && is_string($pair[1]) && preg_match('/^[A-Za-z0-9_\[\].-]{1,64}$/', $pair[0]) === 1) {
                $out[] = [$pair[0], self::text($pair[1])];
            }
        }

        return $out;
    }

    private static function text(string $v): string
    {
        return function_exists('sanitize_text_field') ? sanitize_text_field($v) : trim(strip_tags($v));
    }

    public function prompt(string $mode, string $pass): string
    {
        $key = $mode . '.' . $pass;
        $p = $this->get('prompts');
        if (is_array($p) && is_string($p[$key] ?? null) && $p[$key] !== '') {
            return $p[$key];
        }

        return Prompts::defaults()[$key] ?? '';
    }

    /**
     * An adapter key: constant AUDIOPOST_ADAPTER_KEY_<ID> first, then the
     * secrets option.
     */
    public static function adapterKey(string $adapterId): string
    {
        $const = 'AUDIOPOST_ADAPTER_KEY_' . strtoupper(str_replace('-', '_', $adapterId));
        if (defined($const) && is_string(constant($const)) && constant($const) !== '') {
            return (string) constant($const);
        }
        $s = get_option(self::SECRETS, []);

        return is_array($s) && is_string($s['adapter'][$adapterId] ?? null) ? $s['adapter'][$adapterId] : '';
    }

    public static function modelKey(): string
    {
        if (defined('AUDIOPOST_MODEL_KEY') && is_string(constant('AUDIOPOST_MODEL_KEY')) && constant('AUDIOPOST_MODEL_KEY') !== '') {
            return (string) constant('AUDIOPOST_MODEL_KEY');
        }
        $s = get_option(self::SECRETS, []);

        return is_array($s) && is_string($s['model'] ?? null) ? $s['model'] : '';
    }

    public static function saveSecret(string $kind, string $value, ?string $adapterId = null): void
    {
        $s = get_option(self::SECRETS, []);
        $s = is_array($s) ? $s : [];
        if ($kind === 'model') {
            $s['model'] = $value;
        } elseif ($adapterId !== null) {
            $s['adapter'] = is_array($s['adapter'] ?? null) ? $s['adapter'] : [];
            $s['adapter'][$adapterId] = $value;
        }
        if (get_option(self::SECRETS, null) === null) {
            add_option(self::SECRETS, $s, '', false);
        } else {
            update_option(self::SECRETS, $s, false);
        }
    }

    /** Last four characters, for the settings screen. */
    public static function mask(string $key): string
    {
        return $key === '' ? '' : str_repeat('*', 8) . substr($key, -4);
    }

    /**
     * Every secret currently configured, for redaction.
     *
     * @return list<string>
     */
    public static function allSecrets(): array
    {
        $out = [];
        $s = get_option(self::SECRETS, []);
        if (is_array($s)) {
            if (is_string($s['model'] ?? null) && $s['model'] !== '') {
                $out[] = $s['model'];
            }
            foreach (is_array($s['adapter'] ?? null) ? $s['adapter'] : [] as $v) {
                if (is_string($v) && $v !== '') {
                    $out[] = $v;
                }
            }
        }
        foreach (get_defined_constants(true)['user'] ?? [] as $name => $value) {
            if (is_string($value) && $value !== '' && (str_starts_with((string) $name, 'AUDIOPOST_ADAPTER_KEY_') || $name === 'AUDIOPOST_MODEL_KEY')) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** Strip every known key from a message before it is stored or shown. */
    public static function redact(string $message): string
    {
        foreach (self::allSecrets() as $secret) {
            if (strlen($secret) >= 4) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return $message;
    }
}
