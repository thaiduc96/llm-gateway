<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\TestCase;
use Thaiduc96\LlmGateway\Exceptions\AuthException;
use Thaiduc96\LlmGateway\Exceptions\BadRequestException;
use Thaiduc96\LlmGateway\Exceptions\OverloadedException;
use Thaiduc96\LlmGateway\Exceptions\ProviderException;
use Thaiduc96\LlmGateway\Exceptions\RateLimitedException;
use Thaiduc96\LlmGateway\Exceptions\TimeoutException;
use Thaiduc96\LlmGateway\Providers\OpenAIProvider;

final class OpenAIProviderTest extends TestCase
{
    private function makeProvider(MockHandler $mock): OpenAIProvider
    {
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        return new OpenAIProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'gpt-4o',
            baseUrl: 'https://api.openai.com/v1',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );
    }

    private function successResponse(string $content = 'Hello!', string $model = 'gpt-4o'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'chatcmpl-123',
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
            'model' => $model,
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
            ],
        ]));
    }

    public function test_successful_chat(): void
    {
        $provider = $this->makeProvider(new MockHandler([$this->successResponse()]));

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Hello!', $result->content);
        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-4o', $result->model);
        $this->assertSame(15, $result->usage['total_tokens']);
        $this->assertSame('stop', $result->finishReason);
        $this->assertGreaterThan(0, $result->latencyMs);
    }

    public function test_name_returns_openai(): void
    {
        $provider = $this->makeProvider(new MockHandler([$this->successResponse()]));
        $this->assertSame('openai', $provider->name());
    }

    public function test_http_429_throws_rate_limited(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/chat/completions'),
                new Response(429, [], '{"error":{"message":"Rate limit exceeded"}}')
            ),
        ]);

        $this->expectException(RateLimitedException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_resource_exhausted_in_body_throws_rate_limited(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Resource exhausted',
                new Request('POST', '/chat/completions'),
                new Response(200, [], '{"error":{"code":"RESOURCE_EXHAUSTED","message":"Quota exceeded"}}')
            ),
        ]);

        $this->expectException(RateLimitedException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_http_503_throws_overloaded(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Service Unavailable',
                new Request('POST', '/chat/completions'),
                new Response(503, [], '{}')
            ),
        ]);

        $this->expectException(OverloadedException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_connect_timeout_throws_timeout(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection timed out',
                new Request('POST', '/chat/completions'),
            ),
        ]);

        $this->expectException(TimeoutException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_http_500_throws_provider_exception(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Internal Server Error',
                new Request('POST', '/chat/completions'),
                new Response(500, [], '{"error":{"message":"Server error"}}')
            ),
        ]);

        $this->expectException(ProviderException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_http_502_throws_provider_exception(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Bad Gateway',
                new Request('POST', '/chat/completions'),
                new Response(502, [], '{}')
            ),
        ]);

        $this->expectException(ProviderException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_http_401_throws_auth_exception(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Unauthorized',
                new Request('POST', '/chat/completions'),
                new Response(401, [], '{"error":{"message":"Invalid API key"}}')
            ),
        ]);

        $this->expectException(AuthException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_http_403_throws_auth_exception(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Forbidden',
                new Request('POST', '/chat/completions'),
                new Response(403, [], '{}')
            ),
        ]);

        $this->expectException(AuthException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_http_400_throws_bad_request(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Bad Request',
                new Request('POST', '/chat/completions'),
                new Response(400, [], '{"error":{"message":"Invalid model"}}')
            ),
        ]);

        $this->expectException(BadRequestException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_http_422_throws_bad_request(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Unprocessable Entity',
                new Request('POST', '/chat/completions'),
                new Response(422, [], '{}')
            ),
        ]);

        $this->expectException(BadRequestException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_malformed_json_on_200_throws_provider_exception(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], 'not json at all'),
        ]);

        $provider = $this->makeProvider($mock);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isMalformedResponse());
            $this->assertSame('malformed_response', $e->fallbackReason());
        }
    }

    public function test_missing_fields_on_200_throws_provider_exception(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['choices' => []])),
        ]);

        $provider = $this->makeProvider($mock);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isMalformedResponse());
        }
    }

    public function test_options_are_passed_correctly(): void
    {
        $mock = new MockHandler([$this->successResponse('Response', 'gpt-4')]);
        $provider = $this->makeProvider($mock);

        $result = $provider->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['temperature' => 0.5, 'max_output_tokens' => 100, 'model' => 'gpt-4'],
        );

        $this->assertSame('Response', $result->content);
    }

    /**
     * H1: o-series models should use max_completion_tokens instead of max_tokens.
     */
    public function test_o_series_model_uses_max_completion_tokens(): void
    {
        $requestHistory = [];
        $mock = new MockHandler([$this->successResponse('Hello!', 'o1-preview')]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $client = new Client(['handler' => $handlerStack]);

        $provider = new OpenAIProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'o1-preview',
            baseUrl: 'https://api.openai.com/v1',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );

        $provider->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['max_output_tokens' => 500, 'model' => 'o1-preview'],
        );

        $body = json_decode((string) $requestHistory[0]['request']->getBody(), true);
        $this->assertArrayHasKey('max_completion_tokens', $body);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertSame(500, $body['max_completion_tokens']);
    }

    public function test_o3_model_uses_max_completion_tokens(): void
    {
        $requestHistory = [];
        $mock = new MockHandler([$this->successResponse('Hello!', 'o3-mini')]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $client = new Client(['handler' => $handlerStack]);

        $provider = new OpenAIProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'o3-mini',
            baseUrl: 'https://api.openai.com/v1',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );

        $provider->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['max_output_tokens' => 200, 'model' => 'o3-mini'],
        );

        $body = json_decode((string) $requestHistory[0]['request']->getBody(), true);
        $this->assertArrayHasKey('max_completion_tokens', $body);
        $this->assertArrayNotHasKey('max_tokens', $body);
    }

    public function test_gpt_model_uses_max_tokens(): void
    {
        $requestHistory = [];
        $mock = new MockHandler([$this->successResponse('Hello!', 'gpt-4o')]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $client = new Client(['handler' => $handlerStack]);

        $provider = new OpenAIProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'gpt-4o',
            baseUrl: 'https://api.openai.com/v1',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );

        $provider->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['max_output_tokens' => 200, 'model' => 'gpt-4o'],
        );

        $body = json_decode((string) $requestHistory[0]['request']->getBody(), true);
        $this->assertArrayHasKey('max_tokens', $body);
        $this->assertArrayNotHasKey('max_completion_tokens', $body);
    }

    /**
     * M1: Retry-After header should be parsed on 429 responses.
     */
    public function test_429_with_retry_after_header_parsed(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/chat/completions'),
                new Response(429, ['Retry-After' => '30'], '{"error":{"message":"Rate limit exceeded"}}')
            ),
        ]);

        $provider = $this->makeProvider($mock);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(30, $e->retryAfterSeconds);
        }
    }

    public function test_429_without_retry_after_header(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/chat/completions'),
                new Response(429, [], '{"error":{"message":"Rate limit exceeded"}}')
            ),
        ]);

        $provider = $this->makeProvider($mock);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertNull($e->retryAfterSeconds);
        }
    }
}
