<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * Base class for every exception the plugin throws.
 *
 * Each exception carries a short machine code (for example `unsafe_url`)
 * that is stored on the run and returned by REST and WP-CLI.
 */
abstract class AudioToPostException extends \RuntimeException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
