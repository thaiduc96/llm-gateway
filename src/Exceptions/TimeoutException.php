<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Exceptions;

final class TimeoutException extends LLMException
{
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
        return 'timeout';
    }
}
