<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Providers;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Thaiduc96\LlmGateway\Contracts\LLMProvider;
use Thaiduc96\LlmGateway\DTOs\LLMResult;
use Thaiduc96\LlmGateway\Exceptions\AuthException;
use Thaiduc96\LlmGateway\Exceptions\BadRequestException;
use Thaiduc96\LlmGateway\Exceptions\OverloadedException;
use Thaiduc96\LlmGateway\Exceptions\ProviderException;
use Thaiduc96\LlmGateway\Exceptions\RateLimitedException;
use Thaiduc96\LlmGateway\Exceptions\TimeoutException;

final class GeminiProvider implements LLMProvider
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly float $timeoutSeconds,
        private readonly float $connectTimeoutSeconds,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function chat(array $messages, array $options = []): LLMResult
    {
        $model = $options['model'] ?? $this->model;
        [$contents, $systemText] = $this->convertMessages($messages);
        $generationConfig = $this->buildGenerationConfig($options);

        $body = ['contents' => $contents];
        if ($systemText !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemText]]];
        }
        if (!empty($generationConfig)) {
            $body['generationConfig'] = $generationConfig;
        }

        $url = rtrim($this->baseUrl, '/') . '/models/' . $model . ':generateContent?key=' . $this->apiKey;

        $startTime = hrtime(true);

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => $options['timeout_seconds'] ?? $this->timeoutSeconds,
                'connect_timeout' => $options['connect_timeout_seconds'] ?? $this->connectTimeoutSeconds,
                'http_errors' => true,
            ]);
        } catch (ConnectException $e) {
            throw new TimeoutException(
                'Gemini connection timeout: ' . $this->sanitizeMessage($e->getMessage()),
                0,
                $e,
                $this->name(),
            );
        } catch (RequestException $e) {
            throw $this->classifyHttpException($e);
        }

        $latencyMs = (hrtime(true) - $startTime) / 1_000_000;
        $statusCode = $response->getStatusCode();
        $rawBody = (string) $response->getBody();

        $data = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ProviderException(
                'Gemini returned malformed JSON: ' . json_last_error_msg(),
                0,
                null,
                $this->name(),
                $statusCode,
                true,
            );
        }

        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new ProviderException(
                'Gemini response missing expected fields: candidates[0].content.parts[0].text',
                0,
                null,
                $this->name(),
                $statusCode,
                true,
            );
        }

        $usage = null;
        if (isset($data['usageMetadata'])) {
            $usage = [
                'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? null,
                'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? null,
                'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? null,
            ];
        }

        $finishReason = $data['candidates'][0]['finishReason'] ?? null;

        return new LLMResult(
            content: $data['candidates'][0]['content']['parts'][0]['text'],
            provider: $this->name(),
            model: $model,
            latencyMs: $latencyMs,
            usage: $usage,
            finishReason: $finishReason,
        );
    }

    /**
     * Convert OpenAI-style messages to Gemini contents format.
     *
     * Gemini uses 'user' and 'model' roles. System messages are extracted
     * for the native systemInstruction field.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{0: array<int, array{role: string, parts: array<int, array{text: string}>}>, 1: string}
     */
    private function convertMessages(array $messages): array
    {
        $contents = [];
        $systemText = '';

        foreach ($messages as $message) {
            $role = $message['role'];
            $text = $message['content'];

            if ($role === 'system') {
                $systemText .= ($systemText !== '' ? "\n" : '') . $text;
                continue;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';

            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $text]],
            ];
        }

        return [$contents, $systemText];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildGenerationConfig(array $options): array
    {
        $config = [];

        if (isset($options['temperature'])) {
            $config['temperature'] = $options['temperature'];
        }
        if (isset($options['max_output_tokens'])) {
            $config['maxOutputTokens'] = $options['max_output_tokens'];
        }

        return $config;
    }

    public function chatStream(array $messages, array $options = []): \Generator
    {
        $model = $options['model'] ?? $this->model;
        [$contents, $systemText] = $this->convertMessages($messages);
        $generationConfig = $this->buildGenerationConfig($options);

        $body = ['contents' => $contents];
        if ($systemText !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemText]]];
        }
        if (!empty($generationConfig)) {
            $body['generationConfig'] = $generationConfig;
        }

        $url = rtrim($this->baseUrl, '/') . '/models/' . $model . ':streamGenerateContent?alt=sse&key=' . $this->apiKey;

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => $options['timeout_seconds'] ?? $this->timeoutSeconds,
                'connect_timeout' => $options['connect_timeout_seconds'] ?? $this->connectTimeoutSeconds,
                'http_errors' => true,
                'stream' => true,
            ]);
        } catch (ConnectException $e) {
            throw new TimeoutException(
                'Gemini connection timeout: ' . $this->sanitizeMessage($e->getMessage()),
                0,
                $e,
                $this->name(),
            );
        } catch (RequestException $e) {
            throw $this->classifyHttpException($e);
        }

        return $this->readGeminiSSEStream($response->getBody());
    }

    /**
     * @return \Generator<int, string, mixed, void>
     */
    private function readGeminiSSEStream(\Psr\Http\Message\StreamInterface $body): \Generator
    {
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = json_decode(substr($line, 6), true);

                if ($data === null) {
                    continue;
                }

                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if ($text !== null && $text !== '') {
                    yield $text;
                }
            }
        }
    }

    /**
     * Redact the API key from any string that might be logged or thrown.
     *
     * Gemini uses URL query-parameter authentication (?key=...), so the API key
     * can leak into Guzzle exception messages and stack traces.
     */
    private function sanitizeMessage(string $message): string
    {
        if ($this->apiKey !== '') {
            return str_replace($this->apiKey, '[REDACTED]', $message);
        }

        return $message;
    }

    private function classifyHttpException(RequestException $e): \Thaiduc96\LlmGateway\Exceptions\LLMException
    {
        $response = $e->getResponse();
        $statusCode = $response?->getStatusCode();
        $responseBody = $response ? (string) $response->getBody() : '';
        $message = 'Gemini HTTP ' . ($statusCode ?? 'unknown') . ': ' . $this->sanitizeMessage($e->getMessage());

        $retryAfterSeconds = $this->parseRetryAfter($response);

        // Check body for RESOURCE_EXHAUSTED before status code classification
        if ($responseBody && str_contains($responseBody, 'RESOURCE_EXHAUSTED')) {
            return new RateLimitedException($message, 0, $e, $this->name(), $statusCode, $retryAfterSeconds);
        }

        if ($statusCode === null) {
            return new TimeoutException($message, 0, $e, $this->name());
        }

        return match (true) {
            $statusCode === 429 => new RateLimitedException($message, 0, $e, $this->name(), $statusCode, $retryAfterSeconds),
            $statusCode === 503 => new OverloadedException($message, 0, $e, $this->name(), $statusCode),
            $statusCode === 401, $statusCode === 403 => new AuthException($message, 0, $e, $this->name(), $statusCode),
            $statusCode >= 500 && $statusCode <= 599 => new ProviderException($message, 0, $e, $this->name(), $statusCode),
            $statusCode >= 400 && $statusCode <= 499 => new BadRequestException($message, 0, $e, $this->name(), $statusCode),
            default => new ProviderException($message, 0, $e, $this->name(), $statusCode),
        };
    }

    private function parseRetryAfter(?\Psr\Http\Message\ResponseInterface $response): ?int
    {
        if ($response === null) {
            return null;
        }

        $header = $response->getHeaderLine('Retry-After');
        if ($header !== '' && is_numeric($header)) {
            return max(1, (int) $header);
        }

        return null;
    }
}
