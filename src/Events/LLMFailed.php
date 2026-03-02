<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Events;

final readonly class LLMFailed
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $provider,
        public string $model,
        public float $latencyMs,
        public string $exceptionClass,
        public int $exceptionCode,
        public string $exceptionMessage,
        public array $options = [],
        public ?string $requestId = null,
    ) {}
}
