<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * A transcription provider or model call failed.
 *
 * `retryable` says whether the same request may succeed later (429, 5xx,
 * timeouts). Authentication, account, refusal and size errors are not
 * retryable; the runner treats each of those by its code.
 */
class ProviderException extends AudioToPostException
{
    public const AUTH = 'provider_auth';
    public const ACCOUNT = 'provider_account';
    public const REFUSED = 'provider_refused';
    public const TOO_LARGE = 'provider_too_large';
    public const REJECTED_AUDIO = 'provider_rejected_audio';
    public const TIMEOUT = 'provider_timeout';
    public const TRANSIENT = 'provider_transient';
    public const BAD_RESPONSE = 'provider_bad_response';

    public function __construct(
        string $errorCode,
        string $message,
        private readonly bool $retryable = false,
        private readonly ?int $httpStatus = null,
        private readonly ?int $retryAfterSeconds = null,
        private readonly ?int $billedSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $message, $previous);
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /** Seconds the provider says it billed for a failed call, when it says so. */
    public function billedSeconds(): ?int
    {
        return $this->billedSeconds;
    }

    /**
     * Map an HTTP status to the right exception.
     */
    public static function fromStatus(int $status, string $message, ?int $retryAfter = null): self
    {
        if ($status === 401 || $status === 403) {
            return new self(self::AUTH, $message, false, $status);
        }
        if ($status === 402) {
            return new self(self::ACCOUNT, $message, false, $status);
        }
        if ($status === 413) {
            return new self(self::TOO_LARGE, $message, false, $status);
        }
        if (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
            return new self(self::TRANSIENT, $message, true, $status, $retryAfter);
        }
        if ($status === 400 || $status === 415 || $status === 422) {
            return new self(self::REJECTED_AUDIO, $message, false, $status);
        }

        return new self(self::BAD_RESPONSE, $message, false, $status);
    }
}
