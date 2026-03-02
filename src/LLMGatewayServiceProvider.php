<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway;

use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Thaiduc96\LlmGateway\Contracts\ProviderRegistry;
use Thaiduc96\LlmGateway\DTOs\GatewayConfig;
use Thaiduc96\LlmGateway\Infrastructure\CooldownManager;
use Thaiduc96\LlmGateway\Infrastructure\DefaultProviderRegistry;
use Thaiduc96\LlmGateway\Infrastructure\ResponseCache;
use Thaiduc96\LlmGateway\Infrastructure\RetryPolicy;
use Thaiduc96\LlmGateway\Providers\GeminiProvider;
use Thaiduc96\LlmGateway\Providers\OpenAIProvider;

final class LLMGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/llm-gateway.php', 'llm-gateway');

        $this->app->singleton(GatewayConfig::class, function () {
            return GatewayConfig::fromArray(config('llm-gateway'));
        });

        $this->app->singleton(ProviderRegistry::class, function () {
            $registry = new DefaultProviderRegistry();

            $this->registerBuiltInDrivers($registry);

            return $registry;
        });

        $this->app->singleton(CooldownManager::class, function () {
            /** @var CacheRepository $cache */
            $cache = $this->app->make(CacheRepository::class);
            $gwConfig = $this->app->make(GatewayConfig::class);

            return new CooldownManager($cache, $gwConfig->cooldownSeconds);
        });

        $this->app->singleton(RetryPolicy::class, function () {
            $gwConfig = $this->app->make(GatewayConfig::class);

            return new RetryPolicy(
                maxAttempts: $gwConfig->retryAttempts,
                backoffMs: $gwConfig->retryBackoffMs,
                retryOnOverloaded: $gwConfig->retryOnOverloaded,
                maxBackoffMs: $gwConfig->retryMaxBackoffMs,
            );
        });

        $this->app->singleton(ResponseCache::class, function () {
            /** @var CacheRepository $cache */
            $cache = $this->app->make(CacheRepository::class);
            $gwConfig = $this->app->make(GatewayConfig::class);

            return new ResponseCache($cache, $gwConfig->cacheTtlSeconds);
        });

        $this->app->singleton(LLMGatewayManager::class, function () {
            return new LLMGatewayManager(
                registry: $this->app->make(ProviderRegistry::class),
                cooldownManager: $this->app->make(CooldownManager::class),
                retryPolicy: $this->app->make(RetryPolicy::class),
                events: $this->app->make(Dispatcher::class),
                config: $this->app->make(GatewayConfig::class),
                responseCache: $this->app->make(ResponseCache::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/llm-gateway.php' => config_path('llm-gateway.php'),
            ], 'llm-gateway-config');
        }
    }

    private function registerBuiltInDrivers(DefaultProviderRegistry $registry): void
    {
        $gwConfig = $this->app->make(GatewayConfig::class);
        $timeoutSeconds = $gwConfig->timeoutSeconds;
        $connectTimeoutSeconds = $gwConfig->connectTimeoutSeconds;

        $registry->register('openai', function (array $config) use ($timeoutSeconds, $connectTimeoutSeconds) {
            return new OpenAIProvider(
                client: new Client(),
                apiKey: $config['api_key'] ?? '',
                model: $config['model'] ?? 'gpt-4o',
                baseUrl: $config['base_url'] ?? 'https://api.openai.com/v1',
                timeoutSeconds: $timeoutSeconds,
                connectTimeoutSeconds: $connectTimeoutSeconds,
            );
        });

        $registry->register('gemini', function (array $config) use ($timeoutSeconds, $connectTimeoutSeconds) {
            return new GeminiProvider(
                client: new Client(),
                apiKey: $config['api_key'] ?? '',
                model: $config['model'] ?? 'gemini-2.0-flash',
                baseUrl: $config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta',
                timeoutSeconds: $timeoutSeconds,
                connectTimeoutSeconds: $connectTimeoutSeconds,
            );
        });
    }
}
