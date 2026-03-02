# thaiduc96/llm-gateway

Production-grade, provider-agnostic LLM gateway for Laravel with automatic fallback, circuit breaker, and retry policies.

## Features

- **Provider-agnostic core** — no hardcoded provider logic in the manager
- **Built-in providers** — OpenAI and Gemini (Google AI) out of the box
- **Automatic fallback** — configurable failover from primary to fallback provider
- **Circuit breaker** — cooldown mechanism via Laravel Cache to skip degraded providers
- **Retry policy** — bounded, safe retries for transient failures
- **Typed exceptions** — precise error classification matching HTTP status codes
- **Laravel events** — full observability with structured event payloads
- **Streaming support** — real-time token streaming via SSE with `stream()`
- **Runtime overrides** — switch providers per-request without config changes
- **Extensible** — add new providers without editing core code

## Requirements

- PHP 8.2+
- Laravel 10.x or 11.x

## Installation

```bash
composer require thaiduc96/llm-gateway
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=llm-gateway-config
```

Set your API keys in `.env`:

```env
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=AIza...
LLM_PRIMARY_PROVIDER=openai
LLM_FALLBACK_PROVIDER=gemini
```

## Usage

### Basic Chat

```php
use Thaiduc96\LlmGateway\Facades\LLM;

$result = LLM::chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'Explain quantum computing in one sentence.'],
]);

echo $result->content;       // "Quantum computing uses..."
echo $result->provider;      // "openai"
echo $result->model;         // "gpt-4o"
echo $result->latencyMs;     // 1234.56
echo $result->usage['total_tokens']; // 42
```

### Options

```php
$result = LLM::chat($messages, [
    'temperature' => 0.3,
    'max_output_tokens' => 2048,
    'request_id' => 'req-abc-123',  // passed through to events
]);
```

### Streaming

Stream responses token-by-token using Server-Sent Events (SSE):

```php
$stream = LLM::stream([
    ['role' => 'user', 'content' => 'Write a short poem about Laravel.'],
]);

foreach ($stream as $chunk) {
    echo $chunk; // Each chunk is a string fragment
    flush();
}
```

Streaming supports the same fallback logic as `chat()` — if the primary provider fails to connect, it automatically falls back to the secondary provider. Runtime overrides work too:

```php
$stream = LLM::usingPrimary('gemini')->stream($messages, [
    'temperature' => 0.9,
]);
```

### Runtime Provider Override

```php
// Use Gemini as primary, OpenAI as fallback (for this call only)
$result = LLM::usingPrimary('gemini')
    ->usingFallback('openai')
    ->chat($messages);

// Disable fallback entirely
$result = LLM::usingFallback(null)->chat($messages);
```

## Configuration

All options are configurable in `config/llm-gateway.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `default.primary` | `openai` | Primary provider name |
| `default.fallback` | `gemini` | Fallback provider name |
| `fallback_on` | `[rate_limit, timeout, overloaded, server_error, malformed_response]` | Failure conditions that trigger fallback |
| `cooldown_seconds` | `60` | Circuit breaker cooldown duration |
| `timeout_seconds` | `30` | HTTP request timeout |
| `connect_timeout_seconds` | `5` | HTTP connection timeout |
| `retry_attempts` | `1` | Retry count (0–2 max) |
| `retry_backoff_ms` | `200` | Base backoff between retries (ms) |
| `retry_max_backoff_ms` | `5000` | Max backoff cap for exponential backoff (ms) |
| `retry_on_overloaded` | `false` | Whether to retry 503 errors |
| `cache_ttl_seconds` | `0` | Response cache TTL (0 = disabled) |
| `defaults.temperature` | `0.7` | Default temperature |
| `defaults.max_output_tokens` | `1024` | Default max output tokens |

## Error Classification

| Condition | Exception | Fallback? | Cooldown? |
|-----------|-----------|-----------|-----------|
| HTTP 429 | `RateLimitedException` | YES | YES |
| Body contains `RESOURCE_EXHAUSTED` | `RateLimitedException` | YES | YES |
| HTTP 503 | `OverloadedException` | YES | YES |
| Network/connect timeout | `TimeoutException` | YES | NO |
| HTTP 500–599 (except 503) | `ProviderException` | YES | NO |
| HTTP 401/403 | `AuthException` | NO | NO |
| HTTP 400–499 (except 401/403/429) | `BadRequestException` | NO | NO |
| Malformed JSON on 200 | `ProviderException` | YES | NO |
| Missing fields on 200 | `ProviderException` | YES | NO |

## Events

All events are dispatched via Laravel's event system:

- `LLMRequested` — fired before calling a provider
- `LLMSucceeded` — fired on successful response
- `LLMFailed` — fired on provider failure
- `LLMFallbackTriggered` — fired when falling back to secondary provider

Every event includes: `provider`, `model`, `latencyMs`, `options`, `requestId`, `usage` (where applicable), and `exceptionClass`/`exceptionCode` (on failure).

```php
use Thaiduc96\LlmGateway\Events\LLMFailed;

Event::listen(LLMFailed::class, function (LLMFailed $event) {
    Log::warning('LLM call failed', [
        'provider' => $event->provider,
        'exception' => $event->exceptionClass,
        'latency_ms' => $event->latencyMs,
    ]);
});
```

## Adding a Custom Provider

1. Implement `Thaiduc96\LlmGateway\Contracts\LLMProvider`:

```php
use Thaiduc96\LlmGateway\Contracts\LLMProvider;
use Thaiduc96\LlmGateway\DTOs\LLMResult;

class AnthropicProvider implements LLMProvider
{
    public function name(): string { return 'anthropic'; }

    public function chat(array $messages, array $options = []): LLMResult
    {
        // Your implementation here
    }

    public function chatStream(array $messages, array $options = []): \Generator
    {
        // Your streaming implementation here
    }
}
```

2. Add to config:

```php
// config/llm-gateway.php
'providers' => [
    'anthropic' => [
        'driver' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-sonnet-4-20250514',
        'base_url' => 'https://api.anthropic.com/v1',
    ],
],
```

3. Register the driver in a service provider:

```php
use Thaiduc96\LlmGateway\Contracts\ProviderRegistry;

$this->app->resolving(ProviderRegistry::class, function (ProviderRegistry $registry) {
    $registry->register('anthropic', function (array $config) {
        return new AnthropicProvider($config);
    });
});
```

4. Use it:

```php
LLM::usingPrimary('anthropic')->chat($messages);
```

No changes to `LLMGatewayManager` required.

## Runtime Flow

1. Resolve effective primary (runtime override > config)
2. Resolve effective fallback (runtime override > config)
3. Check cooldown on primary — if active, skip to fallback
4. Fire `LLMRequested`
5. Call primary provider (with retry policy)
6. On success: fire `LLMSucceeded`, return `LLMResult`
7. On failure: fire `LLMFailed`
8. If failure matches fallback rules: activate cooldown (if required), fire `LLMFallbackTriggered`, attempt fallback
9. If fallback fails: fire `LLMFailed`, throw final exception

## Response Caching

Enable response caching by setting `cache_ttl_seconds` to a positive value:

```env
LLM_CACHE_TTL_SECONDS=300  # Cache responses for 5 minutes
```

The cache key is a SHA-256 hash of the provider, model, messages, and merged options. Identical requests within the TTL will return the cached response without calling the provider. Caching is disabled by default (`cache_ttl_seconds = 0`).

## Security Considerations

**Gemini API Key in URLs:** Google's Gemini API uses URL query-parameter authentication (`?key=...`). This means the API key may appear in HTTP access logs, proxy logs, or browser history. The gateway automatically sanitizes (redacts) the API key from all exception messages and stack traces to prevent accidental exposure in application logs.

To further protect your Gemini API key:
- Always use environment variables (`GEMINI_API_KEY`) — never commit keys to version control
- Configure your web server and reverse proxy to avoid logging query strings
- Use Google Cloud's API key restrictions to limit key usage by IP, referrer, or API

## Testing

```bash
composer test
# or
./vendor/bin/phpunit
```

## License

MIT
