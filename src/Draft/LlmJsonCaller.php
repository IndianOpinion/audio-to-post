<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Draft;

use IndianOpinion\AudioToPost\ProviderException;
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\AuthenticationException;
use IndianOpinion\LlmJson\Exception\ContentFilterException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\LlmJsonException;
use IndianOpinion\LlmJson\Exception\RefusalException;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Transport\Transport;
use IndianOpinion\LlmJson\Transport\WordPressTransport;

/**
 * The default caller (case 84): indianopinion/llm-json-php, which
 * validates the reply against the schema and retries malformed or
 * truncated JSON. Only built when the library is installed.
 */
final class LlmJsonCaller implements LlmCaller
{
    /**
     * @param array{provider: string, base_url: string, model: string, key: string, timeout: int} $config
     */
    public function __construct(private readonly array $config, private readonly ?Transport $transport = null)
    {
    }

    /** @internal Tests set this to simulate a site without the library. */
    public static ?bool $assumeAvailable = null;

    public static function available(): bool
    {
        return self::$assumeAvailable ?? class_exists(Client::class);
    }

    public function id(): string
    {
        return 'llm-json-php';
    }

    /** @return list<string> */
    public function configurationProblems(): array
    {
        $p = [];
        if ($this->config['model'] === '') {
            $p[] = 'Set the draft model.';
        }
        if ($this->config['key'] === '' && $this->config['provider'] !== 'generic') {
            $p[] = 'Set the draft model key (constant AUDIOPOST_MODEL_KEY or the settings screen).';
        }
        if ($this->config['provider'] === 'generic' && $this->config['base_url'] === '') {
            $p[] = 'Set the base URL of the chat completions endpoint.';
        }

        return $p;
    }

    public function call(array $request, array $context): array
    {
        $problems = $this->configurationProblems();
        if ($problems !== []) {
            throw new ProviderException(ProviderException::AUTH, implode(' ', $problems));
        }
        try {
            $provider = match ($this->config['provider']) {
                'openai' => Provider::openAi($this->config['key']),
                'generic' => Provider::generic('custom', $this->config['base_url'], $this->config['key'], $this->config['key'] === ''),
                default => Provider::openRouter($this->config['key'], function_exists('home_url') ? home_url('/') : null, 'audio-to-post'),
            };
            $client = Client::create(Chain::of(Route::to($provider, $this->config['model'])), $this->transport ?? new WordPressTransport());
            $req = Request::for('audio-to-post', Schema::fromArray($request['schema_name'], $request['schema']))
                ->withSystem($request['prompt'])
                ->withInput($request['input'])
                ->withTokenCap(max(16, $request['max_output_tokens']))
                ->withDeadline((float) max(30, $this->config['timeout']));
            $result = $client->complete($req);
        } catch (AuthenticationException $e) {
            throw new ProviderException(ProviderException::AUTH, $this->redact($e->getMessage()), false, 401, null, null, $e);
        } catch (CreditExhaustedException $e) {
            throw new ProviderException(ProviderException::ACCOUNT, $this->redact($e->getMessage()), false, 402, null, null, $e);
        } catch (RefusalException | ContentFilterException $e) {
            throw new ProviderException(ProviderException::REFUSED, $this->redact($e->getMessage()), false, null, null, null, $e);
        } catch (LlmJsonException $e) {
            throw new ProviderException(ProviderException::TRANSIENT, $this->redact($e->getMessage()), false, null, null, null, $e);
        }
        $usage = $result->totalUsage();
        /** @var array<string, mixed> $data */
        $data = $result->data();

        return [
            'data' => $data,
            'tokens_in' => (int) ($usage->promptTokens ?? 0),
            'tokens_out' => (int) ($usage->completionTokens ?? 0),
            'model' => $result->model(),
        ];
    }

    private function redact(string $m): string
    {
        return $this->config['key'] === '' ? $m : str_replace($this->config['key'], '[redacted]', $m);
    }
}
