<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Thaiduc96\LlmGateway\DTOs\GatewayConfig;

final class GatewayConfigTest extends TestCase
{
    private function validConfig(array $overrides = []): array
    {
        return array_merge([
            'default' => [
                'primary' => 'openai',
                'fallback' => 'gemini',
            ],
            'fallback_on' => ['rate_limit', 'timeout', 'overloaded', 'server_error', 'malformed_response'],
            'cooldown_seconds' => 60,
            'timeout_seconds' => 30,
            'connect_timeout_seconds' => 5,
            'retry_attempts' => 1,
            'retry_backoff_ms' => 200,
            'retry_max_backoff_ms' => 5000,
            'retry_on_overloaded' => false,
            'cache_ttl_seconds' => 0,
            'defaults' => [
                'temperature' => 0.7,
                'max_output_tokens' => 1024,
            ],
            'providers' => [
                'openai' => ['driver' => 'openai', 'api_key' => 'test', 'model' => 'gpt-4o'],
                'gemini' => ['driver' => 'gemini', 'api_key' => 'test', 'model' => 'gemini-2.0-flash'],
            ],
        ], $overrides);
    }

    public function test_from_array_creates_valid_config(): void
    {
        $config = GatewayConfig::fromArray($this->validConfig());

        $this->assertSame('openai', $config->primaryProvider);
        $this->assertSame('gemini', $config->fallbackProvider);
        $this->assertSame(60, $config->cooldownSeconds);
        $this->assertSame(30.0, $config->timeoutSeconds);
        $this->assertSame(5.0, $config->connectTimeoutSeconds);
        $this->assertSame(1, $config->retryAttempts);
        $this->assertSame(200, $config->retryBackoffMs);
        $this->assertSame(5000, $config->retryMaxBackoffMs);
        $this->assertFalse($config->retryOnOverloaded);
        $this->assertSame(0, $config->cacheTtlSeconds);
        $this->assertCount(5, $config->fallbackOn);
        $this->assertCount(2, $config->providers);
    }

    public function test_from_array_with_null_fallback(): void
    {
        $config = GatewayConfig::fromArray($this->validConfig([
            'default' => ['primary' => 'openai', 'fallback' => null],
        ]));

        $this->assertNull($config->fallbackProvider);
    }

    public function test_to_array_round_trip(): void
    {
        $original = $this->validConfig();
        $config = GatewayConfig::fromArray($original);
        $array = $config->toArray();

        $this->assertSame('openai', $array['default']['primary']);
        $this->assertSame('gemini', $array['default']['fallback']);
        $this->assertSame(60, $array['cooldown_seconds']);
        $this->assertSame(200, $array['retry_backoff_ms']);
        $this->assertSame(0, $array['cache_ttl_seconds']);
    }

    public function test_throws_on_missing_primary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default.primary');
        GatewayConfig::fromArray($this->validConfig([
            'default' => ['primary' => '', 'fallback' => 'gemini'],
        ]));
    }

    public function test_throws_on_non_string_primary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default.primary');
        GatewayConfig::fromArray($this->validConfig([
            'default' => ['primary' => 123, 'fallback' => 'gemini'],
        ]));
    }

    public function test_throws_on_empty_string_fallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default.fallback');
        GatewayConfig::fromArray($this->validConfig([
            'default' => ['primary' => 'openai', 'fallback' => ''],
        ]));
    }

    public function test_throws_on_negative_cooldown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cooldown_seconds');
        GatewayConfig::fromArray($this->validConfig([
            'cooldown_seconds' => -1,
        ]));
    }

    public function test_throws_on_zero_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout_seconds');
        GatewayConfig::fromArray($this->validConfig([
            'timeout_seconds' => 0,
        ]));
    }

    public function test_throws_on_zero_connect_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('connect_timeout_seconds');
        GatewayConfig::fromArray($this->validConfig([
            'connect_timeout_seconds' => 0,
        ]));
    }

    public function test_throws_on_negative_retry_attempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry_attempts');
        GatewayConfig::fromArray($this->validConfig([
            'retry_attempts' => -1,
        ]));
    }

    public function test_throws_on_negative_retry_backoff(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry_backoff_ms');
        GatewayConfig::fromArray($this->validConfig([
            'retry_backoff_ms' => -1,
        ]));
    }

    public function test_throws_on_negative_cache_ttl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cache_ttl_seconds');
        GatewayConfig::fromArray($this->validConfig([
            'cache_ttl_seconds' => -1,
        ]));
    }

    public function test_defaults_for_missing_optional_keys(): void
    {
        $minimal = [
            'default' => ['primary' => 'openai'],
            'providers' => ['openai' => ['driver' => 'openai']],
        ];

        $config = GatewayConfig::fromArray($minimal);

        $this->assertNull($config->fallbackProvider);
        $this->assertSame(60, $config->cooldownSeconds);
        $this->assertSame(30.0, $config->timeoutSeconds);
        $this->assertSame(5.0, $config->connectTimeoutSeconds);
        $this->assertSame(1, $config->retryAttempts);
        $this->assertSame(200, $config->retryBackoffMs);
        $this->assertSame(5000, $config->retryMaxBackoffMs);
        $this->assertFalse($config->retryOnOverloaded);
        $this->assertSame(0, $config->cacheTtlSeconds);
    }

    public function test_is_immutable(): void
    {
        $config = GatewayConfig::fromArray($this->validConfig());

        $reflection = new \ReflectionClass($config);
        $this->assertTrue($reflection->isReadOnly());
    }
}
