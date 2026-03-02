<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Exceptions;

use RuntimeException;

abstract class LLMException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $provider = '',
        public readonly ?int $httpStatusCode = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Whether this exception type should trigger a fallback.
     */
    abstract public function shouldFallback(): bool;

    /**
     * Whether this exception type should trigger a cooldown.
     */
    abstract public function shouldCooldown(): bool;

    /**
     * The fallback reason key for config matching.
     */
    abstract public function fallbackReason(): string;
}
