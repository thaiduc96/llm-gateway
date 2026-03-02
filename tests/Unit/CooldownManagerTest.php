<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use PHPUnit\Framework\TestCase;
use Thaiduc96\LlmGateway\Infrastructure\CooldownManager;

final class CooldownManagerTest extends TestCase
{
    private function makeCooldownManager(int $seconds = 60): CooldownManager
    {
        $cache = new CacheRepository(new ArrayStore());

        return new CooldownManager($cache, $seconds);
    }

    public function test_not_in_cooldown_by_default(): void
    {
        $manager = $this->makeCooldownManager();

        $this->assertFalse($manager->isInCooldown('openai'));
    }

    public function test_activate_puts_provider_in_cooldown(): void
    {
        $manager = $this->makeCooldownManager();

        $manager->activate('openai');

        $this->assertTrue($manager->isInCooldown('openai'));
        $this->assertFalse($manager->isInCooldown('gemini'));
    }

    public function test_clear_removes_cooldown(): void
    {
        $manager = $this->makeCooldownManager();

        $manager->activate('openai');
        $this->assertTrue($manager->isInCooldown('openai'));

        $manager->clear('openai');
        $this->assertFalse($manager->isInCooldown('openai'));
    }

    public function test_cache_key_format(): void
    {
        $this->assertSame('llm_gateway:cooldown:openai', CooldownManager::cacheKey('openai'));
        $this->assertSame('llm_gateway:cooldown:gemini', CooldownManager::cacheKey('gemini'));
    }

    public function test_different_providers_have_independent_cooldowns(): void
    {
        $manager = $this->makeCooldownManager();

        $manager->activate('openai');

        $this->assertTrue($manager->isInCooldown('openai'));
        $this->assertFalse($manager->isInCooldown('gemini'));

        $manager->activate('gemini');

        $this->assertTrue($manager->isInCooldown('openai'));
        $this->assertTrue($manager->isInCooldown('gemini'));

        $manager->clear('openai');

        $this->assertFalse($manager->isInCooldown('openai'));
        $this->assertTrue($manager->isInCooldown('gemini'));
    }

    /**
     * M1: activate() accepts custom duration from Retry-After header.
     */
    public function test_activate_with_custom_duration(): void
    {
        $manager = $this->makeCooldownManager();

        $manager->activate('openai', 120);

        $this->assertTrue($manager->isInCooldown('openai'));
    }

    public function test_activate_with_null_duration_uses_default(): void
    {
        $manager = $this->makeCooldownManager();

        $manager->activate('openai', null);

        $this->assertTrue($manager->isInCooldown('openai'));
    }
}
