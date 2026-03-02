<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Infrastructure;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Thaiduc96\LlmGateway\DTOs\LLMResult;

final class ResponseCache
{
    private const KEY_PREFIX = 'llm_gateway:response:';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * Whether caching is enabled (TTL > 0).
     */
    public function isEnabled(): bool
    {
        return $this->ttlSeconds > 0;
    }

    /**
     * Retrieve a cached response for the given request parameters.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function get(string $provider, string $model, array $messages, array $options): ?LLMResult
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $key = $this->buildKey($provider, $model, $messages, $options);
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            return null;
        }

        return new LLMResult(
            content: $cached['content'],
            provider: $cached['provider'],
            model: $cached['model'],
            latencyMs: $cached['latency_ms'],
            usage: $cached['usage'] ?? null,
            finishReason: $cached['finish_reason'] ?? null,
        );
    }

    /**
     * Store a response in the cache.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function put(string $provider, string $model, array $messages, array $options, LLMResult $result): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = $this->buildKey($provider, $model, $messages, $options);
        $this->cache->put($key, $result->toArray(), $this->ttlSeconds);
    }

    /**
     * Build a deterministic cache key from the request parameters.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function buildKey(string $provider, string $model, array $messages, array $options): string
    {
        $data = json_encode([
            'provider' => $provider,
            'model' => $model,
            'messages' => $messages,
            'options' => $options,
        ], JSON_THROW_ON_ERROR);

        return self::KEY_PREFIX . hash('sha256', $data);
    }

    public function getTtlSeconds(): int
    {
        return $this->ttlSeconds;
    }
}
