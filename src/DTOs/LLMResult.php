<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\DTOs;

final class LLMResult
{
    /**
     * @param  array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}|null  $usage
     */
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly float $latencyMs,
        public readonly ?array $usage = null,
        public readonly ?string $finishReason = null,
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
