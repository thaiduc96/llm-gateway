<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use PHPUnit\Framework\TestCase;
use Thaiduc96\LlmGateway\DTOs\LLMResult;
use Thaiduc96\LlmGateway\Infrastructure\ResponseCache;

final class ResponseCacheTest extends TestCase
{
    private function makeCache(int $ttl = 300): ResponseCache
    {
        return new ResponseCache(new CacheRepository(new ArrayStore()), $ttl);
    }

    private function sampleResult(): LLMResult
    {
        return new LLMResult(
            content: 'Hello from cache!',
            provider: 'openai',
            model: 'gpt-4o',
            latencyMs: 123.45,
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            finishReason: 'stop',
        );
    }

    public function test_disabled_when_ttl_is_zero(): void
    {
        $cache = $this->makeCache(0);

        $this->assertFalse($cache->isEnabled());
        $this->assertSame(0, $cache->getTtlSeconds());
    }

    public function test_enabled_when_ttl_is_positive(): void
    {
        $cache = $this->makeCache(300);

        $this->assertTrue($cache->isEnabled());
        $this->assertSame(300, $cache->getTtlSeconds());
    }

    public function test_get_returns_null_when_disabled(): void
    {
        $cache = $this->makeCache(0);

        $result = $cache->get('openai', 'gpt-4o', [['role' => 'user', 'content' => 'Hello']], []);

        $this->assertNull($result);
    }

    public function test_put_does_nothing_when_disabled(): void
    {
        $cache = $this->makeCache(0);
        $result = $this->sampleResult();

        // Should not throw or store anything
        $cache->put('openai', 'gpt-4o', [['role' => 'user', 'content' => 'Hello']], [], $result);

        $this->assertNull($cache->get('openai', 'gpt-4o', [['role' => 'user', 'content' => 'Hello']], []));
    }

    public function test_get_returns_null_on_cache_miss(): void
    {
        $cache = $this->makeCache(300);

        $result = $cache->get('openai', 'gpt-4o', [['role' => 'user', 'content' => 'Hello']], []);

        $this->assertNull($result);
    }

    public function test_put_and_get_round_trip(): void
    {
        $cache = $this->makeCache(300);
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = ['temperature' => 0.7];
        $original = $this->sampleResult();

        $cache->put('openai', 'gpt-4o', $messages, $options, $original);
        $cached = $cache->get('openai', 'gpt-4o', $messages, $options);

        $this->assertNotNull($cached);
        $this->assertSame('Hello from cache!', $cached->content);
        $this->assertSame('openai', $cached->provider);
        $this->assertSame('gpt-4o', $cached->model);
        $this->assertSame(123.45, $cached->latencyMs);
        $this->assertSame(15, $cached->usage['total_tokens']);
        $this->assertSame('stop', $cached->finishReason);
    }

    public function test_different_provider_different_key(): void
    {
        $cache = $this->makeCache(300);
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = [];
        $result = $this->sampleResult();

        $cache->put('openai', 'gpt-4o', $messages, $options, $result);

        // Different provider should miss
        $this->assertNull($cache->get('gemini', 'gpt-4o', $messages, $options));
    }

    public function test_different_model_different_key(): void
    {
        $cache = $this->makeCache(300);
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = [];
        $result = $this->sampleResult();

        $cache->put('openai', 'gpt-4o', $messages, $options, $result);

        // Different model should miss
        $this->assertNull($cache->get('openai', 'gpt-3.5-turbo', $messages, $options));
    }

    public function test_different_messages_different_key(): void
    {
        $cache = $this->makeCache(300);
        $options = [];
        $result = $this->sampleResult();

        $cache->put('openai', 'gpt-4o', [['role' => 'user', 'content' => 'Hello']], $options, $result);

        // Different messages should miss
        $this->assertNull($cache->get('openai', 'gpt-4o', [['role' => 'user', 'content' => 'Goodbye']], $options));
    }

    public function test_different_options_different_key(): void
    {
        $cache = $this->makeCache(300);
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $result = $this->sampleResult();

        $cache->put('openai', 'gpt-4o', $messages, ['temperature' => 0.7], $result);

        // Different options should miss
        $this->assertNull($cache->get('openai', 'gpt-4o', $messages, ['temperature' => 0.3]));
    }

    public function test_build_key_is_deterministic(): void
    {
        $cache = $this->makeCache(300);
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = ['temperature' => 0.7];

        $key1 = $cache->buildKey('openai', 'gpt-4o', $messages, $options);
        $key2 = $cache->buildKey('openai', 'gpt-4o', $messages, $options);

        $this->assertSame($key1, $key2);
    }

    public function test_build_key_has_prefix(): void
    {
        $cache = $this->makeCache(300);

        $key = $cache->buildKey('openai', 'gpt-4o', [], []);

        $this->assertStringStartsWith('llm_gateway:response:', $key);
    }

    public function test_cached_result_without_optional_fields(): void
    {
        $cache = $this->makeCache(300);
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $result = new LLMResult(
            content: 'Response',
            provider: 'openai',
            model: 'gpt-4o',
            latencyMs: 100.0,
        );

        $cache->put('openai', 'gpt-4o', $messages, [], $result);
        $cached = $cache->get('openai', 'gpt-4o', $messages, []);

        $this->assertNotNull($cached);
        $this->assertSame('Response', $cached->content);
        $this->assertNull($cached->usage);
        $this->assertNull($cached->finishReason);
    }
}
