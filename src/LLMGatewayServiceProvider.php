<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway;

use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Thaiduc96\LlmGateway\Contracts\ProviderRegistry;
use Thaiduc96\LlmGateway\Infrastructure\CooldownManager;
use Thaiduc96\LlmGateway\Infrastructure\DefaultProviderRegistry;
use Thaiduc96\LlmGateway\Infrastructure\RetryPolicy;
use Thaiduc96\LlmGateway\Providers\GeminiProvider;
use Thaiduc96\LlmGateway\Providers\OpenAIProvider;

final class LLMGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/llm-gateway.php', 'llm-gateway');

        $this->app->singleton(ProviderRegistry::class, function () {
            $registry = new DefaultProviderRegistry();

            $this->registerBuiltInDrivers($registry);

            return $registry;
        });

        $this->app->singleton(CooldownManager::class, function () {
            /** @var CacheRepository $cache */
            $cache = $this->app->make(CacheRepository::class);
            $cooldownSeconds = (int) config('llm-gateway.cooldown_seconds', 60);

            return new CooldownManager($cache, $cooldownSeconds);
        });

        $this->app->singleton(RetryPolicy::class, function () {
            return new RetryPolicy(
                maxAttempts: (int) config('llm-gateway.retry_attempts', 1),
                backoffMs: (int) config('llm-gateway.retry_backoff_ms', 200),
                retryOnOverloaded: (bool) config('llm-gateway.retry_on_overloaded', false),
            );
        });

        $this->app->singleton(LLMGatewayManager::class, function () {
            return new LLMGatewayManager(
                registry: $this->app->make(ProviderRegistry::class),
                cooldownManager: $this->app->make(CooldownManager::class),
                retryPolicy: $this->app->make(RetryPolicy::class),
                events: $this->app->make(Dispatcher::class),
                config: config('llm-gateway'),
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
        $timeoutSeconds = (float) config('llm-gateway.timeout_seconds', 30);
        $connectTimeoutSeconds = (float) config('llm-gateway.connect_timeout_seconds', 5);

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
