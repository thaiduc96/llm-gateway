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
use Thaiduc96\LlmGateway\Exceptions\RateLimitedException;
use Thaiduc96\LlmGateway\Exceptions\TimeoutException;
use Thaiduc96\LlmGateway\Providers\GeminiProvider;

final class GeminiStreamingTest extends TestCase
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

    private function sseResponse(array $chunks): Response
    {
        $body = '';
        foreach ($chunks as $chunk) {
            $body .= 'data: ' . json_encode([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => $chunk]],
                            'role' => 'model',
                        ],
                    ],
                ],
            ]) . "\n\n";
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    public function test_successful_stream_yields_chunks(): void
    {
        $provider = $this->makeProvider(new MockHandler([
            $this->sseResponse(['Hello', ' from', ' Gemini!']),
        ]));

        $chunks = iterator_to_array($provider->chatStream([['role' => 'user', 'content' => 'Hi']]));

        $this->assertSame(['Hello', ' from', ' Gemini!'], $chunks);
    }

    public function test_stream_skips_empty_text_chunks(): void
    {
        $body = 'data: ' . json_encode([
            'candidates' => [['content' => ['parts' => [['text' => '']], 'role' => 'model']]],
        ]) . "\n\n";
        $body .= 'data: ' . json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Hello']], 'role' => 'model']]],
        ]) . "\n\n";

        $provider = $this->makeProvider(new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $body),
        ]));

        $chunks = iterator_to_array($provider->chatStream([['role' => 'user', 'content' => 'Hi']]));

        $this->assertSame(['Hello'], $chunks);
    }

    public function test_stream_with_system_message(): void
    {
        $provider = $this->makeProvider(new MockHandler([
            $this->sseResponse(['Response']),
        ]));

        $chunks = iterator_to_array($provider->chatStream([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]));

        $this->assertSame(['Response'], $chunks);
    }

    public function test_stream_connection_timeout_throws_timeout_exception(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection timed out',
                new Request('POST', '/v1beta/models/gemini-2.0-flash:streamGenerateContent'),
            ),
        ]);

        $this->expectException(TimeoutException::class);
        $this->makeProvider($mock)->chatStream([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_stream_http_401_throws_auth_exception(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Unauthorized',
                new Request('POST', '/v1beta/models/gemini-2.0-flash:streamGenerateContent'),
                new Response(401, [], '{}')
            ),
        ]);

        $this->expectException(AuthException::class);
        $this->makeProvider($mock)->chatStream([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_stream_http_429_throws_rate_limited(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/v1beta/models/gemini-2.0-flash:streamGenerateContent'),
                new Response(429, [], '{"error":{"message":"Rate limit"}}')
            ),
        ]);

        $this->expectException(RateLimitedException::class);
        $this->makeProvider($mock)->chatStream([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_stream_skips_malformed_json_lines(): void
    {
        $body = "data: not-valid-json\n\ndata: " . json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Valid']], 'role' => 'model']]],
        ]) . "\n\n";

        $provider = $this->makeProvider(new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $body),
        ]));

        $chunks = iterator_to_array($provider->chatStream([['role' => 'user', 'content' => 'Hi']]));

        $this->assertSame(['Valid'], $chunks);
    }

    public function test_stream_with_options(): void
    {
        $provider = $this->makeProvider(new MockHandler([
            $this->sseResponse(['Response']),
        ]));

        $chunks = iterator_to_array($provider->chatStream(
            [['role' => 'user', 'content' => 'Hi']],
            ['temperature' => 0.3, 'max_output_tokens' => 200],
        ));

        $this->assertSame(['Response'], $chunks);
    }
}
