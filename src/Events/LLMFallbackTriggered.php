<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Events;

final readonly class LLMFallbackTriggered
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $primaryProvider,
        public string $fallbackProvider,
        public string $fallbackReason,
        public string $exceptionClass,
        public int $exceptionCode,
        public array $options = [],
        public ?string $requestId = null,
    ) {}
}
