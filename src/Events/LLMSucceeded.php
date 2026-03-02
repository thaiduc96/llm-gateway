<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Events;

final readonly class LLMSucceeded
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array{prompt_tokens?: int|null, completion_tokens?: int|null, total_tokens?: int|null}|null  $usage
     */
    public function __construct(
        public string $provider,
        public string $model,
        public float $latencyMs,
        public array $options = [],
        public ?array $usage = null,
        public ?string $requestId = null,
    ) {}
}
