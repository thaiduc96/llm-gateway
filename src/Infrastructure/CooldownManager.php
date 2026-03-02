<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Infrastructure;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class CooldownManager
{
    private const KEY_PREFIX = 'llm_gateway:cooldown:';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $cooldownSeconds,
    ) {}

    /**
     * Check if a provider is currently in cooldown.
     */
    public function isInCooldown(string $provider): bool
    {
        return $this->cache->has(self::KEY_PREFIX . $provider);
    }

    /**
     * Put a provider into cooldown.
     */
    public function activate(string $provider, ?int $durationSeconds = null): void
    {
        $this->cache->put(
            self::KEY_PREFIX . $provider,
            true,
            $durationSeconds ?? $this->cooldownSeconds,
        );
    }

    /**
     * Clear cooldown for a provider.
     */
    public function clear(string $provider): void
    {
        $this->cache->forget(self::KEY_PREFIX . $provider);
    }

    /**
     * Get the cache key for a provider (for testing).
     */
    public static function cacheKey(string $provider): string
    {
        return self::KEY_PREFIX . $provider;
    }
}
