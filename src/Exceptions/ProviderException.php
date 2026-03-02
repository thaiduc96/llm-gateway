<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Exceptions;

final class ProviderException extends LLMException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        string $provider = '',
        ?int $httpStatusCode = null,
        private readonly bool $malformedResponse = false,
    ) {
        parent::__construct($message, $code, $previous, $provider, $httpStatusCode);
    }

    public function shouldFallback(): bool
    {
        return true;
    }

    public function shouldCooldown(): bool
    {
        return false;
    }

    public function fallbackReason(): string
    {
        if ($this->malformedResponse) {
            return 'malformed_response';
        }

        return 'server_error';
    }

    public function isMalformedResponse(): bool
    {
        return $this->malformedResponse;
    }
}
