<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Events;

final class LLMFailed
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly float $latencyMs,
        public readonly string $exceptionClass,
        public readonly int $exceptionCode,
        public readonly string $exceptionMessage,
        public readonly array $options = [],
        public readonly ?string $requestId = null,
    ) {}
}
