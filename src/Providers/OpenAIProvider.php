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

final class OpenAIProvider implements LLMProvider
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
        return 'openai';
    }

    public function chat(array $messages, array $options = []): LLMResult
    {
        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }
        if (isset($options['max_output_tokens'])) {
            $model = $body['model'];
            if (preg_match('/^(o1|o3|o4)/', $model)) {
                $body['max_completion_tokens'] = $options['max_output_tokens'];
            } else {
                $body['max_tokens'] = $options['max_output_tokens'];
            }
        }

        $startTime = hrtime(true);

        try {
            $response = $this->client->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => $options['timeout_seconds'] ?? $this->timeoutSeconds,
                'connect_timeout' => $options['connect_timeout_seconds'] ?? $this->connectTimeoutSeconds,
                'http_errors' => true,
            ]);
        } catch (ConnectException $e) {
            throw new TimeoutException(
                'OpenAI connection timeout: ' . $e->getMessage(),
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
                'OpenAI returned malformed JSON: ' . json_last_error_msg(),
                0,
                null,
                $this->name(),
                $statusCode,
                true,
            );
        }

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new ProviderException(
                'OpenAI response missing expected fields: choices[0].message.content',
                0,
                null,
                $this->name(),
                $statusCode,
                true,
            );
        }

        $usage = null;
        if (isset($data['usage'])) {
            $usage = [
                'prompt_tokens' => $data['usage']['prompt_tokens'] ?? null,
                'completion_tokens' => $data['usage']['completion_tokens'] ?? null,
                'total_tokens' => $data['usage']['total_tokens'] ?? null,
            ];
        }

        return new LLMResult(
            content: $data['choices'][0]['message']['content'],
            provider: $this->name(),
            model: $data['model'] ?? ($options['model'] ?? $this->model),
            latencyMs: $latencyMs,
            usage: $usage,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
        );
    }

    public function chatStream(array $messages, array $options = []): \Generator
    {
        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'stream' => true,
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }
        if (isset($options['max_output_tokens'])) {
            $model = $body['model'];
            if (preg_match('/^(o1|o3|o4)/', $model)) {
                $body['max_completion_tokens'] = $options['max_output_tokens'];
            } else {
                $body['max_tokens'] = $options['max_output_tokens'];
            }
        }

        try {
            $response = $this->client->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
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
                'OpenAI connection timeout: ' . $e->getMessage(),
                0,
                $e,
                $this->name(),
            );
        } catch (RequestException $e) {
            throw $this->classifyHttpException($e);
        }

        return $this->readOpenAISSEStream($response->getBody());
    }

    /**
     * @return \Generator<int, string, mixed, void>
     */
    private function readOpenAISSEStream(\Psr\Http\Message\StreamInterface $body): \Generator
    {
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || $line === 'data: [DONE]') {
                    continue;
                }

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = json_decode(substr($line, 6), true);

                if ($data === null) {
                    continue;
                }

                $content = $data['choices'][0]['delta']['content'] ?? null;

                if ($content !== null && $content !== '') {
                    yield $content;
                }
            }
        }
    }

    private function classifyHttpException(RequestException $e): \Thaiduc96\LlmGateway\Exceptions\LLMException
    {
        $response = $e->getResponse();
        $statusCode = $response?->getStatusCode();
        $responseBody = $response ? (string) $response->getBody() : '';
        $message = 'OpenAI HTTP ' . ($statusCode ?? 'unknown') . ': ' . $e->getMessage();

        $retryAfterSeconds = $this->parseRetryAfter($response);

        // Check body for RESOURCE_EXHAUSTED (Gemini-style but could appear)
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
