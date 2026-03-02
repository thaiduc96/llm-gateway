<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Exceptions;

final class RateLimitedException extends LLMException
{
    public readonly ?int $retryAfterSeconds;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        string $provider = '',
        ?int $httpStatusCode = null,
        ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $code, $previous, $provider, $httpStatusCode);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public function shouldFallback(): bool
    {
        return true;
    }

    public function shouldCooldown(): bool
    {
        return true;
    }

    public function fallbackReason(): string
    {
        return 'rate_limit';
    }
}
