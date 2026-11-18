<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Http;

/**
 * A connection-level failure: DNS, TLS, reset or timeout.
 */
final class TransportError extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timeout = false)
    {
        parent::__construct($message);
    }
}
