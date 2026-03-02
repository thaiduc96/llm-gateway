<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Integration;

use Thaiduc96\LlmGateway\Contracts\ProviderRegistry;
use Thaiduc96\LlmGateway\Infrastructure\CooldownManager;
use Thaiduc96\LlmGateway\Infrastructure\RetryPolicy;
use Thaiduc96\LlmGateway\LLMGatewayManager;
use Thaiduc96\LlmGateway\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $config = config('llm-gateway');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('default', $config);
        $this->assertArrayHasKey('providers', $config);
        $this->assertArrayHasKey('fallback_on', $config);
        $this->assertSame('openai', $config['default']['primary']);
    }

    public function test_provider_registry_is_resolvable(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);

        $this->assertInstanceOf(ProviderRegistry::class, $registry);
    }

    public function test_provider_registry_is_singleton(): void
    {
        $a = $this->app->make(ProviderRegistry::class);
        $b = $this->app->make(ProviderRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_llm_gateway_manager_is_resolvable(): void
    {
        $manager = $this->app->make(LLMGatewayManager::class);

        $this->assertInstanceOf(LLMGatewayManager::class, $manager);
    }

    public function test_llm_gateway_manager_is_singleton(): void
    {
        $a = $this->app->make(LLMGatewayManager::class);
        $b = $this->app->make(LLMGatewayManager::class);

        $this->assertSame($a, $b);
    }

    public function test_cooldown_manager_is_resolvable(): void
    {
        $manager = $this->app->make(CooldownManager::class);

        $this->assertInstanceOf(CooldownManager::class, $manager);
    }

    public function test_retry_policy_is_resolvable(): void
    {
        $policy = $this->app->make(RetryPolicy::class);

        $this->assertInstanceOf(RetryPolicy::class, $policy);
    }

    public function test_built_in_drivers_are_registered(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);

        $this->assertTrue($registry->hasDriver('openai'));
        $this->assertTrue($registry->hasDriver('gemini'));
    }
}
