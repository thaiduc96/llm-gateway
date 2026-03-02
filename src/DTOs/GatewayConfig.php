<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\DTOs;

use InvalidArgumentException;

/**
 * Type-safe, immutable representation of the llm-gateway configuration.
 */
final readonly class GatewayConfig
{
    /**
     * @param  list<string>  $fallbackOn
     * @param  array<string, mixed>  $defaults
     * @param  array<string, array<string, mixed>>  $providers
     */
    public function __construct(
        public string $primaryProvider,
        public ?string $fallbackProvider,
        public array $fallbackOn,
        public int $cooldownSeconds,
        public float $timeoutSeconds,
        public float $connectTimeoutSeconds,
        public int $retryAttempts,
        public int $retryBackoffMs,
        public bool $retryOnOverloaded,
        public int $retryMaxBackoffMs,
        public array $defaults,
        public array $providers,
        public int $cacheTtlSeconds = 0,
    ) {}

    /**
     * Parse and validate a raw config array into a typed GatewayConfig.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $config): self
    {
        $primary = $config['default']['primary'] ?? null;
        if (! is_string($primary) || $primary === '') {
            throw new InvalidArgumentException(
                'LLM Gateway config [default.primary] must be a non-empty string.'
            );
        }

        $fallback = $config['default']['fallback'] ?? null;
        if ($fallback !== null && (! is_string($fallback) || $fallback === '')) {
            throw new InvalidArgumentException(
                'LLM Gateway config [default.fallback] must be a non-empty string or null.'
            );
        }

        $fallbackOn = $config['fallback_on'] ?? [];
        if (! is_array($fallbackOn)) {
            throw new InvalidArgumentException(
                'LLM Gateway config [fallback_on] must be an array.'
            );
        }

        $cooldownSeconds = (int) ($config['cooldown_seconds'] ?? 60);
        if ($cooldownSeconds < 0) {
            throw new InvalidArgumentException(
                'LLM Gateway config [cooldown_seconds] must be >= 0.'
            );
        }

        $timeoutSeconds = (float) ($config['timeout_seconds'] ?? 30);
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException(
                'LLM Gateway config [timeout_seconds] must be > 0.'
            );
        }

        $connectTimeoutSeconds = (float) ($config['connect_timeout_seconds'] ?? 5);
        if ($connectTimeoutSeconds <= 0) {
            throw new InvalidArgumentException(
                'LLM Gateway config [connect_timeout_seconds] must be > 0.'
            );
        }

        $retryAttempts = (int) ($config['retry_attempts'] ?? 1);
        if ($retryAttempts < 0) {
            throw new InvalidArgumentException(
                'LLM Gateway config [retry_attempts] must be >= 0.'
            );
        }

        $retryBackoffMs = (int) ($config['retry_backoff_ms'] ?? 200);
        if ($retryBackoffMs < 0) {
            throw new InvalidArgumentException(
                'LLM Gateway config [retry_backoff_ms] must be >= 0.'
            );
        }

        $providers = $config['providers'] ?? [];
        if (! is_array($providers)) {
            throw new InvalidArgumentException(
                'LLM Gateway config [providers] must be an array.'
            );
        }

        $cacheTtlSeconds = (int) ($config['cache_ttl_seconds'] ?? 0);
        if ($cacheTtlSeconds < 0) {
            throw new InvalidArgumentException(
                'LLM Gateway config [cache_ttl_seconds] must be >= 0.'
            );
        }

        return new self(
            primaryProvider: $primary,
            fallbackProvider: $fallback,
            fallbackOn: array_values($fallbackOn),
            cooldownSeconds: $cooldownSeconds,
            timeoutSeconds: $timeoutSeconds,
            connectTimeoutSeconds: $connectTimeoutSeconds,
            retryAttempts: $retryAttempts,
            retryBackoffMs: $retryBackoffMs,
            retryOnOverloaded: (bool) ($config['retry_on_overloaded'] ?? false),
            retryMaxBackoffMs: (int) ($config['retry_max_backoff_ms'] ?? 5000),
            defaults: (array) ($config['defaults'] ?? []),
            providers: $providers,
            cacheTtlSeconds: $cacheTtlSeconds,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'default' => [
                'primary' => $this->primaryProvider,
                'fallback' => $this->fallbackProvider,
            ],
            'fallback_on' => $this->fallbackOn,
            'cooldown_seconds' => $this->cooldownSeconds,
            'timeout_seconds' => $this->timeoutSeconds,
            'connect_timeout_seconds' => $this->connectTimeoutSeconds,
            'retry_attempts' => $this->retryAttempts,
            'retry_backoff_ms' => $this->retryBackoffMs,
            'retry_on_overloaded' => $this->retryOnOverloaded,
            'retry_max_backoff_ms' => $this->retryMaxBackoffMs,
            'defaults' => $this->defaults,
            'providers' => $this->providers,
            'cache_ttl_seconds' => $this->cacheTtlSeconds,
        ];
    }
}
