<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Events;

final class LLMSucceeded
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array{prompt_tokens?: int|null, completion_tokens?: int|null, total_tokens?: int|null}|null  $usage
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly float $latencyMs,
        public readonly array $options = [],
        public readonly ?array $usage = null,
        public readonly ?string $requestId = null,
    ) {}
}
