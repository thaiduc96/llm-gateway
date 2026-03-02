<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Thaiduc96\LlmGateway\DTOs\LLMResult;
use Thaiduc96\LlmGateway\Exceptions\AuthException;
use Thaiduc96\LlmGateway\Exceptions\BadRequestException;
use Thaiduc96\LlmGateway\Exceptions\LLMException;
use Thaiduc96\LlmGateway\Exceptions\OverloadedException;
use Thaiduc96\LlmGateway\Exceptions\ProviderException;
use Thaiduc96\LlmGateway\Exceptions\RateLimitedException;
use Thaiduc96\LlmGateway\Exceptions\TimeoutException;

final class FoundationTest extends TestCase
{
    public function test_llm_result_is_immutable(): void
    {
        $result = new LLMResult(
            content: 'Hello',
            provider: 'openai',
            model: 'gpt-4o',
            latencyMs: 123.45,
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            finishReason: 'stop',
        );

        $this->assertSame('Hello', $result->content);
        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-4o', $result->model);
        $this->assertSame(123.45, $result->latencyMs);
        $this->assertSame(15, $result->usage['total_tokens']);
        $this->assertSame('stop', $result->finishReason);
    }

    public function test_llm_result_to_array(): void
    {
        $result = new LLMResult(
            content: 'Hi',
            provider: 'gemini',
            model: 'gemini-2.0-flash',
            latencyMs: 200.0,
        );

        $array = $result->toArray();

        $this->assertSame('Hi', $array['content']);
        $this->assertSame('gemini', $array['provider']);
        $this->assertNull($array['usage']);
        $this->assertNull($array['finish_reason']);
    }

    public function test_rate_limited_exception_flags(): void
    {
        $e = new RateLimitedException('rate limited', 429, null, 'openai', 429);

        $this->assertTrue($e->shouldFallback());
        $this->assertTrue($e->shouldCooldown());
        $this->assertSame('rate_limit', $e->fallbackReason());
        $this->assertSame('openai', $e->provider);
        $this->assertSame(429, $e->httpStatusCode);
    }

    public function test_overloaded_exception_flags(): void
    {
        $e = new OverloadedException('overloaded', 503, null, 'gemini', 503);

        $this->assertTrue($e->shouldFallback());
        $this->assertTrue($e->shouldCooldown());
        $this->assertSame('overloaded', $e->fallbackReason());
    }

    public function test_timeout_exception_flags(): void
    {
        $e = new TimeoutException('timeout', 0, null, 'openai');

        $this->assertTrue($e->shouldFallback());
        $this->assertFalse($e->shouldCooldown());
        $this->assertSame('timeout', $e->fallbackReason());
    }

    public function test_provider_exception_server_error_flags(): void
    {
        $e = new ProviderException('server error', 500, null, 'openai', 500);

        $this->assertTrue($e->shouldFallback());
        $this->assertFalse($e->shouldCooldown());
        $this->assertSame('server_error', $e->fallbackReason());
        $this->assertFalse($e->isMalformedResponse());
    }

    public function test_provider_exception_malformed_response_flags(): void
    {
        $e = new ProviderException('bad json', 0, null, 'gemini', 200, true);

        $this->assertTrue($e->shouldFallback());
        $this->assertFalse($e->shouldCooldown());
        $this->assertSame('malformed_response', $e->fallbackReason());
        $this->assertTrue($e->isMalformedResponse());
    }

    public function test_auth_exception_flags(): void
    {
        $e = new AuthException('unauthorized', 401, null, 'openai', 401);

        $this->assertFalse($e->shouldFallback());
        $this->assertFalse($e->shouldCooldown());
        $this->assertSame('auth', $e->fallbackReason());
    }

    public function test_bad_request_exception_flags(): void
    {
        $e = new BadRequestException('bad request', 400, null, 'openai', 400);

        $this->assertFalse($e->shouldFallback());
        $this->assertFalse($e->shouldCooldown());
        $this->assertSame('bad_request', $e->fallbackReason());
    }

    public function test_all_exceptions_extend_llm_exception(): void
    {
        $exceptions = [
            new RateLimitedException(),
            new OverloadedException(),
            new TimeoutException(),
            new ProviderException(),
            new AuthException(),
            new BadRequestException(),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(LLMException::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }
}
