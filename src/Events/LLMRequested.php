<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Events;

final readonly class LLMRequested
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $options = [],
        public ?string $requestId = null,
    ) {}
}
