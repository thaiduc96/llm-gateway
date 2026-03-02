<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Contracts;

interface ProviderRegistry
{
    /**
     * Register a provider factory for the given driver name.
     *
     * @param  string  $driver  The driver identifier (e.g., 'openai', 'gemini').
     * @param  callable(array<string, mixed>): LLMProvider  $factory
     */
    public function register(string $driver, callable $factory): void;

    /**
     * Resolve a provider instance by its config name.
     *
     * @param  string  $name  The provider config key (e.g., 'openai', 'gemini').
     * @param  array<string, mixed>  $providerConfig  The provider's config array.
     *
     * @throws \Thaiduc96\LlmGateway\Exceptions\LLMException
     */
    public function resolve(string $name, array $providerConfig): LLMProvider;

    /**
     * Check if a driver is registered.
     */
    public function hasDriver(string $driver): bool;
}
