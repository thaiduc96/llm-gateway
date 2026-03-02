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
use Thaiduc96\LlmGateway\Providers\GeminiProvider;

final class GeminiProviderTest extends TestCase
{
    private function makeProvider(MockHandler $mock): GeminiProvider
    {
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        return new GeminiProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'gemini-2.0-flash',
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );
    }

    private function successResponse(string $content = 'Hello from Gemini!'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => $content]],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 8,
                'candidatesTokenCount' => 12,
                'totalTokenCount' => 20,
            ],
        ]));
    }

    public function test_successful_chat(): void
    {
        $provider = $this->makeProvider(new MockHandler([$this->successResponse()]));

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Hello from Gemini!', $result->content);
        $this->assertSame('gemini', $result->provider);
        $this->assertSame('gemini-2.0-flash', $result->model);
        $this->assertSame(20, $result->usage['total_tokens']);
        $this->assertSame('STOP', $result->finishReason);
        $this->assertGreaterThan(0, $result->latencyMs);
    }

    public function test_name_returns_gemini(): void
    {
        $provider = $this->makeProvider(new MockHandler([$this->successResponse()]));
        $this->assertSame('gemini', $provider->name());
    }

    /**
     * H2: System messages should use native systemInstruction field.
     */
    public function test_system_message_uses_system_instruction_field(): void
    {
        $requestHistory = [];
        $mock = new MockHandler([$this->successResponse()]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $client = new Client(['handler' => $handlerStack]);

        $provider = new GeminiProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'gemini-2.0-flash',
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );

        $provider->chat([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $body = json_decode((string) $requestHistory[0]['request']->getBody(), true);

        // systemInstruction should be set
        $this->assertArrayHasKey('systemInstruction', $body);
        $this->assertSame('You are a helpful assistant.', $body['systemInstruction']['parts'][0]['text']);

        // System text should NOT be prepended to user message
        $this->assertCount(1, $body['contents']);
        $this->assertSame('Hello', $body['contents'][0]['parts'][0]['text']);
    }

    public function test_multiple_system_messages_concatenated_in_system_instruction(): void
    {
        $requestHistory = [];
        $mock = new MockHandler([$this->successResponse()]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $client = new Client(['handler' => $handlerStack]);

        $provider = new GeminiProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'gemini-2.0-flash',
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );

        $provider->chat([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $body = json_decode((string) $requestHistory[0]['request']->getBody(), true);

        $this->assertSame("You are helpful.\nBe concise.", $body['systemInstruction']['parts'][0]['text']);
    }

    public function test_no_system_message_omits_system_instruction(): void
    {
        $requestHistory = [];
        $mock = new MockHandler([$this->successResponse()]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $client = new Client(['handler' => $handlerStack]);

        $provider = new GeminiProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'gemini-2.0-flash',
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );

        $provider->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $body = json_decode((string) $requestHistory[0]['request']->getBody(), true);

        $this->assertArrayNotHasKey('systemInstruction', $body);
    }

    public function test_http_429_throws_rate_limited(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/v1beta/models/gemini-2.0-flash:generateContent'),
                new Response(429, [], '{"error":{"message":"Rate limit"}}')
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
                new Request('POST', '/v1beta/models/gemini-2.0-flash:generateContent'),
                new Response(429, [], '{"error":{"code":429,"status":"RESOURCE_EXHAUSTED","message":"Quota exceeded"}}')
            ),
        ]);

        $provider = $this->makeProvider($mock);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertTrue($e->shouldCooldown());
        }
    }

    public function test_http_503_throws_overloaded(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Service Unavailable',
                new Request('POST', '/'),
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
                new Request('POST', '/'),
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
                new Request('POST', '/'),
                new Response(500, [], '{}')
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
                new Request('POST', '/'),
                new Response(401, [], '{}')
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
                new Request('POST', '/'),
                new Response(400, [], '{}')
            ),
        ]);

        $this->expectException(BadRequestException::class);
        $this->makeProvider($mock)->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_malformed_json_on_200_throws_provider_exception(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'not json'),
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
            new Response(200, [], json_encode(['candidates' => []])),
        ]);

        $provider = $this->makeProvider($mock);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isMalformedResponse());
        }
    }

    public function test_assistant_role_converted_to_model(): void
    {
        $provider = $this->makeProvider(new MockHandler([$this->successResponse()]));

        $result = $provider->chat([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
            ['role' => 'user', 'content' => 'How are you?'],
        ]);

        $this->assertSame('Hello from Gemini!', $result->content);
    }

    /**
     * M1: Retry-After header should be parsed on 429 responses.
     */
    public function test_429_with_retry_after_header_parsed(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/'),
                new Response(429, ['Retry-After' => '45'], '{"error":{"message":"Rate limit"}}')
            ),
        ]);

        $provider = $this->makeProvider($mock);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(45, $e->retryAfterSeconds);
        }
    }

    /**
     * C1: API key should be sanitized from exception messages.
     */
    public function test_c1_connect_timeout_sanitizes_api_key(): void
    {
        $apiKey = 'AIzaSyAbcdef123456';
        $handlerStack = HandlerStack::create(new MockHandler([
            new ConnectException(
                'cURL error 28: Connection timed out after 5ms for https://example.com/models/gemini:generateContent?key=' . $apiKey,
                new Request('POST', '/'),
            ),
        ]));
        $client = new Client(['handler' => $handlerStack]);

        $provider = new GeminiProvider(
            client: $client,
            apiKey: $apiKey,
            model: 'gemini-2.0-flash',
            baseUrl: 'https://example.com/v1beta',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected TimeoutException');
        } catch (TimeoutException $e) {
            // API key should NOT appear in exception message
            $this->assertStringNotContainsString($apiKey, $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }

    public function test_c1_http_error_sanitizes_api_key(): void
    {
        $apiKey = 'AIzaSyAbcdef123456';
        $handlerStack = HandlerStack::create(new MockHandler([
            new RequestException(
                'Client error: POST https://example.com/models/gemini:generateContent?key=' . $apiKey . ' 429 Too Many Requests',
                new Request('POST', '/'),
                new Response(429, [], '{"error":{"message":"Rate limit"}}')
            ),
        ]));
        $client = new Client(['handler' => $handlerStack]);

        $provider = new GeminiProvider(
            client: $client,
            apiKey: $apiKey,
            model: 'gemini-2.0-flash',
            baseUrl: 'https://example.com/v1beta',
            timeoutSeconds: 30.0,
            connectTimeoutSeconds: 5.0,
        );

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected RateLimitedException');
        } catch (RateLimitedException $e) {
            // API key should NOT appear in exception message
            $this->assertStringNotContainsString($apiKey, $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }

    public function test_429_without_retry_after_header(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/'),
                new Response(429, [], '{"error":{"message":"Rate limit"}}')
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
