<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\DTOs;

final readonly class LLMResult
{
    /**
     * @param  array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}|null  $usage
     */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public float $latencyMs,
        public ?array $usage = null,
        public ?string $finishReason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'provider' => $this->provider,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            'usage' => $this->usage,
            'finish_reason' => $this->finishReason,
        ];
    }
}
