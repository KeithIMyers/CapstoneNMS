<?php

namespace App\Services\Ai;

use Exception;

/**
 * Thrown by drivers when a completion call fails. Carries the HTTP
 * status (when known) so the caller can differentiate between transient
 * rate limits and configuration problems.
 */
class AiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerKind = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }
}
