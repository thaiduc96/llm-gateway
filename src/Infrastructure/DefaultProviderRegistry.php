<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Infrastructure;

use Thaiduc96\LlmGateway\Contracts\LLMProvider;
use Thaiduc96\LlmGateway\Contracts\ProviderRegistry;
use Thaiduc96\LlmGateway\Exceptions\ProviderException;

final class DefaultProviderRegistry implements ProviderRegistry
{
    /** @var array<string, callable(array<string, mixed>): LLMProvider> */
    private array $factories = [];

    /** @var array<string, LLMProvider> */
    private array $resolved = [];

    public function register(string $driver, callable $factory): void
    {
        $this->factories[$driver] = $factory;
        // Clear ALL resolved instances — any provider using this driver
        // may have a stale instance from the previous factory.
        $this->resolved = [];
    }

    public function resolve(string $name, array $providerConfig): LLMProvider
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $driver = $providerConfig['driver'] ?? $name;

        if (!isset($this->factories[$driver])) {
            throw new ProviderException(
                "No factory registered for driver '{$driver}'. Register it via ProviderRegistry::register().",
                0,
                null,
                $name,
            );
        }

        $provider = ($this->factories[$driver])($providerConfig);
        $this->resolved[$name] = $provider;

        return $provider;
    }

    public function hasDriver(string $driver): bool
    {
        return isset($this->factories[$driver]);
    }
}
