<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Events;

final class LLMRequested
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $options = [],
        public readonly ?string $requestId = null,
    ) {}
}
