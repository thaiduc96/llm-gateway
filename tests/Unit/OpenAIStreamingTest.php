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
use Thaiduc96\LlmGateway\Providers\OpenAIProvider;

final class OpenAIStreamingTest extends TestCase
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

    private function sseResponse(array $chunks): Response
    {
        $body = '';
        foreach ($chunks as $chunk) {
            $body .= 'data: ' . json_encode([
                'choices' => [
                    [
                        'delta' => ['content' => $chunk],
                        'finish_reason' => null,
                    ],
                ],
            ]) . "\n\n";
        }
        $body .= "data: [DONE]\n\n";

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    public function test_successful_stream_yields_chunks(): void
    {
        $provider = $this->makeProvider(new MockHandler([
            $this->sseResponse(['Hello', ' world', '!']),
        ]));

        $chunks = iterator_to_array($provider->chatStream([['role' => 'user', 'content' => 'Hi']]));

        $this->assertSame(['Hello', ' world', '!'], $chunks);
    }

    public function test_stream_skips_empty_content_deltas(): void
    {
        $body = '';
        // First chunk: role only, no content
        $body .= 'data: ' . json_encode([
            'choices' => [['delta' => ['role' => 'assistant'], 'finish_reason' => null]],
        ]) . "\n\n";
        // Second chunk: empty content
        $body .= 'data: ' . json_encode([
            'choices' => [['delta' => ['content' => ''], 'finish_reason' => null]],
        ]) . "\n\n";
        // Third chunk: actual content
        $body .= 'data: ' . json_encode([
            'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]],
        ]) . "\n\n";
        // Fourth chunk: empty delta (finish)
        $body .= 'data: ' . json_encode([
            'choices' => [['delta' => [], 'finish_reason' => 'stop']],
        ]) . "\n\n";
        $body .= "data: [DONE]\n\n";

        $provider = $this->makeProvider(new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $body),
        ]));

        $chunks = iterator_to_array($provider->chatStream([['role' => 'user', 'content' => 'Hi']]));

        $this->assertSame(['Hello'], $chunks);
    }

    public function test_stream_handles_done_marker(): void
    {
        $provider = $this->makeProvider(new MockHandler([
            $this->sseResponse(['Test']),
        ]));

        $chunks = iterator_to_array($provider->chatStream([['role' => 'user', 'content' => 'Hi']]));

        $this->assertSame(['Test'], $chunks);
    }

    public function test_stream_connection_timeout_throws_timeout_exception(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection timed out',
                new Request('POST', '/chat/completions'),
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
                new Request('POST', '/chat/completions'),
                new Response(401, [], '{"error":{"message":"Invalid API key"}}')
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
                new Request('POST', '/chat/completions'),
                new Response(429, [], '{"error":{"message":"Rate limit exceeded"}}')
            ),
        ]);

        $this->expectException(RateLimitedException::class);
        $this->makeProvider($mock)->chatStream([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_stream_skips_malformed_json_lines(): void
    {
        $body = "data: not-json\n\ndata: " . json_encode([
            'choices' => [['delta' => ['content' => 'Valid'], 'finish_reason' => null]],
        ]) . "\n\ndata: [DONE]\n\n";

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
            ['temperature' => 0.5, 'max_output_tokens' => 100, 'model' => 'gpt-4'],
        ));

        $this->assertSame(['Response'], $chunks);
    }
}
