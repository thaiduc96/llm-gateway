<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Exceptions;

final class OverloadedException extends LLMException
{
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
        return 'overloaded';
    }
}
