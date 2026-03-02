<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Events;

final class LLMFallbackTriggered
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $primaryProvider,
        public readonly string $fallbackProvider,
        public readonly string $fallbackReason,
        public readonly string $exceptionClass,
        public readonly int $exceptionCode,
        public readonly array $options = [],
        public readonly ?string $requestId = null,
    ) {}
}
