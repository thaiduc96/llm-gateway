<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Exceptions;

final class BadRequestException extends LLMException
{
    public function shouldFallback(): bool
    {
        return false;
    }

    public function shouldCooldown(): bool
    {
        return false;
    }

    public function fallbackReason(): string
    {
        return 'bad_request';
    }
}
