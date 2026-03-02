<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Facades;

use Illuminate\Support\Facades\Facade;
use Thaiduc96\LlmGateway\DTOs\LLMResult;
use Thaiduc96\LlmGateway\LLMGatewayManager;

/**
 * @method static LLMResult chat(array<int, array{role: string, content: string}> $messages, array<string, mixed> $options = [])
 * @method static \Generator<int, string, mixed, void> stream(array<int, array{role: string, content: string}> $messages, array<string, mixed> $options = [])
 * @method static LLMGatewayManager usingPrimary(string $provider)
 * @method static LLMGatewayManager usingFallback(?string $provider)
 *
 * @see LLMGatewayManager
 */
final class LLM extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LLMGatewayManager::class;
    }
}
